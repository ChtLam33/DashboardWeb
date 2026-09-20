<?php
// save_order.php — Sauvegarde le nouvel ordre des cuves (colonne position, SQLite)
// Reçoit un JSON : { "order": ["ID1","ID2","ID3", ...] }
require __DIR__ . '/cuves_lib.php';
header("Content-Type: application/json; charset=utf-8");

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["error" => "Méthode non autorisée"]);
    exit;
}

$input = file_get_contents("php://input");
$data  = json_decode($input, true);

if (!$data || !isset($data['order']) || !is_array($data['order'])) {
    http_response_code(400);
    echo json_encode(["error" => "JSON invalide ou champ 'order' manquant"]);
    exit;
}

$orderIds = array_values(array_filter($data['order'], function ($id) {
    return is_string($id) && trim($id) !== '';
}));

try {
    $count = saveCuvesOrder($orderIds);
    echo json_encode(["status" => "OK", "saved" => $count]);
} catch (\Throwable $e) {
    error_log('[cuves] save_order.php failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(["error" => "Impossible d'enregistrer l'ordre"]);
}
