<?php
// barriques_lib.php

require_once __DIR__ . '/secrets.local.php'; // BARRIQUE_PROVISIONING_SECRET

/* =========================================================
   PATHS
   ========================================================= */
function barriquesDbPath(): string {
    return __DIR__ . '/barriques.sqlite';
}

function backupDir(): string {
    $dir = __DIR__ . '/backup_barriques';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir;
}

/* =========================================================
   SQLITE
   - Phase 1 (sept. 2026) : mesures (ex logs/barriques.log).
   - Phase 2 (sept. 2026) : config lots, offsets, historique des
     lots, reglages (config.json + notifications_config.json),
     abonnements push. Voir audit CSV/JSON vs SQLite vs Supabase.
   ========================================================= */
function dbConnect(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('sqlite:' . barriquesDbPath());
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA journal_mode = WAL;');
        // Sans ca, une ecriture qui tombe pile en meme temps qu'une autre
        // echoue immediatement (SQLITE_BUSY) au lieu d'attendre son tour.
        $pdo->exec('PRAGMA busy_timeout = 5000;');
        $pdo->exec('CREATE TABLE IF NOT EXISTS mesures (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            sensor_id TEXT NOT NULL,
            date_iso TEXT NOT NULL,
            raw INTEGER NOT NULL,
            battery_mv INTEGER,
            rssi INTEGER,
            fw TEXT,
            ts INTEGER NOT NULL
        )');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_mesures_sensor_ts ON mesures(sensor_id, ts)');

        // v2.0.1 firmware : duree de deep sleep (s) utilisee par CE cycle de
        // mesure, pour calculer une date de prochain reveil fiable par capteur.
        // Ajoutee via ALTER TABLE (colonne absente sur les bases creees avant).
        $mesuresCols = $pdo->query('PRAGMA table_info(mesures)')->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!in_array('sleep_s', $mesuresCols, true)) {
            $pdo->exec('ALTER TABLE mesures ADD COLUMN sleep_s INTEGER');
        }

        $pdo->exec('CREATE TABLE IF NOT EXISTS settings (
            key TEXT PRIMARY KEY,
            value TEXT NOT NULL
        )');

        $pdo->exec('CREATE TABLE IF NOT EXISTS offsets_creux (
            sensor_id TEXT PRIMARY KEY,
            offset_cm REAL NOT NULL DEFAULT 0
        )');

        $pdo->exec('CREATE TABLE IF NOT EXISTS lots_config (
            sensor_id TEXT PRIMARY KEY,
            lot TEXT NOT NULL,
            barriques INTEGER NOT NULL DEFAULT 0
        )');

        $pdo->exec('CREATE TABLE IF NOT EXISTS lot_history (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            sensor_id TEXT NOT NULL,
            lot TEXT NOT NULL,
            from_ts INTEGER NOT NULL,
            to_ts INTEGER,
            barriques INTEGER NOT NULL DEFAULT 0
        )');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_lot_history_sensor ON lot_history(sensor_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_lot_history_lot ON lot_history(lot)');

        $pdo->exec('CREATE TABLE IF NOT EXISTS push_subscriptions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            endpoint TEXT NOT NULL UNIQUE,
            p256dh TEXT NOT NULL,
            auth TEXT NOT NULL,
            expiration_time INTEGER
        )');

        // Cle API par capteur (firmware >= 2.1.0, voir register.php). Table
        // dediee car barriques n'a pas de table "config" par capteur comme
        // cuves ou rattacher cette colonne - lots_config/offsets_creux ne
        // sont pas garantis d'avoir une ligne pour chaque capteur existant.
        $pdo->exec('CREATE TABLE IF NOT EXISTS api_keys (
            sensor_id TEXT PRIMARY KEY,
            api_key TEXT NOT NULL,
            created_ts INTEGER NOT NULL
        )');
    }
    return $pdo;
}

/* =========================================================
   REGLAGES (cle/valeur) - remplace config.json + notifications_config.json
   ========================================================= */
function getSetting(string $key, string $default = ''): string {
    $pdo = dbConnect();
    $stmt = $pdo->prepare('SELECT value FROM settings WHERE key = ?');
    $stmt->execute([$key]);
    $v = $stmt->fetchColumn();
    return ($v === false) ? $default : (string)$v;
}

function setSetting(string $key, string $value): void {
    $pdo = dbConnect();
    $stmt = $pdo->prepare(
        'INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value'
    );
    $stmt->execute([$key, $value]);
}

/* =========================================================
   CONFIG LOTS (par capteur) - remplace config_lots.json
   ========================================================= */
function loadLotsConfig(): array {
    $pdo = dbConnect();
    $rows = $pdo->query('SELECT sensor_id, lot, barriques FROM lots_config')->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $r) {
        $out[$r['sensor_id']] = [
            'lot'       => $r['lot'],
            'barriques' => (int)$r['barriques'],
        ];
    }
    return $out;
}

/**
 * Definit (ou retire si $lot === '') le lot/barriques d'un capteur.
 */
function saveSensorLot(string $sensorId, string $lot, int $barriques): void {
    $pdo = dbConnect();
    if ($lot === '') {
        $stmt = $pdo->prepare('DELETE FROM lots_config WHERE sensor_id = ?');
        $stmt->execute([$sensorId]);
        return;
    }
    $stmt = $pdo->prepare(
        'INSERT INTO lots_config (sensor_id, lot, barriques) VALUES (?, ?, ?)
         ON CONFLICT(sensor_id) DO UPDATE SET lot = excluded.lot, barriques = excluded.barriques'
    );
    $stmt->execute([$sensorId, $lot, $barriques]);
}

/**
 * Met a jour le nombre de barriques pour tous les capteurs d'un lot donne.
 */
function setLotBarriques(string $lot, int $barriques): void {
    $pdo = dbConnect();
    $stmt = $pdo->prepare('UPDATE lots_config SET barriques = ? WHERE lot = ?');
    $stmt->execute([$barriques, $lot]);
}

/* =========================================================
   ABONNEMENTS PUSH - remplace subscriptions.json
   ========================================================= */
function loadPushSubscriptions(): array {
    $pdo = dbConnect();
    $rows = $pdo->query('SELECT endpoint, p256dh, auth, expiration_time FROM push_subscriptions')->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'endpoint'       => $r['endpoint'],
            'expirationTime' => $r['expiration_time'],
            'keys'           => [
                'p256dh' => $r['p256dh'],
                'auth'   => $r['auth'],
            ],
        ];
    }
    return $out;
}

/**
 * Ajoute un abonnement s'il n'existe pas deja (meme endpoint).
 */
function addPushSubscriptionIfNew(array $sub): void {
    $endpoint = (string)($sub['endpoint'] ?? '');
    if ($endpoint === '') return;

    $pdo = dbConnect();
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM push_subscriptions WHERE endpoint = ?');
    $stmt->execute([$endpoint]);
    if ((int)$stmt->fetchColumn() > 0) return;

    $p256dh = (string)($sub['keys']['p256dh'] ?? '');
    $auth   = (string)($sub['keys']['auth'] ?? '');
    $expiry = array_key_exists('expirationTime', $sub) && $sub['expirationTime'] !== null
        ? (int)$sub['expirationTime']
        : null;

    $stmt = $pdo->prepare(
        'INSERT INTO push_subscriptions (endpoint, p256dh, auth, expiration_time) VALUES (?, ?, ?, ?)'
    );
    $stmt->execute([$endpoint, $p256dh, $auth, $expiry]);
}

/* =========================================================
   CLE API PAR CAPTEUR (voir register.php)
   Meme principe que cuves_lib.php::registerCuveSensor()/isValidCuveApiKey() :
   secret de provisionnement PARTAGE (grave dans le firmware) autorisant
   une inscription unique par capteur, qui recoit alors sa propre cle.
   ========================================================= */

/**
 * Verifie le secret partage (BARRIQUE_PROVISIONING_SECRET) et, si valide,
 * genere/enregistre une nouvelle cle propre a ce capteur (remplace toute
 * cle existante - utile si un capteur perd sa memoire NVS). Retourne null
 * si le secret ne correspond pas.
 */
function registerBarriqueSensor(string $sensorId, string $providedSecret): ?string {
    if (!hash_equals(BARRIQUE_PROVISIONING_SECRET, $providedSecret)) {
        return null;
    }

    $apiKey = bin2hex(random_bytes(16));
    $pdo = dbConnect();
    $stmt = $pdo->prepare(
        'INSERT INTO api_keys (sensor_id, api_key, created_ts) VALUES (?, ?, ?)
         ON CONFLICT(sensor_id) DO UPDATE SET api_key = excluded.api_key, created_ts = excluded.created_ts'
    );
    $stmt->execute([$sensorId, $apiKey, time()]);

    return $apiKey;
}

/**
 * Verifie la cle API d'un capteur avant d'accepter une requete (voir
 * api_post.php). TOLERANT pendant la transition : si ce capteur n'a
 * encore aucune cle enregistree (ancien firmware, ou pas encore appele
 * register.php), la requete est acceptee sans cle - comportement actuel,
 * ouvert. Des qu'une cle existe pour ce sensor_id, elle devient
 * obligatoire et doit correspondre.
 */
function isValidBarriqueApiKey(string $sensorId, ?string $providedKey): bool {
    $pdo = dbConnect();
    $stmt = $pdo->prepare('SELECT api_key FROM api_keys WHERE sensor_id = ?');
    $stmt->execute([$sensorId]);
    $storedKey = $stmt->fetchColumn();

    if ($storedKey === false || $storedKey === '') {
        return true; // pas encore de cle pour ce capteur : ouvert (transition)
    }

    return $providedKey !== null && hash_equals($storedKey, $providedKey);
}

function insertMeasurement(string $sensorId, string $dateIso, int $raw, ?int $batteryMv, ?int $rssi, ?string $fw, int $ts, ?int $sleepS = null): void {
    $pdo = dbConnect();
    $stmt = $pdo->prepare(
        'INSERT INTO mesures (sensor_id, date_iso, raw, battery_mv, rssi, fw, ts, sleep_s) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$sensorId, $dateIso, $raw, $batteryMv, $rssi, $fw, $ts, $sleepS]);
}

/**
 * Toutes les mesures, dans l'ordre d'insertion (equivalent a l'ordre
 * d'origine du fichier log). Meme forme que les anciennes lignes TSV
 * parsees : ['date_iso'=>..., 'id'=>..., 'raw'=>..., 'batt'=>...,
 * 'rssi'=>..., 'fw'=>..., 'ts'=>..., 'sleep_s'=>...]
 * 'sleep_s' est NULL pour les mesures d'avant le firmware 2.0.1.
 */
function getAllMeasurementRows(): array {
    $pdo = dbConnect();
    $stmt = $pdo->query(
        'SELECT date_iso, sensor_id AS id, raw, battery_mv AS batt, rssi, fw, ts, sleep_s FROM mesures ORDER BY id ASC'
    );
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Estimation de la prochaine mesure attendue pour un capteur, a partir de sa
 * DERNIERE ligne connue (celle qui contient son propre sleep_s - fiable meme
 * si le reglage serveur a change depuis). Retourne null si sleep_s est
 * inconnu (mesure d'avant le firmware 2.0.1).
 */
function estimateNextWakeTs(array $lastRow): ?int {
    if (!isset($lastRow['sleep_s']) || $lastRow['sleep_s'] === null) {
        return null;
    }
    $ts = (int)($lastRow['ts'] ?? 0);
    $sleepS = (int)$lastRow['sleep_s'];
    if ($ts <= 0 || $sleepS <= 0) {
        return null;
    }
    return $ts + $sleepS;
}

/* =========================================================
   SAUVEGARDES (backup_barriques/)
   - Pas de declenchement manuel par l'utilisateur : verification
     opportuniste a chaque chargement du dashboard (voir
     maybeRunScheduledBackup(), appelee depuis index.php). Pas
     besoin de configurer un vrai cron sur l'hebergement.
   - Restauration disponible depuis le dashboard, limitee aux
     sauvegardes existantes (voir listAvailableBackups()).
   ========================================================= */

/**
 * Cree une nouvelle sauvegarde consistante de barriques.sqlite via
 * VACUUM INTO (capture propre meme en mode WAL, contrairement a une
 * simple copie de fichier), puis supprime les plus anciennes au-dela
 * de 3. Retourne le nom du fichier cree.
 */
function createBackupNow(): string {
    $pdo = dbConnect();
    $filename = 'barriques_' . date('Y-m-d_His') . '.sqlite';
    $targetPath = backupDir() . '/' . $filename;

    $escapedPath = str_replace("'", "''", $targetPath);
    $pdo->exec("VACUUM INTO '{$escapedPath}'");

    setSetting('last_sqlite_backup_ts', (string)time());

    $backups = listAvailableBackups();
    if (count($backups) > 3) {
        foreach (array_slice($backups, 3) as $old) {
            @unlink(backupDir() . '/' . $old['filename']);
        }
    }

    return $filename;
}

/**
 * A appeler sur les pages consultees regulierement (index.php) :
 * declenche une sauvegarde si la derniere date de plus de 30 jours
 * (ou s'il n'y en a jamais eu). Remplace un vrai cron - inutile de
 * configurer quoi que ce soit sur l'hebergement.
 */
function maybeRunScheduledBackup(): void {
    $lastTs = (int)getSetting('last_sqlite_backup_ts', '0');
    $intervalS = 30 * 86400;
    if ($lastTs > 0 && (time() - $lastTs) < $intervalS) {
        return;
    }
    try {
        createBackupNow();
    } catch (\Throwable $e) {
        error_log('[barriques] maybeRunScheduledBackup failed: ' . $e->getMessage());
    }
}

/**
 * Liste les sauvegardes disponibles, les plus recentes en premier :
 * [ ['filename'=>..., 'ts'=>..., 'label'=>'15/09/2026 22:10'], ... ]
 */
function listAvailableBackups(): array {
    $files = glob(backupDir() . '/barriques_*.sqlite') ?: [];
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
 * Restaure une sauvegarde par son nom de fichier (doit exister dans
 * backup_barriques/, aucune autre valeur acceptee - basename() empeche
 * toute tentative de path traversal). Sauvegarde l'etat courant avant
 * d'ecraser, pour pouvoir annuler une restauration.
 *
 * IMPORTANT : ne doit etre appelee qu'avant toute autre utilisation de
 * dbConnect() dans la requete en cours (voir index.php), pour ne pas
 * remplacer le fichier pendant qu'une connexion PDO y est deja ouverte.
 */
function restoreBackup(string $filename): bool {
    $filename = basename($filename);
    $sourcePath = backupDir() . '/' . $filename;
    if (!is_file($sourcePath)) {
        return false;
    }

    // Filet de securite : sauvegarder l'etat actuel avant d'ecraser
    try {
        createBackupNow();
    } catch (\Throwable $e) {
        error_log('[barriques] pre-restore backup failed: ' . $e->getMessage());
    }

    $dbPath = barriquesDbPath();

    // Le fichier restaure ne doit heriter d'aucun WAL/SHM perime
    @unlink($dbPath . '-wal');
    @unlink($dbPath . '-shm');

    if (!copy($sourcePath, $dbPath)) {
        return false;
    }

    @unlink($dbPath . '-wal');
    @unlink($dbPath . '-shm');

    return true;
}

/* =========================================================
   OFFSETS CREUX
   - table offsets_creux : sensor_id => offset_cm
   - offset en cm, appliqué sur creux_cm et dérivé en litres
   ========================================================= */
function loadCreuxOffsets(): array {
    $pdo = dbConnect();
    $rows = $pdo->query('SELECT sensor_id, offset_cm FROM offsets_creux')->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $r) {
        $out[$r['sensor_id']] = (float)$r['offset_cm'];
    }
    return $out;
}

/**
 * Remplace entierement les offsets par le tableau donne (meme
 * comportement que l'ancien fichier JSON, ecrase tout a chaque appel).
 */
function saveCreuxOffsets(array $offsets): void {
    $pdo = dbConnect();
    $pdo->beginTransaction();
    $pdo->exec('DELETE FROM offsets_creux');
    $stmt = $pdo->prepare('INSERT INTO offsets_creux (sensor_id, offset_cm) VALUES (?, ?)');
    foreach ($offsets as $sensorId => $offset) {
        if (!is_numeric($offset)) continue;
        $stmt->execute([(string)$sensorId, (float)$offset]);
    }
    $pdo->commit();
}

function getCreuxOffsetForSensor(string $sensorId): float {
    static $cache = null;
    if ($cache === null) {
        $cache = loadCreuxOffsets();
    }
    $sensorId = (string)$sensorId;

    if (!isset($cache[$sensorId])) return 0.0;

    $v = $cache[$sensorId];
    if (is_numeric($v)) return (float)$v;

    return 0.0;
}

/* =========================================================
   INTERPRÉTATION RAW (palier) + offset creux en cm (optionnel)
   ========================================================= */
function interpret_raw(int $raw, float $offsetCm = 0.0): array {
    $raw = (int)$raw;

    // Grille d'origine
    $paliers = [
        [
            'min' => 1600,
            'max' => 99999,
            'niveau' => 1,
            'couleur' => 'vert',
            'creux_cm' => [0.0, 2.5],
            'creux_l'  => [0.0, 0.9],
        ],
        [
            'min' => 1400,
            'max' => 1599,
            'niveau' => 2,
            'couleur' => 'jaune',
            'creux_cm' => [2.5, 3.5],
            'creux_l'  => [0.9, 2.0],
        ],
        [
            'min' => 1200,
            'max' => 1399,
            'niveau' => 3,
            'couleur' => 'orange',
            'creux_cm' => [3.5, 4.5],
            'creux_l'  => [2.0, 4.2],
        ],
        [
            'min' => 900,
            'max' => 1199,
            'niveau' => 4,
            'couleur' => 'rouge',
            'creux_cm' => [4.5, 5.5],
            'creux_l'  => [4.2, 6.2],
        ],
        [
            'min' => 500,
            'max' => 899,
            'niveau' => 5,
            'couleur' => 'rouge_vif',
            'creux_cm' => [5.5, 7.0],
            'creux_l'  => [6.2, 8.0],
        ],
        [
            'min' => 0,
            'max' => 499,
            'niveau' => 6,
            'couleur' => 'rouge_ultra',
            'creux_cm' => [7.0, 10.0],
            'creux_l'  => [8.0, 12.0],
        ],
    ];

    foreach ($paliers as $p) {
        if ($raw >= $p['min'] && $raw <= $p['max']) {

            $cmMin = $p['creux_cm'][0];
            $cmMax = $p['creux_cm'][1];
            $lMin  = $p['creux_l'][0];
            $lMax  = $p['creux_l'][1];

            // Offset cm (empêcher creux négatif)
            if ($cmMin !== null && $cmMax !== null) {
                $cmMin = (float)$cmMin + $offsetCm;
                $cmMax = (float)$cmMax + $offsetCm;
                if ($cmMin < 0) $cmMin = 0.0;
                if ($cmMax < 0) $cmMax = 0.0;
            }

            // Conversion offset -> litres (interpolation sur le palier)
            if ($lMin !== null && $lMax !== null && $p['creux_cm'][0] !== null && $p['creux_cm'][1] !== null) {
                $baseCmMin = (float)$p['creux_cm'][0];
                $baseCmMax = (float)$p['creux_cm'][1];
                $baseLCm   = ($baseCmMax > $baseCmMin)
                    ? (((float)$lMax - (float)$lMin) / ($baseCmMax - $baseCmMin))
                    : 0.0;

                $lMin = (float)$lMin + ($offsetCm * $baseLCm);
                $lMax = (float)$lMax + ($offsetCm * $baseLCm);
                if ($lMin < 0) $lMin = 0.0;
                if ($lMax < 0) $lMax = 0.0;
            }

            return [
                'niveau'       => $p['niveau'],
                'couleur'      => $p['couleur'],
                'raw'          => $raw,
                'creux_cm_min' => ($cmMin === null ? null : round((float)$cmMin, 1)),
                'creux_cm_max' => ($cmMax === null ? null : round((float)$cmMax, 1)),
                'creux_l_min'  => ($lMin  === null ? null : round((float)$lMin,  1)),
                'creux_l_max'  => ($lMax  === null ? null : round((float)$lMax,  1)),
            ];
        }
    }

    // Normalement inatteignable depuis tes min/max, mais on garde un fallback
    return [
        'niveau'       => 7,
        'couleur'      => 'erreur',
        'raw'          => $raw,
        'creux_cm_min' => null,
        'creux_cm_max' => null,
        'creux_l_min'  => null,
        'creux_l_max'  => null,
    ];
}

/* =========================================================
   LOT HISTORY
   - table lot_history : sensor_id, lot, from_ts, to_ts (NULL=ouvert), barriques
   - loadLotHistory() reconstruit le format assoc historique :
     { "id": [ {lot, from_ts, to_ts, barriques}, ... ] }
     pour que normalizeLotHistory() et le reste du fichier n'aient
     rien a changer.
   ========================================================= */
function loadLotHistory(): array {
    $pdo = dbConnect();
    $rows = $pdo->query(
        'SELECT sensor_id, lot, from_ts, to_ts, barriques FROM lot_history ORDER BY sensor_id, from_ts'
    )->fetchAll(PDO::FETCH_ASSOC);

    $out = [];
    foreach ($rows as $r) {
        $sid = (string)$r['sensor_id'];
        $out[$sid] ??= [];
        $out[$sid][] = [
            'lot'       => $r['lot'],
            'from_ts'   => (int)$r['from_ts'],
            'to_ts'     => ($r['to_ts'] === null) ? null : (int)$r['to_ts'],
            'barriques' => (int)$r['barriques'],
        ];
    }
    return $out;
}

/**
 * Remplace entierement l'historique par le tableau donne (meme
 * comportement que l'ancien fichier JSON, ecrase tout a chaque appel).
 * Attend le format assoc : [sensorId => [ {lot, from_ts, to_ts, barriques}, ... ]]
 */
function saveLotHistory(array $history): void {
    $pdo = dbConnect();
    $pdo->beginTransaction();
    $pdo->exec('DELETE FROM lot_history');
    $stmt = $pdo->prepare(
        'INSERT INTO lot_history (sensor_id, lot, from_ts, to_ts, barriques) VALUES (?, ?, ?, ?, ?)'
    );
    foreach ($history as $sensorId => $periods) {
        if (!is_array($periods)) continue;
        foreach ($periods as $p) {
            if (!is_array($p)) continue;
            $fromTs = (int)($p['from_ts'] ?? 0);
            if ($fromTs <= 0) continue;
            $toTs = (array_key_exists('to_ts', $p) && $p['to_ts'] !== null) ? (int)$p['to_ts'] : null;

            $stmt->execute([
                (string)$sensorId,
                (string)($p['lot'] ?? ''),
                $fromTs,
                $toTs,
                (int)($p['barriques'] ?? 0),
            ]);
        }
    }
    $pdo->commit();
}

/**
 * Normalise lot_history en format assoc :
 *  [id] => [ ['lot'=>..., 'from_ts'=>..., 'to_ts'=>..., 'barriques'=>...], ... ]
 */
function normalizeLotHistory(array $hist): array {
    if (empty($hist)) return [];

    // Déjà assoc ?
    $isAssoc = array_keys($hist) !== range(0, count($hist) - 1);
    if ($isAssoc) {
        // s'assurer que chaque segment a barriques (option B : 0 si inconnu)
        foreach ($hist as $sid => &$periods) {
            if (!is_array($periods)) { $periods = []; continue; }
            foreach ($periods as &$p) {
                if (!is_array($p)) { $p = []; continue; }
                if (!isset($p['barriques'])) $p['barriques'] = 0;
                // harmoniser clés éventuelles
                if (!isset($p['from_ts']) && isset($p['start_ts'])) $p['from_ts'] = (int)$p['start_ts'];
                if (!array_key_exists('to_ts', $p) && array_key_exists('end_ts', $p)) $p['to_ts'] = $p['end_ts'];
            }
            unset($p);

            usort($periods, fn($a,$b) => ((int)($a['from_ts'] ?? 0)) <=> ((int)($b['from_ts'] ?? 0)));
        }
        unset($periods);

        return $hist;
    }

    // Format plat => reconstruire assoc
    $out = [];
    foreach ($hist as $entry) {
        if (!is_array($entry)) continue;

        $id  = trim((string)($entry['id'] ?? ''));
        $lot = trim((string)($entry['lot'] ?? ''));
        if ($id === '' || $lot === '') continue;

        $fromTs = 0;
        if (isset($entry['from_ts'])) $fromTs = (int)$entry['from_ts'];
        elseif (isset($entry['start_ts'])) $fromTs = (int)$entry['start_ts'];

        $toRaw = $entry['to_ts'] ?? ($entry['end_ts'] ?? null);
        $toTs  = ($toRaw === null) ? null : (int)$toRaw;

        if ($fromTs <= 0) continue;

        $bar = isset($entry['barriques']) ? (int)$entry['barriques'] : 0;

        $out[$id] ??= [];
        $out[$id][] = [
            'lot'       => $lot,
            'from_ts'   => $fromTs,
            'to_ts'     => $toTs,
            'barriques' => $bar,
        ];
    }

    foreach ($out as &$periods) {
        usort($periods, fn($a,$b) => ((int)$a['from_ts']) <=> ((int)$b['from_ts']));
        // option B : barriques toujours présent
        foreach ($periods as &$p) {
            if (!isset($p['barriques'])) $p['barriques'] = 0;
        }
        unset($p);
    }
    unset($periods);

    return $out;
}

/**
 * Retourne [id] => periods normalisés.
 */
function loadLotHistoryNormalized(): array {
    $hist = loadLotHistory();
    return normalizeLotHistory(is_array($hist) ? $hist : []);
}

/**
 * Retourne toutes les périodes d'un capteur (id), normalisées.
 */
function getLotPeriodsForSensor(string $sensorId, ?array $histNorm = null): array {
    $sensorId = (string)$sensorId;
    if ($histNorm === null) $histNorm = loadLotHistoryNormalized();
    $periods = $histNorm[$sensorId] ?? [];
    return is_array($periods) ? $periods : [];
}

/**
 * Retourne les segments pour un lot donné (tous capteurs), sous forme de liste :
 *  [ ['id'=>..., 'from_ts'=>..., 'to_ts'=>..., 'barriques'=>...], ... ]
 */
function getSegmentsForLot(string $lot, ?array $histNorm = null): array {
    $lot = trim((string)$lot);
    if ($lot === '') return [];
    if ($histNorm === null) $histNorm = loadLotHistoryNormalized();

    $out = [];
    foreach ($histNorm as $sid => $periods) {
        if (!is_array($periods)) continue;
        foreach ($periods as $p) {
            if (!is_array($p)) continue;
            $pLot = trim((string)($p['lot'] ?? ''));
            if ($pLot !== $lot) continue;

            $fromTs = (int)($p['from_ts'] ?? 0);
            $toTs   = array_key_exists('to_ts', $p) ? $p['to_ts'] : null;
            $toTs   = ($toTs === null) ? null : (int)$toTs;
            if ($fromTs <= 0) continue;

            $out[] = [
                'id'        => (string)$sid,
                'from_ts'   => $fromTs,
                'to_ts'     => $toTs,
                'barriques' => isset($p['barriques']) ? (int)$p['barriques'] : 0,
            ];
        }
    }

    usort($out, fn($a,$b) => ((int)$a['from_ts']) <=> ((int)$b['from_ts']));
    return $out;
}

/**
 * True si un lot a AU MOINS une période ouverte (to_ts=null).
 */
function lotHasOpenSegment(string $lot, ?array $histNorm = null): bool {
    $lot = trim((string)$lot);
    if ($lot === '') return false;
    if ($histNorm === null) $histNorm = loadLotHistoryNormalized();

    foreach ($histNorm as $sid => $periods) {
        if (!is_array($periods)) continue;
        foreach ($periods as $p) {
            if (!is_array($p)) continue;
            if (trim((string)($p['lot'] ?? '')) !== $lot) continue;
            if (!array_key_exists('to_ts', $p) || $p['to_ts'] === null) return true;
        }
    }
    return false;
}

/**
 * Update lot history on lot change
 * + option : figer "barriques" sur le segment fermé et écrire "barriques" sur le segment ouvert
 *
 * Option B : si barriques inconnu => 0.
 */
function updateLotHistoryOnLotChange(
    string $sensorId,
    string $oldLot,
    string $newLot,
    ?int $changeTs = null,
    ?int $oldLotBarriques = null,
    ?int $newLotBarriques = null
): void {
    $changeTs = $changeTs ?? time();
    $sensorId = (string)$sensorId;

    if ($oldLot === $newLot) return;

    $history  = loadLotHistory();              // on garde le fichier tel quel (format A attendu)
    $segments = $history[$sensorId] ?? [];

    // 1) Fermer le segment ouvert si existant
    if (!empty($segments)) {
        $lastIndex = count($segments) - 1;
        if (($segments[$lastIndex]['to_ts'] ?? null) === null) {
            $segLot = (string)($segments[$lastIndex]['lot'] ?? '');
            if ($oldLot === '' || $segLot === $oldLot) {
                $segments[$lastIndex]['to_ts'] = $changeTs - 1;

                // figer barriques sur le lot qu'on ferme (si info fournie)
                if ($oldLotBarriques !== null) {
                    $segments[$lastIndex]['barriques'] = (int)$oldLotBarriques;
                } else {
                    // si déjà présent on garde, sinon 0 (option B)
                    if (!isset($segments[$lastIndex]['barriques'])) {
                        $segments[$lastIndex]['barriques'] = 0;
                    }
                }
            }
        }
    }

    // 2) Ouvrir un segment pour le nouveau lot
    if ($newLot !== '') {
        $segments[] = [
            'lot'       => $newLot,
            'from_ts'   => $changeTs,
            'to_ts'     => null,
            'barriques' => (int)($newLotBarriques ?? 0), // option B : 0 si inconnu
        ];
    }

    $history[$sensorId] = $segments;
    saveLotHistory($history);
}
