<?php
// api_cuve.php — Réception des données envoyées par ESP32 (POST JSON)
//
// Migration SQLite (remplace data_cuves.csv) : chaque mesure devient une
// ligne (INSERT), plus de lecture-modification-écriture d'un fichier
// partagé donc plus besoin de verrou manuel (voir cuves_lib.php).
//
// Ne stocke QUE la distance brute : le volume/%/hauteurs sont recalculés
// à la lecture via interpretCuve() avec la config courante (comme
// barriques le fait déjà avec interpret_raw()). Les champs calculés que
// l'ancien firmware envoie encore (volume, capacite, pourcentage,
// hauteurPlein, hauteurCuve, correction) sont acceptés mais ignorés :
// ça permet une transition sans casser l'OTA (le firmware n'a pas besoin
// de changer pour que cette migration prenne effet).

require __DIR__ . '/cuves_lib.php';

header("Content-Type: application/json; charset=utf-8");

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["error" => "Méthode non autorisée."]);
    exit;
}

$input = file_get_contents("php://input");
$data  = json_decode($input, true);

if (!$data || !is_array($data)) {
    http_response_code(400);
    echo json_encode(["error" => "Données JSON invalides."]);
    exit;
}

$id   = htmlspecialchars(trim((string)($data['id']   ?? 'sans_id')));
$cuve = htmlspecialchars(trim((string)($data['cuve'] ?? 'inconnue')));

$distance = (int)round(floatval($data['distance'] ?? 0));
$rssi     = isset($data['rssi']) ? intval($data['rssi']) : null;
$fw       = htmlspecialchars(trim((string)($data['fw'] ?? '')));

$now = time();

try {
    ensureCuveConfigExists($id, $cuve);
    insertCuveMeasurement($id, $distance, $rssi, $fw !== '' ? $fw : null, $now, date('Y-m-d H:i:s', $now));
} catch (\Throwable $e) {
    error_log('[cuves] api_cuve.php insert failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(["error" => "Erreur d'enregistrement."]);
    exit;
}

echo json_encode([
    "status"    => "OK",
    "id"        => $id,
    "cuve"      => $cuve,
    "fw"        => $fw,
    "timestamp" => date('Y-m-d H:i:s', $now)
], JSON_UNESCAPED_UNICODE);
