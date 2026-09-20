<?php
require __DIR__ . '/cuves_lib.php';
require __DIR__ . '/../shared/roadmap_lib.php';

/* =========================================================
   0) RESTAURATION D'UNE SAUVEGARDE
   - Traite AVANT toute autre section : aucune connexion PDO ne doit
     etre ouverte sur cuves.sqlite avant qu'on le remplace.
   ========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['restore_backup'])) {
    restoreCuvesBackup((string)$_POST['restore_backup']);
    header('Location: index.php');
    exit;
}

maybeRunScheduledBackupCuves();

// --- Helpers ---
function valf($arr, $key, $default = null) {
    return isset($arr[$key]) && $arr[$key] !== '' ? $arr[$key] : $default;
}
function nf($v, $dec = 1) {
    return is_numeric($v) ? number_format((float)$v, $dec, ',', '') : '';
}

// --- Charger la config depuis SQLite (toujours a jour, plus de fichier de
//     cache intermediaire a regenerer). "last_*" = dernier etat connu du
//     capteur, mis a jour a CHAQUE reception (voir api_cuve.php) ---
$config = getCuvesConfig(); // deja trie par position

$lotById   = [];
$colorById = [];
$hMaxById  = [];

foreach ($config as $cfg) {
    $id = $cfg['sensor_id'];
    $lotById[$id]   = $cfg['lot'];
    $colorById[$id] = $cfg['couleur'];
    $hMaxById[$id]  = (float)$cfg['hauteur_max_liquide'];
}

// --- Construit $cuves (une entree par capteur n'ayant jamais report une
//     mesure), dans l'ordre de la config ---
$cuves = [];
foreach ($config as $cfg) {
    $id = $cfg['sensor_id'];
    if ($cfg['last_distance_cm'] === null) continue; // jamais vu de mesure

    $interp = interpretCuve((int)$cfg['last_distance_cm'], $cfg);

    $cuves[] = [
        "id"           => $id,
        "cuve"         => $cfg['nom_cuve'],
        "datetime"     => $cfg['last_date_iso'],
        "distance_cm"  => (float)$cfg['last_distance_cm'],
        "volume_hl"    => $interp['volume_hl'],
        "capacite_hl"  => $interp['capacite_hl'],
        "pourcentage"  => $interp['pourcentage'],
        "correction"   => $interp['correction'],
        "hauteurPlein" => $interp['hauteurPlein'],
        "hauteurCuve"  => $interp['hauteurCuve'],
        "rssi"         => $cfg['last_rssi'] !== null ? (int)$cfg['last_rssi'] : null,
        "fw"           => $cfg['last_fw'] ?? '',
    ];
}

// La page est toujours generee a la demande (plus de cache intermediaire) :
// l'heure affichee est simplement celle du rendu de la page.
$lastUpdate = date("d/m/Y H:i:s");

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

// --- Historique des snapshots (deja trie desc + limite a 50) ---
$historyDisplay = getCuveSnapshots(50);
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
/* Par defaut (desktop/tablette/paysage smartphone), chaque info-row garde
   le meme rendu qu'avant (deux lignes empilees et centrees) : les spans
   qu'elle contient restent en bloc. En portrait smartphone (media query
   plus bas), info-row passe en ligne pour repartir % et Hauteur, puis
   Volume et Distance, sur la largeur disponible plutot que de les
   empiler ou de laisser l'espace vide a droite. */
.info-row > span{display:block}
.muted{color:var(--muted)}
#loading{display:none;text-align:center;padding:8px;background:#fff3be;color:#b17f00}

/* Portrait smartphone : carte compacte en ligne (baton de remplissage
   etroit a gauche, texte a droite empile) au lieu de la carte verticale
   complete, pour voir un maximum de cuves sans defiler. Meme logique que
   le mode paysage compact plus haut, adaptee a l'orientation portrait. */
@media (orientation:portrait) and (max-width:700px){
  main{grid-template-columns:1fr; gap:6px; padding:8px;}
  .cuve{
    display:grid;
    grid-template-columns:44px 1fr;
    grid-template-areas:"bar head" "bar infos";
    align-items:center;
    column-gap:10px;
    row-gap:0;
    padding:8px 10px;
    text-align:left;
  }
  .bar{
    grid-area:bar;
    width:100%;
    height:auto;
    align-self:stretch;
    min-height:56px;
    border-radius:10px;
  }
  .head{grid-area:head; justify-content:flex-start; margin-bottom:2px;}
  .head h2{font-size:.85rem;}
  .head .lot-label{font-size:.7rem;}
  .wifi-icon{width:14px;height:14px;}
  .infos{grid-area:infos; margin-top:0; font-size:.72rem; line-height:1.3;}
  .info-row{
    display:flex;
    justify-content:space-between;
    align-items:baseline;
    gap:8px;
  }
  .info-row .pourc-val,.info-row .vol-val{
    min-width:0;
    overflow:hidden;
    text-overflow:ellipsis;
    white-space:nowrap;
  }
  .info-row .hauteur-line,.info-row .distance-line{
    color:var(--muted);
    white-space:nowrap;
    flex-shrink:0;
  }
  .drag-handle{display:none;}
  .reflet{height:10px;}
}

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
  /* max-width > min-width de .param-table (820px) + padding, sinon la
     barre de defilement horizontale apparaissait meme sur grand ecran
     avec largement la place. */
  width:90%;max-width:900px;border:1px solid #2a2a2a;
  max-height:90vh;
  display:flex;flex-direction:column;
}
.popup-content h3{margin:.2rem 0 10px;color:var(--gold);flex-shrink:0}
.popup-scroll{
  overflow-y:auto;
  overflow-x:auto;
  -webkit-overflow-scrolling:touch;
}
.popup-footer{
  flex-shrink:0;
  display:flex;
  flex-wrap:wrap;
  gap:8px;
  padding-top:10px;
  margin-top:8px;
  border-top:1px solid #2a2a2a;
}
/* Bouton restaurer : couleur et position volontairement differentes du
   bouton Enregistrer (meme dore, colles l'un a l'autre - risque de clic
   par erreur signale par l'utilisateur). Pousse a droite du pied de page. */
.restore-link-btn{
  margin-left:auto;
  background:transparent !important;
  border:1px solid #555 !important;
  color:#aaa !important;
  font-size:.85rem;
}
.restore-link-btn:hover{
  border-color:#888 !important;
  color:#ddd !important;
}
/* table-layout:fixed + colonnes en largeur fixe : sur petit ecran, la
   table est plus large que la popup et defile horizontalement (scroll)
   au lieu de compresser chaque colonne jusqu'a l'illisible (nom tronque,
   couleur/lot reduits a une lettre...). */
.param-table{width:100%;min-width:820px;border-collapse:collapse;table-layout:fixed}
.param-table th,.param-table td{
  border-bottom:1px solid #2a2a2a;padding:6px 8px;text-align:left;font-size:.9rem;
  overflow:hidden;text-overflow:ellipsis;white-space:nowrap;
}
.param-table th{background:#141414;color:#d8c07a}
.param-table th:nth-child(1),.param-table td:nth-child(1){width:115px}
.param-table th:nth-child(2),.param-table td:nth-child(2){width:70px}
.param-table th:nth-child(3),.param-table td:nth-child(3){width:100px}
.param-table th:nth-child(4),.param-table td:nth-child(4){width:125px}
.param-table th:nth-child(5),.param-table td:nth-child(5){width:70px}
.param-table th:nth-child(6),.param-table td:nth-child(6){width:90px}
.param-table th:nth-child(7),.param-table td:nth-child(7){width:90px}
.param-table th:nth-child(8),.param-table td:nth-child(8){width:80px}
.param-table th:nth-child(9),.param-table td:nth-child(9){width:70px}
.param-table input, .param-table select{
  width:100%;border:1px solid #3a3a3a;border-radius:6px;
  padding:6px;background:#0e0e0e;color:#eee;
  font-size:.85rem;
}
.param-table select{
  padding-right:20px;
}
/* Paysage smartphone (peu de hauteur) : colonnes plus etroites pour
   limiter le scroll horizontal, tout en restant lisibles (place apres
   les regles de base pour bien les surcharger, meme specificite). */
@media (max-height:500px){
  .popup-content{padding:10px;max-height:96vh}
  .param-table{min-width:660px}
  .param-table th,.param-table td{padding:4px 5px;font-size:.75rem}
  .param-table input,.param-table select{padding:4px;font-size:.72rem}
  .param-table th:nth-child(1),.param-table td:nth-child(1){width:78px}
  .param-table th:nth-child(2),.param-table td:nth-child(2){width:52px}
  .param-table th:nth-child(3),.param-table td:nth-child(3){width:70px}
  .param-table th:nth-child(4),.param-table td:nth-child(4){width:95px}
  .param-table th:nth-child(5),.param-table td:nth-child(5){width:56px}
  .param-table th:nth-child(6),.param-table td:nth-child(6){width:68px}
  .param-table th:nth-child(7),.param-table td:nth-child(7){width:68px}
  .param-table th:nth-child(8),.param-table td:nth-child(8){width:60px}
  .param-table th:nth-child(9),.param-table td:nth-child(9){width:56px}
}
.popup-content button{
  background:var(--gold2);color:#000;border:none;padding:8px 14px;border-radius:8px;cursor:pointer
}

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
.history-cuves-inner{
  width:100%;
  border-collapse:collapse;
  font-size:.78rem;
  margin:2px 0 2px 20px;
  width:calc(100% - 20px);
}
.history-cuves-inner th, .history-cuves-inner td{
  padding:2px 4px;
  border-bottom:1px solid #262626;
  color:#bbb;
}

.roadmap-modal{position:fixed;inset:0;background:rgba(0,0,0,.65);display:none;align-items:center;justify-content:center;z-index:1000;}
.roadmap-content{background:#111;border:1px solid #444;border-radius:8px;min-width:280px;max-width:520px;width:92%;max-height:85vh;color:#ddd;box-shadow:0 0 20px rgba(0,0,0,.6);display:flex;flex-direction:column;}
.roadmap-scroll{overflow-y:auto;-webkit-overflow-scrolling:touch;padding:1.2rem 1.2rem 0 1.2rem;}
.roadmap-footer{flex-shrink:0;padding:.8rem 1.2rem 1.2rem 1.2rem;border-top:1px solid #333;text-align:right;}
.roadmap-content h2{margin:0 0 .8rem 0;font-size:1.15rem;color:#f3d26b;}
.roadmap-content h3{margin:1rem 0 .4rem 0;font-size:.85rem;color:#f3d26b;text-transform:uppercase;letter-spacing:.03em;border-bottom:1px solid #333;padding-bottom:.3rem;}
.roadmap-item{border-left:3px solid #444;padding:.3rem .6rem;margin-bottom:.6rem;font-size:.85rem;}
.roadmap-item .titre{font-weight:600;}
.roadmap-item .desc{color:#9aa0a6;font-size:.8rem;margin-top:.2rem;line-height:1.4;}
.roadmap-item.manuel{border-left-color:#f3d26b;background:rgba(243,210,107,0.06);}
.roadmap-item .date{color:#6b7280;font-size:.72rem;margin-top:.3rem;}
.roadmap-item .edit-link{background:none;border:none;color:#6b7280;font-size:.72rem;cursor:pointer;padding:0 0 0 8px;text-decoration:underline;}
.roadmap-item .edit-link:hover{color:#f3d26b;}
.roadmap-form{margin-top:.8rem;display:flex;flex-direction:column;gap:6px;}
.roadmap-form input, .roadmap-form textarea{background:#0b0e13;border:1px solid #444;border-radius:4px;color:#ddd;padding:6px 8px;font-size:.85rem;font-family:inherit;}
.roadmap-form textarea{min-height:50px;resize:vertical;}
.roadmap-form button{align-self:flex-end;background:#f3d26b;border:none;border-radius:4px;color:#111;padding:6px 14px;font-size:.85rem;cursor:pointer;font-weight:600;}
.roadmap-empty{color:#9aa0a6;font-size:.85rem;}
.roadmap-footer button{background:transparent;border:none;color:#9aa0a6;font-size:1.2rem;cursor:pointer;}

/* Smartphone en paysage (large mais bas) : place en toute fin de feuille
   de style pour etre sur de surcharger toutes les regles de base
   ci-dessus (meme specificite partout ici -> l'ordre dans le fichier
   decide, cf. le bug d'ordre corrige sur le popup parametres). Cible ce
   format (hauteur faible = telephone tenu en main, pas une
   tablette/ecran) pour maximiser le nombre de cuves visibles et eviter
   que la barre d'actions et le tableau "Volumes par lot" debordent. */
@media (orientation:landscape) and (max-height:500px){
  main{grid-template-columns: repeat(auto-fill, minmax(130px, 1fr)); gap:6px; padding:6px;}
  .cuve{padding:6px;}
  .bar{height:55px;}
  .head{margin-bottom:3px; gap:5px;}
  .head h2{font-size:.78rem;}
  .head .lot-label{font-size:.68rem;}
  .wifi-icon{width:14px;height:14px;}
  .infos{font-size:.7rem;margin-top:4px;line-height:1.3;}
  .drag-handle{width:16px;height:16px;font-size:10px;right:4px;bottom:4px;}

  /* Barre d'entete + actions : evite que les boutons soient coupes a
     droite (ils se retrouvaient plus larges que l'espace restant apres
     le titre, sans possibilite de passer a la ligne ni de defiler). */
  header{padding:6px 10px; gap:6px;}
  header h1{font-size:.8rem;}
  #updateTime{display:none;}
  .exit-link{width:24px;height:24px;font-size:.85rem;}
  .actions{display:flex; flex-wrap:wrap; justify-content:flex-end; gap:4px; width:100%;}
  .actions button{padding:5px 8px; font-size:.72rem;}

  /* Tableau "Volumes par lot" : largeurs de colonnes fixes + defilement
     horizontal si besoin, au lieu de compresser la colonne Lot jusqu'a
     l'illisible (une seule lettre visible). */
  .lots-summary{overflow-x:auto; -webkit-overflow-scrolling:touch;}
  .lots-summary table{width:100%; min-width:420px; table-layout:fixed; font-size:.72rem;}
  .lots-summary th, .lots-summary td{
    padding:3px 4px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;
  }
  .lots-summary th:nth-child(1),.lots-summary td:nth-child(1){width:70px}
  .lots-summary th:nth-child(2),.lots-summary td:nth-child(2){width:80px}
  .lots-summary th:nth-child(3),.lots-summary td:nth-child(3){width:90px}
  .lots-summary th:nth-child(4),.lots-summary td:nth-child(4){width:90px}
  .lots-summary th:nth-child(5),.lots-summary td:nth-child(5){width:70px}
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
    <button onclick="document.getElementById('roadmap-modal').style.display='flex'" title="Chantiers en cours et à venir">🚧 Chantiers</button>
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
    // Le capteur envoie toutes les ~8s : 5 min de silence est deja anormal
    // (avant : 25s, beaucoup trop strict, un capteur bien vivant clignotait
    // "hors ligne" au moindre leger decalage reseau).
    $offlineThreshold = 300;

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
      <div class="info-row">
        <span class="pourc-val"><strong><?= nf($pourc,1) ?> %</strong> rempli</span>
        <?php if($hPlein!==null && $hCuve!==null): ?>
          <span class="hauteur-line">Hauteur : <?= nf($hPlein,1) ?> / <?= nf($hCuve,1) ?> cm</span>
        <?php endif; ?>
      </div>
      <div class="info-row">
        <span class="vol-val">
          <?= nf($vol,2) ?> / <?= nf($cap,2) ?> HL
          <span class="muted">(+<?= nf($corr,2) ?> HL)</span>
        </span>
        <?php if($dist!==null): ?>
          <span class="distance-line">Distance : <?= (int)$dist ?> cm</span>
        <?php endif; ?>
      </div>

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
                <th style="width:24px;"></th>
                <th>Lot</th>
                <th>Volume (HL)</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($snapLots as $lotIdx => $lotInfo):
                $hLotName  = isset($lotInfo['lot']) ? $lotInfo['lot'] : '';
                $hLotVol   = isset($lotInfo['volume_hl']) ? $lotInfo['volume_hl'] : null;
                $hLotCuves = (isset($lotInfo['cuves']) && is_array($lotInfo['cuves'])) ? $lotInfo['cuves'] : [];
                $lotRowId  = $rowId . '-lot' . $lotIdx;
              ?>
              <tr class="history-row"<?= !empty($hLotCuves) ? ' data-details-id="'.htmlspecialchars($lotRowId).'"' : '' ?>>
                <td class="history-toggle"><?= !empty($hLotCuves) ? '+' : '' ?></td>
                <td><?= htmlspecialchars($hLotName) ?></td>
                <td><?= nf($hLotVol, 2) ?></td>
              </tr>
              <?php if (!empty($hLotCuves)): ?>
              <tr class="history-details" id="<?= htmlspecialchars($lotRowId) ?>" style="display:none;">
                <td colspan="3">
                  <table class="history-cuves-inner">
                    <tbody>
                      <?php foreach ($hLotCuves as $cuveInfo): ?>
                      <tr>
                        <td><?= htmlspecialchars($cuveInfo['nom_cuve'] ?? '') ?></td>
                        <td><?= nf($cuveInfo['volume_hl'] ?? null, 2) ?></td>
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
    <div class="popup-scroll">
      <div id="paramContainer">Chargement...</div>
    </div>
    <div class="popup-footer">
      <button class="save-btn" onclick="saveConfig()">💾 Enregistrer les modifications</button>
      <button onclick="hideParamPopup()">Fermer</button>
      <button type="button" class="restore-link-btn"
              onclick="document.getElementById('paramPopup').style.display='none';document.getElementById('restore-modal-cuves').style.display='flex';">
        🗄️ Restaurer une sauvegarde…
      </button>
    </div>
  </div>
</div>

<!-- Popup restauration d'une sauvegarde cuves.sqlite -->
<div class="param-popup" id="restore-modal-cuves">
  <div class="popup-content" style="max-height:60vh;">
    <h3>Restaurer une sauvegarde</h3>
    <div class="popup-scroll">
      <?php $availableCuvesBackups = listAvailableCuvesBackups(); ?>
      <?php if (empty($availableCuvesBackups)): ?>
        <p class="muted" style="font-size:.85rem;">Aucune sauvegarde disponible pour l'instant (la première sera créée automatiquement).</p>
      <?php else: ?>
        <?php foreach ($availableCuvesBackups as $b): ?>
          <form method="post" action="index.php"
                onsubmit="return confirm('Restaurer la sauvegarde du <?php echo htmlspecialchars($b['label'], ENT_QUOTES, 'UTF-8'); ?> ?\nTout ce qui a été enregistré après cette date sera perdu (une sauvegarde de l\'état actuel sera prise avant, au cas où).');"
                style="margin-bottom:8px;">
            <input type="hidden" name="restore_backup" value="<?php echo htmlspecialchars($b['filename'], ENT_QUOTES, 'UTF-8'); ?>">
            <button type="submit" style="width:100%;">Restaurer — <?php echo htmlspecialchars($b['label'], ENT_QUOTES, 'UTF-8'); ?></button>
          </form>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
    <div class="popup-footer">
      <button type="button" onclick="document.getElementById('restore-modal-cuves').style.display='none';">Fermer</button>
    </div>
  </div>
</div>

<script>
// --- Actualisation des données ---
// Depuis la migration SQLite, la page est toujours generee a jour (plus
// de fichier de cache intermediaire a regenerer) : "Actualiser" recharge
// simplement la page. Le petit delai + le spinner gardent le meme retour
// visuel qu'avant pour l'utilisateur.
function refreshData(){
  const loading=document.getElementById('loading');
  loading.style.display='block';loading.innerText="⏳ Mise à jour...";
  setTimeout(()=>location.reload(),300);
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
      <th>Firmware</th>
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
      <td>${cu.fw?cu.fw:'<span class="muted">?</span>'}</td>
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
    // "fw" est une info affichee (derniere version connue, deduite des
    // mesures en base), pas un reglage : on ne l'envoie pas a save_config.php.
    const toSave = configData.map(({fw, ...rest}) => rest);
    const res = await fetch('save_config.php',{
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body:JSON.stringify(toSave)
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

<!-- Popup chantiers en cours et a venir -->
<?php $roadmap = getRoadmapGrouped(); ?>
<div id="roadmap-modal" class="roadmap-modal">
    <div class="roadmap-content">
    <div class="roadmap-scroll">
        <h2>🚧 Chantiers en cours et à venir</h2>
        <?php if (empty($roadmap['categories']) && empty($roadmap['manuel'])): ?>
            <div class="roadmap-empty">Rien pour l'instant.</div>
        <?php endif; ?>
        <?php foreach ($roadmap['categories'] as $cat => $items): ?>
            <h3><?php echo htmlspecialchars($cat, ENT_QUOTES, 'UTF-8'); ?></h3>
            <?php foreach ($items as $item): ?>
                <div class="roadmap-item">
                    <div class="titre"><?php echo htmlspecialchars((string)($item['titre'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                    <?php if (!empty($item['description'])): ?>
                        <div class="desc"><?php echo nl2br(htmlspecialchars((string)$item['description'], ENT_QUOTES, 'UTF-8')); ?></div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endforeach; ?>

        <?php if (!empty($roadmap['manuel'])): ?>
            <h3>Ajouts manuels</h3>
            <?php foreach ($roadmap['manuel'] as $item):
                $itemId = htmlspecialchars((string)($item['id'] ?? ''), ENT_QUOTES, 'UTF-8');
            ?>
                <div class="roadmap-item manuel">
                    <div class="titre">
                        <?php echo htmlspecialchars((string)($item['titre'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                        <button type="button" class="edit-link" onclick="document.getElementById('edit-form-<?php echo $itemId; ?>').style.display='flex'">✏️ Modifier</button>
                    </div>
                    <?php if (!empty($item['description'])): ?>
                        <div class="desc"><?php echo nl2br(htmlspecialchars((string)$item['description'], ENT_QUOTES, 'UTF-8')); ?></div>
                    <?php endif; ?>
                    <div class="date">Ajouté le <?php echo htmlspecialchars((string)($item['date'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>

                    <form class="roadmap-form" id="edit-form-<?php echo $itemId; ?>" method="post" action="/shared/roadmap_edit.php" style="display:none;margin-top:8px;">
                        <input type="hidden" name="id" value="<?php echo $itemId; ?>">
                        <input type="hidden" name="redirect" value="/cuves/index.php">
                        <input type="text" name="titre" value="<?php echo htmlspecialchars((string)($item['titre'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" required maxlength="200">
                        <textarea name="description"><?php echo htmlspecialchars((string)($item['description'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                        <button type="submit">Enregistrer la modification</button>
                    </form>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <h3>Ajouter une idée</h3>
        <form class="roadmap-form" method="post" action="/shared/roadmap_add.php">
            <input type="hidden" name="redirect" value="/cuves/index.php">
            <input type="text" name="titre" placeholder="Titre" required maxlength="200">
            <textarea name="description" placeholder="Détails (optionnel)"></textarea>
            <button type="submit">Ajouter</button>
        </form>
    </div>
    <div class="roadmap-footer">
        <button type="button" onclick="document.getElementById('roadmap-modal').style.display='none'">✖</button>
    </div>
    </div>
</div>
</body>
</html>