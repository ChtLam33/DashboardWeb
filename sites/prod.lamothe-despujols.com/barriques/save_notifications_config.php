<?php
// save_notifications_config.php
// Met à jour notifications_config.json ET config.json (capteurs)

$notifFile    = __DIR__ . '/notifications_config.json';
$barConfigFile = __DIR__ . '/config.json';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo "Méthode non autorisée";
    exit;
}

/* =========================================================
   1) Notifications (notifications_config.json)
   ========================================================= */

// Valeurs autorisées pour le mode
$allowedModes = ['off', 'daily', 'weekly'];

$mode = $_POST['mode'] ?? 'weekly';
if (!in_array($mode, $allowedModes, true)) {
    $mode = 'weekly';
}

$includeBattery = isset($_POST['include_battery']);
$includeOffline = isset($_POST['include_offline']);

// Jour hebdomadaire (1 = lundi ... 7 = dimanche)
$weeklyDay = isset($_POST['weekly_day']) ? (int)$_POST['weekly_day'] : 2;
if ($weeklyDay < 1 || $weeklyDay > 7) {
    $weeklyDay = 2; // défaut : mardi
}

// Fréquence de mesure attendue (en jours) pour la logique de "capteur inactif"
$measureIntervalDays = isset($_POST['measure_interval_days'])
    ? (int)$_POST['measure_interval_days']
    : 7;
if ($measureIntervalDays < 1) {
    $measureIntervalDays = 7;
}

// Marge de sécurité pour considérer un capteur inactif (en jours)
// -> on la fixe désormais à 1 jour, pas de champ dans l'UI
$offlineGraceDays = 1;

$notifConfig = [
    'mode'                  => $mode,
    'include_battery'       => $includeBattery,
    'include_offline'       => $includeOffline,
    'weekly_day'            => $weeklyDay,
    'measure_interval_days' => $measureIntervalDays,
    'offline_grace_days'    => $offlineGraceDays,
];

// Sauvegarde JSON "joli"
file_put_contents(
    $notifFile,
    json_encode($notifConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
);

/* =========================================================
   2) Config globale capteurs (config.json)
   ========================================================= */

// Lecture config existante (si besoin)
$barConfig = [
    'measure_interval_s'    => 604800,
    'maintenance'           => true,
    'test_mode'             => false,
    'measure_interval_days' => 7,
];

if (file_exists($barConfigFile)) {
    $jsonBar = file_get_contents($barConfigFile);
    $dataBar = json_decode($jsonBar, true);
    if (is_array($dataBar)) {
        $barConfig = array_merge($barConfig, $dataBar);
    }
}

// Mode test (20s) ?
$testMode = isset($_POST['test_mode']) ? true : false;

// Maintenance (deep-sleep désactivé)
$maintenance = isset($_POST['maintenance']) ? true : false;

// Intervalle utilisé par le firmware
if ($testMode) {
    // Mode test : intervalle fixe de 20 secondes
    $measureIntervalS = 20;
} else {
    // Mode normal : jours -> secondes
    $measureIntervalS = $measureIntervalDays * 86400;
}

// Mise à jour de la config capteurs
$barConfig['measure_interval_s']    = $measureIntervalS;
$barConfig['maintenance']           = $maintenance;
$barConfig['test_mode']             = $testMode;
$barConfig['measure_interval_days'] = $measureIntervalDays;

// Sauvegarde config.json
file_put_contents(
    $barConfigFile,
    json_encode($barConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
);

// Redirection simple vers le dashboard barriques
header('Location: ./');
exit;