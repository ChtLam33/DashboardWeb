<?php
// get_config.php — renvoie la configuration complète ou celle d'un ID spécifique
header("Content-Type: application/json; charset=utf-8");
require __DIR__ . '/lock_lib.php';

$file = __DIR__ . "/config_cuves.json";
$raw  = lockedRead($file);
if ($raw === null) {
    echo json_encode(["error" => "Fichier config_cuves.json introuvable"]);
    exit;
}

$config = json_decode($raw, true);
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

// --- Sinon, renvoyer tout le tableau (pour le dashboard), enrichi de la
//     derniere version firmware connue par capteur (cache_dashboard.json,
//     alimente par update_cache.php a partir de data_cuves.csv) ---
$fwById = [];
$cacheRaw = lockedRead(__DIR__ . "/cache_dashboard.json");
if ($cacheRaw !== null) {
    $cacheData = json_decode($cacheRaw, true);
    if (is_array($cacheData)) {
        foreach ($cacheData as $c) {
            if (isset($c['id'])) {
                $fwById[$c['id']] = $c['fw'] ?? '';
            }
        }
    }
}
foreach ($config as &$cuve) {
    $cuve['fw'] = $fwById[$cuve['id'] ?? ''] ?? '';
}
unset($cuve);

echo json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
?>
