<?php
// save_config.php — Enregistre les nouveaux paramètres (table config, SQLite)
require __DIR__ . '/cuves_lib.php';
header("Content-Type: application/json; charset=utf-8");

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["error" => "Méthode non autorisée"]);
    exit;
}

$input = file_get_contents("php://input");
$data  = json_decode($input, true);

if (!$data || !is_array($data)) {
    http_response_code(400);
    echo json_encode(["error" => "JSON invalide"]);
    exit;
}

try {
    $saved = saveCuvesConfig($data);
    echo json_encode(["status" => "OK", "saved" => count($saved)]);
} catch (\Throwable $e) {
    error_log('[cuves] save_config.php failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(["error" => "Impossible d'écrire la configuration"]);
}
