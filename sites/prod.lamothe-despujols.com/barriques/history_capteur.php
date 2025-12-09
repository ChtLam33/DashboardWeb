<?php
// barriques_history.php
require __DIR__ . '/barriques_lib.php';

$logFile = __DIR__ . '/logs/barriques.log';

// Récup ID capteur
$id = isset($_GET['id']) ? trim($_GET['id']) : '';
if ($id === '') {
    http_response_code(400);
    echo "ID capteur manquant (?id=...)";
    exit;
}

$rows = [];

if (file_exists($logFile)) {
    $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        // Format log : date_iso \t id \t raw \t batt \t rssi \t fw \t ts
        $parts = explode("\t", $line);
        if (count($parts) < 7) continue;

        list($dateIso, $logId, $raw, $batt, $rssi, $fw, $ts) = $parts;
        $logId = trim($logId);

        if ($logId !== $id) continue;

        $rawInt = (int)$raw;
        $interp = interpret_raw($rawInt);

        // On ignore les lignes sans interprétation de creux
        if ($interp['creux_l_min'] === null || $interp['creux_l_max'] === null) {
            continue;
        }

        $tsInt = (int)$ts;
        if ($tsInt <= 0) {
            // fallback si jamais ts n’est pas bon
            $tsInt = strtotime($dateIso) ?: time();
        }

        $rows[] = [
            'date_iso'    => trim($dateIso),
            'ts'          => $tsInt,
            'raw'         => $rawInt,
            'creux_l_min' => (float)$interp['creux_l_min'],
            'creux_l_max' => (float)$interp['creux_l_max'],
            'creux_l_mid' => ((float)$interp['creux_l_min'] + (float)$interp['creux_l_max']) / 2.0,
        ];
    }
}

// Tri par timestamp croissant
usort($rows, function ($a, $b) {
    return $a['ts'] <=> $b['ts'];
});

$labels   = [];
$dataMin  = [];
$dataMax  = [];
$dataMid  = [];

foreach ($rows as $r) {
    // Label lisible : ex 06/12 03:34
    $ts = $r['ts'];
    $labels[]  = date('d/m H:i', $ts);
    $dataMin[] = $r['creux_l_min'];
    $dataMax[] = $r['creux_l_max'];
    $dataMid[] = $r['creux_l_mid'];
}

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Historique barrique <?php echo htmlspecialchars($id, ENT_QUOTES, 'UTF-8'); ?></title>

    <!-- Favicon goutte jaune simple (inline SVG) -->
    <link rel="icon" type="image/svg+xml"
          href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Cpath fill='%23f3d26b' d='M32 6C26 16 18 24 18 34c0 8 6.3 14 14 14s14-6 14-14C46 24 38 16 32 6z'/%3E%3C/svg%3E">

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <style>
        :root {
            --bg-page: #050608;
            --card-bg: #15171c;
            --card-border: #262a33;
            --text-main: #f5f5f7;
            --text-muted: #9ca3af;
            --accent: #f3d26b;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 20px;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background: radial-gradient(circle at top, #101318 0, #050608 45%, #000000 100%);
            color: var(--text-main);
        }

        .page {
            max-width: 1100px;
            margin: 0 auto;
        }

        .topbar {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 16px;
            padding-bottom: 8px;
            border-bottom: 1px solid #262a33;
        }

        .back-link {
            text-decoration: none;
            font-size: 22px;
            line-height: 1;
            color: var(--text-main);
        }

        .back-link:hover {
            color: var(--accent);
        }

        h1 {
            margin: 0;
            font-size: 22px;
            font-weight: 500;
            letter-spacing: 0.04em;
        }

        .card {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 10px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.35);
            padding: 16px;
        }

        .muted {
            color: var(--text-muted);
            font-size: 13px;
            margin-bottom: 10px;
        }

        canvas {
            max-height: 460px;
        }
    </style>
</head>
<body>
<div class="page">
    <div class="topbar">
        <a href="index.php" class="back-link" title="Retour au dashboard barriques">🚪</a>
        <h1>Historique capteur <?php echo htmlspecialchars($id, ENT_QUOTES, 'UTF-8'); ?></h1>
    </div>

    <div class="card">
        <?php if (empty($rows)): ?>
            <p class="muted">Aucune donnée historique trouvée pour ce capteur.</p>
        <?php else: ?>
            <p class="muted">
                Creux en <strong>litres</strong> (bande = min ↔ max, ligne = valeur moyenne).
            </p>
            <canvas id="historyChart"></canvas>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($rows)): ?>
<script>
    const labels   = <?php echo json_encode($labels, JSON_UNESCAPED_UNICODE); ?>;
    const dataMin  = <?php echo json_encode($dataMin); ?>;
    const dataMax  = <?php echo json_encode($dataMax); ?>;
    const dataMid  = <?php echo json_encode($dataMid); ?>;

    const ctx = document.getElementById('historyChart').getContext('2d');

    new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [
                // Bande min (bas de la zone)
                {
                    label: 'Creux min (L)',
                    data: dataMin,
                    borderWidth: 0,
                    pointRadius: 0,
                    fill: '+1', // remplit jusqu’au dataset suivant (max)
                    backgroundColor: 'rgba(243, 210, 107, 0.12)', // bande dorée transparente
                    tension: 0.25
                },
                // Bande max (haut de la zone)
                {
                    label: 'Creux max (L)',
                    data: dataMax,
                    borderWidth: 0,
                    pointRadius: 0,
                    fill: false,
                    backgroundColor: 'rgba(243, 210, 107, 0.12)',
                    tension: 0.25
                },
                // Courbe moyenne
                {
                    label: 'Creux moyen (L)',
                    data: dataMid,
                    borderColor: 'rgba(243, 210, 107, 1)',
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
            interaction: {
                mode: 'nearest',
                intersect: false
            },
            plugins: {
                legend: {
                    labels: {
                        color: '#e5e7eb',
                        font: { size: 11 }
                    }
                },
                tooltip: {
                    callbacks: {
                        label: (ctx) => {
                            const v = ctx.parsed.y;
                            return ctx.dataset.label + ': ' + v.toFixed(2) + ' L';
                        }
                    }
                }
            },
            scales: {
                x: {
                    ticks: {
                        color: '#9ca3af',
                        maxRotation: 60,
                        minRotation: 30
                    },
                    grid: {
                        color: 'rgba(75,85,99,0.3)'
                    }
                },
                y: {
                    title: {
                        display: true,
                        text: 'Creux (L)',
                        color: '#e5e7eb'
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
<?php endif; ?>
</body>
</html>