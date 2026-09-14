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

// Fréquence de mesure attendue (en jours, partie entière) pour la logique
// de "capteur inactif" et pour le calcul de l'intervalle réel (avec les minutes)
$measureIntervalDays = isset($_POST['measure_interval_days'])
    ? (int)$_POST['measure_interval_days']
    : 7;
if ($measureIntervalDays < 0) {
    $measureIntervalDays = 0;
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

// Minutes complémentaires aux jours (0 à 1439, soit moins de 24h)
$measureIntervalMinutes = isset($_POST['measure_interval_minutes'])
    ? (int)$_POST['measure_interval_minutes']
    : 0;
if ($measureIntervalMinutes < 0) $measureIntervalMinutes = 0;
if ($measureIntervalMinutes > 1439) $measureIntervalMinutes = 1439;

// Intervalle utilisé par le firmware : jours + minutes -> secondes
// (plancher de securite 60s, le firmware applique aussi son propre plancher)
$measureIntervalS = max(60, $measureIntervalDays * 86400 + $measureIntervalMinutes * 60);

// Config capteurs (remplace entièrement config.json)
$barConfig = [
    'measure_interval_s'       => $measureIntervalS,
    'measure_interval_days'    => $measureIntervalDays,
    'measure_interval_minutes' => $measureIntervalMinutes,
];

// Sauvegarde config.json
file_put_contents(
    $barConfigFile,
    json_encode($barConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
);

// Redirection simple vers le dashboard barriques
header('Location: ./');
exit;