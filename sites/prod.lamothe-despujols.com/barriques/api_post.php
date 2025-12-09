<?php
// barriques/api_post.php

// Réponse JSON par défaut
header('Content-Type: application/json; charset=utf-8');

// Autoriser uniquement POST pour les mesures (GET = petit ping de test possible si tu veux)
$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'POST') {
    echo json_encode([
        'status'  => 'alive',
        'message' => 'Use POST with JSON payload from sensor.',
    ]);
    exit;
}

// 1) Récupérer le JSON brut
$raw = file_get_contents('php://input');

if ($raw === false || $raw === '') {
    http_response_code(400);
    echo json_encode([
        'status'  => 'error',
        'message' => 'Empty body',
    ]);
    exit;
}

// 2) Décoder le JSON
$data = json_decode($raw, true);

if ($data === null) {
    http_response_code(400);
    echo json_encode([
        'status'  => 'error',
        'message' => 'Invalid JSON',
    ]);
    exit;
}

// 3) Vérifications minimales des champs attendus
$id         = $data['id']         ?? null;
$fw         = $data['fw']         ?? null;
$value_raw  = $data['value_raw']  ?? null;
$rssi       = $data['rssi']       ?? null;
$battery_mv = $data['battery_mv'] ?? null;
$ts         = $data['ts']         ?? 0;

if ($id === null || $value_raw === null) {
    http_response_code(400);
    echo json_encode([
        'status'  => 'error',
        'message' => 'Missing id or value_raw',
    ]);
    exit;
}

// 4) Dossier de log + fichier unique
$logDir = __DIR__ . '/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0775, true);
}

// Fichier unique pour toutes les mesures, toutes les dates
$logFile = $logDir . '/barriques.log';

// Une ligne = TSV : date_iso, id, raw, batt_mV, rssi, fw, ts
$line = sprintf(
    "%s\t%s\t%s\t%s\t%s\t%s\t%s" . PHP_EOL,
    date('c'),
    $id,
    $value_raw,
    $battery_mv,
    $rssi,
    $fw,
    $ts
);

file_put_contents($logFile, $line, FILE_APPEND);

// 5) Réponse envoyée au capteur
// (plus tard on mettra ici un "sleep_seconds" pour piloter le deep sleep)
echo json_encode([
    'status'  => 'ok',
    'message' => 'Measurement stored',
    'sleep_seconds' => 5,  // valeur de test pour plus tard
]);