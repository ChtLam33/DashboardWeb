<?php
// migrate_config_sqlite.php
// Script UNIQUE (a supprimer apres usage) : copie config.json,
// notifications_config.json, config_lots.json, lot_history.json,
// offsets_creux.json et subscriptions.json vers les tables SQLite
// correspondantes (phase 2 de la migration, voir barriques_lib.php).
// Idempotent : si les tables contiennent deja des donnees, ne
// reimporte rien.

require __DIR__ . '/barriques_lib.php';

header('Content-Type: text/plain; charset=utf-8');

if (($_GET['run'] ?? '') !== '1') {
    echo "Ajoutez ?run=1 a l'URL pour lancer la migration.\n";
    exit;
}

$pdo = dbConnect(); // cree les tables si besoin

$existing =
    (int)$pdo->query('SELECT COUNT(*) FROM settings')->fetchColumn() +
    (int)$pdo->query('SELECT COUNT(*) FROM lots_config')->fetchColumn() +
    (int)$pdo->query('SELECT COUNT(*) FROM lot_history')->fetchColumn() +
    (int)$pdo->query('SELECT COUNT(*) FROM offsets_creux')->fetchColumn() +
    (int)$pdo->query('SELECT COUNT(*) FROM push_subscriptions')->fetchColumn();

if ($existing > 0) {
    echo "Les tables de config contiennent deja des donnees ($existing ligne(s) au total). Migration deja faite - rien reimporte (evite les doublons).\n";
    exit;
}

$report = [];

// 1) settings <- config.json
$configJsonFile = __DIR__ . '/config.json';
if (file_exists($configJsonFile)) {
    $data = json_decode(file_get_contents($configJsonFile), true);
    if (is_array($data)) {
        if (isset($data['measure_interval_s']))       setSetting('measure_interval_s', (string)(int)$data['measure_interval_s']);
        if (isset($data['measure_interval_days']))    setSetting('measure_interval_days', (string)(int)$data['measure_interval_days']);
        if (isset($data['measure_interval_minutes'])) setSetting('measure_interval_minutes', (string)(int)$data['measure_interval_minutes']);
        $report[] = "settings <- config.json : ok";
    }
} else {
    $report[] = "settings <- config.json : fichier absent, ignore";
}

// 1bis) settings <- notifications_config.json
$notifJsonFile = __DIR__ . '/notifications_config.json';
if (file_exists($notifJsonFile)) {
    $data = json_decode(file_get_contents($notifJsonFile), true);
    if (is_array($data)) {
        if (isset($data['mode']))                  setSetting('notif_mode', (string)$data['mode']);
        setSetting('notif_include_battery', !empty($data['include_battery']) ? '1' : '0');
        setSetting('notif_include_offline', !empty($data['include_offline']) ? '1' : '0');
        if (isset($data['weekly_day']))             setSetting('notif_weekly_day', (string)(int)$data['weekly_day']);
        if (isset($data['measure_interval_days']))  setSetting('notif_measure_interval_days', (string)(int)$data['measure_interval_days']);
        if (isset($data['offline_grace_days']))     setSetting('notif_offline_grace_days', (string)(int)$data['offline_grace_days']);
        $report[] = "settings <- notifications_config.json : ok";
    }
} else {
    $report[] = "settings <- notifications_config.json : fichier absent, ignore";
}

// 2) lots_config <- config_lots.json
$configLotsFile = __DIR__ . '/config_lots.json';
$lotsCount = 0;
if (file_exists($configLotsFile)) {
    $data = json_decode(file_get_contents($configLotsFile), true);
    if (is_array($data)) {
        foreach ($data as $sensorId => $v) {
            if (!is_array($v)) continue;
            $lot = trim((string)($v['lot'] ?? ''));
            if ($lot === '') continue;
            $bar = isset($v['barriques']) ? (int)$v['barriques'] : 0;
            saveSensorLot((string)$sensorId, $lot, $bar);
            $lotsCount++;
        }
    }
}
$report[] = "lots_config <- config_lots.json : $lotsCount ligne(s)";

// 3) lot_history <- lot_history.json (format assoc attendu)
$lotHistoryFile = __DIR__ . '/lot_history.json';
$historyCount = 0;
if (file_exists($lotHistoryFile)) {
    $data = json_decode(file_get_contents($lotHistoryFile), true);
    if (is_array($data)) {
        saveLotHistory($data);
        foreach ($data as $periods) {
            if (is_array($periods)) $historyCount += count($periods);
        }
    }
}
$report[] = "lot_history <- lot_history.json : $historyCount periode(s)";

// 4) offsets_creux <- offsets_creux.json
$offsetsFile = __DIR__ . '/offsets_creux.json';
$offsetsCount = 0;
if (file_exists($offsetsFile)) {
    $data = json_decode(file_get_contents($offsetsFile), true);
    if (is_array($data)) {
        saveCreuxOffsets($data);
        $offsetsCount = count($data);
    }
}
$report[] = "offsets_creux <- offsets_creux.json : $offsetsCount ligne(s)";

// 5) push_subscriptions <- subscriptions.json
$subsFile = __DIR__ . '/subscriptions.json';
$subsCount = 0;
if (file_exists($subsFile)) {
    $data = json_decode(file_get_contents($subsFile), true);
    if (is_array($data)) {
        foreach ($data as $sub) {
            if (!is_array($sub)) continue;
            addPushSubscriptionIfNew($sub);
            $subsCount++;
        }
    }
}
$report[] = "push_subscriptions <- subscriptions.json : $subsCount ligne(s)";

echo implode("\n", $report) . "\n";

echo "\nVerification finale (nombre de lignes par table) :\n";
echo "settings: "           . (int)$pdo->query('SELECT COUNT(*) FROM settings')->fetchColumn()           . "\n";
echo "lots_config: "        . (int)$pdo->query('SELECT COUNT(*) FROM lots_config')->fetchColumn()        . "\n";
echo "lot_history: "        . (int)$pdo->query('SELECT COUNT(*) FROM lot_history')->fetchColumn()        . "\n";
echo "offsets_creux: "      . (int)$pdo->query('SELECT COUNT(*) FROM offsets_creux')->fetchColumn()      . "\n";
echo "push_subscriptions: " . (int)$pdo->query('SELECT COUNT(*) FROM push_subscriptions')->fetchColumn() . "\n";
