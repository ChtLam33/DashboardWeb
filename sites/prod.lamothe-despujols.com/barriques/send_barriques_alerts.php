<?php
// send_barriques_alerts.php
// Envoie une notification Web Push récapitulative des barriques

require __DIR__ . '/barriques_lib.php';
require __DIR__ . '/../vendor/autoload.php'; // Minishlink/WebPush

use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

// ---------- FICHIERS ----------
$logFile           = __DIR__ . '/logs/barriques.log';
$configLotsFile    = __DIR__ . '/config_lots.json';
$notifConfigFile   = __DIR__ . '/notifications_config.json';
$subscriptionsFile = __DIR__ . '/subscriptions.json';

// ---------- 1. LIRE CONFIG NOTIFS ----------
$notifConfig = [
    'mode'                 => 'weekly',   // off | daily | weekly (logique "fonctionnelle")
    'include_battery'      => true,
    'include_offline'      => true,
    'weekly_day'           => 2,          // 1 = lundi ... 7 = dimanche
    'measure_interval_days'=> 7,          // fréquence attendue des mesures (en jours)
    'offline_grace_days'   => 1,          // marge de sécurité (en jours)
];

if (file_exists($notifConfigFile)) {
    $data = json_decode(file_get_contents($notifConfigFile), true);
    if (is_array($data)) {
        // mode
        $mode = $data['mode'] ?? 'weekly';
        if (!in_array($mode, ['off', 'daily', 'weekly'], true)) {
            $mode = 'weekly';
        }
        $notifConfig['mode'] = $mode;

        // sections optionnelles
        $notifConfig['include_battery'] = !empty($data['include_battery']);
        $notifConfig['include_offline'] = !empty($data['include_offline']);

        // jour hebdo
        if (isset($data['weekly_day'])) {
            $wd = (int)$data['weekly_day'];
            if ($wd >= 1 && $wd <= 7) {
                $notifConfig['weekly_day'] = $wd;
            }
        }

        // fréquence de mesure en jours
        if (isset($data['measure_interval_days'])) {
            $notifConfig['measure_interval_days'] = max(1, (int)$data['measure_interval_days']);
        }

        // marge de sécurité en jours
        if (isset($data['offline_grace_days'])) {
            $notifConfig['offline_grace_days'] = max(0, (int)$data['offline_grace_days']);
        }
    }
}

// Si notifications désactivées → on sort
if ($notifConfig['mode'] === 'off') {
    echo "[INFO] Notifications désactivées (mode = off dans notifications_config.json)\n";
    exit(0);
}

// Si mode weekly : on ne continue QUE si on est le bon jour
if ($notifConfig['mode'] === 'weekly') {
    $todayNum  = (int)date('N'); // 1 = lundi ... 7 = dimanche
    $weeklyDay = (int)$notifConfig['weekly_day'];
    if ($weeklyDay < 1 || $weeklyDay > 7) {
        $weeklyDay = 2; // mardi par défaut
    }

    if ($todayNum !== $weeklyDay) {
        echo "[INFO] Mode weekly : aujourd'hui (N=$todayNum) ≠ jour configuré (N=$weeklyDay), pas d'envoi.\n";
        exit(0);
    }
}

// ---------- 2. CHARGER CONFIG LOTS ----------
$configById = [];

if (!file_exists($configLotsFile)) {
    echo "[WARN] Fichier de config lots introuvable : $configLotsFile\n";
} else {
    $cfgJson = file_get_contents($configLotsFile);
    $rawCfg  = json_decode($cfgJson, true);
    if ($rawCfg === null) {
        echo "[WARN] Erreur JSON dans config_lots.json : " . json_last_error_msg() . "\n";
    } else {
        // Format attendu :
        // {
        //   "330989340": { "lot": "L24", "barriques": 10 },
        //   "123456789": { "lot": "L25", "barriques": 8 }
        // }
        foreach ($rawCfg as $idKey => $v) {
            if (!is_array($v)) continue;
            $idStr = (string)$idKey;
            $configById[$idStr] = [
                'lot'       => $v['lot']       ?? '',
                'barriques' => isset($v['barriques']) ? (int)$v['barriques'] : 1,
            ];
        }
    }
}

// ---------- 3. LIRE DERNIÈRES MESURES PAR CAPTEUR ----------
if (!file_exists($logFile)) {
    echo "[INFO] Aucun log trouvé ($logFile), pas de notification.\n";
    exit(0);
}

$lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
if (!$lines) {
    echo "[INFO] Log vide, pas de notification.\n";
    exit(0);
}

$capteurs = []; // id => infos dernière mesure
foreach ($lines as $line) {
    $parts = explode("\t", $line);
    if (count($parts) < 7) continue;

    list($dateIso, $id, $raw, $batt, $rssi, $fw, $ts) = $parts;

    $id = trim($id);
    if ($id === '') continue;

    $capteurs[$id] = [
        'date_iso' => trim($dateIso),
        'id'       => $id,
        'raw'      => (int)$raw,
        'batt'     => (int)$batt,
        'rssi'     => (int)$rssi,
        'fw'       => trim($fw),
        'ts'       => (int)$ts,
    ];
}

if (empty($capteurs)) {
    echo "[INFO] Aucun capteur trouvé dans le log.\n";
    exit(0);
}

// ---------- 4. INTERPRÉTATION & AGRÉGATION ----------

$now        = time();
$perLot     = []; // lot => infos par niveau
$lowBattery = []; // capteurs batterie faible
$offline    = []; // capteurs inactifs

// Seuil d'inactivité indexé sur la fréquence de mesure + marge
$freqDays    = max(1, (int)$notifConfig['measure_interval_days']); // ex : 7
$graceDays   = max(0, (int)$notifConfig['offline_grace_days']);    // ex : 1
$offlineDays = $freqDays + $graceDays;

// On impose un minimum de 2 jours pour éviter les faux positifs extrêmes
if ($offlineDays < 2) {
    $offlineDays = 2;
}

$inactiveDelay = $offlineDays * 24 * 3600;

// seuil batterie faible (en mV) - à ajuster si besoin
$lowBattThreshold = 3700;

foreach ($capteurs as $id => $info) {
    $cfg = $configById[$id] ?? ['lot' => '', 'barriques' => 1];
    $lotName = trim($cfg['lot']) !== '' ? $cfg['lot'] : '-';
    $nbBar   = (int)($cfg['barriques'] ?? 1);
    if ($nbBar <= 0) $nbBar = 1;

    $interp = interpret_raw($info['raw']);

    // Construction du texte niveau
    if ($interp['creux_cm_min'] === null) {
        $nivText = "niveau inconnu";
    } else {
        $nivText = sprintf(
            "%s [%s–%s] cm (%s–%s L)",
            $interp['couleur'],
            $interp['creux_cm_min'],
            $interp['creux_cm_max'],
            $interp['creux_l_min'],
            $interp['creux_l_max']
        );
    }

    // Regroupement par lot & par couleur
    if (!isset($perLot[$lotName])) {
        $perLot[$lotName] = [
            'barriques' => $nbBar,   // total barriques du lot (approx)
            'details'   => []        // ex: 'orange' => [ ['id'=>..., 'nivText'=>...], ... ]
        ];
    }
    // on ne veut pas ajouter n fois les barriques si plusieurs capteurs
    $perLot[$lotName]['barriques'] = max($perLot[$lotName]['barriques'], $nbBar);

    $couleurKey = $interp['couleur'] ?? 'erreur';
    if (!isset($perLot[$lotName]['details'][$couleurKey])) {
        $perLot[$lotName]['details'][$couleurKey] = [];
    }
    $perLot[$lotName]['details'][$couleurKey][] = [
        'id'      => $id,
        'nivText' => $nivText,
    ];

    // Batterie faible ?
    if ($info['batt'] > 0 && $info['batt'] < $lowBattThreshold) {
        $lowBattery[] = [
            'id'   => $id,
            'lot'  => $lotName,
            'batt' => $info['batt'],
        ];
    }

    // Capteur inactif ?
    if (
        $notifConfig['include_offline'] &&
        $info['ts'] > 0 &&
        ($now - $info['ts']) > $inactiveDelay
    ) {
        $offline[] = [
            'id'   => $id,
            'lot'  => $lotName,
            'last' => $info['date_iso'],
        ];
    }
}

// Si aucun problème (tout vert, pas de batterie faible, pas d'offline) → pas d'envoi
$onlyGreen = true;
foreach ($perLot as $lotName => $data) {
    foreach ($data['details'] as $color => $list) {
        if ($color !== 'vert') {
            $onlyGreen = false;
            break 2;
        }
    }
}
if ($onlyGreen && empty($lowBattery) && empty($offline)) {
    echo "[INFO] Tous les capteurs sont verts, aucune alerte envoyée.\n";
    exit(0);
}

// ---------- 5. CONSTRUIRE LE MESSAGE ----------

$lines = [];
$lines[] = "Alerte Ouillage";

// Par lot
foreach ($perLot as $lotName => $data) {
    // On ne liste que les couleurs non vertes
    $nonGreenLines = [];
    foreach ($data['details'] as $color => $entries) {
        if ($color === 'vert') continue;
        $count = count($entries);
        // On prend le premier pour les bornes (tous ont même palier)
        $first = $entries[0]['nivText'];
        $nonGreenLines[] = "$count bar $color : $first";
    }
    if (!empty($nonGreenLines)) {
        $barTxt = $data['barriques'] . " bar";
        $lines[] = "Lot " . ($lotName !== '-' ? $lotName : "- (sans nom)") . " ($barTxt) :";
        foreach ($nonGreenLines as $l) {
            $lines[] = " - " . $l;
        }
        $lines[] = "----------";
    }
}

// Batterie faible (si activé dans la config)
if ($notifConfig['include_battery'] && !empty($lowBattery)) {
    $lines[] = "Capteurs batterie faible :";
    foreach ($lowBattery as $b) {
        $lotLabel = ($b['lot'] === '-') ? 'lot -' : 'lot ' . $b['lot'];
        $lines[] = " - Capteur n°{$b['id']} ({$lotLabel}) : {$b['batt']} mV";
    }
    $lines[] = "----------";
}

// Capteurs inactifs (si activé dans la config ET déjà filtrés plus haut)
if (!empty($offline)) {
    $lines[] = "Capteurs inactifs :";
    foreach ($offline as $o) {
        $lotLabel = ($o['lot'] === '-') ? 'lot -' : 'lot ' . $o['lot'];
        // date FR pour lecture humaine
        $ts = strtotime($o['last']);
        if ($ts !== false) {
            $dateFr = date('d/m/Y H\hi', $ts);
        } else {
            $dateFr = $o['last'];
        }
        $lines[] = " - Capteur n°{$o['id']} ({$lotLabel}) : dernière mesure {$dateFr}";
    }
    $lines[] = "----------";
}

$body = implode("\n", $lines);

// ---------- 6. ENVOI WEB PUSH ----------

if (!file_exists($subscriptionsFile)) {
    echo "[WARN] Aucun subscriptions.json trouvé, personne à notifier.\n";
    exit(0);
}

$subsJson = file_get_contents($subscriptionsFile);
$subsData = json_decode($subsJson, true);
if (empty($subsData) || !is_array($subsData)) {
    echo "[WARN] subscriptions.json vide ou invalide.\n";
    exit(0);
}

// Tes clés VAPID
$auth = [
    'VAPID' => [
        'subject'    => 'mailto:webmaster@lamothe-despujols.com',
        'publicKey'  => 'BDBBkJp-c7FZt5JJu5j3COn2Z3v42a54NlWngElS49Ogt0bd2nwaxnQvHGh7vbgH62ECDWhlPChglbSXgyvw4r0',
        'privateKey' => 'lwXO723hBCJacXilBJbQmJIxdcwyc3z9O-n_bVdEi_k',
    ],
];

$webPush = new WebPush($auth);

$title = "Alerte ouillage barriques";
$url   = "https://prod.lamothe-despujols.com/barriques/";

// Envoi à chaque abonnement
foreach ($subsData as $subArr) {
    try {
        $subscription = Subscription::create($subArr);

        $payload = json_encode([
            'title' => $title,
            'body'  => $body,
            'url'   => $url,
        ], JSON_UNESCAPED_UNICODE);

        $webPush->sendOneNotification($subscription, $payload);
    } catch (\Throwable $e) {
        echo "[ERROR] Envoi à un abonnement : " . $e->getMessage() . "\n";
    }
}

foreach ($webPush->flush() as $report) {
    $endpoint = $report->getRequest()->getUri()->__toString();
    if ($report->isSuccess()) {
        echo "[OK] Notification envoyée à {$endpoint}\n";
    } else {
        echo "[FAIL] Notification échouée pour {$endpoint}: " . $report->getReason() . "\n";
    }
}