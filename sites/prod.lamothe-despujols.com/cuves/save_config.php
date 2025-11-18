<?php
// save_config.php — Enregistre les nouveaux paramètres dans config_cuves.json
header("Content-Type: application/json; charset=utf-8");

// Vérifie la méthode
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["error" => "Méthode non autorisée"]);
    exit;
}

// Récupère les données JSON envoyées
$input = file_get_contents("php://input");
$data = json_decode($input, true);

if (!$data || !is_array($data)) {
    http_response_code(400);
    echo json_encode(["error" => "JSON invalide"]);
    exit;
}

// Convertit les champs numériques en float
foreach ($data as &$cuve) {
    $cuve['id'] = trim($cuve['id']);
    $cuve['nomCuve'] = trim($cuve['nomCuve']);
    $cuve['hauteurCapteurFond'] = floatval($cuve['hauteurCapteurFond']);
    $cuve['hauteurMaxLiquide'] = floatval($cuve['hauteurMaxLiquide']);
    $cuve['diametreCuve'] = floatval($cuve['diametreCuve']);
    $cuve['AjustementHL'] = floatval($cuve['AjustementHL']);
}

// Chemin du fichier
$file = __DIR__ . "/config_cuves.json";

// Sauvegarde du fichier
if (file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))) {
    echo json_encode(["status" => "OK", "saved" => count($data)]);
} else {
    http_response_code(500);
    echo json_encode(["error" => "Impossible d’écrire dans config_cuves.json"]);
}
?>
