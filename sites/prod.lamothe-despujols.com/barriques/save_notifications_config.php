<?php
// save_notifications_config.php
// Met à jour les réglages notifications + capteurs (table SQLite "settings")

require __DIR__ . '/barriques_lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo "Méthode non autorisée";
    exit;
}

/* =========================================================
   1) Notifications
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

setSetting('notif_mode', $mode);
setSetting('notif_include_battery', $includeBattery ? '1' : '0');
setSetting('notif_include_offline', $includeOffline ? '1' : '0');
setSetting('notif_weekly_day', (string)$weeklyDay);
setSetting('notif_measure_interval_days', (string)$measureIntervalDays);
setSetting('notif_offline_grace_days', (string)$offlineGraceDays);

/* =========================================================
   2) Config globale capteurs
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

setSetting('measure_interval_s', (string)$measureIntervalS);
setSetting('measure_interval_days', (string)$measureIntervalDays);
setSetting('measure_interval_minutes', (string)$measureIntervalMinutes);

// Redirection simple vers le dashboard barriques
header('Location: ./');
exit;
