<?php
// update_lot_history.php
// Met à jour l’historique des lots par capteur en fonction de config_lots.json
//
// Principe :
// - on regarde, pour chaque capteur, quel était le lot "ouvert" avant (dans lot_history.json)
// - on regarde quel est le lot actuel (dans config_lots.json)
// - si ça change, on ferme l’ancienne période et on ouvre une nouvelle à time()
// - si lot actuel = vide => on ferme juste l’ancienne période éventuelle

$baseDir        = __DIR__;
$configLotsFile = $baseDir . '/config_lots.json';
$lotHistoryFile = $baseDir . '/lot_history.json';

// Si pas de config_lots, rien à faire
if (!file_exists($configLotsFile)) {
    return;
}

// Charger config_lots.json (id_capteur => ['lot' => ..., 'barriques' => ...])
$configLots = [];
$jsonCfg = file_get_contents($configLotsFile);
$dataCfg = json_decode($jsonCfg, true);
if (is_array($dataCfg)) {
    $configLots = $dataCfg;
}

// Charger lot_history.json (liste de périodes) s’il existe
$history = [];
if (file_exists($lotHistoryFile)) {
    $jsonHist = file_get_contents($lotHistoryFile);
    $dataHist = json_decode($jsonHist, true);
    if (is_array($dataHist)) {
        $history = $dataHist;
    }
}

// Extraire les périodes encore ouvertes (end_ts = null) : id => lot
$openById = []; // id_capteur => lot_name
foreach ($history as $entry) {
    if (!is_array($entry)) continue;
    if (!array_key_exists('end_ts', $entry) || $entry['end_ts'] !== null) {
        continue; // période déjà fermée
    }
    $id  = isset($entry['id'])  ? (string)$entry['id']  : '';
    $lot = isset($entry['lot']) ? (string)$entry['lot'] : '';
    if ($id === '' || $lot === '') continue;

    // On suppose au plus une période ouverte par capteur
    $openById[$id] = $lot;
}

$now = time();

// Construire la liste des capteurs concernés (union des ids présents dans l’historique + dans la config)
$allIds = array_unique(array_merge(array_keys($openById), array_keys($configLots)));

foreach ($allIds as $id) {
    $id = (string)$id;

    $oldLot = $openById[$id] ?? null; // lot en cours d’après l’historique
    $newLot = '';

    if (isset($configLots[$id])) {
        $newLot = trim($configLots[$id]['lot'] ?? '');
    }

    // Cas 1 : pas de changement (même lot ou tous les deux vides)
    if ($oldLot === $newLot || ($oldLot === null && $newLot === '')) {
        continue;
    }

    // Cas 2 : il y avait un lot ouvert et il change (y compris vers vide)
    if ($oldLot !== null && $oldLot !== '') {
        // fermer la période ouverte correspondante
        for ($i = count($history) - 1; $i >= 0; $i--) {
            if (!is_array($history[$i])) continue;

            if (
                (string)($history[$i]['id'] ?? '')  === $id &&
                (string)($history[$i]['lot'] ?? '') === $oldLot &&
                array_key_exists('end_ts', $history[$i]) &&
                $history[$i]['end_ts'] === null
            ) {
                $history[$i]['end_ts'] = $now;
                break;
            }
        }
    }

    // Cas 3 : il y a un nouveau lot (non vide)
    if ($newLot !== '') {
        $history[] = [
            'id'       => $id,
            'lot'      => $newLot,
            'start_ts' => $now,
            'end_ts'   => null,
        ];
    }
}

// Sauvegarde
file_put_contents(
    $lotHistoryFile,
    json_encode($history, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
);