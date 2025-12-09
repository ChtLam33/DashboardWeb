<?php
// --- Lecture du CACHE JSON ---
$cacheFile   = __DIR__ . "/cache_dashboard.json";
$configFile  = __DIR__ . "/config_cuves.json";
$historyFile = __DIR__ . "/history_lots.json";

$cuves   = [];
$config  = [];
$history = [];
$historyDisplay = [];

// Charger le cache (dernières mesures)
if (file_exists($cacheFile)) {
    $json  = file_get_contents($cacheFile);
    $cuves = json_decode($json, true) ?: [];
}

// Date/heure de dernière mise à jour du cache
$lastUpdate = file_exists($cacheFile)
    ? date("d/m/Y H:i:s", filemtime($cacheFile))
    : "N/A";

// --- Helpers ---
function valf($arr, $key, $default = null) {
    return isset($arr[$key]) && $arr[$key] !== '' ? $arr[$key] : $default;
}
function nf($v, $dec = 1) {
    return is_numeric($v) ? number_format((float)$v, $dec, ',', '') : '';
}

// --- Charger la config (pour ordre + lot + couleur + hauteur max) ---
$lotById   = [];
$colorById = [];
$hMaxById  = [];
$config    = [];

if (file_exists($configFile)) {
    $config = json_decode(file_get_contents($configFile), true);
    if (!is_array($config)) $config = [];
}

// Mapping ID → lot / couleur / hauteur max (dashboard)
foreach ($config as $cfg) {
    if (!empty($cfg['id'])) {
        $id = $cfg['id'];

        $lotById[$id]   = isset($cfg['lot']) ? $cfg['lot'] : '';
        $colorById[$id] = isset($cfg['couleur']) ? $cfg['couleur'] : '';

        // Hauteur max liquide saisie dans le dashboard (champ "Hauteur max")
        $hMaxById[$id]  = (isset($cfg['hauteurMaxLiquide']) && $cfg['hauteurMaxLiquide'] !== '')
            ? (float)$cfg['hauteurMaxLiquide']
            : null;
    }
}

// --- Réordonner les cuves selon l'ordre de config_cuves.json ---
if (!empty($config) && !empty($cuves)) {
    $orderIds = [];
    foreach ($config as $cfg) {
        if (!empty($cfg['id'])) {
            $orderIds[] = $cfg['id'];
        }
    }

    if (!empty($orderIds)) {
        $byId = [];
        $noId = [];
        foreach ($cuves as $c) {
            $id = isset($c['id']) ? $c['id'] : null;
            if ($id) {
                $byId[$id] = $c;
            } else {
                $noId[] = $c;
            }
        }

        $ordered = [];
        foreach ($orderIds as $id) {
            if (isset($byId[$id])) {
                $ordered[] = $byId[$id];
                unset($byId[$id]);
            }
        }
        foreach ($byId as $c) {
            $ordered[] = $c;
        }
        foreach ($noId as $c) {
            $ordered[] = $c;
        }

        $cuves = $ordered;
    }
}

// --- Calcul des totaux par lot ---
$lotsTotals  = [];
$totalGlobal = 0.0;

foreach ($cuves as $c) {
    $id  = valf($c, 'id', '');
    $vol = valf($c, 'volume_hl', null);

    if ($id === '' || !is_numeric($vol)) continue;

    $lotName = isset($lotById[$id]) && trim($lotById[$id]) !== ''
        ? trim($lotById[$id])
        : 'Sans lot';

    if (!isset($lotsTotals[$lotName])) {
        $lotsTotals[$lotName] = 0.0;
    }
    $lotsTotals[$lotName] += (float)$vol;
    $totalGlobal          += (float)$vol;
}

// --- Charger l'historique des snapshots ---
if (file_exists($historyFile)) {
    $history = json_decode(file_get_contents($historyFile), true);
    if (!is_array($history)) {
        $history = [];
    }
}

// Trier par date décroissante (les plus récents d'abord)
if (!empty($history)) {
    usort($history, function($a, $b) {
        $da = isset($a['datetime']) ? $a['datetime'] : '';
        $db = isset($b['datetime']) ? $b['datetime'] : '';
        return strcmp($db, $da); // on veut le plus récent en premier
    });
    // On n'affiche que les 50 plus récents
    $historyDisplay = array_slice($history, 0, 50);
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Dashboard Cuves – Château Lamothe</title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<!-- Favicon goutte jaune simple (inline SVG) -->
<link rel="icon" type="image/svg+xml"
      href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Cpath fill='%23f3d26b' d='M32 6C26 16 18 24 18 34c0 8 6.3 14 14 14s14-6 14-14C46 24 38 16 32 6z'/%3E%3C/svg%3E">

<style>
:root{
  --bg:#111;--card:#1b1b1b;--text:#ddd;--muted:#9aa0a6;
  --gold:#f3d26b;--gold2:#b38728;
  --fill1:#ffe57e;--fill2:#fbc02d;
  --ok:#00e676;--mid:#ffeb3b;--bad:#ff1744;--unk:#555;
}
*{box-sizing:border-box}
body{
  margin:0;
  background:var(--bg);
  color:var(--text);
  font-family:Segoe UI,Roboto,Arial,sans-serif
}

/* HEADER sobre, proche de la page parente */
header{
  background:#111;
  color:#f5f5f5;
  padding:10px 16px;
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:12px;
  flex-wrap:wrap;
  border-bottom:1px solid #242424;
}
.header-left{
  display:flex;
  align-items:center;
  gap:10px;
}
header h1{
  margin:0;
  font-size:1.0rem;
  font-weight:500;
}
#updateTime{
  font-size:.85rem;
  opacity:.9;
  margin-left:6px;
}

/* Lien retour (porte) */
.exit-link{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  width:28px;
  height:28px;
  border-radius:999px;
  border:1px solid #333;
  color:#ddd;
  text-decoration:none;
  font-size:1rem;
  background:#181818;
  transition:.2s;
}
.exit-link:hover{
  border-color:var(--gold);
  color:var(--gold);
  background:#202020;
}

.actions button{
  background:#0000;
  border:1px solid rgba(255,255,255,.45);
  color:#fff;
  padding:8px 12px;
  border-radius:8px;
  font-weight:600;
  cursor:pointer
}
.actions button:hover{background:rgba(255,255,255,.12)}

main{display:grid;gap:12px;padding:12px}
main{grid-template-columns: repeat(2, minmax(180px, 1fr));}
@media (min-width:900px) and (orientation:landscape){
  main{grid-template-columns: repeat(5, 1fr);}
}

.cuve{
  background:var(--card);
  border-radius:12px;
  box-shadow:0 2px 8px rgba(0,0,0,.35);
  padding:10px;
  text-align:center;
  transition:.2s;
  position:relative;
  overflow:hidden;
}
.cuve:hover{transform:translateY(-2px);box-shadow:0 6px 16px rgba(0,0,0,.45)}

.cuve.dragging{
  opacity:.7;
  outline:1px dashed var(--gold);
}

/* Ligne tête : wifi + nom + lot */
.head{
  display:flex;
  align-items:center;
  justify-content:center;
  gap:8px;
  margin-bottom:6px;
}
.head h2{
  margin:0;
  font-size:.95rem;
  font-weight:700;
  white-space:nowrap;
  overflow:hidden;
  text-overflow:ellipsis;
}
.head .title-block{
  display:flex;
  align-items:baseline;
  gap:4px;
}
.head .lot-label{
  font-size:.8rem;
  opacity:.9;
}

.wifi-icon{
  width:18px;height:18px;display:inline-block;
  mask:url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" fill="white" viewBox="0 0 24 24"><path d="M12 20a2 2 0 1 0 0-4 2 2 0 0 0 0 4zm6.93-5.36a8.001 8.001 0 0 0-13.86 0l1.69 1.13a6 6 0 0 1 10.48 0l1.69-1.13zM12 4a16 16 0 0 0-9.14 2.83l1.63 1.16A14 14 0 0 1 12 6a14 14 0 0 1 7.51 2.01l1.63-1.16A16 16 0 0 0 12 4zm0 4a12 12 0 0 0-7.2 2.4l1.64 1.16A10 10 0 0 1 12 10a10 10 0 0 1 5.56 1.56l1.64-1.16A12 12 0 0 0 12 8z"/></svg>') no-repeat center;
  mask-size:contain;
  background-color:var(--unk);
}

/* Wi-Fi barré quand capteur hors ligne */
.cuve.offline .wifi-icon{
  position:relative;
}
.cuve.offline .wifi-icon::after{
  content:"";
  position:absolute;
  top:3px;left:3px;right:3px;bottom:3px;
  border-top:2px solid #fff;
  transform:rotate(35deg);
}

/* --- Cuve cylindrique avec effet de profondeur --- */
.bar{
  position:relative;
  height:110px;
  border-radius:50% / 8%;
  overflow:hidden;
  background:radial-gradient(ellipse at center, #2c2c2c 0%, #1e1e1e 80%);
  border:1px solid #2e2e2e;
  box-shadow:
    inset 6px 0 10px rgba(0,0,0,0.4),
    inset -6px 0 10px rgba(0,0,0,0.4),
    inset 0 -6px 12px rgba(0,0,0,0.25);
}
.bar::before{
  content:"";
  position:absolute;
  top:0;
  left:3%;
  width:8%;
  height:100%;
  background:linear-gradient(180deg,rgba(255,255,255,0.15),rgba(255,255,255,0));
  pointer-events:none;
  filter:blur(1px);
  opacity:0.3;
}

.canvas-wave{
  position:absolute;
  left:0;
  bottom:0;
  width:100%;
  height:100%;
}
.reflet{
  position:absolute;
  top:0;
  left:0;
  width:100%;
  height:30px;
  background:linear-gradient(to bottom,rgba(255,255,255,0.2),rgba(255,255,255,0));
  opacity:.3;
  pointer-events:none;
}

.infos{margin-top:8px;font-size:.82rem;color:#cfcfcf;line-height:1.4}
.muted{color:var(--muted)}
#loading{display:none;text-align:center;padding:8px;background:#fff3be;color:#b17f00}

/* Handle de drag (4 flèches) */
.drag-handle{
  position:absolute;
  right:6px;
  bottom:6px;
  width:20px;
  height:20px;
  border-radius:6px;
  border:1px solid #444;
  font-size:12px;
  display:flex;
  align-items:center;
  justify-content:center;
  cursor:grab;
  background:rgba(255,255,255,0.03);
  color:#ccc;
}
.drag-handle:hover{
  background:rgba(255,255,255,0.08);
}

/* POPUP PARAMÈTRES */
.param-popup{
  display:none;position:fixed;inset:0;
  background:rgba(0,0,0,.45);justify-content:center;align-items:center;z-index:10
}
.popup-content{
  background:#101010;color:#eee;padding:16px;border-radius:12px;
  width:90%;max-width:820px;border:1px solid #2a2a2a
}
.popup-content h3{margin:.2rem 0 10px;color:var(--gold)}
.param-table{width:100%;border-collapse:collapse}
.param-table th,.param-table td{
  border-bottom:1px solid #2a2a2a;padding:6px 8px;text-align:left;font-size:.9rem
}
.param-table th{background:#141414;color:#d8c07a}
.param-table input, .param-table select{
  width:100%;border:1px solid #3a3a3a;border-radius:6px;
  padding:6px;background:#0e0e0e;color:#eee;
  font-size:.85rem;
}
.param-table select{
  padding-right:20px;
}
.popup-content button{
  background:var(--gold2);color:#000;border:none;padding:8px 14px;border-radius:8px;cursor:pointer
}
.save-btn{margin-top:10px}

/* Résumé des volumes par lot */
.lots-summary{
  padding:0 12px 16px 12px;
  margin-top:-4px;
}
.lots-summary-header{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:8px;
}
.lots-summary h2{
  margin:6px 0;
  font-size:.95rem;
  color:var(--gold);
}
.snapshot-btn{
  border:none;
  background:rgba(243,210,107,0.12);
  color:var(--gold);
  border-radius:50%;
  width:30px;
  height:30px;
  cursor:pointer;
  font-size:1rem;
  display:flex;
  align-items:center;
  justify-content:center;
}
.snapshot-btn:hover{
  background:rgba(243,210,107,0.25);
}
.lots-summary table{
  width:100%;
  border-collapse:collapse;
  font-size:.85rem;
}
.lots-summary th, .lots-summary td{
  padding:4px 6px;
  border-bottom:1px solid #2a2a2a;
}
.lots-summary th{
  text-align:left;
  background:#171717;
}
.lots-summary tfoot td{
  font-weight:700;
  border-top:1px solid #444;
}

/* Historique */
.history-section{
  padding:0 12px 16px 12px;
}
.history-section h2{
  margin:6px 0;
  font-size:.95rem;
  color:var(--gold);
}
.history-table{
  width:100%;
  border-collapse:collapse;
  font-size:.85rem;
}
.history-table th, .history-table td{
  padding:4px 6px;
  border-bottom:1px solid #2a2a2a;
}
.history-table th{
  text-align:left;
  background:#171717;
}
.history-toggle{
  width:24px;
  text-align:center;
  cursor:pointer;
  font-weight:bold;
}
.history-toggle:hover{
  background:#222;
}
.history-details td{
  background:#151515;
}
.history-lots-inner{
  width:100%;
  border-collapse:collapse;
  font-size:.8rem;
}
.history-lots-inner th, .history-lots-inner td{
  padding:3px 4px;
  border-bottom:1px solid #2a2a2a;
}
</style>
</head>
<body>
<header>
  <div class="header-left">
    <a href="https://prod.lamothe-despujols.com/" class="exit-link"
       title="Retour au site principal">🚪</a>
    <h1>Cuves - Château Lamothe <span id="updateTime">(<?= htmlspecialchars($lastUpdate) ?>)</span></h1>
  </div>
  <div class="actions">
    <button onclick="refreshData()">🔄 Actualiser</button>
    <button onclick="purgeSensors()" title="Supprimer les capteurs hors ligne (ils seront réinitialisés)">🧹 Purger</button>
    <button onclick="showParamPopup()">⚙️ Paramètres</button>
  </div>
</header>

<div id="loading">⏳ Actualisation en cours...</div>

<main id="cuvesContainer">
<?php if (empty($cuves)): ?>
  <p style="grid-column:1/-1;text-align:center;">Aucune donnée affichée. Cliquez sur 🔄 pour actualiser.</p>
<?php else: ?>
  <?php foreach ($cuves as $c):
    $id     = htmlspecialchars(valf($c,'id',''));
    $nom    = htmlspecialchars(valf($c,'cuve','(sans nom)'));
    $pourc  = (float)valf($c,'pourcentage',0);
    $vol    = valf($c,'volume_hl',null);
    $cap    = valf($c,'capacite_hl',null);
    $corr   = valf($c,'correction',null);
    $dist   = valf($c,'distance_cm',null);
    $hPlein = valf($c,'hauteurPlein',null);
    $hCuve  = valf($c,'hauteurCuve',null);
    $rssi   = isset($c['rssi']) ? (int)$c['rssi'] : null;
    $dtStr  = valf($c,'datetime',null);

    $ageSec     = null;
    $isOffline  = false;
    $offlineThreshold = 25;

    if ($dtStr) {
      $ts = strtotime($dtStr);
      if ($ts !== false) {
        $ageSec = time() - $ts;
        if ($ageSec < 0) $ageSec = 0;
        if ($ageSec > $offlineThreshold) {
          $isOffline = true;
        }
      }
    }

    // Couleur titre / lot et liquide en fonction de la config
    $configColorKey = isset($colorById[$id]) ? trim($colorById[$id]) : '';

    switch ($configColorKey) {
      case 'jauneClair':
        $titleColor   = '#ffe57e';
        $liquidColor1 = '#fff59d';
        $liquidColor2 = '#ffe082';
        break;

      case 'jauneFonce':
        $titleColor   = '#c79a00';
        $liquidColor1 = '#fbc02d';
        $liquidColor2 = '#c79a00';
        break;

      case 'vert':
        $titleColor   = '#00e676';
        $liquidColor1 = '#69f0ae';
        $liquidColor2 = '#00e676';
        break;

      case 'gris':
        $titleColor   = '#9aa0a6';
        $liquidColor1 = '#cfd8dc';
        $liquidColor2 = '#90a4ae';
        break;

      case 'violet':
        $titleColor   = '#b388ff';
        $liquidColor1 = '#e1bee7';
        $liquidColor2 = '#b388ff';
        break;

      case 'rouge':
        $titleColor   = '#ff5252';
        $liquidColor1 = '#ff8a80';
        $liquidColor2 = '#ff5252';
        break;

      case 'bleu':
        $titleColor   = '#42a5f5';
        $liquidColor1 = '#90caf9';
        $liquidColor2 = '#42a5f5';
        break;

      case 'jaune': // compat éventuelle
      case '':
      default:
        $titleColor   = 'var(--gold)';
        $liquidColor1 = '#ffe57e';
        $liquidColor2 = '#fbc02d';
        break;
    }

    // Lot pour cette cuve
    $lotName = isset($lotById[$id]) ? trim($lotById[$id]) : '';

    // Couleur du pictogramme Wi-Fi
    $wifiColor = 'var(--unk)';
    if ($isOffline) {
      $wifiColor = 'var(--bad)';
    } elseif ($rssi !== null) {
      if     ($rssi > -65) $wifiColor = 'var(--ok)';
      elseif ($rssi > -75) $wifiColor = 'var(--mid)';
      else                 $wifiColor = 'var(--bad)';
    }

    // Détection obstacle / couvercle : distance < Hauteur max (dashboard)
    $hasObstacle = false;
    $obstacleMsg = '';

    $hMaxDash = isset($hMaxById[$id]) ? $hMaxById[$id] : null;

    if ($hMaxDash !== null && $dist !== null && is_numeric($hMaxDash) && is_numeric($dist)) {
        if ((float)$dist < (float)$hMaxDash) {
            $hasObstacle = true;
            $obstacleMsg = ' (' . (int)$dist . ' < ' . (int)$hMaxDash . ')';
        }
    }
  ?>
  <div class="cuve<?= $isOffline ? ' offline' : '' ?>"
       data-pourc="<?= $pourc ?>"
       data-id="<?= $id ?>"
       data-liquid1="<?= htmlspecialchars($liquidColor1) ?>"
       data-liquid2="<?= htmlspecialchars($liquidColor2) ?>"
       draggable="true">
    <div class="head">
      <span class="wifi-icon" style="background-color:<?= $wifiColor ?>"
        title="<?php
          if ($isOffline) {
            echo 'Capteur hors ligne'.($dtStr ? ' – dernière mesure : '.$dtStr : '');
          } else {
            if ($rssi !== null) {
              echo 'RSSI: '.$rssi.' dBm';
            } else {
              echo 'RSSI indisponible';
            }
          }
        ?>"></span>
      <div class="title-block">
        <h2 style="color:<?= htmlspecialchars($titleColor) ?>"><?= $nom ?></h2>
        <?php if ($lotName !== ''): ?>
          <span class="lot-label" style="color:<?= htmlspecialchars($titleColor) ?>">
            (<?= htmlspecialchars($lotName) ?>)
          </span>
        <?php endif; ?>
      </div>
    </div>

    <div class="bar">
      <canvas class="canvas-wave"></canvas>
      <div class="reflet"></div>
    </div>

    <div class="infos">
      <div><strong><?= nf($pourc,1) ?> %</strong> rempli</div>
      <div>
        <?= nf($vol,2) ?> / <?= nf($cap,2) ?> HL
        <span class="muted">(+<?= nf($corr,2) ?> HL)</span>
      </div>
      <?php if($hPlein!==null && $hCuve!==null): ?>
        <div>Hauteur : <?= nf($hPlein,1) ?> / <?= nf($hCuve,1) ?> cm</div>
      <?php endif; ?>
      <?php if($dist!==null): ?>
        <div>Distance : <?= (int)$dist ?> cm</div>
      <?php endif; ?>

      <?php if($hasObstacle): ?>
        <div class="muted" style="color:#ffb74d;font-size:.8rem;">
          ⚠️ Mesure incohérente (couvercle ou obstacle possible)<?= $obstacleMsg ?>
        </div>
      <?php endif; ?>

      <?php if($isOffline && $dtStr): ?>
        <div class="muted" style="font-size:.75rem;color:#ff6b6b;">
          ⚠ Capteur hors ligne – dernière mesure : <?= htmlspecialchars($dtStr) ?>
        </div>
      <?php endif; ?>
    </div>

    <div class="drag-handle" title="Réorganiser cette cuve">⠿</div>
  </div>
  <?php endforeach; ?>
<?php endif; ?>
</main>

<?php if (!empty($lotsTotals)): ?>
<section class="lots-summary">
  <div class="lots-summary-header">
    <h2>Volumes par lot</h2>
    <button class="snapshot-btn" type="button" onclick="saveLotsSnapshot()" title="Enregistrer un instantané des volumes par lot">
      💾
    </button>
  </div>
  <table>
    <thead>
      <tr>
        <th>Lot</th>
        <th>Volume total (HL)</th>
        <th>Équiv. barriques</th>
        <th>Équiv. bouteilles</th>
        <th>Proportion</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($lotsTotals as $lotName => $valHL): ?>
        <tr>
          <td><?= htmlspecialchars($lotName) ?></td>
          <td><?= nf($valHL,2) ?></td>
          <td><?= nf($valHL / 2.25, 2) ?></td>
          <td><?= number_format($valHL * 133, 0, ',', ' ') ?></td>
          <td><?= $totalGlobal > 0 ? round(($valHL / $totalGlobal) * 100) : 0 ?>%</td>
        </tr>
      <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr>
        <td>Total</td>
        <td><?= nf($totalGlobal,2) ?></td>
        <td><?= nf($totalGlobal / 2.25, 2) ?></td>
        <td><?= number_format($totalGlobal * 133, 0, ',', ' ') ?></td>
        <td>100%</td>
      </tr>
    </tfoot>
  </table>
</section>
<?php endif; ?>

<?php if (!empty($historyDisplay)): ?>
<section class="history-section">
  <h2>Historique des enregistrements</h2>
  <table class="history-table">
    <thead>
      <tr>
        <th style="width:28px;"></th>
        <th>Date</th>
        <th>Total (HL)</th>
        <th>Commentaire</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($historyDisplay as $idx => $snap):
        $rowId = 'hist' . $idx;
        $snapDate = isset($snap['datetime']) ? $snap['datetime'] : '';
        $snapTotal = isset($snap['total_hl']) ? $snap['total_hl'] : null;
        $snapComment = isset($snap['comment']) ? $snap['comment'] : '';
        $snapLots = (isset($snap['lots']) && is_array($snap['lots'])) ? $snap['lots'] : [];
      ?>
      <tr class="history-row" data-details-id="<?= htmlspecialchars($rowId) ?>">
        <td class="history-toggle">+</td>
        <td><?= htmlspecialchars($snapDate) ?></td>
        <td><?= nf($snapTotal, 2) ?></td>
        <td><?= htmlspecialchars($snapComment) ?></td>
      </tr>
      <?php if (!empty($snapLots)): ?>
      <tr class="history-details" id="<?= htmlspecialchars($rowId) ?>" style="display:none;">
        <td colspan="4">
          <table class="history-lots-inner">
            <thead>
              <tr>
                <th>Lot</th>
                <th>Volume (HL)</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($snapLots as $lotInfo):
                $hLotName = isset($lotInfo['lot']) ? $lotInfo['lot'] : '';
                $hLotVol  = isset($lotInfo['volume_hl']) ? $lotInfo['volume_hl'] : null;
              ?>
              <tr>
                <td><?= htmlspecialchars($hLotName) ?></td>
                <td><?= nf($hLotVol, 2) ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </td>
      </tr>
      <?php endif; ?>
      <?php endforeach; ?>
    </tbody>
  </table>
  <p class="muted" style="margin-top:4px;font-size:.75rem;">
    Les enregistrements les plus récents sont affichés en premier. (limite ~300 enregistrements en mémoire)
  </p>
</section>
<?php endif; ?>

<div class="param-popup" id="paramPopup">
  <div class="popup-content">
    <h3>Paramètres des cuves</h3>
    <div id="paramContainer">Chargement...</div>
    <button class="save-btn" onclick="saveConfig()">💾 Enregistrer les modifications</button>
    <button onclick="hideParamPopup()">Fermer</button>
  </div>
</div>

<script>
// --- Actualisation des données ---
async function refreshData(){
  const loading=document.getElementById('loading');
  loading.style.display='block';loading.innerText="⏳ Mise à jour...";
  try{
    await fetch('update_config_from_csv.php?nocache='+Date.now(),{cache:"no-store"});
    const r=await fetch('update_cache.php?nocache='+Date.now(),{cache:"no-store"});
    const d=await r.json();
    if(d.status==="OK"){loading.innerText="✅ Données actualisées";setTimeout(()=>location.reload(),700);}
    else{loading.innerText="⚠️ Erreur actualisation";setTimeout(()=>loading.style.display='none',2000);}
  }catch(e){loading.innerText="⚠️ Erreur serveur";setTimeout(()=>loading.style.display='none',2000);}
}

// --- Purge des capteurs hors ligne ---
async function purgeSensors(){
  if (!confirm(
    "ATTENTION :\n\n" +
    "- Tous les capteurs considérés comme HORS LIGNE vont être supprimés du dashboard.\n" +
    "- Leurs paramètres (nom de cuve, hauteurs, lot, couleur...) seront perdus.\n" +
    "- Lorsqu’ils se reconnecteront, ils apparaîtront comme de nouveaux capteurs avec les paramètres par défaut.\n\n" +
    "Continuer ?"
  )) {
    return;
  }

  const loading = document.getElementById('loading');
  loading.style.display = 'block';
  loading.innerText = "🧹 Purge en cours...";

  try {
    const res  = await fetch('purge_cuves.php', { method: 'POST' });
    const data = await res.json();

    if (data.status === "OK") {
      const removed = data.removed ?? 0;
      loading.innerText =
        `✅ Purge terminée : ${removed} capteur(s) hors ligne supprimé(s).\n` +
        "Regénération des données...";
      setTimeout(() => {
        refreshData();
      }, 800);
    } else {
      loading.innerText = "⚠️ Erreur lors de la purge.";
      setTimeout(() => { loading.style.display = 'none'; }, 3000);
    }
  } catch (e) {
    console.error(e);
    loading.innerText = "⚠️ Erreur réseau lors de la purge.";
    setTimeout(() => { loading.style.display = 'none'; }, 3000);
  }
}

// --- Enregistrement d'un snapshot des volumes par lot ---
async function saveLotsSnapshot(){
  const comment = prompt("Commentaire pour cet enregistrement (ex : 'avant collage') :","");
  if(comment === null) return;

  const loading = document.getElementById('loading');
  loading.style.display='block';
  loading.innerText="⏳ Enregistrement de l'instantané...";

  try{
    const res = await fetch('save_history.php',{
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify({comment})
    });
    const data = await res.json();
    if(data.status === "OK"){
      loading.innerText = "✅ Instantané enregistré. Pense à actualiser pour voir l'historique à jour.";
      setTimeout(()=>{ loading.style.display='none'; }, 3000);
    }else{
      loading.innerText = "⚠️ Erreur lors de l'enregistrement de l'instantané.";
      setTimeout(()=>{ loading.style.display='none'; }, 3000);
    }
  }catch(e){
    loading.innerText = "⚠️ Erreur réseau lors de l'enregistrement.";
    setTimeout(()=>{ loading.style.display='none'; }, 3000);
  }
}

// --- Paramètres ---
let configData=[];
async function showParamPopup(){
  const p=document.getElementById('paramPopup');p.style.display='flex';
  const c=document.getElementById('paramContainer');
  c.innerHTML="Chargement...";
  try{
    const res=await fetch('get_config.php?nocache='+Date.now());
    configData=await res.json();
    if(!Array.isArray(configData)){c.innerHTML="Erreur.";return;}
    let html=`<table class='param-table'>
    <tr>
      <th>ID</th>
      <th>Nom</th>
      <th>Couleur</th>
      <th>Lot</th>
      <th>Capteur→Fond</th>
      <th>Hauteur max</th>
      <th>Diamètre</th>
      <th>Aj. HL</th>
    </tr>`;
    configData.forEach((cu,i)=>{
      const col = cu.couleur ?? '';
      html+=`<tr>
      <td>${cu.id}</td>
      <td><input value="${cu.nomCuve??''}" data-i="${i}" data-k="nomCuve"></td>
      <td>
        <select data-i="${i}" data-k="couleur">
          <option value="" ${col===''?'selected':''}>Jaune (défaut)</option>
          <option value="jauneClair" ${col==='jauneClair'?'selected':''}>Jaune clair</option>
          <option value="jauneFonce" ${col==='jauneFonce'?'selected':''}>Jaune foncé</option>
          <option value="vert" ${col==='vert'?'selected':''}>Vert</option>
          <option value="gris" ${col==='gris'?'selected':''}>Gris</option>
          <option value="violet" ${col==='violet'?'selected':''}>Violet</option>
          <option value="rouge" ${col==='rouge'?'selected':''}>Rouge</option>
          <option value="bleu" ${col==='bleu'?'selected':''}>Bleu</option>
        </select>
      </td>
      <td><input value="${cu.lot??''}" data-i="${i}" data-k="lot"></td>
      <td><input value="${cu.hauteurCapteurFond??''}" data-i="${i}" data-k="hauteurCapteurFond" type="number" step="0.1"></td>
      <td><input value="${cu.hauteurMaxLiquide??''}" data-i="${i}" data-k="hauteurMaxLiquide" type="number" step="0.1"></td>
      <td><input value="${cu.diametreCuve??''}" data-i="${i}" data-k="diametreCuve" type="number" step="0.1"></td>
      <td><input value="${cu.AjustementHL??''}" data-i="${i}" data-k="AjustementHL" type="number" step="0.01"></td>
      </tr>`;
    });
    html+=`</table>`;
    c.innerHTML=html;
    c.querySelectorAll('input,select').forEach(inp=>{
      inp.addEventListener('input',e=>{
        const i=e.target.dataset.i,k=e.target.dataset.k;configData[i][k]=e.target.value;
      });
      inp.addEventListener('change',e=>{
        const i=e.target.dataset.i,k=e.target.dataset.k;configData[i][k]=e.target.value;
      });
    });
  }catch(e){
    c.innerHTML="Impossible de charger les paramètres.";
  }
}

async function saveConfig(){
  const c = document.getElementById('paramContainer');

  let statusEl = document.getElementById('saveStatus');
  if (!statusEl) {
    statusEl = document.createElement('p');
    statusEl.id = 'saveStatus';
    statusEl.className = 'muted';
    c.appendChild(statusEl);
  }

  statusEl.style.color = '';
  statusEl.textContent = "⏳ Sauvegarde en cours...";

  try{
    const res = await fetch('save_config.php',{
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body:JSON.stringify(configData)
    });
    const d = await res.json();

    if (d.status === "OK") {
      let remaining = 60;
      statusEl.style.color = '#7CFC00';
      statusEl.textContent =
        `✅ Sauvegardé. Config prise en compte dans ${remaining}s (penser à actualiser).`;

      const intervalId = setInterval(()=>{
        remaining--;
        if (remaining > 0) {
          statusEl.textContent =
            `✅ Sauvegardé. Config prise en compte dans ${remaining}s (penser à actualiser).`;
        } else {
          clearInterval(intervalId);
          statusEl.textContent =
            "✅ Sauvegardé. Config est prise en compte. (penser à actualiser)";
        }
      }, 1000);

    } else {
      statusEl.style.color = '#ff6b6b';
      statusEl.textContent = "⚠️ Erreur lors de l'enregistrement de la configuration.";
    }

  } catch(e){
    statusEl.style.color = '#ff6b6b';
    statusEl.textContent = "⚠️ Impossible de contacter le serveur.";
  }
}
function hideParamPopup(){document.getElementById('paramPopup').style.display='none';}

// --- Animation de vague fluide ---
document.querySelectorAll('.canvas-wave').forEach((canvas,i)=>{
  const ctx=canvas.getContext('2d');
  let w,h,phase=Math.random()*Math.PI*2;
  function resize(){w=canvas.width=canvas.offsetWidth;h=canvas.height=canvas.offsetHeight;}
  window.addEventListener('resize',resize);resize();
  const parent=canvas.closest('.cuve');
  const pourc=parseFloat(parent.dataset.pourc)||0;
  const c1 = parent.dataset.liquid1 || '#ffe57e';
  const c2 = parent.dataset.liquid2 || '#fbc02d';
  function draw(){
    ctx.clearRect(0,0,w,h);
    const amp=2, freq=0.04, speed=0.02;
    const level=h*(1-pourc/100);
    ctx.beginPath();
    ctx.moveTo(0,h);
    for(let x=0;x<=w;x++){
      const y=level+Math.sin(x*freq+phase)*amp;
      ctx.lineTo(x,y);
    }
    ctx.lineTo(w,h);
    ctx.closePath();
    const grd=ctx.createLinearGradient(0,level,w,h);
    grd.addColorStop(0,c1);
    grd.addColorStop(1,c2);
    ctx.fillStyle=grd;
    ctx.fill();
    phase+=speed;
    requestAnimationFrame(draw);
  }
  draw();
});

// --- Drag & Drop des cartes de cuves + autosave ordre ---
const container = document.getElementById('cuvesContainer');
let dragSrcEl = null;

function handleDragStart(e){
  dragSrcEl = this;
  this.classList.add('dragging');
  if(e.dataTransfer){
    e.dataTransfer.effectAllowed = 'move';
    e.dataTransfer.setData('text/plain', this.dataset.id || '');
  }
}

function handleDragOver(e){
  e.preventDefault();
  const target = e.target.closest('.cuve');
  if(!target || target === dragSrcEl || target.parentNode !== container) return;

  const rect = target.getBoundingClientRect();
  const clientY = e.clientY || (e.touches && e.touches[0].clientY);
  const offset = clientY - rect.top;
  const midpoint = rect.height / 2;

  if(offset > midpoint){
    container.insertBefore(dragSrcEl, target.nextSibling);
  }else{
    container.insertBefore(dragSrcEl, target);
  }
}

function handleDrop(e){
  e.preventDefault();
  return false;
}

function handleDragEnd(e){
  this.classList.remove('dragging');
  saveNewOrder();
}

function initDragAndDrop(){
  const cards = container.querySelectorAll('.cuve');
  cards.forEach(card=>{
    card.addEventListener('dragstart', handleDragStart);
    card.addEventListener('dragover', handleDragOver);
    card.addEventListener('drop', handleDrop);
    card.addEventListener('dragend', handleDragEnd);
  });
}

async function saveNewOrder(){
  const ids = Array.from(container.querySelectorAll('.cuve'))
    .map(c => c.dataset.id)
    .filter(id => id && id.trim() !== "");

  if(ids.length === 0) return;

  try{
    const res = await fetch('save_order.php',{
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify({order: ids})
    });
    const data = await res.json();
    console.log('Ordre sauvegardé', data);
  }catch(e){
    console.error('Erreur sauvegarde ordre', e);
  }
}

// --- Toggle historique (afficher / masquer les détails par lot) ---
function initHistoryToggle(){
  const rows = document.querySelectorAll('.history-row');
  rows.forEach(row=>{
    const toggleCell = row.querySelector('.history-toggle');
    if(!toggleCell) return;
    toggleCell.addEventListener('click', ()=>{
      const id = row.dataset.detailsId;
      if(!id) return;
      const details = document.getElementById(id);
      if(!details) return;
      const isHidden = (details.style.display === 'none' || details.style.display === '');
      details.style.display = isHidden ? 'table-row' : 'none';
      toggleCell.textContent = isHidden ? '−' : '+';
    });
  });
}

initDragAndDrop();
initHistoryToggle();
</script>
</body>
</html>