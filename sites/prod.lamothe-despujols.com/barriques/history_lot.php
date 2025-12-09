<?php
// history_lot.php
// Graphique d’historique par lot (creux en L, bande min/max)

require __DIR__ . '/barriques_lib.php';

// ---------- 0. Récupérer le nom du lot ----------
$lot = isset($_GET['lot']) ? trim($_GET['lot']) : '';
if ($lot === '') {
    echo "Lot manquant.";
    exit;
}

// ---------- 1. Fichiers ----------
$logFile        = __DIR__ . '/logs/barriques.log';
$configLotsFile = __DIR__ . '/config_lots.json';
$lotHistoryFile = __DIR__ . '/lot_history.json';

// ---------- 2. Charger log ----------
if (!file_exists($logFile)) {
    echo "Aucun fichier de log trouvé.";
    exit;
}

$rows = []; // chaque ligne brute du log
$lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
foreach ($lines as $line) {
    $parts = explode("\t", $line);
    if (count($parts) < 7) continue;

    list($dateIso, $id, $raw, $batt, $rssi, $fw, $ts) = $parts;
    $id = trim($id);
    if ($id === '') continue;

    $rows[] = [
        'date_iso' => trim($dateIso),
        'id'       => $id,
        'raw'      => (int)$raw,
        'batt'     => (int)$batt,
        'rssi'     => (int)$rssi,
        'fw'       => trim($fw),
        'ts'       => (int)$ts,
    ];
}

if (empty($rows)) {
    echo "Log vide.";
    exit;
}

// ---------- 3. Charger config_lots (pour fallback et nb barriques si besoin) ----------
$configLots = [];
if (file_exists($configLotsFile)) {
    $json = file_get_contents($configLotsFile);
    $data = json_decode($json, true);
    if (is_array($data)) {
        $configLots = $data; // id => ['lot'=>..., 'barriques'=>...]
    }
}

// ---------- 4. Charger historique des lots ----------
// On accepte 2 formats de lot_history.json :
//
//  FORMAT A (celui que tu as actuellement) :
//  {
//      "330989340": [
//          { "lot": "SE16", "from_ts": 1765243906, "to_ts": 1765243977 },
//          { "lot": "L16",  "from_ts": ...,        "to_ts": ...        },
//          ...
//      ],
//      "AUTRE_ID": [
//          ...
//      ]
//  }
//
//  FORMAT B (plat) :
//  [
//      { "id": "330989340", "lot": "L24", "start_ts": 1765000000, "end_ts": null },
//      ...
//  ]

$periodsById = []; // id_capteur => [ [start_ts, end_ts], ... ]

if (file_exists($lotHistoryFile)) {
    $jsonH = file_get_contents($lotHistoryFile);
    $hist  = json_decode($jsonH, true);

    if (is_array($hist)) {

        // Détection du format : si index 0 existe ⇒ liste plate (FORMAT B)
        if (array_key_exists(0, $hist) && is_array($hist[0])) {
            // ===== FORMAT B : liste d'entrées {id, lot, start_ts, end_ts} =====
            foreach ($hist as $entry) {
                if (!is_array($entry)) continue;

                $eLot = isset($entry['lot']) ? (string)$entry['lot'] : '';
                $eId  = isset($entry['id'])  ? (string)$entry['id']  : '';
                $sTs  = isset($entry['start_ts']) ? (int)$entry['start_ts'] : 0;
                $eTs  = array_key_exists('end_ts', $entry) ? $entry['end_ts'] : null;
                $eTs  = ($eTs === null) ? null : (int)$eTs;

                if ($eLot === '' || $eId === '' || $sTs <= 0) continue;
                if ($eLot !== $lot) continue; // on ne garde que le lot demandé

                if (!isset($periodsById[$eId])) {
                    $periodsById[$eId] = [];
                }
                $periodsById[$eId][] = [
                    'start' => $sTs,
                    'end'   => $eTs,
                ];
            }
        } else {
            // ===== FORMAT A : id_capteur => [ {lot, from_ts, to_ts}, ... ] =====
            foreach ($hist as $sensorId => $periods) {
                if (!is_array($periods)) continue;

                $eId = (string)$sensorId;

                foreach ($periods as $p) {
                    if (!is_array($p)) continue;

                    $eLot = isset($p['lot']) ? (string)$p['lot'] : '';
                    $sTs  = isset($p['from_ts']) ? (int)$p['from_ts'] : 0;
                    $eTs  = array_key_exists('to_ts', $p) ? $p['to_ts'] : null;
                    $eTs  = ($eTs === null) ? null : (int)$eTs;

                    if ($eLot === '' || $sTs <= 0) continue;
                    if ($eLot !== $lot) continue; // seulement le lot demandé

                    if (!isset($periodsById[$eId])) {
                        $periodsById[$eId] = [];
                    }
                    $periodsById[$eId][] = [
                        'start' => $sTs,
                        'end'   => $eTs,
                    ];
                }
            }
        }
    }
}

// Est-ce qu’on a de vraies périodes d’historique pour ce lot ?
$useHistory = !empty($periodsById);

// ---------- 5. Construire les points à tracer ----------
// On va agréger par timestamp : ts => moyennes des capteurs du lot
$dataPoints = []; // ts => ['min_sum'=>..., 'max_sum'=>..., 'count'=>...]

foreach ($rows as $row) {
    $id = $row['id'];
    $ts = (int)$row['ts'];
    if ($ts <= 0) continue;

    $useThisRow = false;

    if ($useHistory) {
        // Mode historique : on garde uniquement les lignes qui tombent
        // dans une période [start_ts ; end_ts] pour ce lot.
        if (!isset($periodsById[$id])) {
            continue;
        }
        foreach ($periodsById[$id] as $p) {
            $start = $p['start'];
            $end   = $p['end']; // peut être null = “toujours ouvert”
            if ($ts >= $start && ($end === null || $ts <= $end)) {
                $useThisRow = true;
                break;
            }
        }
        if (!$useThisRow) continue;
    } else {
        // Fallback : aucun historique exploitable pour ce lot
        // ⇒ on se base sur la config actuelle (lot du capteur)
        if (!isset($configLots[$id])) continue;
        $lotName = trim($configLots[$id]['lot'] ?? '');
        if ($lotName !== $lot) {
            continue;
        }
        $useThisRow = true;
    }

    if (!$useThisRow) continue;

    // Interprétation du RAW pour récupérer creux en L
    $interp = interpret_raw($row['raw']);
    if ($interp['creux_l_min'] === null || $interp['creux_l_max'] === null) {
        continue;
    }

    $minL = (float)$interp['creux_l_min'];
    $maxL = (float)$interp['creux_l_max'];

    if (!isset($dataPoints[$ts])) {
        $dataPoints[$ts] = [
            'min_sum' => 0.0,
            'max_sum' => 0.0,
            'count'   => 0,
        ];
    }

    $dataPoints[$ts]['min_sum'] += $minL;
    $dataPoints[$ts]['max_sum'] += $maxL;
    $dataPoints[$ts]['count']   += 1;
}

// Si rien à tracer → message simple
if (empty($dataPoints)) {
    ?>
    <!DOCTYPE html>
    <html lang="fr">
    <head>
        <meta charset="UTF-8">
        <title>Historique lot <?php echo htmlspecialchars($lot, ENT_QUOTES, 'UTF-8'); ?></title>
    </head>
    <body style="background:#050608;color:#f5f5f7;font-family:system-ui;padding:20px;">
    <a href="index.php" style="color:#f3d26b;text-decoration:none;">← Retour</a>
    <h1>Historique lot <?php echo htmlspecialchars($lot, ENT_QUOTES, 'UTF-8'); ?></h1>
    <p>Aucune donnée historique trouvée pour ce lot.</p>
    </body>
    </html>
    <?php
    exit;
}

// ---------- 6. Construire les séries triées ----------
ksort($dataPoints); // tri par timestamp croissant

$labels = [];
$minSeries = [];
$maxSeries = [];

foreach ($dataPoints as $ts => $vals) {
    $count = max(1, (int)$vals['count']);
    $avgMin = $vals['min_sum'] / $count;
    $avgMax = $vals['max_sum'] / $count;

    $labels[]    = date('d/m/Y', $ts); // Chart.js sautera des labels si trop nombreux
    $minSeries[] = round($avgMin, 1);
    $maxSeries[] = round($avgMax, 1);
}

// Encodage pour JS
$labelsJson = json_encode($labels, JSON_UNESCAPED_UNICODE);
$minJson    = json_encode($minSeries);
$maxJson    = json_encode($maxSeries);
$lotEsc     = htmlspecialchars($lot, ENT_QUOTES, 'UTF-8');

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Historique lot <?php echo $lotEsc; ?></title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body {
            background: #050608;
            color: #f5f5f7;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            margin: 0;
            padding: 20px;
        }
        .page {
            max-width: 1200px;
            margin: 0 auto;
        }
        a.back {
            color: #f3d26b;
            text-decoration: none;
            display: inline-block;
            margin-bottom: 10px;
        }
        h1 {
            margin: 0 0 10px 0;
            font-size: 22px;
        }
        .hint {
            font-size: 12px;
            color: #9ca3af;
            margin-bottom: 15px;
        }
        .chart-container {
            background: #15171c;
            border: 1px solid #262a33;
            border-radius: 10px;
            padding: 16px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.35);
        }
        canvas {
            max-height: 480px;
        }
    </style>
</head>
<body>
<div class="page">
    <a href="index.php" class="back">← Retour au dashboard barriques</a>
    <h1>Historique du lot <?php echo $lotEsc; ?></h1>
    <div class="hint">
        Creux moyen par capteur pour ce lot, avec bande entre creux min et max (en litres).<br>
        L’historique utilise <strong>lot_history.json</strong> (périodes from_ts / to_ts ou start_ts / end_ts).
    </div>

    <div class="chart-container">
        <canvas id="lotChart"></canvas>
    </div>
</div>

<script>
    const labels   = <?php echo $labelsJson; ?>;
    const minData  = <?php echo $minJson; ?>;
    const maxData  = <?php echo $maxJson; ?>;

    const ctx = document.getElementById('lotChart').getContext('2d');

    new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [
                {
                    label: 'Creux min (L)',
                    data: minData,
                    borderWidth: 2,
                    pointRadius: 2,
                    tension: 0.2,
                    borderColor: 'rgba(212,175,55,1)',
                    backgroundColor: 'rgba(212,175,55,0.25)',
                    fill: false
                },
                {
                    label: 'Creux max (L)',
                    data: maxData,
                    borderWidth: 2,
                    pointRadius: 2,
                    tension: 0.2,
                    borderColor: 'rgba(148,163,184,1)',
                    backgroundColor: 'rgba(212,175,55,0.18)',
                    // Remplir entre max et min → bande
                    fill: {
                        target: 0 // dataset index 0 = min
                    }
                }
            ]
        },
        options: {
            plugins: {
                legend: {
                    labels: {
                        color: '#f5f5f7'
                    }
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return context.dataset.label + ': ' + context.formattedValue + ' L';
                        }
                    }
                }
            },
            scales: {
                x: {
                    ticks: {
                        color: '#9ca3af',
                        autoSkip: true,
                        maxRotation: 0,
                        minRotation: 0
                    },
                    grid: {
                        color: 'rgba(75,85,99,0.3)'
                    }
                },
                y: {
                    title: {
                        display: true,
                        text: 'Creux (L)',
                        color: '#f5f5f7'
                    },
                    ticks: {
                        color: '#9ca3af'
                    },
                    grid: {
                        color: 'rgba(75,85,99,0.3)'
                    }
                }
            }
        }
    });
</script>
</body>
</html>