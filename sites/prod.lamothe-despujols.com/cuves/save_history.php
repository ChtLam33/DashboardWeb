<?php
// save_history.php — Enregistre un "instantané" des volumes par lot (SQLite)
require __DIR__ . '/cuves_lib.php';
header("Content-Type: application/json; charset=utf-8");

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["status" => "ERROR", "error" => "Méthode non autorisée"]);
    exit;
}

$raw  = file_get_contents("php://input");
$data = json_decode($raw, true);
$comment = '';
if (is_array($data) && isset($data['comment'])) {
    $comment = trim($data['comment']);
}

try {
    $latest = getLatestCuveMeasurements();
    $config = getCuvesConfig();

    $lotById = [];
    foreach ($config as $cfg) {
        $lotById[$cfg['sensor_id']] = $cfg['lot'];
    }

    $lotsTotals = [];
    foreach ($config as $cfg) {
        $sensorId = $cfg['sensor_id'];
        if (!isset($latest[$sensorId])) continue;

        $interp = interpretCuve((int)$latest[$sensorId]['distance_cm'], $cfg);
        $vol = $interp['volume_hl'];

        $lotName = trim((string)($lotById[$sensorId] ?? '')) !== ''
            ? trim((string)$lotById[$sensorId])
            : 'Sans lot';

        if (!isset($lotsTotals[$lotName])) {
            $lotsTotals[$lotName] = 0.0;
        }
        $lotsTotals[$lotName] += (float)$vol;
    }

    $entry = addCuveSnapshot($comment, $lotsTotals);

    echo json_encode([
        "status" => "OK",
        "saved"  => true,
        "entry"  => $entry
    ]);
} catch (\Throwable $e) {
    error_log('[cuves] save_history.php failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(["status" => "ERROR", "error" => "Impossible d'enregistrer l'instantané"]);
}
