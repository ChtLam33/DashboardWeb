<?php
// cuves_lib.php — acces SQLite pour le module cuves (migration depuis
// data_cuves.csv / config_cuves.json / cache_dashboard.json / history_lots.json).
// Memes conventions que barriques/barriques_lib.php.

require_once __DIR__ . '/secrets.local.php'; // CUVE_PROVISIONING_SECRET

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
        // Sans ca, une ecriture qui tombe pile en meme temps qu'une autre
        // (plusieurs capteurs postent a quelques secondes d'intervalle)
        // echoue immediatement (SQLITE_BUSY) au lieu d'attendre son tour -
        // le capteur verrait sa requete rejetee sans raison apparente.
        $pdo->exec('PRAGMA busy_timeout = 5000;');

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

        // "last_*" : dernier etat connu du capteur, mis a jour a CHAQUE
        // reception (toutes les ~8s), independamment de "mesures" ci-dessous
        // qui elle est volontairement throttlee. Separe "le capteur est-il
        // en ligne / que vaut-il maintenant" (toujours frais) de "faut-il
        // garder une trace historique de cette valeur" (rarement vrai) -
        // voir insertCuveMeasurementIfNeeded().
        $configCols = $pdo->query('PRAGMA table_info(config)')->fetchAll(PDO::FETCH_COLUMN, 1);
        foreach ([
            'last_distance_cm' => 'INTEGER',
            'last_rssi'        => 'INTEGER',
            'last_fw'          => 'TEXT',
            'last_seen_ts'     => 'INTEGER',
            'last_date_iso'    => 'TEXT',
            'api_key'          => 'TEXT', // cle propre au capteur, voir registerCuveSensor()
        ] as $col => $type) {
            if (!in_array($col, $configCols, true)) {
                $pdo->exec("ALTER TABLE config ADD COLUMN $col $type");
            }
        }

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

        // Detail par cuve d'un instantane (pour pouvoir "developper" un lot
        // et voir quelles cuves le composaient) - absent des instantanes
        // importes depuis l'ancien history_lots.json (qui ne gardait que
        // les totaux par lot), donc peut etre vide pour les anciens snapshots.
        $pdo->exec('CREATE TABLE IF NOT EXISTS history_snapshot_cuves (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            snapshot_id INTEGER NOT NULL,
            sensor_id TEXT NOT NULL,
            nom_cuve TEXT NOT NULL DEFAULT "",
            lot TEXT NOT NULL DEFAULT "",
            volume_hl REAL NOT NULL DEFAULT 0,
            status TEXT NOT NULL DEFAULT ""
        )');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_cuves_snapshot_cuves_snapshot ON history_snapshot_cuves(snapshot_id)');
        $snapCuvesCols = $pdo->query('PRAGMA table_info(history_snapshot_cuves)')->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!in_array('status', $snapCuvesCols, true)) {
            $pdo->exec('ALTER TABLE history_snapshot_cuves ADD COLUMN status TEXT NOT NULL DEFAULT ""');
        }

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

/**
 * Mesure incoherente (couvercle/obstacle probable) : la distance brute
 * est plus petite que la hauteur max liquide configuree, donc le capteur
 * "voit" quelque chose au-dessus du niveau plein attendu. Meme regle que
 * l'avertissement affiche sur le dashboard (index.php).
 */
function isCuveMeasurementIncoherent(int $distanceRaw, array $config): bool {
    $hauteurMaxLiquide = (float)($config['hauteur_max_liquide'] ?? 50);
    return $distanceRaw < $hauteurMaxLiquide;
}

// Meme seuil que l'indicateur "hors ligne" affiche sur chaque carte cuve
// (index.php) - reutilise ici pour que le statut soit identique partout.
const CUVE_OFFLINE_DISPLAY_SECONDS = 300;

/**
 * Le capteur est considere hors ligne s'il n'a plus donne signe de vie
 * (config.last_seen_ts, mis a jour a CHAQUE reception) depuis plus de
 * CUVE_OFFLINE_DISPLAY_SECONDS.
 */
function isCuveOffline(array $config, int $now): bool {
    $lastSeen = (int)($config['last_seen_ts'] ?? 0);
    return $lastSeen <= 0 || ($now - $lastSeen) > CUVE_OFFLINE_DISPLAY_SECONDS;
}

// Seuil (bien plus large que CUVE_OFFLINE_DISPLAY_SECONDS ci-dessus) au-dela
// duquel un capteur silencieux est propose a la purge (reinitialisation de
// sa config) - action destructrice, donc volontairement tres tolerante :
// une coupure Wi-Fi de quelques heures ne doit jamais y mener.
const CUVE_PURGE_THRESHOLD_SECONDS = 172800; // 2 jours

/* =========================================================
   MESURES
   - "config.last_*" (mis a jour a CHAQUE reception, ~toutes les 8s) sert
     de source pour "le capteur est en ligne / que vaut-il maintenant".
   - "mesures" (throttlee) ne sert qu'a garder un historique exploitable
     plus tard (pas encore de graphique dans le dashboard - reserve pour
     une future fonctionnalite), sans exploser en dizaines de milliers de
     lignes quasi identiques pour une cuve dont le niveau ne bouge pas.
     Une nouvelle ligne n'est ecrite QUE si la distance a reellement
     change (pas de "point de repere" periodique meme si rien ne bouge -
     inutile, "config.last_*" ci-dessus donne deja l'etat en direct).
   ========================================================= */
const CUVE_CHANGE_THRESHOLD_CM = 1; // en dessous, on considere que c'est du bruit capteur

/**
 * Met a jour le "dernier etat connu" du capteur (config.last_*) - appele
 * a CHAQUE reception, meme quand aucune ligne "mesures" n'est ecrite.
 */
function updateCuveLastSeen(string $sensorId, int $distanceCm, ?int $rssi, ?string $fw, int $ts, string $dateIso): void {
    $pdo = dbConnectCuves();
    $stmt = $pdo->prepare(
        'UPDATE config SET last_distance_cm = ?, last_rssi = ?, last_fw = ?, last_seen_ts = ?, last_date_iso = ? WHERE sensor_id = ?'
    );
    $stmt->execute([$distanceCm, $rssi, $fw, $ts, $dateIso, $sensorId]);
}

/**
 * Ecrit une ligne "mesures" seulement si necessaire (voir constantes
 * ci-dessus). Retourne true si une ligne a effectivement ete ecrite.
 */
function insertCuveMeasurementIfNeeded(string $sensorId, int $distanceCm, ?int $rssi, ?string $fw, int $ts, string $dateIso): bool {
    $pdo = dbConnectCuves();
    $stmt = $pdo->prepare('SELECT distance_cm, ts FROM mesures WHERE sensor_id = ? ORDER BY ts DESC LIMIT 1');
    $stmt->execute([$sensorId]);
    $last = $stmt->fetch(PDO::FETCH_ASSOC);

    $shouldRecord = true;
    if ($last !== false) {
        $delta = abs($distanceCm - (int)$last['distance_cm']);
        $shouldRecord = ($delta >= CUVE_CHANGE_THRESHOLD_CM);
    }

    if (!$shouldRecord) {
        return false;
    }

    $insert = $pdo->prepare(
        'INSERT INTO mesures (sensor_id, distance_cm, rssi, fw, ts, date_iso) VALUES (?, ?, ?, ?, ?, ?)'
    );
    $insert->execute([$sensorId, $distanceCm, $rssi, $fw, $ts, $dateIso]);
    return true;
}

/**
 * Insertion inconditionnelle (utilisee uniquement par le script de
 * migration, pour importer les mesures historiques sans passer par le
 * throttling ci-dessus).
 */
function insertCuveMeasurement(string $sensorId, int $distanceCm, ?int $rssi, ?string $fw, int $ts, string $dateIso): void {
    $pdo = dbConnectCuves();
    $stmt = $pdo->prepare(
        'INSERT INTO mesures (sensor_id, distance_cm, rssi, fw, ts, date_iso) VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$sensorId, $distanceCm, $rssi, $fw, $ts, $dateIso]);
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
 * Enregistrement d'un capteur avec cle API (voir register.php). Le secret
 * fourni doit correspondre a CUVE_PROVISIONING_SECRET (partage par toute
 * l'installation, grave dans le firmware) - sinon retourne null (rejet).
 * Si valide : cree la config si besoin (comme ensureCuveConfigExists),
 * genere une NOUVELLE cle propre a ce capteur, l'enregistre, la retourne.
 * Peut etre rappelee pour un capteur deja connu (ex: apres perte de la
 * memoire NVS du capteur) : le secret partage fait toujours foi, une
 * nouvelle cle est alors emise et remplace l'ancienne.
 */
function registerCuveSensor(string $sensorId, string $nomCuve, string $providedSecret): ?string {
    if (!hash_equals(CUVE_PROVISIONING_SECRET, $providedSecret)) {
        return null;
    }

    ensureCuveConfigExists($sensorId, $nomCuve);

    $apiKey = bin2hex(random_bytes(16));
    $pdo = dbConnectCuves();
    $stmt = $pdo->prepare('UPDATE config SET api_key = ? WHERE sensor_id = ?');
    $stmt->execute([$apiKey, $sensorId]);

    return $apiKey;
}

/**
 * Verifie la cle API d'un capteur avant d'accepter une requete (voir
 * api_cuve.php). TOLERANT pendant la transition : si ce capteur n'a
 * encore aucune cle enregistree (ancien firmware jamais mis a jour, ou
 * capteur qui n'a pas encore appele register.php), la requete est
 * acceptee sans cle - comportement actuel, ouvert. Des qu'une cle existe
 * pour ce sensor_id, elle devient obligatoire et doit correspondre.
 */
function isValidCuveApiKey(string $sensorId, ?string $providedKey): bool {
    $cfg = getCuveConfigById($sensorId);
    $storedKey = $cfg['api_key'] ?? null;

    if ($storedKey === null || $storedKey === '') {
        return true; // pas encore de cle pour ce capteur : ouvert (transition)
    }

    return $providedKey !== null && hash_equals($storedKey, $providedKey);
}

/**
 * Remplace entierement la config (meme comportement que l'ancien
 * save_config.php : le client envoie le tableau complet). "position"
 * = index dans le tableau recu.
 *
 * IMPORTANT : passe par un UPSERT (pas DELETE+INSERT) pour ne jamais
 * toucher aux colonnes "last_*" (dernier etat connu du capteur) - un
 * DELETE+INSERT les aurait remises a NULL a chaque sauvegarde de
 * parametres, faisant croire que tous les capteurs viennent de
 * disparaitre jusqu'a leur prochaine mesure.
 *
 * Declenche aussi une sauvegarde automatique si le lot d'au moins un
 * capteur a change (voir createCuvesBackupNow()) - un changement de lot
 * marque un evenement metier important (soutirage, assemblage...).
 */
function saveCuvesConfig(array $entries): array {
    $pdo = dbConnectCuves();

    $oldLotById = [];
    foreach ($pdo->query('SELECT sensor_id, lot FROM config') as $r) {
        $oldLotById[$r['sensor_id']] = $r['lot'];
    }

    $pdo->beginTransaction();
    $stmt = $pdo->prepare(
        'INSERT INTO config (sensor_id, nom_cuve, lot, hauteur_capteur_fond, hauteur_max_liquide, diametre_cuve, ajustement_hl, couleur, position)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON CONFLICT(sensor_id) DO UPDATE SET
            nom_cuve = excluded.nom_cuve,
            lot = excluded.lot,
            hauteur_capteur_fond = excluded.hauteur_capteur_fond,
            hauteur_max_liquide = excluded.hauteur_max_liquide,
            diametre_cuve = excluded.diametre_cuve,
            ajustement_hl = excluded.ajustement_hl,
            couleur = excluded.couleur,
            position = excluded.position'
    );
    $saved = [];
    $submittedIds = [];
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
        $submittedIds[] = $sensorId;
    }

    // Retire les capteurs qui ne sont plus dans le tableau soumis (le
    // client envoie toujours l'etat complet voulu)
    if (!empty($submittedIds)) {
        $placeholders = implode(',', array_fill(0, count($submittedIds), '?'));
        $pdo->prepare("DELETE FROM config WHERE sensor_id NOT IN ($placeholders)")->execute($submittedIds);
    } else {
        $pdo->exec('DELETE FROM config');
    }

    $pdo->commit();

    $lotChanged = false;
    foreach ($saved as $row) {
        $old = $oldLotById[$row['sensor_id']] ?? null;
        if ($old !== null && $old !== $row['lot']) {
            $lotChanged = true;
            break;
        }
    }
    if ($lotChanged) {
        try {
            createCuvesBackupNow();
        } catch (\Throwable $e) {
            error_log('[cuves] backup sur changement de lot echoue : ' . $e->getMessage());
        }
    }

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
// Libelles courts pour les statuts anormaux d'une cuve au moment d'un
// instantane - utilises a la fois pour la colonne "Commentaire" de sa
// ligne de detail et pour le resume ajoute automatiquement au commentaire
// global de l'instantane.
const CUVE_SNAPSHOT_STATUS_LABELS = [
    'offline'     => 'hors ligne',
    'incoherent'  => 'mesure incohérente',
];

/**
 * $cuveEntries = [ ['sensor_id'=>..., 'nom_cuve'=>..., 'lot'=>..., 'volume_hl'=>..., 'status'=>''|'offline'|'incoherent'], ... ]
 * (une ligne par cuve ayant une mesure au moment de l'instantane). Les
 * totaux par lot (history_snapshot_lots, pour l'affichage rapide) sont
 * calcules a partir de ce detail, qui est lui-meme conserve
 * (history_snapshot_cuves) pour pouvoir "developper" un lot et voir
 * quelles cuves le composaient.
 *
 * Si une ou plusieurs cuves sont hors ligne / en mesure incoherente au
 * moment de l'instantane, un resume est automatiquement ajoute au
 * commentaire (demande utilisateur : le volume enregistre pour ces
 * cuves peut etre perime/faux, autant le savoir sans avoir a deplier
 * chaque cuve).
 */
function addCuveSnapshot(string $comment, array $cuveEntries): array {
    $pdo = dbConnectCuves();
    $ts = time();

    $stmtCuve = $pdo->prepare(
        'INSERT INTO history_snapshot_cuves (snapshot_id, sensor_id, nom_cuve, lot, volume_hl, status) VALUES (?, ?, ?, ?, ?, ?)'
    );
    $lotsTotals = [];
    $cuvesArray = [];
    $anomalies  = [];
    foreach ($cuveEntries as $e) {
        $lotName = trim((string)($e['lot'] ?? '')) !== '' ? trim((string)$e['lot']) : 'Sans lot';
        $vol     = (float)($e['volume_hl'] ?? 0);
        $status  = (string)($e['status'] ?? '');
        $lotsTotals[$lotName] = ($lotsTotals[$lotName] ?? 0.0) + $vol;
        $cuvesArray[] = ['sensor_id' => $e['sensor_id'], 'nom_cuve' => $e['nom_cuve'] ?? '', 'lot' => $lotName, 'volume_hl' => $vol, 'status' => $status];
        if (isset(CUVE_SNAPSHOT_STATUS_LABELS[$status])) {
            $anomalies[] = ($e['nom_cuve'] ?: $e['sensor_id']) . ' (' . CUVE_SNAPSHOT_STATUS_LABELS[$status] . ')';
        }
    }

    $fullComment = $comment;
    if (!empty($anomalies)) {
        $suffix = '⚠ ' . implode(', ', $anomalies);
        $fullComment = ($comment !== '') ? ($comment . ' — ' . $suffix) : $suffix;
    }

    $pdo->beginTransaction();
    $stmt = $pdo->prepare('INSERT INTO history_snapshots (ts, comment) VALUES (?, ?)');
    $stmt->execute([$ts, $fullComment]);
    $snapshotId = (int)$pdo->lastInsertId();

    foreach ($cuvesArray as $c) {
        $stmtCuve->execute([$snapshotId, (string)$c['sensor_id'], (string)$c['nom_cuve'], (string)$c['lot'], (float)$c['volume_hl'], (string)$c['status']]);
    }

    $stmtLot = $pdo->prepare('INSERT INTO history_snapshot_lots (snapshot_id, lot, volume_hl) VALUES (?, ?, ?)');
    $lotsArray = [];
    foreach ($lotsTotals as $lotName => $vhl) {
        $stmtLot->execute([$snapshotId, $lotName, $vhl]);
        $lotsArray[] = ['lot' => $lotName, 'volume_hl' => $vhl];
    }

    // Retention : garde les 300 plus recents snapshots (comme l'ancienne limite JSON)
    $ids = $pdo->query('SELECT id FROM history_snapshots ORDER BY ts DESC')->fetchAll(PDO::FETCH_COLUMN);
    if (count($ids) > 300) {
        $toDelete = array_slice($ids, 300);
        $placeholders = implode(',', array_fill(0, count($toDelete), '?'));
        $pdo->prepare("DELETE FROM history_snapshot_lots WHERE snapshot_id IN ($placeholders)")->execute($toDelete);
        $pdo->prepare("DELETE FROM history_snapshot_cuves WHERE snapshot_id IN ($placeholders)")->execute($toDelete);
        $pdo->prepare("DELETE FROM history_snapshots WHERE id IN ($placeholders)")->execute($toDelete);
    }

    $pdo->commit();

    return [
        'datetime' => date('Y-m-d H:i:s', $ts),
        'comment'  => $fullComment,
        'total_hl' => array_sum($lotsTotals),
        'lots'     => $lotsArray,
        'cuves'    => $cuvesArray,
    ];
}

/**
 * Renvoie les snapshots les plus recents d'abord, limite a $limit.
 * Chaque lot de chaque snapshot porte un sous-tableau "cuves" (vide pour
 * les anciens instantanes importes de history_lots.json, qui ne
 * gardaient que les totaux par lot).
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
        $lotsBySnapshot[$l['snapshot_id']][$l['lot']] = ['lot' => $l['lot'], 'volume_hl' => (float)$l['volume_hl'], 'cuves' => []];
    }

    $cuvesStmt = $pdo->prepare("SELECT snapshot_id, sensor_id, nom_cuve, lot, volume_hl, status FROM history_snapshot_cuves WHERE snapshot_id IN ($placeholders)");
    $cuvesStmt->execute($ids);
    foreach ($cuvesStmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
        if (!isset($lotsBySnapshot[$c['snapshot_id']][$c['lot']])) continue;
        $status = (string)($c['status'] ?? '');
        $lotsBySnapshot[$c['snapshot_id']][$c['lot']]['cuves'][] = [
            'sensor_id' => $c['sensor_id'], 'nom_cuve' => $c['nom_cuve'], 'volume_hl' => (float)$c['volume_hl'],
            'status' => $status,
            'status_label' => CUVE_SNAPSHOT_STATUS_LABELS[$status] ?? '',
        ];
    }

    $out = [];
    foreach ($snapshots as $s) {
        $lots = array_values($lotsBySnapshot[$s['id']] ?? []);
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
