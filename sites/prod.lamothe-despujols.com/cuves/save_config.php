<?php
// save_config.php — Enregistre les nouveaux paramètres dans config_cuves.json
header("Content-Type: application/json; charset=utf-8");
require __DIR__ . '/lock_lib.php';

// Vérifie la méthode
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["error" => "Méthode non autorisée"]);
    exit;
}

// Récupère les données JSON envoyées
$input = file_get_contents("php://input");
$data  = json_decode($input, true);

if (!$data || !is_array($data)) {
    http_response_code(400);
    echo json_encode(["error" => "JSON invalide"]);
    exit;
}

// Normalisation des champs
foreach ($data as &$cuve) {
    $cuve['id']    = isset($cuve['id'])    ? trim($cuve['id'])    : '';
    $cuve['nomCuve'] = isset($cuve['nomCuve']) ? trim($cuve['nomCuve']) : '';

    // Nouveau champ : lot
    $cuve['lot'] = isset($cuve['lot']) ? trim($cuve['lot']) : '';

    $cuve['hauteurCapteurFond'] = isset($cuve['hauteurCapteurFond']) ? floatval($cuve['hauteurCapteurFond']) : 0.0;
    $cuve['hauteurMaxLiquide']  = isset($cuve['hauteurMaxLiquide'])  ? floatval($cuve['hauteurMaxLiquide'])  : 0.0;
    $cuve['diametreCuve']       = isset($cuve['diametreCuve'])       ? floatval($cuve['diametreCuve'])       : 0.0;
    $cuve['AjustementHL']       = isset($cuve['AjustementHL'])       ? floatval($cuve['AjustementHL'])       : 0.0;
}
unset($cuve);

// Chemin du fichier
$file = __DIR__ . "/config_cuves.json";

// Ecriture protegee par verrou exclusif (evite qu'une ecriture concurrente
// - save_order.php, purge_cuves.php, update_config_from_csv.php - ne
// corrompe le fichier ou n'ecrase cette sauvegarde juste apres).
try {
    $saved = lockedReadModifyWriteJson($file, function ($old) use ($data) {
        return $data;
    });
    echo json_encode(["status" => "OK", "saved" => count($saved)]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(["error" => "Impossible d’écrire dans config_cuves.json"]);
}
?>