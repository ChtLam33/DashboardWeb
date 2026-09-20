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
    $config = getCuvesConfig();
    $now = time();

    $cuveEntries = [];
    foreach ($config as $cfg) {
        if ($cfg['last_distance_cm'] === null) continue;

        $distance = (int)$cfg['last_distance_cm'];
        $interp = interpretCuve($distance, $cfg);

        // Signale les cuves dont le volume enregistre est potentiellement
        // perime/faux au moment de l'instantane (demande utilisateur) :
        // priorite a "hors ligne" (donnee perimee) sur "mesure incoherente"
        // (donnee recente mais suspecte).
        $status = '';
        if (isCuveOffline($cfg, $now)) {
            $status = 'offline';
        } elseif (isCuveMeasurementIncoherent($distance, $cfg)) {
            $status = 'incoherent';
        }

        $cuveEntries[] = [
            'sensor_id' => $cfg['sensor_id'],
            'nom_cuve'  => $cfg['nom_cuve'],
            'lot'       => $cfg['lot'],
            'volume_hl' => $interp['volume_hl'],
            'status'    => $status,
        ];
    }

    $entry = addCuveSnapshot($comment, $cuveEntries);

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
