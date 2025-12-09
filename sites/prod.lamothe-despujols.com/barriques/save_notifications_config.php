<?php
// save_notifications_config.php
// Met à jour notifications_config.json depuis un formulaire

$configFile = __DIR__ . '/notifications_config.json';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo "Méthode non autorisée";
    exit;
}

// Valeurs autorisées pour le mode
$allowedModes = ['off', 'daily', 'weekly'];

$mode = $_POST['mode'] ?? 'weekly';
if (!in_array($mode, $allowedModes, true)) {
    $mode = 'weekly';
}

$includeBattery = isset($_POST['include_battery']) ? true : false;
$includeOffline = isset($_POST['include_offline']) ? true : false;

// Jour hebdomadaire (1 = lundi ... 7 = dimanche)
$weeklyDay = isset($_POST['weekly_day']) ? (int)$_POST['weekly_day'] : 2;
if ($weeklyDay < 1 || $weeklyDay > 7) {
    $weeklyDay = 2; // défaut : mardi
}

// Fréquence de mesure attendue (en jours)
$measureIntervalDays = isset($_POST['measure_interval_days'])
    ? (int)$_POST['measure_interval_days']
    : 7;
if ($measureIntervalDays < 1) {
    $measureIntervalDays = 7;
}

// Marge de sécurité pour considérer un capteur inactif (en jours)
$offlineGraceDays = isset($_POST['offline_grace_days'])
    ? (int)$_POST['offline_grace_days']
    : 1;
if ($offlineGraceDays < 0) {
    $offlineGraceDays = 1;
}

$config = [
    'mode'                 => $mode,
    'include_battery'      => $includeBattery,
    'include_offline'      => $includeOffline,
    'weekly_day'           => $weeklyDay,
    'measure_interval_days'=> $measureIntervalDays,
    'offline_grace_days'   => $offlineGraceDays,
];

// Sauvegarde JSON joli
file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

// Redirection simple vers le dashboard barriques
header('Location: ./');
exit;