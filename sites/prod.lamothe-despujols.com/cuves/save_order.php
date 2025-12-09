<?php
// save_order.php — Sauvegarde le nouvel ordre des cuves
// Reçoit un JSON : { "order": ["ID1","ID2","ID3", ...] }
// et réordonne config_cuves.json en conséquence.

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

$orderIds = array_values(array_filter($data['order'], function($id){
    return is_string($id) && trim($id) !== '';
}));

$configFile = __DIR__ . "/config_cuves.json";

if (!file_exists($configFile)) {
    http_response_code(500);
    echo json_encode(["error" => "Fichier config_cuves.json introuvable"]);
    exit;
}

$config = json_decode(file_get_contents($configFile), true);
if (!is_array($config)) {
    http_response_code(500);
    echo json_encode(["error" => "config_cuves.json invalide"]);
    exit;
}

// Indexer la config par ID
$index = [];
foreach ($config as $entry) {
    if (isset($entry['id'])) {
        $index[$entry['id']] = $entry;
    }
}

// Construire un nouveau tableau ordonné
$newConfig = [];

// 1) D'abord les IDs fournis dans "order"
foreach ($orderIds as $id) {
    if (isset($index[$id])) {
        $newConfig[] = $index[$id];
        unset($index[$id]);
    }
}

// 2) Puis tous les autres, dans l'ordre d'origine
foreach ($config as $entry) {
    $id = $entry['id'] ?? null;
    if ($id !== null && isset($index[$id])) {
        $newConfig[] = $entry;
        unset($index[$id]);
    }
}

// Sauvegarde
if (file_put_contents($configFile, json_encode($newConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))) {
    echo json_encode([
        "status" => "OK",
        "saved"  => count($newConfig)
    ]);
} else {
    http_response_code(500);
    echo json_encode(["error" => "Impossible d'écrire dans config_cuves.json"]);
}
?>