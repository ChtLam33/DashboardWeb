<?php
// save_history.php — Enregistre un "instantané" des volumes par lot
header("Content-Type: application/json; charset=utf-8");
require __DIR__ . '/lock_lib.php';

// On n'accepte que POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["status" => "ERROR", "error" => "Méthode non autorisée"]);
    exit;
}

// Récupération du commentaire éventuel
$raw = file_get_contents("php://input");
$data = json_decode($raw, true);
$comment = '';
if (is_array($data) && isset($data['comment'])) {
    $comment = trim($data['comment']);
}

// Fichiers existants
$cacheFile   = __DIR__ . "/cache_dashboard.json";
$configFile  = __DIR__ . "/config_cuves.json";
$historyFile = __DIR__ . "/history_lots.json";

// Helpers locaux
function valf_local($arr, $key, $default = null) {
    return isset($arr[$key]) && $arr[$key] !== '' ? $arr[$key] : $default;
}

// Charger le cache cuves (lecture protegee : evite de lire pendant
// qu'update_cache.php est en train de le reecrire)
$cuvesJson = lockedRead($cacheFile);
if ($cuvesJson === null) {
    http_response_code(500);
    echo json_encode(["status" => "ERROR", "error" => "cache_dashboard.json introuvable"]);
    exit;
}
$cuves = json_decode($cuvesJson, true);
if (!is_array($cuves)) {
    http_response_code(500);
    echo json_encode(["status" => "ERROR", "error" => "cache_dashboard.json invalide"]);
    exit;
}

// Charger la config pour récupérer les lots
$lotById = [];
$configJson = lockedRead($configFile);
if ($configJson !== null) {
    $config = json_decode($configJson, true);
    if (is_array($config)) {
        foreach ($config as $cfg) {
            if (!empty($cfg['id'])) {
                $lotById[$cfg['id']] = isset($cfg['lot']) ? $cfg['lot'] : '';
            }
        }
    }
}

// Calcul des totaux par lot à cet instant
$lotsTotals  = [];
$totalGlobal = 0.0;

foreach ($cuves as $c) {
    $id  = valf_local($c, 'id', '');
    $vol = valf_local($c, 'volume_hl', null);

    if ($id === '' || !is_numeric($vol)) continue;

    $lotName = isset($lotById[$id]) && trim($lotById[$id]) !== ''
        ? trim($lotById[$id])
        : 'Sans lot';

    if (!isset($lotsTotals[$lotName])) {
        $lotsTotals[$lotName] = 0.0;
    }
    $lotsTotals[$lotName] += (float)$vol;
    $totalGlobal          += (float)$vol;
}

// Construire la structure des lots pour l'historique
$lotsArray = [];
foreach ($lotsTotals as $ln => $vhl) {
    $lotsArray[] = [
        "lot"        => $ln,
        "volume_hl"  => (float)$vhl
    ];
}

// Nouvelle entrée
$newEntry = [
    "datetime"  => date("Y-m-d H:i:s"),
    "comment"   => $comment,
    "total_hl"  => (float)$totalGlobal,
    "lots"      => $lotsArray
];

// Lecture + ajout + ecriture de l'historique sous un seul verrou continu
// (deux "Enregistrer un instantané" presque simultanés ne doivent pas
// s'écraser l'un l'autre).
try {
    $history = lockedReadModifyWriteJson($historyFile, function ($history) use ($newEntry) {
        if (!is_array($history)) {
            $history = [];
        }

        // Ajouter à la fin
        $history[] = $newEntry;

        // Limiter à 300 entrées max (on garde les plus récentes)
        $maxEntries = 300;
        if (count($history) > $maxEntries) {
            // On coupe par le bas (anciennes en premier)
            $history = array_slice($history, -$maxEntries);
        }

        return $history;
    });
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(["status" => "ERROR", "error" => "Impossible d'écrire dans history_lots.json"]);
    exit;
}

echo json_encode([
    "status" => "OK",
    "saved"  => true,
    "entry"  => $newEntry
]);