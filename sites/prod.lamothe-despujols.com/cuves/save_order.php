<?php
// save_order.php — Sauvegarde le nouvel ordre des cuves
// Reçoit un JSON : { "order": ["ID1","ID2","ID3", ...] }
// et réordonne config_cuves.json en conséquence.

header("Content-Type: application/json; charset=utf-8");
require __DIR__ . '/lock_lib.php';

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

// Lecture + reordonnancement + ecriture sous un seul verrou continu, pour
// ne pas se baser sur une config perimee si une autre requete (save_config,
// purge_cuves...) ecrit entre notre lecture et notre ecriture.
try {
    $newConfig = lockedReadModifyWriteJson($configFile, function ($config) use ($orderIds) {
        if (!is_array($config)) {
            $config = [];
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

        return $newConfig;
    });

    echo json_encode([
        "status" => "OK",
        "saved"  => count($newConfig)
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(["error" => "Impossible d'écrire dans config_cuves.json"]);
}
?>