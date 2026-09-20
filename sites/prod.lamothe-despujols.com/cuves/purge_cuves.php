<?php
// purge_cuves.php — Réinitialise la config des capteurs hors ligne (SQLite)
//
// Comportement (validé avec l'utilisateur lors de la migration SQLite) :
// on conserve désormais l'historique de mesures du capteur (utile
// maintenant qu'on a un vrai historique en base) - seule sa ligne
// "config" (nom, lot, hauteurs) est réinitialisée, comme s'il s'agissait
// d'un nouveau capteur au prochain contact.
//
// Deux modes (voir index.php) :
// - aperçu (par défaut, $_POST['confirm'] absent) : calcule qui SERAIT
//   purgé sans rien supprimer - sert à afficher la liste nommée dans la
//   confirmation avant d'agir, plutôt qu'un message générique.
// - exécution ($_POST['confirm'] === '1') : purge réellement.
// Les données lues (config.last_seen_ts) sont toujours celles en base au
// moment de l'appel : pas besoin d'un "Actualiser" séparé avant, la purge
// voit toujours l'état le plus récent, que la page ait été rechargée ou non.
require __DIR__ . '/cuves_lib.php';
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["error" => "Méthode non autorisée."]);
    exit;
}

$confirm = ($_POST['confirm'] ?? '') === '1';
$now = time();

try {
    $config = getCuvesConfig();

    $online  = []; // [id => nom_cuve]
    $offline = []; // [id => nom_cuve]

    foreach ($config as $cfg) {
        $sensorId = $cfg['sensor_id'];
        $label    = $cfg['nom_cuve'] !== '' ? $cfg['nom_cuve'] : $sensorId;
        $ts       = (int)($cfg['last_seen_ts'] ?? 0);
        $age      = $now - $ts;
        if ($ts > 0 && $age <= CUVE_PURGE_THRESHOLD_SECONDS) {
            $online[$sensorId] = $label;
        } else {
            $offline[$sensorId] = $label;
        }
    }

    $removed = 0;
    if ($confirm) {
        foreach (array_keys($offline) as $sensorId) {
            resetCuveConfig($sensorId);
            $removed++;
        }
    }

    echo json_encode([
        "status"            => "OK",
        "executed"          => $confirm,
        "removed"           => $removed,
        "online"            => array_values($online),
        "offline"           => array_values($offline),
        "threshold_seconds" => CUVE_PURGE_THRESHOLD_SECONDS
    ], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    error_log('[cuves] purge_cuves.php failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(["error" => "Erreur lors de la purge."]);
}
