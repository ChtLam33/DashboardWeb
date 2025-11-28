<?php
// get_config.php — renvoie la configuration complète ou celle d'un ID spécifique
header("Content-Type: application/json; charset=utf-8");

$file = __DIR__ . "/config_cuves.json";
if (!file_exists($file)) {
    echo json_encode(["error" => "Fichier config_cuves.json introuvable"]);
    exit;
}

$config = json_decode(file_get_contents($file), true);
if (!$config) {
    echo json_encode(["error" => "Fichier config_cuves.json invalide"]);
    exit;
}

// --- Si un ID est passé dans l'URL, renvoyer uniquement cet élément ---
$id = $_GET['id'] ?? '';
if ($id !== '') {
    foreach ($config as $cuve) {
        if ($cuve['id'] === $id) {
            echo json_encode($cuve, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
    echo json_encode(["error" => "Aucune configuration trouvée pour $id"]);
    exit;
}

// --- Sinon, renvoyer tout le tableau (pour le dashboard) ---
echo json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
?>
