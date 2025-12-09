<?php
// purge_cuves.php — Supprime tous les capteurs hors ligne
// et ne garde que ceux qui sont encore "récents" dans data_cuves.csv

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["error" => "Méthode non autorisée"]);
    exit;
}

$csvFile    = __DIR__ . '/data_cuves.csv';
$configFile = __DIR__ . '/config_cuves.json';

// Seuil : au-delà de X secondes sans mesure, on considère le capteur hors ligne
$offlineThreshold = 60; // 60 s, à ajuster si tu veux
$now = time();

// ------------------------------------------------------------------
// 1) On lit data_cuves.csv pour connaître la dernière mesure de
//    chaque capteur (id -> timestamp le plus récent)
// ------------------------------------------------------------------
$lastById = [];

if (file_exists($csvFile)) {
    $lines = file($csvFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    if ($lines) {
        // On suppose que la première ligne est l'en-tête "datetime;id;..."
        $startIndex = 0;
        if (isset($lines[0]) && str_starts_with($lines[0], 'datetime')) {
            $startIndex = 1;
        }

        for ($i = $startIndex; $i < count($lines); $i++) {
            $line = trim($lines[$i]);
            if ($line === '') continue;

            $cols = str_getcsv($line, ';');
            if (count($cols) < 2) continue;

            $dtStr = $cols[0];
            $id    = $cols[1];

            $ts = strtotime($dtStr);
            if ($ts === false) continue;

            if (!isset($lastById[$id]) || $ts > $lastById[$id]) {
                $lastById[$id] = $ts;
            }
        }
    }
}

// On sépare les IDs en "en ligne" vs "hors ligne"
$onlineIds  = [];
$offlineIds = [];

foreach ($lastById as $id => $ts) {
    $age = $now - $ts;
    if ($age <= $offlineThreshold) {
        $onlineIds[$id] = true;
    } else {
        $offlineIds[$id] = true;
    }
}

// ------------------------------------------------------------------
// 2) On filtre config_cuves.json :
//    - on GARDE uniquement les capteurs encore en ligne
//    - on supprime les autres (ils perdront leurs paramètres)
// ------------------------------------------------------------------
$removed = 0;

if (file_exists($configFile)) {
    $config = json_decode(file_get_contents($configFile), true);
    if (!is_array($config)) {
        $config = [];
    }

    $newConfig = [];
    foreach ($config as $entry) {
        $id = $entry['id'] ?? '';

        if ($id && isset($onlineIds[$id])) {
            // Capteur encore en ligne : on garde la config telle quelle
            $newConfig[] = $entry;
        } else {
            // Hors ligne : on le considère comme supprimé
            if ($id && isset($offlineIds[$id])) {
                $removed++;
            }
        }
    }

    file_put_contents(
        $configFile,
        json_encode($newConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
    );
}

// ------------------------------------------------------------------
// 3) On reconstruit data_cuves.csv en ne gardant que les capteurs
//    encore en ligne (les autres perdent leurs mesures)
// ------------------------------------------------------------------
if (file_exists($csvFile)) {
    $lines = file($csvFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $newLines = [];

    // En-tête attendu
    $header = "datetime;id;cuve;distance_cm;volume_hl;capacite_hl;pourcentage;correction;hauteur_plein_cm;hauteur_cuve_cm;rssi";

    if ($lines) {
        // Première ligne : si elle commence par datetime, on remet un header propre
        if (isset($lines[0]) && str_starts_with($lines[0], 'datetime')) {
            $newLines[] = $header;
            $start = 1;
        } else {
            // Sinon, on force un header propre
            $newLines[] = $header;
            $start = 0;
        }

        for ($i = $start; $i < count($lines); $i++) {
            $line = trim($lines[$i]);
            if ($line === '') continue;

            $cols = str_getcsv($line, ';');
            if (count($cols) < 2) continue;

            $id = $cols[1];

            // On ne garde que les lignes des capteurs encore en ligne
            if (isset($onlineIds[$id])) {
                $newLines[] = $line;
            }
        }
    }

    file_put_contents($csvFile, implode("\n", $newLines) . "\n", LOCK_EX);
}

// ------------------------------------------------------------------
// 4) Réponse JSON
// ------------------------------------------------------------------
echo json_encode([
    "status"   => "OK",
    "removed"  => $removed,
    "online"   => array_keys($onlineIds),
    "offline"  => array_keys($offlineIds),
    "threshold_seconds" => $offlineThreshold
]);