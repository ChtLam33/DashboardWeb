<?php
// history_capteur.php
// Historique d’un capteur : bande min/max + courbe moyenne
// + fond coloré selon le lot (lot_history.json)
// + décimation (Chart.js) pour garder un graphe propre
// + plage de dates automatique (toutes les données disponibles)
// + axe Y dynamique (s’adapte aux valeurs)

require __DIR__ . '/barriques_lib.php';

$logFile        = __DIR__ . '/logs/barriques.log';
$configLotsFile = __DIR__ . '/config_lots.json';
$lotHistoryFile = __DIR__ . '/lot_history.json';

/* ===============================
   0) ID capteur
   =============================== */
$id = isset($_GET['id']) ? trim((string)$_GET['id']) : '';
if ($id === '') {
    http_response_code(400);
    echo "ID capteur manquant (?id=...)";
    exit;
}

/* ===============================
   1) Offset (via lib)
   =============================== */
$offset = getCreuxOffsetForSensor((string)$id);

/* ===============================
   2) config_lots (optionnel, juste pour afficher le lot courant si besoin)
   =============================== */
$configLots = [];
if (file_exists($configLotsFile)) {
    $tmp = json_decode((string)file_get_contents($configLotsFile), true);
    if (is_array($tmp)) $configLots = $tmp;
}
$lotCourant = '';
if (isset($configLots[$id]['lot'])) $lotCourant = trim((string)$configLots[$id]['lot']);

/* ===============================
   3) Historique des lots pour CE capteur (lot_history.json)
   Format attendu (assoc) : hist[id] = [ {lot, from_ts, to_ts, barriques?}, ... ]
   =============================== */
$periods = [];
if (file_exists($lotHistoryFile)) {
    $hist = json_decode((string)file_get_contents($lotHistoryFile), true);
    if (is_array($hist) && isset($hist[$id]) && is_array($hist[$id])) {
        $periods = $hist[$id];
    }
}

// Normaliser/trier les périodes et sécuriser (end null => ouverte)
$normPeriods = [];
foreach ($periods as $p) {
    if (!is_array($p)) continue;
    $lotName = trim((string)($p['lot'] ?? ''));
    $fromTs  = (int)($p['from_ts'] ?? ($p['start_ts'] ?? 0));
    $toRaw   = $p['to_ts'] ?? ($p['end_ts'] ?? null);
    $toTs    = ($toRaw === null) ? null : (int)$toRaw;

    if ($lotName === '' || $fromTs <= 0) continue;
    $normPeriods[] = [
        'lot'  => $lotName,
        'from' => $fromTs,
        'to'   => $toTs, // null = open
    ];
}
usort($normPeriods, fn($a,$b) => ((int)$a['from']) <=> ((int)$b['from']));

/* Helper: lot au timestamp donné */
function findLotAtTs(array $periods, int $ts): string {
    // On parcourt les périodes (triées) et on prend celle qui match.
    // Si chevauchement, la plus récente (from la plus grande) gagne.
    $found = '';
    foreach ($periods as $p) {
        $from = (int)$p['from'];
        $to   = $p['to'];
        if ($ts < $from) continue;
        if ($to !== null && $ts > (int)$to) continue;
        $found = (string)$p['lot'];
    }
    return $found;
}

/* ===============================
   4) Lecture log -> points (ts, min, max)
   =============================== */
$records = read_barriques_log_records($logFile);
$points = [];

foreach ($records as $line) {
    $parts = preg_split("/\t+/", trim((string)$line));
    if (!$parts || count($parts) < 7) continue;

    [$dateIso, $logId, $raw, $batt, $rssi, $fw, $ts] = $parts;
    if (trim((string)$logId) !== $id) continue;

    $tsInt = (int)$ts;
    if ($tsInt <= 0) {
        $tsInt = strtotime((string)$dateIso) ?: 0;
    }
    if ($tsInt <= 0) continue;

    $interp = interpret_raw((int)$raw, (float)$offset);
    if ($interp['creux_l_min'] === null || $interp['creux_l_max'] === null) continue;

    $points[] = [
        'ts'  => $tsInt,
        'min' => (float)$interp['creux_l_min'],
        'max' => (float)$interp['creux_l_max'],
    ];
}

usort($points, fn($a,$b) => ((int)$a['ts']) <=> ((int)$b['ts']));

/* Si rien */
if (empty($points)) {
    $idEsc = htmlspecialchars($id, ENT_QUOTES, 'UTF-8');
    ?>
    <!doctype html>
    <html lang="fr">
    <head>
        <meta charset="utf-8">
        <title>Historique capteur <?php echo $idEsc; ?></title>
        <style>
            body{background:#050608;color:#f5f5f7;font-family:system-ui;padding:20px;}
            a{color:#f3d26b;text-decoration:none;}
            .card{background:#15171c;border:1px solid #262a33;border-radius:10px;padding:16px;margin-top:14px;}
        </style>
    </head>
    <body>
        <a href="index.php">← Retour</a>
        <div class="card">
            <h1 style="margin:0 0 6px 0;font-size:20px;font-weight:600;">Historique capteur <?php echo $idEsc; ?></h1>
            <p style="margin:0;color:#9ca3af;">Aucune donnée trouvée dans le log pour ce capteur.</p>
        </div>
    </body>
    </html>
    <?php
    exit;
}

/* ===============================
   5) Labels + séries + lotByIndex + bornes Y
   =============================== */
$firstTs = (int)$points[0]['ts'];
$lastTs  = (int)$points[count($points)-1]['ts'];
$spanSec = max(0, $lastTs - $firstTs);

$labelFormat = 'd/m/Y';
if ($spanSec >= 370 * 86400) {         // > ~1 an
    $labelFormat = 'm/Y';
} elseif ($spanSec >= 60 * 86400) {    // > ~2 mois
    $labelFormat = 'd/m';
} elseif ($spanSec <= 3 * 86400) {     // sur quelques jours
    $labelFormat = 'd/m H:i';
}

$labels = [];
$min = [];
$max = [];
$mid = [];
$tsArr = [];
$lotByIndex = [];

$globalMin = null;
$globalMax = null;

foreach ($points as $r) {
    $ts = (int)$r['ts'];               // <-- IMPORTANT : crochet fermé, corrige ton parse error
    $mn = (float)$r['min'];
    $mx = (float)$r['max'];
    $md = ($mn + $mx) / 2.0;

    $labels[] = date($labelFormat, $ts);
    $tsArr[]  = $ts;

    $min[] = $mn;
    $max[] = $mx;
    $mid[] = $md;

    $lotByIndex[] = findLotAtTs($normPeriods, $ts);

    $globalMin = ($globalMin === null) ? $mn : min($globalMin, $mn);
    $globalMax = ($globalMax === null) ? $mx : max($globalMax, $mx);
}

// marge axe Y
$yMin = null;
$yMax = null;
if ($globalMin !== null && $globalMax !== null) {
    $range = max(0.01, (float)$globalMax - (float)$globalMin);
    $pad = $range * 0.12; // 12% de marge
    $yMin = max(0.0, (float)$globalMin - $pad);
    $yMax = (float)$globalMax + $pad;
}

$periodText = date('d/m/Y', $firstTs) . ' → ' . date('d/m/Y', $lastTs);

$idEsc  = htmlspecialchars($id, ENT_QUOTES, 'UTF-8');
$lotEsc = htmlspecialchars($lotCourant, ENT_QUOTES, 'UTF-8');

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Historique capteur <?php echo $idEsc; ?></title>
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
        body{background:var(--bg);color:var(--text);font-family:system-ui;padding:20px;margin:0;}
        a{color:var(--accent);text-decoration:none;}
        a:hover{opacity:.85;}
        .page{max-width:1200px;margin:0 auto;}
        .card{background:var(--card);border:1px solid var(--border);border-radius:10px;padding:16px;margin-bottom:16px;box-shadow:0 10px 25px rgba(0,0,0,0.35);}
        h1{margin:0 0 6px 0;font-size:20px;font-weight:600;}
        .sub{color:var(--muted);font-size:12px;line-height:1.4;}
        .pill{display:inline-block;padding:2px 8px;border:1px solid var(--border);border-radius:999px;color:var(--muted);font-size:11px;margin-left:8px;}
        .chart-wrap{height:560px;} /* <-- hauteur réelle */
        canvas{width:100% !important;height:100% !important;}
        .hint{margin-top:10px;color:var(--muted);font-size:12px;}
        .legend-mini{color:var(--muted);font-size:12px;margin-top:6px;}
        code{color:#e5e7eb;}
    </style>
</head>
<body>
<div class="page">
    <a href="index.php">← Retour</a>

    <div class="card" style="margin-top:12px;">
        <h1>
            Historique capteur <?php echo $idEsc; ?>
            <span class="pill"><?php echo htmlspecialchars($periodText, ENT_QUOTES, 'UTF-8'); ?></span>
            <?php if ($lotCourant !== ''): ?>
                <span class="pill">Lot courant: <?php echo $lotEsc; ?></span>
            <?php endif; ?>
        </h1>
        <div class="sub">
            Bande min/max (L) + courbe moyenne. Fond coloré quand le lot change (lot_history.json).
            <br>Décimation activée pour garder un graphe lisible même sur plusieurs années.
        </div>
    </div>

    <div class="card">
        <div class="chart-wrap">
            <canvas id="chart"></canvas>
        </div>
        <div class="hint">
            Astuce : survol = valeurs exactes. Si le fond ne change pas, c’est que <code>lot_history.json</code> n’a pas de périodes pour ce capteur.
        </div>
    </div>
</div>

<script>
const labels   = <?php echo json_encode($labels, JSON_UNESCAPED_UNICODE); ?>;
const minData  = <?php echo json_encode($min); ?>;
const maxData  = <?php echo json_encode($max); ?>;
const midData  = <?php echo json_encode($mid); ?>;
const lotByIdx = <?php echo json_encode($lotByIndex, JSON_UNESCAPED_UNICODE); ?>;

// Palette stable par "lot"
function hashString(str){
  let h = 0;
  for (let i=0;i<str.length;i++) h = (h*31 + str.charCodeAt(i)) >>> 0;
  return h;
}
function lotColor(lot){
  if (!lot) return null;
  const h = hashString(lot) % 360;
  // fond discret
  return `hsla(${h}, 55%, 45%, 0.10)`;
}

// Plugin : fond coloré par segments de lot (sur l’axe X catégorie)
const lotBackgroundPlugin = {
  id: 'lotBackgroundPlugin',
  beforeDatasetsDraw(chart, args, pluginOptions) {
    const {ctx, chartArea, scales} = chart;
    const x = scales.x;
    if (!x || !chartArea) return;

    // regroupe indices contigus ayant le même lot
    let start = 0;
    while (start < lotByIdx.length) {
      const lot = lotByIdx[start] || '';
      let end = start;
      while (end + 1 < lotByIdx.length && (lotByIdx[end + 1] || '') === lot) end++;

      const fill = lotColor(lot);
      if (fill) {
        // zone : du bord gauche du tick start au bord droit du tick end
        const left  = x.getPixelForValue(start) - (x.getPixelForValue(start+1) - x.getPixelForValue(start)) / 2;
        const right = (end+1 < lotByIdx.length)
          ? x.getPixelForValue(end+1) - (x.getPixelForValue(end+1) - x.getPixelForValue(end)) / 2
          : x.getPixelForValue(end) + (x.getPixelForValue(end) - x.getPixelForValue(end-1)) / 2;

        ctx.save();
        ctx.fillStyle = fill;
        ctx.fillRect(left, chartArea.top, right - left, chartArea.bottom - chartArea.top);
        ctx.restore();
      }
      start = end + 1;
    }
  }
};

Chart.register(lotBackgroundPlugin);

new Chart(document.getElementById('chart').getContext('2d'),{
  type:'line',
  data:{
    labels: labels,
    datasets:[
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
        pointRadius: 0,           // pas de points (sur plusieurs années c’est illisible)
        pointHoverRadius: 3,
        fill: false,
        tension: 0.25
      }
    ]
  },
  options:{
    responsive:true,
    maintainAspectRatio:false,
    interaction:{mode:'nearest',intersect:false},
    plugins:{
      legend:{labels:{color:'#e5e7eb',font:{size:11}}},
      tooltip:{
        callbacks:{
          title:(items)=> items?.[0]?.label ?? '',
          label:(ctx)=> `${ctx.dataset.label}: ${Number(ctx.parsed.y).toFixed(2)} L`
        }
      },
      // Décimation : garde la forme globale sans “spaghetti”
      decimation: {
        enabled: true,
        algorithm: 'lttb',
        samples: 700
      }
    },
    scales:{
      x:{
        ticks:{
          autoSkip:true,
          maxTicksLimit: 14,
          minRotation: 45,
          maxRotation: 45,
          color:'#9ca3af'
        },
        grid:{color:'rgba(75,85,99,0.30)'}
      },
      y:{
        title:{display:true,text:'Creux (L)',color:'#f5f5f7'},
        ticks:{color:'#9ca3af'},
        grid:{color:'rgba(75,85,99,0.30)'},
        <?php if ($yMin !== null && $yMax !== null): ?>
        suggestedMin: <?php echo json_encode((float)$yMin); ?>,
        suggestedMax: <?php echo json_encode((float)$yMax); ?>,
        <?php endif; ?>
      }
    }
  }
});
</script>

</body>
</html>