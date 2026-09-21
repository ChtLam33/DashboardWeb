<?php
// barriques/api_post.php

require __DIR__ . '/barriques_lib.php';

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
$sleep_s    = $data['sleep_s']    ?? null; // firmware >= 2.0.1 seulement

if ($id === null || $value_raw === null) {
    http_response_code(400);
    echo json_encode([
        'status'  => 'error',
        'message' => 'Missing id or value_raw',
    ]);
    exit;
}

// 3bis) Cle API (voir register.php / isValidBarriqueApiKey()) - tolerant
// tant qu'aucune cle n'a encore ete emise pour ce capteur (transition
// firmware en cours, voir barriques_lib.php).
$apiKeyHeader = $_SERVER['HTTP_X_API_KEY'] ?? null;
if (!isValidBarriqueApiKey((string)$id, $apiKeyHeader)) {
    http_response_code(401);
    echo json_encode([
        'status'  => 'error',
        'message' => 'Invalid API key',
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

// Une ligne = TSV : date_iso, id, raw, batt_mV, rssi, fw, ts, sleep_s
$line = sprintf(
    "%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s" . PHP_EOL,
    date('c'),
    $id,
    $value_raw,
    $battery_mv,
    $rssi,
    $fw,
    $ts,
    $sleep_s
);

file_put_contents($logFile, $line, FILE_APPEND);

// 4bis) Ecriture en parallele dans SQLite (phase 1 migration, voir barriques_lib.php)
// Le log texte ci-dessus reste la sauvegarde de reference tant que la transition n'est pas validee.
try {
    insertMeasurement(
        (string)$id,
        date('c'),
        (int)$value_raw,
        ($battery_mv === null ? null : (int)$battery_mv),
        ($rssi === null ? null : (int)$rssi),
        ($fw === null ? null : (string)$fw),
        (int)$ts,
        ($sleep_s === null ? null : (int)$sleep_s)
    );
} catch (\Throwable $e) {
    // Ne bloque jamais la reponse au capteur : le log texte suffit si SQLite echoue.
    error_log('[barriques] insertMeasurement failed: ' . $e->getMessage());
}

// 5) Réponse envoyée au capteur
// (plus tard on mettra ici un "sleep_seconds" pour piloter le deep sleep)
echo json_encode([
    'status'  => 'ok',
    'message' => 'Measurement stored',
    'sleep_seconds' => 5,  // valeur de test pour plus tard
]);