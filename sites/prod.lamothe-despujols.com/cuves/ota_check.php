<?php
// ota_check.php — renvoie la version firmware disponible pour les ESP32
header("Content-Type: application/json; charset=utf-8");

$versionFile = __DIR__ . "/firmware/version.json";

// Valeurs de secours si le fichier n'existe pas
$default = [
    "version" => "1.0.0",
    "url"     => "https://prod.lamothe-despujols.com/cuves/firmware/firmware.bin"
];

if (!file_exists($versionFile)) {
    echo json_encode($default, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

$json = file_get_contents($versionFile);
$data = json_decode($json, true);

if (!is_array($data) || !isset($data["version"]) || !isset($data["url"])) {
    echo json_encode($default, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// On renvoie tel quel le contenu de version.json
echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);