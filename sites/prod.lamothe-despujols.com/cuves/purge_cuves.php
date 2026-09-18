<?php
// purge_cuves.php — Supprime tous les capteurs hors ligne
// et ne garde que ceux qui sont encore "récents" dans data_cuves.csv

header('Content-Type: application/json; charset=utf-8');
require __DIR__ . '/lock_lib.php';

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

$csvContent = lockedRead($csvFile);
if ($csvContent !== null && trim($csvContent) !== '') {
    $lines = preg_split('/\r\n|\r|\n/', $csvContent, -1, PREG_SPLIT_NO_EMPTY);

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
    lockedReadModifyWriteJson($configFile, function ($config) use ($onlineIds, $offlineIds, &$removed) {
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

        return $newConfig;
    });
}

// ------------------------------------------------------------------
// 3) On reconstruit data_cuves.csv en ne gardant que les capteurs
//    encore en ligne (les autres perdent leurs mesures)
// ------------------------------------------------------------------
if (file_exists($csvFile)) {
    lockedReadModifyWriteText($csvFile, function ($content) use ($onlineIds) {
        $lines = ($content === '') ? [] : preg_split('/\r\n|\r|\n/', $content, -1, PREG_SPLIT_NO_EMPTY);
        $newLines = [];

        // En-tête attendu
        $header = "datetime;id;cuve;distance_cm;volume_hl;capacite_hl;pourcentage;correction;hauteur_plein_cm;hauteur_cuve_cm;rssi;fw";

        $newLines[] = $header;
        $start = (isset($lines[0]) && str_starts_with($lines[0], 'datetime')) ? 1 : 0;

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

        return implode("\n", $newLines) . "\n";
    });
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