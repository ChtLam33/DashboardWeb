<?php
// --- Lecture du CACHE JSON ---
$cacheFile = __DIR__ . "/cache_dashboard.json";
$cuves = [];

if (file_exists($cacheFile)) {
    $json = file_get_contents($cacheFile);
    $cuves = json_decode($json, true) ?: [];
}

// Date/heure de dernière mise à jour
$lastUpdate = file_exists($cacheFile) ? date("d/m/Y H:i:s", filemtime($cacheFile)) : "N/A";

// Helpers
function valf($arr, $key, $default = null) {
  return isset($arr[$key]) && $arr[$key] !== '' ? $arr[$key] : $default;
}
function nf($v, $dec = 1) {
  return is_numeric($v) ? number_format((float)$v, $dec) : '';
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<link rel="icon" type="image/svg+xml" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Cpath fill='%23fbc02d' d='M32 4C32 4 16 24 16 36a16 16 0 0032 0C48 24 32 4 32 4z'/%3E%3C/svg%3E">
<title>Dashboard Cuves – Château Lamothe</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
:root{
  --bg:#111;--card:#1b1b1b;--text:#ddd;--muted:#9aa0a6;
  --gold:#f3d26b;--gold2:#b38728;
  --fill1:#ffe57e;--fill2:#fbc02d;
  --ok:#00e676;--mid:#ffeb3b;--bad:#ff1744;--unk:#555;
}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--text);font-family:Segoe UI,Roboto,Arial,sans-serif}
header{
  background:linear-gradient(90deg,#8d6e00,#b38728);
  color:#fff;padding:10px 16px;display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap
}
header h1{margin:0;font-size:1.05rem}
#updateTime{font-size:.85rem;opacity:.9;margin-left:6px}
.actions button{
  background:#0000;border:1px solid rgba(255,255,255,.45);color:#fff;padding:8px 12px;border-radius:8px;font-weight:600;cursor:pointer
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
  overflow:hidden
}
.cuve:hover{transform:translateY(-2px);box-shadow:0 6px 16px rgba(0,0,0,.45)}
.head{display:flex;align-items:center;justify-content:center;gap:8px;margin-bottom:6px}
.head h2{margin:0;font-size:.95rem;color:var(--gold);font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}

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

.param-popup{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);justify-content:center;align-items:center;z-index:10}
.popup-content{background:#101010;color:#eee;padding:16px;border-radius:12px;width:90%;max-width:780px;border:1px solid #2a2a2a}
.popup-content h3{margin:.2rem 0 10px;color:var(--gold)}
.param-table{width:100%;border-collapse:collapse}
.param-table th,.param-table td{border-bottom:1px solid #2a2a2a;padding:6px 8px;text-align:left;font-size:.9rem}
.param-table th{background:#141414;color:#d8c07a}
.param-table input{width:100%;border:1px solid #3a3a3a;border-radius:6px;padding:6px;background:#0e0e0e;color:#eee}
.popup-content button{background:var(--gold2);color:#000;border:none;padding:8px 14px;border-radius:8px;cursor:pointer}
.save-btn{margin-top:10px}
</style>
</head>
<body>
<header>
  <h1>Cuves - Château Lamothe <span id="updateTime">(<?= htmlspecialchars($lastUpdate) ?>)</span></h1>
  <div class="actions">
    <button onclick="refreshData()">🔄 Actualiser</button>
    <button onclick="showParamPopup()">⚙️ Paramètres</button>
  </div>
</header>

<div id="loading">⏳ Actualisation en cours...</div>

<main id="cuvesContainer">
<?php if (empty($cuves)): ?>
  <p style="grid-column:1/-1;text-align:center;">Aucune donnée affichée. Cliquez sur 🔄 pour actualiser.</p>
<?php else: ?>
  <?php foreach ($cuves as $c):

    $nom    = htmlspecialchars(valf($c,'cuve','(sans nom)'));
    $pourc  = (float)valf($c,'pourcentage',0);
    $vol    = valf($c,'volume_hl',null);
    $cap    = valf($c,'capacite_hl',null);
    $corr   = valf($c,'correction',null);
    $dist   = valf($c,'distance_cm',null);
    $hPlein = valf($c,'hauteurPlein',null);
    $hCuve  = valf($c,'hauteurCuve',null);
    $rssi   = isset($c['rssi']) ? (int)$c['rssi'] : null;

    // Gestion "online / offline" via l'âge de la dernière mesure
    $dtStr      = valf($c,'datetime',null);
    $ageSec     = null;
    $isOffline  = false;
    $offlineThreshold = 25; // Mesure luna toutes les 6 secondes, 25s sans mesure = pas de wifi

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

    // Couleur du Wi-Fi
    $color = 'var(--unk)';
    if ($isOffline) {
      $color = 'var(--bad)'; // rouge si hors ligne
    } elseif ($rssi !== null) {
      if ($rssi > -60)      $color = 'var(--ok)';   // vert
      else if ($rssi > -75) $color = 'var(--mid)';  // jaune
      else                  $color = 'var(--bad)';  // rouge
    }
  ?>
  <div class="cuve<?= $isOffline ? ' offline' : '' ?>" data-pourc="<?= $pourc ?>">
    <div class="head">
      <span class="wifi-icon" style="background-color:<?= $color ?>"
        title="<?=
          $isOffline
            ? 'Capteur hors ligne'.($dtStr ? ' – dernière mesure : '.$dtStr : '')
            : ($rssi !== null ? ('RSSI: '.$rssi.' dBm') : 'RSSI indisponible')
        ?>"></span>
      <h2><?= $nom ?></h2>
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

      <?php if($isOffline && $dtStr): ?>
        <div class="muted" style="font-size:.75rem;color:#ff6b6b;">
          ⚠ Capteur hors ligne – dernière mesure : <?= htmlspecialchars($dtStr) ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
<?php endif; ?>
</main>

<!-- POPUP PARAMÈTRES -->
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
    <tr><th>ID</th><th>Nom</th><th>Capteur→Fond</th><th>Hauteur max</th><th>Diamètre</th><th>Aj. HL</th></tr>`;
    configData.forEach((cu,i)=>{
      html+=`<tr>
      <td>${cu.id}</td>
      <td><input value="${cu.nomCuve??''}" data-i="${i}" data-k="nomCuve"></td>
      <td><input value="${cu.hauteurCapteurFond??''}" data-i="${i}" data-k="hauteurCapteurFond" type="number"></td>
      <td><input value="${cu.hauteurMaxLiquide??''}" data-i="${i}" data-k="hauteurMaxLiquide" type="number"></td>
      <td><input value="${cu.diametreCuve??''}" data-i="${i}" data-k="diametreCuve" type="number"></td>
      <td><input value="${cu.AjustementHL??''}" data-i="${i}" data-k="AjustementHL" type="number" step="0.01"></td>
      </tr>`;
    });
    html+=`</table>`;
    c.innerHTML=html;
    c.querySelectorAll('input').forEach(inp=>{
      inp.addEventListener('input',e=>{
        const i=e.target.dataset.i,k=e.target.dataset.k;configData[i][k]=e.target.value;
      });
    });
  }catch(e){
    c.innerHTML="Impossible de charger les paramètres.";
  }
}
async function saveConfig(){
  const c=document.getElementById('paramContainer');
  c.insertAdjacentHTML('beforeend',"<p class='muted'>⏳ Sauvegarde...</p>");
  try{
    const res=await fetch('save_config.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(configData)});
    const d=await res.json();
    if(d.status==="OK"){
      c.insertAdjacentHTML('beforeend',"<p style='color:#7CFC00;'>✅ Sauvegardé</p>");
    }else{
      c.insertAdjacentHTML('beforeend',"<p style='color:#ff6b6b;'>⚠️ Erreur</p>");
    }
  }catch(e){
    c.insertAdjacentHTML('beforeend',"<p style='color:#ff6b6b;'>⚠️ Impossible de contacter le serveur.</p>");
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
    grd.addColorStop(0,'#ffe57e');
    grd.addColorStop(1,'#fbc02d');
    ctx.fillStyle=grd;
    ctx.fill();
    phase+=speed;
    requestAnimationFrame(draw);
  }
  draw();
});
</script>
</body>
</html>
