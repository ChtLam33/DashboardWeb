<?php
// get_config.php – Config globale pour les capteurs barriques
header('Content-Type: application/json; charset=utf-8');

$configFile = __DIR__ . '/config.json';

// Valeurs par défaut si le JSON est absent ou cassé
$default = [
    'measure_interval_s' => 600,   // 10 minutes par défaut
    'maintenance'        => true,  // mode maintenance actif par défaut
    'test_mode'          => false  // test deep-sleep désactivé par défaut
];

// Si le fichier n'existe pas -> on renvoie les valeurs par défaut
if (!file_exists($configFile)) {
    echo json_encode($default);
    exit;
}

$json = file_get_contents($configFile);
$data = json_decode($json, true);

// Si le JSON est invalide -> fallback sur défaut
if (!is_array($data)) {
    echo json_encode($default);
    exit;
}

// Fusion (les clés manquantes gardent la valeur défaut)
$out = array_merge($default, $data);

// Réponse JSON propre pour le firmware
echo json_encode($out);