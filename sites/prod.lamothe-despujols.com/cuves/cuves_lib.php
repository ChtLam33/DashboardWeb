<?php
// cuves_lib.php — acces SQLite pour le module cuves (migration depuis
// data_cuves.csv / config_cuves.json / cache_dashboard.json / history_lots.json).
// Memes conventions que barriques/barriques_lib.php.

/* =========================================================
   PATHS
   ========================================================= */
function cuvesDbPath(): string {
    return __DIR__ . '/cuves.sqlite';
}

function cuvesBackupDir(): string {
    $dir = __DIR__ . '/backup_cuves';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir;
}

/* =========================================================
   SQLITE
   - mesures : stocke uniquement la distance brute (comme barriques
     stocke "raw"). Le volume/%, etc. sont recalcules a la lecture via
     interpretCuve(), avec la config COURANTE - pas figes a l'insertion.
     Ca evite de perdre l'ancien bug (calcul fait cote firmware, jamais
     rejoue si la calibration change) et ca reste coherent avec la
     facon dont barriques fait deja interpret_raw().
   - config : remplace config_cuves.json. "position" remplace l'ordre
     implicite du tableau JSON (utilise par save_order.php).
   - history_snapshots / history_snapshot_lots : remplace history_lots.json
     (structure normalisee : un snapshot -> plusieurs lignes de lot).
   ========================================================= */
function dbConnectCuves(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('sqlite:' . cuvesDbPath());
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA journal_mode = WAL;');

        $pdo->exec('CREATE TABLE IF NOT EXISTS mesures (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            sensor_id TEXT NOT NULL,
            distance_cm INTEGER NOT NULL,
            rssi INTEGER,
            fw TEXT,
            ts INTEGER NOT NULL,
            date_iso TEXT NOT NULL
        )');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_cuves_mesures_sensor_ts ON mesures(sensor_id, ts)');

        $pdo->exec('CREATE TABLE IF NOT EXISTS config (
            sensor_id TEXT PRIMARY KEY,
            nom_cuve TEXT NOT NULL DEFAULT "",
            lot TEXT NOT NULL DEFAULT "",
            hauteur_capteur_fond REAL NOT NULL DEFAULT 200,
            hauteur_max_liquide REAL NOT NULL DEFAULT 50,
            diametre_cuve REAL NOT NULL DEFAULT 70,
            ajustement_hl REAL NOT NULL DEFAULT 0,
            couleur TEXT NOT NULL DEFAULT "",
            position INTEGER NOT NULL DEFAULT 0
        )');

        $pdo->exec('CREATE TABLE IF NOT EXISTS history_snapshots (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ts INTEGER NOT NULL,
            comment TEXT NOT NULL DEFAULT ""
        )');
        $pdo->exec('CREATE TABLE IF NOT EXISTS history_snapshot_lots (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            snapshot_id INTEGER NOT NULL,
            lot TEXT NOT NULL,
            volume_hl REAL NOT NULL DEFAULT 0
        )');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_cuves_snapshot_lots_snapshot ON history_snapshot_lots(snapshot_id)');

        $pdo->exec('CREATE TABLE IF NOT EXISTS settings (
            key TEXT PRIMARY KEY,
            value TEXT NOT NULL
        )');
    }
    return $pdo;
}

function getCuvesSetting(string $key, string $default = ''): string {
    $pdo = dbConnectCuves();
    $stmt = $pdo->prepare('SELECT value FROM settings WHERE key = ?');
    $stmt->execute([$key]);
    $v = $stmt->fetchColumn();
    return ($v === false) ? $default : (string)$v;
}

function setCuvesSetting(string $key, string $value): void {
    $pdo = dbConnectCuves();
    $stmt = $pdo->prepare(
        'INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value'
    );
    $stmt->execute([$key, $value]);
}

/* =========================================================
   INTERPRETATION DISTANCE BRUTE -> VOLUME
   Port fidele de buildStatusString() du firmware cuve
   (GITHUB-ESP32-LUNA/.../*.ino) : meme formule, cote serveur, pour que
   la config s'applique immediatement (plus besoin d'attendre un
   redemarrage/sync du capteur pour qu'un changement de calibration
   prenne effet).
   ========================================================= */
function interpretCuve(int $distanceRaw, array $config): array {
    $hauteurCapteurFond = (float)($config['hauteur_capteur_fond'] ?? 200);
    $hauteurMaxLiquide  = (float)($config['hauteur_max_liquide']  ?? 50);
    $diametreCuve       = (float)($config['diametre_cuve']        ?? 70);
    $ajustementHL       = (float)($config['ajustement_hl']        ?? 0);

    $hauteurCuve = $hauteurCapteurFond - $hauteurMaxLiquide;
    if ($hauteurCuve <= 0) $hauteurCuve = 1.0;

    $hauteurPlein = $hauteurCuve - ($distanceRaw - $hauteurMaxLiquide);
    if ($hauteurPlein < 0) $hauteurPlein = 0;
    if ($hauteurPlein > $hauteurCuve) $hauteurPlein = $hauteurCuve;

    $pourcentage   = ($hauteurPlein / $hauteurCuve) * 100.0;
    $capaciteHL    = (M_PI * pow($diametreCuve / 2.0, 2) * $hauteurCuve) / 100000.0;
    $volumeHL      = ($pourcentage / 100.0) * $capaciteHL + $ajustementHL;

    if (is_nan($pourcentage)) $pourcentage = 0;
    if (is_nan($capaciteHL))  $capaciteHL  = 0;
    if (is_nan($volumeHL))    $volumeHL    = 0;

    return [
        'distance_cm'  => $distanceRaw,
        'volume_hl'    => $volumeHL,
        'capacite_hl'  => $capaciteHL,
        'pourcentage'  => $pourcentage,
        'correction'   => $ajustementHL,
        'hauteurPlein' => $hauteurPlein,
        'hauteurCuve'  => $hauteurCuve,
    ];
}

/* =========================================================
   MESURES
   ========================================================= */
function insertCuveMeasurement(string $sensorId, int $distanceCm, ?int $rssi, ?string $fw, int $ts, string $dateIso): void {
    $pdo = dbConnectCuves();
    $stmt = $pdo->prepare(
        'INSERT INTO mesures (sensor_id, distance_cm, rssi, fw, ts, date_iso) VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$sensorId, $distanceCm, $rssi, $fw, $ts, $dateIso]);
}

/**
 * Une ligne par capteur : la mesure la plus recente. Cle = sensor_id.
 */
function getLatestCuveMeasurements(): array {
    $pdo = dbConnectCuves();
    $rows = $pdo->query(
        'SELECT m.sensor_id, m.distance_cm, m.rssi, m.fw, m.ts, m.date_iso
         FROM mesures m
         INNER JOIN (
             SELECT sensor_id, MAX(ts) AS max_ts FROM mesures GROUP BY sensor_id
         ) last ON last.sensor_id = m.sensor_id AND last.max_ts = m.ts'
    )->fetchAll(PDO::FETCH_ASSOC);

    $out = [];
    foreach ($rows as $r) {
        $out[$r['sensor_id']] = $r;
    }
    return $out;
}

/* =========================================================
   CONFIG (remplace config_cuves.json)
   ========================================================= */
function getCuvesConfig(): array {
    $pdo = dbConnectCuves();
    return $pdo->query('SELECT * FROM config ORDER BY position ASC')->fetchAll(PDO::FETCH_ASSOC);
}

function getCuveConfigById(string $sensorId): ?array {
    $pdo = dbConnectCuves();
    $stmt = $pdo->prepare('SELECT * FROM config WHERE sensor_id = ?');
    $stmt->execute([$sensorId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Enregistre un capteur par defaut s'il n'existe pas encore dans config
 * (reprend le role de l'ancien update_config_from_csv.php, declenche
 * directement depuis api_cuve.php a la reception d'une mesure).
 */
function ensureCuveConfigExists(string $sensorId, string $nomCuve): void {
    $pdo = dbConnectCuves();
    $stmt = $pdo->prepare('SELECT 1 FROM config WHERE sensor_id = ?');
    $stmt->execute([$sensorId]);
    if ($stmt->fetchColumn() !== false) {
        return;
    }
    $maxPos = (int)$pdo->query('SELECT COALESCE(MAX(position), -1) FROM config')->fetchColumn();
    $stmt = $pdo->prepare(
        'INSERT INTO config (sensor_id, nom_cuve, lot, hauteur_capteur_fond, hauteur_max_liquide, diametre_cuve, ajustement_hl, couleur, position)
         VALUES (?, ?, "", 200, 50, 70, 0, "", ?)'
    );
    $stmt->execute([$sensorId, $nomCuve, $maxPos + 1]);
}

/**
 * Remplace entierement la config (meme comportement que l'ancien
 * save_config.php : le client envoie le tableau complet). "position"
 * = index dans le tableau recu.
 */
function saveCuvesConfig(array $entries): array {
    $pdo = dbConnectCuves();
    $pdo->beginTransaction();
    $pdo->exec('DELETE FROM config');
    $stmt = $pdo->prepare(
        'INSERT INTO config (sensor_id, nom_cuve, lot, hauteur_capteur_fond, hauteur_max_liquide, diametre_cuve, ajustement_hl, couleur, position)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $saved = [];
    foreach ($entries as $i => $e) {
        $sensorId = trim((string)($e['id'] ?? ''));
        if ($sensorId === '') continue;
        $row = [
            'sensor_id'            => $sensorId,
            'nom_cuve'             => trim((string)($e['nomCuve'] ?? '')),
            'lot'                  => trim((string)($e['lot'] ?? '')),
            'hauteur_capteur_fond' => (float)($e['hauteurCapteurFond'] ?? 0),
            'hauteur_max_liquide'  => (float)($e['hauteurMaxLiquide'] ?? 0),
            'diametre_cuve'        => (float)($e['diametreCuve'] ?? 0),
            'ajustement_hl'        => (float)($e['AjustementHL'] ?? 0),
            'couleur'              => (string)($e['couleur'] ?? ''),
            'position'             => $i,
        ];
        $stmt->execute(array_values($row));
        $saved[] = $row;
    }
    $pdo->commit();
    return $saved;
}

/**
 * Reordonne la config : les ids listes d'abord (dans l'ordre donne),
 * puis les autres dans leur ordre actuel - meme logique que l'ancien
 * save_order.php.
 */
function saveCuvesOrder(array $orderIds): int {
    $pdo = dbConnectCuves();
    $current = $pdo->query('SELECT sensor_id FROM config ORDER BY position ASC')->fetchAll(PDO::FETCH_COLUMN);

    $newOrder = [];
    $seen = [];
    foreach ($orderIds as $id) {
        if (in_array($id, $current, true) && !isset($seen[$id])) {
            $newOrder[] = $id;
            $seen[$id] = true;
        }
    }
    foreach ($current as $id) {
        if (!isset($seen[$id])) {
            $newOrder[] = $id;
            $seen[$id] = true;
        }
    }

    $pdo->beginTransaction();
    $stmt = $pdo->prepare('UPDATE config SET position = ? WHERE sensor_id = ?');
    foreach ($newOrder as $pos => $id) {
        $stmt->execute([$pos, $id]);
    }
    $pdo->commit();

    return count($newOrder);
}

/**
 * Reinitialise la config d'un capteur (nom/lot/hauteurs remis a zero),
 * sans toucher a ses mesures passees - voir purge_cuves.php.
 */
function resetCuveConfig(string $sensorId): void {
    $pdo = dbConnectCuves();
    $stmt = $pdo->prepare('DELETE FROM config WHERE sensor_id = ?');
    $stmt->execute([$sensorId]);
}

/* =========================================================
   HISTORIQUE DES SNAPSHOTS (remplace history_lots.json)
   ========================================================= */
function addCuveSnapshot(string $comment, array $lotsTotals): array {
    $pdo = dbConnectCuves();
    $ts = time();
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('INSERT INTO history_snapshots (ts, comment) VALUES (?, ?)');
    $stmt->execute([$ts, $comment]);
    $snapshotId = (int)$pdo->lastInsertId();

    $stmtLot = $pdo->prepare('INSERT INTO history_snapshot_lots (snapshot_id, lot, volume_hl) VALUES (?, ?, ?)');
    $lotsArray = [];
    foreach ($lotsTotals as $lotName => $vhl) {
        $stmtLot->execute([$snapshotId, $lotName, (float)$vhl]);
        $lotsArray[] = ['lot' => $lotName, 'volume_hl' => (float)$vhl];
    }

    // Retention : garde les 300 plus recents snapshots (comme l'ancienne limite JSON)
    $ids = $pdo->query('SELECT id FROM history_snapshots ORDER BY ts DESC')->fetchAll(PDO::FETCH_COLUMN);
    if (count($ids) > 300) {
        $toDelete = array_slice($ids, 300);
        $placeholders = implode(',', array_fill(0, count($toDelete), '?'));
        $pdo->prepare("DELETE FROM history_snapshot_lots WHERE snapshot_id IN ($placeholders)")->execute($toDelete);
        $pdo->prepare("DELETE FROM history_snapshots WHERE id IN ($placeholders)")->execute($toDelete);
    }

    $pdo->commit();

    return [
        'datetime' => date('Y-m-d H:i:s', $ts),
        'comment'  => $comment,
        'total_hl' => array_sum(array_map('floatval', $lotsTotals)),
        'lots'     => $lotsArray,
    ];
}

/**
 * Renvoie les snapshots les plus recents d'abord, limite a $limit.
 */
function getCuveSnapshots(int $limit = 50): array {
    $pdo = dbConnectCuves();
    $stmt = $pdo->prepare('SELECT id, ts, comment FROM history_snapshots ORDER BY ts DESC LIMIT ?');
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();
    $snapshots = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($snapshots)) return [];

    $ids = array_column($snapshots, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $lotsStmt = $pdo->prepare("SELECT snapshot_id, lot, volume_hl FROM history_snapshot_lots WHERE snapshot_id IN ($placeholders)");
    $lotsStmt->execute($ids);
    $lotsBySnapshot = [];
    foreach ($lotsStmt->fetchAll(PDO::FETCH_ASSOC) as $l) {
        $lotsBySnapshot[$l['snapshot_id']][] = ['lot' => $l['lot'], 'volume_hl' => (float)$l['volume_hl']];
    }

    $out = [];
    foreach ($snapshots as $s) {
        $lots = $lotsBySnapshot[$s['id']] ?? [];
        $out[] = [
            'datetime' => date('Y-m-d H:i:s', (int)$s['ts']),
            'comment'  => $s['comment'],
            'total_hl' => array_sum(array_column($lots, 'volume_hl')),
            'lots'     => $lots,
        ];
    }
    return $out;
}

/* =========================================================
   SAUVEGARDES (backup_cuves/)
   Identique au principe de barriques_lib.php : pas de bouton de
   sauvegarde manuelle, verification opportuniste a chaque chargement
   du dashboard (maybeRunScheduledBackupCuves(), appelee depuis
   index.php), restauration disponible depuis le dashboard.
   ========================================================= */
function createCuvesBackupNow(): string {
    $pdo = dbConnectCuves();
    $filename = 'cuves_' . date('Y-m-d_His') . '.sqlite';
    $targetPath = cuvesBackupDir() . '/' . $filename;

    $escapedPath = str_replace("'", "''", $targetPath);
    $pdo->exec("VACUUM INTO '{$escapedPath}'");

    setCuvesSetting('last_sqlite_backup_ts', (string)time());

    $backups = listAvailableCuvesBackups();
    if (count($backups) > 3) {
        foreach (array_slice($backups, 3) as $old) {
            @unlink(cuvesBackupDir() . '/' . $old['filename']);
        }
    }

    return $filename;
}

function maybeRunScheduledBackupCuves(): void {
    $lastTs = (int)getCuvesSetting('last_sqlite_backup_ts', '0');
    $intervalS = 30 * 86400;
    if ($lastTs > 0 && (time() - $lastTs) < $intervalS) {
        return;
    }
    try {
        createCuvesBackupNow();
    } catch (\Throwable $e) {
        error_log('[cuves] maybeRunScheduledBackupCuves failed: ' . $e->getMessage());
    }
}

function listAvailableCuvesBackups(): array {
    $files = glob(cuvesBackupDir() . '/cuves_*.sqlite') ?: [];
    $out = [];
    foreach ($files as $path) {
        $out[] = [
            'filename' => basename($path),
            'ts'       => filemtime($path) ?: 0,
        ];
    }
    usort($out, fn($a, $b) => $b['ts'] <=> $a['ts']);
    foreach ($out as &$b) {
        $b['label'] = date('d/m/Y H:i', $b['ts']);
    }
    unset($b);
    return $out;
}

/**
 * IMPORTANT : ne doit etre appelee qu'avant tout dbConnectCuves() dans
 * la requete en cours (voir index.php), pour ne pas remplacer le
 * fichier pendant qu'une connexion PDO y est deja ouverte.
 */
function restoreCuvesBackup(string $filename): bool {
    $filename = basename($filename);
    $sourcePath = cuvesBackupDir() . '/' . $filename;
    if (!is_file($sourcePath)) {
        return false;
    }

    try {
        createCuvesBackupNow();
    } catch (\Throwable $e) {
        error_log('[cuves] pre-restore backup failed: ' . $e->getMessage());
    }

    $dbPath = cuvesDbPath();

    @unlink($dbPath . '-wal');
    @unlink($dbPath . '-shm');

    if (!copy($sourcePath, $dbPath)) {
        return false;
    }

    @unlink($dbPath . '-wal');
    @unlink($dbPath . '-shm');

    return true;
}
