<?php
// history_lot.php
// Graphique d’historique par lot (creux en L, bande min/max)
// - Respecte STRICTEMENT les périodes du lot via l'historique (table lot_history, IMPORTANT pour archives)
// - Applique les offsets par capteur (table offsets_creux)
// - Axe X plus lisible (rotation + format "intelligent" selon durée)

require __DIR__ . '/barriques_lib.php';

// ---------- 0) Lot ----------
$lot = isset($_GET['lot']) ? trim((string)$_GET['lot']) : '';
if ($lot === '') {
    echo "Lot manquant.";
    exit;
}

// ---------- 2) Mesures (SQLite, phase 1 migration) ----------
$rows = [];
$measurementRows = getAllMeasurementRows();
foreach ($measurementRows as $row) {
    $id = trim((string)($row['id'] ?? ''));
    if ($id === '') continue;

    $tsInt = (int)($row['ts'] ?? 0);
    if ($tsInt <= 0) {
        $tsInt = strtotime((string)($row['date_iso'] ?? '')) ?: 0;
    }
    if ($tsInt <= 0) continue;

    $rows[] = [
        'id'       => $id,
        'raw'      => (int)($row['raw'] ?? 0),
        'ts'       => $tsInt,
        'date_iso' => trim((string)($row['date_iso'] ?? '')),
    ];
}

if (empty($rows)) {
    echo "Log vide.";
    exit;
}

// ---------- 3) config lots (fallback si pas d'historique) ----------
$configLots = loadLotsConfig(); // id => ['lot'=>..., 'barriques'=>...]

// ---------- 4) Périodes de lots (STRICT) ----------
// On construit : $periodsById[id] = [ ['start'=>ts, 'end'=>ts|null], ... ] pour CE lot uniquement
$periodsById = [];

$hist = loadLotHistory(); // format assoc : id => [ {lot, from_ts, to_ts, barriques}, ... ]
foreach ($hist as $sensorId => $periods) {
    if (!is_array($periods)) continue;
    $eId = trim((string)$sensorId);
    if ($eId === '') continue;

    foreach ($periods as $p) {
        if (!is_array($p)) continue;

        $eLot = isset($p['lot']) ? trim((string)$p['lot']) : '';
        if ($eLot === '' || $eLot !== $lot) continue;

        $sTs = (int)($p['from_ts'] ?? 0);
        $eTs = array_key_exists('to_ts', $p) && $p['to_ts'] !== null ? (int)$p['to_ts'] : null;

        if ($sTs <= 0) continue;

        if (!isset($periodsById[$eId])) $periodsById[$eId] = [];
        $periodsById[$eId][] = ['start' => $sTs, 'end' => $eTs];
    }
}

// IMPORTANT : si on a des périodes -> on les impose (archives)
$useHistory = !empty($periodsById);

// Normaliser / trier les périodes par capteur
if ($useHistory) {
    foreach ($periodsById as $sid => &$plist) {
        usort($plist, fn($a, $b) => ((int)$a['start']) <=> ((int)$b['start']));
    }
    unset($plist);
}

// ---------- 4bis) Déterminer fenêtre globale (pour afficher période suivie + bornes) ----------
$globalStart = null;
$globalEnd   = null;

if ($useHistory) {
    foreach ($periodsById as $sid => $plist) {
        foreach ($plist as $p) {
            $s = (int)$p['start'];
            $e = $p['end'] === null ? null : (int)$p['end'];

            if ($globalStart === null || $s < $globalStart) $globalStart = $s;

            // si open => on ne fixe pas globalEnd ici, on le résoudra avec les logs
            if ($e !== null) {
                if ($globalEnd === null || $e > $globalEnd) $globalEnd = $e;
            }
        }
    }
}

// Si périodes ouvertes (end=null), on prendra comme borne max la dernière mesure trouvée dans les logs
// (dans le lot), calculée plus bas.

// ---------- 5) Construire les points (agrégation par ts) ----------
$dataPoints = []; // ts => ['min_sum'=>..., 'max_sum'=>..., 'count'=>...]

foreach ($rows as $row) {
    $id = $row['id'];
    $ts = (int)$row['ts'];
    if ($ts <= 0) continue;

    $useThisRow = false;

    if ($useHistory) {
        if (!isset($periodsById[$id])) continue;

        foreach ($periodsById[$id] as $p) {
            $start = (int)$p['start'];
            $end   = $p['end']; // null=open
            if ($ts >= $start && ($end === null || $ts <= (int)$end)) {
                $useThisRow = true;
                break;
            }
        }
        if (!$useThisRow) continue;
    } else {
        // Fallback si aucun historique exploitable
        if (!isset($configLots[$id])) continue;
        $lotName = trim((string)($configLots[$id]['lot'] ?? ''));
        if ($lotName !== $lot) continue;
        $useThisRow = true;
    }

    // Offset du capteur (IMPORTANT)
    $offset = getCreuxOffsetForSensor($id);

    $interp = interpret_raw((int)$row['raw'], $offset);
    if ($interp['creux_l_min'] === null || $interp['creux_l_max'] === null) continue;

    $minL = (float)$interp['creux_l_min'];
    $maxL = (float)$interp['creux_l_max'];

    if (!isset($dataPoints[$ts])) {
        $dataPoints[$ts] = ['min_sum' => 0.0, 'max_sum' => 0.0, 'count' => 0];
    }
    $dataPoints[$ts]['min_sum'] += $minL;
    $dataPoints[$ts]['max_sum'] += $maxL;
    $dataPoints[$ts]['count']   += 1;

    // Pour les périodes ouvertes : on met à jour globalEnd via les logs retenus
    if ($globalStart !== null) {
        if ($globalEnd === null || $ts > $globalEnd) $globalEnd = $ts;
    }
}

if (empty($dataPoints)) {
    $lotEsc = htmlspecialchars($lot, ENT_QUOTES, 'UTF-8');
    ?>
    <!DOCTYPE html>
    <html lang="fr">
    <head>
        <meta charset="UTF-8">
        <title>Historique lot <?php echo $lotEsc; ?></title>
    </head>
    <body style="background:#050608;color:#f5f5f7;font-family:system-ui;padding:20px;">
    <a href="index.php" style="color:#f3d26b;text-decoration:none;">← Retour</a>
    <h1>Historique lot <?php echo $lotEsc; ?></h1>
    <p>Aucune donnée historique trouvée pour ce lot.</p>
    <?php if ($useHistory): ?>
        <p style="color:#9ca3af;font-size:12px;">(Filtrage strict par l'historique des lots)</p>
    <?php else: ?>
        <p style="color:#9ca3af;font-size:12px;">(Fallback : filtrage via la config des lots)</p>
    <?php endif; ?>
    </body>
    </html>
    <?php
    exit;
}

// ---------- 6) Séries triées ----------
ksort($dataPoints);

// Axe X "intelligent"
$firstTs = (int)array_key_first($dataPoints);
$lastTs  = (int)array_key_last($dataPoints);
$spanSec = max(0, $lastTs - $firstTs);

// Par défaut : dates, puis mois/année si période longue
$labelFormat = 'd/m/Y';
if ($spanSec >= 370 * 86400) {         // > ~1 an
    $labelFormat = 'm/Y';
} elseif ($spanSec >= 60 * 86400) {    // > ~2 mois
    $labelFormat = 'm/Y';
} elseif ($spanSec <= 3 * 86400) {     // sur quelques jours
    $labelFormat = 'd/m H:i';
}

$labels = [];
$minSeries = [];
$maxSeries = [];
$midSeries = [];

foreach ($dataPoints as $ts => $vals) {
    $count = max(1, (int)$vals['count']);
    $avgMin = $vals['min_sum'] / $count;
    $avgMax = $vals['max_sum'] / $count;

    $labels[]    = date($labelFormat, (int)$ts);
    $minSeries[] = round($avgMin, 2);
    $maxSeries[] = round($avgMax, 2);
    $midSeries[] = round(($avgMin + $avgMax) / 2.0, 2);
}

$labelsJson = json_encode($labels, JSON_UNESCAPED_UNICODE);
$minJson    = json_encode($minSeries);
$maxJson    = json_encode($maxSeries);
$midJson    = json_encode($midSeries);
$lotEsc     = htmlspecialchars($lot, ENT_QUOTES, 'UTF-8');

// Période affichée
$periodText = '';
if ($globalStart !== null && $globalEnd !== null) {
    $periodText = date('d/m/Y', (int)$globalStart) . ' → ' . date('d/m/Y', (int)$globalEnd);
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Historique lot <?php echo $lotEsc; ?></title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root{
            --bg:#050608;
            --card:#15171c;
            --border:#262a33;
            --text:#f5f5f7;
            --muted:#9ca3af;
            --accent:#f3d26b;
        }
        body { background:var(--bg); color:var(--text); font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif; margin:0; padding:20px; }
        .page { max-width:1200px; margin:0 auto; }
        a.back { color:var(--accent); text-decoration:none; display:inline-block; margin-bottom:10px; }
        a.back:hover{ opacity:.85; }
        h1 { margin:0 0 8px 0; font-size:22px; font-weight:500; letter-spacing:0.04em; }
        .hint { font-size:12px; color:var(--muted); margin-bottom:14px; line-height:1.4; }
        .chart-container {
            background:var(--card);
            border:1px solid var(--border);
            border-radius:10px;
            padding:16px;
            box-shadow:0 10px 25px rgba(0,0,0,0.35);
        }
        canvas { max-height:520px; }
        .pill{
            display:inline-block;
            padding:2px 8px;
            border:1px solid var(--border);
            border-radius:999px;
            margin-left:8px;
            color:var(--muted);
            font-size:11px;
        }
    </style>
</head>
<body>
<div class="page">
    <a href="index.php" class="back">← Retour au dashboard barriques</a>
    <h1>
        Historique du lot <?php echo $lotEsc; ?>
        <?php if ($periodText !== ''): ?>
            <span class="pill"><?php echo htmlspecialchars($periodText, ENT_QUOTES, 'UTF-8'); ?></span>
        <?php endif; ?>
    </h1>

    <div class="hint">
        Bande min/max (L) + courbe moyenne. <?php echo $useHistory ? 'Filtrage strict via l\'<strong>historique des lots</strong>.' : 'Fallback via la <strong>config des lots</strong>.'; ?>
        <br>Offsets par capteur appliqués.
    </div>

    <div class="chart-container">
        <canvas id="lotChart"></canvas>
    </div>
</div>

<script>
const labels  = <?php echo $labelsJson; ?>;
const minData = <?php echo $minJson; ?>;
const maxData = <?php echo $maxJson; ?>;
const midData = <?php echo $midJson; ?>;

new Chart(document.getElementById('lotChart').getContext('2d'), {
    type: 'line',
    data: {
        labels: labels,
        datasets: [
            // bande min -> max
            {
                label: 'Creux min (L)',
                data: minData,
                borderWidth: 0,
                pointRadius: 0,
                fill: '+1',
                backgroundColor: 'rgba(243,210,107,0.15)',
                tension: 0.25
            },
            {
                label: 'Creux max (L)',
                data: maxData,
                borderWidth: 0,
                pointRadius: 0,
                fill: false,
                tension: 0.25
            },
            // moyenne
            {
                label: 'Creux moyen (L)',
                data: midData,
                borderColor: 'rgba(243,210,107,1)',
                borderWidth: 2,
                pointRadius: 2,
                pointHoverRadius: 4,
                fill: false,
                tension: 0.25
            }
        ]
    },
    options: {
        responsive: true,
        interaction: { mode: 'nearest', intersect: false },
        plugins: {
            legend: { labels: { color:'#e5e7eb', font:{ size: 11 } } },
            tooltip: {
                callbacks: {
                    label: (ctx) => `${ctx.dataset.label}: ${Number(ctx.parsed.y).toFixed(2)} L`
                }
            }
        },
        scales: {
            x: {
                ticks: {
                    autoSkip: true,
                    maxTicksLimit: 14,
                    minRotation: 45,
                    maxRotation: 45,
                    color: '#9ca3af'
                },
                grid: { color: 'rgba(75,85,99,0.3)' }
            },
            y: {
                title: { display:true, text:'Creux (L)', color:'#f5f5f7' },
                ticks: { color:'#9ca3af' },
                grid: { color: 'rgba(75,85,99,0.3)' }
            }
        }
    }
});
</script>
</body>
</html>