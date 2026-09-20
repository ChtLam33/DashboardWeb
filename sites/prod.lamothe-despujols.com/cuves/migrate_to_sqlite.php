<?php
// migrate_to_sqlite.php
// Script UNIQUE (a supprimer apres usage) : copie config_cuves.json,
// data_cuves.csv et history_lots.json vers les tables SQLite
// correspondantes (voir cuves_lib.php). "config" est toujours reimporte
// (remplace entierement, sans risque de doublon) ; "mesures" et
// "history_snapshots" ne sont importes que s'ils sont encore vides
// (evite les doublons si le script est lance plusieurs fois).

require __DIR__ . '/cuves_lib.php';

header('Content-Type: text/plain; charset=utf-8');

if (($_GET['run'] ?? '') !== '1') {
    echo "Ajoutez ?run=1 a l'URL pour lancer la migration.\n";
    exit;
}

$pdo = dbConnectCuves(); // cree les tables si besoin

// NOTE IMPORTANT : contrairement aux scripts de migration barriques, la
// table "config" peut deja contenir des lignes AVANT meme que ce script
// tourne : des capteurs reels postent en continu vers api_cuve.php, qui
// auto-enregistre un capteur inconnu avec des valeurs par defaut
// (ensureCuveConfigExists()). Si on bloquait l'import de config des que
// la table n'est pas vide, on risquerait de ne JAMAIS importer les
// vraies valeurs de calibration (lots, hauteurs, couleurs) et de garder
// les capteurs sur leurs valeurs par defaut. saveCuvesConfig() remplace
// TOUJOURS entierement la table (DELETE + INSERT), donc reimporter
// config_cuves.json est sans risque de doublon - on ne protege donc que
// mesures/history_snapshots contre un double-import (eux s'accumulent).
$mesuresExisting   = (int)$pdo->query('SELECT COUNT(*) FROM mesures')->fetchColumn();
$historyExisting   = (int)$pdo->query('SELECT COUNT(*) FROM history_snapshots')->fetchColumn();

$report = [];

// 1) config <- config_cuves.json (position = index dans le tableau)
//    Toujours reimporte (remplace entierement), pour ecraser d'eventuelles
//    lignes auto-enregistrees par defaut depuis le dernier deploiement.
$configFile = __DIR__ . '/config_cuves.json';
$configCount = 0;
if (file_exists($configFile)) {
    $data = json_decode(file_get_contents($configFile), true);
    if (is_array($data)) {
        saveCuvesConfig($data);
        $configCount = count($data);
    }
}
$report[] = "config <- config_cuves.json : $configCount ligne(s) (remplace entierement)";

// 2) mesures <- data_cuves.csv (une seule ligne par capteur dans l'ancien
//    format : devient la toute premiere mesure de son historique)
$csvFile = __DIR__ . '/data_cuves.csv';
$mesuresCount = 0;
if ($mesuresExisting > 0) {
    $report[] = "mesures <- data_cuves.csv : deja $mesuresExisting mesure(s) en base, rien reimporte (evite les doublons)";
} elseif (file_exists($csvFile)) {
    $lines = file($csvFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines && count($lines) > 1) {
        $header = str_getcsv(array_shift($lines), ';');
        foreach ($lines as $line) {
            $cols = str_getcsv($line, ';');
            if (count($cols) < count($header)) continue;
            $row = array_combine($header, $cols);
            if (!$row || empty($row['id'])) continue;

            $dateIso = $row['datetime'] ?? date('Y-m-d H:i:s');
            $ts = strtotime($dateIso) ?: time();
            $distance = isset($row['distance_cm']) ? (int)round((float)$row['distance_cm']) : 0;
            $rssi = isset($row['rssi']) && $row['rssi'] !== '' ? (int)$row['rssi'] : null;
            $fw = isset($row['fw']) && $row['fw'] !== '' ? (string)$row['fw'] : null;

            insertCuveMeasurement((string)$row['id'], $distance, $rssi, $fw, $ts, $dateIso);
            $mesuresCount++;
        }
    }
    $report[] = "mesures <- data_cuves.csv : $mesuresCount ligne(s)";
} else {
    $report[] = "mesures <- data_cuves.csv : fichier absent, ignore";
}

// 3) history_snapshots/history_snapshot_lots <- history_lots.json
$historyFile = __DIR__ . '/history_lots.json';
$snapshotsCount = 0;
if ($historyExisting > 0) {
    $report[] = "history_snapshots <- history_lots.json : deja $historyExisting snapshot(s) en base, rien reimporte (evite les doublons)";
} elseif (file_exists($historyFile)) {
    $data = json_decode(file_get_contents($historyFile), true);
    if (is_array($data)) {
        foreach ($data as $snap) {
            if (!is_array($snap)) continue;
            $ts = isset($snap['datetime']) ? (strtotime((string)$snap['datetime']) ?: time()) : time();
            $comment = (string)($snap['comment'] ?? '');
            $lots = [];
            foreach (($snap['lots'] ?? []) as $l) {
                if (!is_array($l) || !isset($l['lot'])) continue;
                $lots[(string)$l['lot']] = (float)($l['volume_hl'] ?? 0);
            }

            $stmt = $pdo->prepare('INSERT INTO history_snapshots (ts, comment) VALUES (?, ?)');
            $stmt->execute([$ts, $comment]);
            $snapshotId = (int)$pdo->lastInsertId();
            $stmtLot = $pdo->prepare('INSERT INTO history_snapshot_lots (snapshot_id, lot, volume_hl) VALUES (?, ?, ?)');
            foreach ($lots as $lotName => $vol) {
                $stmtLot->execute([$snapshotId, $lotName, $vol]);
            }
            $snapshotsCount++;
        }
    }
    $report[] = "history_snapshots <- history_lots.json : $snapshotsCount snapshot(s)";
} else {
    $report[] = "history_snapshots <- history_lots.json : fichier absent, ignore";
}

echo implode("\n", $report) . "\n";

echo "\nVerification finale (nombre de lignes par table) :\n";
echo "config: "             . (int)$pdo->query('SELECT COUNT(*) FROM config')->fetchColumn()             . "\n";
echo "mesures: "            . (int)$pdo->query('SELECT COUNT(*) FROM mesures')->fetchColumn()            . "\n";
echo "history_snapshots: "  . (int)$pdo->query('SELECT COUNT(*) FROM history_snapshots')->fetchColumn()  . "\n";
echo "history_snapshot_lots: " . (int)$pdo->query('SELECT COUNT(*) FROM history_snapshot_lots')->fetchColumn() . "\n";
