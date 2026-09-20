<?php
// purge_cuves.php — Réinitialise la config des capteurs hors ligne (SQLite)
//
// Comportement (validé avec l'utilisateur lors de la migration SQLite) :
// on conserve désormais l'historique de mesures du capteur (utile
// maintenant qu'on a un vrai historique en base) - seule sa ligne
// "config" (nom, lot, hauteurs) est réinitialisée, comme s'il s'agissait
// d'un nouveau capteur au prochain contact.
require __DIR__ . '/cuves_lib.php';
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["error" => "Méthode non autorisée."]);
    exit;
}

// Seuil : au-delà de X secondes sans mesure, on considère le capteur hors ligne
$offlineThreshold = 60;
$now = time();

try {
    $latest = getLatestCuveMeasurements();
    $config = getCuvesConfig();

    $onlineIds  = [];
    $offlineIds = [];

    foreach ($config as $cfg) {
        $sensorId = $cfg['sensor_id'];
        $ts = isset($latest[$sensorId]) ? (int)$latest[$sensorId]['ts'] : 0;
        $age = $now - $ts;
        if ($ts > 0 && $age <= $offlineThreshold) {
            $onlineIds[$sensorId] = true;
        } else {
            $offlineIds[$sensorId] = true;
        }
    }

    $removed = 0;
    foreach (array_keys($offlineIds) as $sensorId) {
        resetCuveConfig($sensorId);
        $removed++;
    }

    echo json_encode([
        "status"            => "OK",
        "removed"           => $removed,
        "online"            => array_keys($onlineIds),
        "offline"           => array_keys($offlineIds),
        "threshold_seconds" => $offlineThreshold
    ]);
} catch (\Throwable $e) {
    error_log('[cuves] purge_cuves.php failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(["error" => "Erreur lors de la purge."]);
}
