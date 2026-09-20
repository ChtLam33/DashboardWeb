<?php
// api_cuve.php — Réception des données envoyées par ESP32 (POST JSON)
//
// Migration SQLite (remplace data_cuves.csv) : plus de lecture-modification-
// écriture d'un fichier partagé donc plus besoin de verrou manuel (voir
// cuves_lib.php).
//
// Ne stocke QUE la distance brute : le volume/%/hauteurs sont recalculés
// à la lecture via interpretCuve() avec la config courante (comme
// barriques le fait déjà avec interpret_raw()). Les champs calculés que
// l'ancien firmware envoie encore (volume, capacite, pourcentage,
// hauteurPlein, hauteurCuve, correction) sont acceptés mais ignorés :
// ça permet une transition sans casser l'OTA (le firmware n'a pas besoin
// de changer pour que cette migration prenne effet).
//
// Le capteur envoie une mesure toutes les ~8s : un INSERT par reception
// dans "mesures" ferait exploser la table pour rien (une cuve qui ne
// bouge pas pendant des semaines n'a besoin que de quelques points
// d'historique, pas de dizaines de milliers de lignes identiques).
// "config.last_*" est mis a jour a CHAQUE reception (etat en direct,
// utilise par le dashboard et pour detecter un capteur hors ligne),
// "mesures" n'est ecrit que si necessaire (voir insertCuveMeasurementIfNeeded).

require __DIR__ . '/cuves_lib.php';

header("Content-Type: application/json; charset=utf-8");

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["error" => "Méthode non autorisée."]);
    exit;
}

$input = file_get_contents("php://input");
$data  = json_decode($input, true);

if (!$data || !is_array($data)) {
    http_response_code(400);
    echo json_encode(["error" => "Données JSON invalides."]);
    exit;
}

$id   = htmlspecialchars(trim((string)($data['id']   ?? 'sans_id')));
$cuve = htmlspecialchars(trim((string)($data['cuve'] ?? 'inconnue')));

$distance = (int)round(floatval($data['distance'] ?? 0));
$rssi     = isset($data['rssi']) ? intval($data['rssi']) : null;
$fw       = htmlspecialchars(trim((string)($data['fw'] ?? '')));

// Cle API (transition tolerante : voir isValidCuveApiKey() - accepte les
// capteurs qui n'ont encore aucune cle enregistree, rejette seulement si
// une cle existe pour cet id et ne correspond pas).
$apiKey = $_SERVER['HTTP_X_API_KEY'] ?? null;
if (!isValidCuveApiKey($id, $apiKey)) {
    http_response_code(401);
    echo json_encode(["error" => "Clé API invalide."]);
    exit;
}

$now     = time();
$dateIso = date('Y-m-d H:i:s', $now);
$fwOrNull = $fw !== '' ? $fw : null;

try {
    ensureCuveConfigExists($id, $cuve);

    $configBefore = getCuveConfigById($id);
    $volumeBefore = ($configBefore !== null && $configBefore['last_distance_cm'] !== null)
        ? interpretCuve((int)$configBefore['last_distance_cm'], $configBefore)['volume_hl']
        : null;

    // Etat en direct : toujours mis a jour, meme si aucune ligne d'historique
    // n'est ecrite ci-dessous (garde le capteur "en ligne" et le dashboard a jour).
    updateCuveLastSeen($id, $distance, $rssi, $fwOrNull, $now, $dateIso);

    // Historique : throttle (voir cuves_lib.php)
    insertCuveMeasurementIfNeeded($id, $distance, $rssi, $fwOrNull, $now, $dateIso);

    // Sauvegarde automatique si le volume vient de changer de plus d'1 HL
    // ET que la mesure n'a pas l'air d'etre un artefact (couvercle/obstacle) -
    // un vrai changement de volume de cette ampleur est un evenement metier
    // (soutirage, remplissage...) qui merite un point de restauration.
    if ($volumeBefore !== null && $configBefore !== null && !isCuveMeasurementIncoherent($distance, $configBefore)) {
        $volumeAfter = interpretCuve($distance, $configBefore)['volume_hl'];
        if (abs($volumeAfter - $volumeBefore) > 1.0) {
            try {
                createCuvesBackupNow();
            } catch (\Throwable $e) {
                error_log('[cuves] backup sur changement de volume echoue : ' . $e->getMessage());
            }
        }
    }
} catch (\Throwable $e) {
    error_log('[cuves] api_cuve.php insert failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(["error" => "Erreur d'enregistrement."]);
    exit;
}

echo json_encode([
    "status"    => "OK",
    "id"        => $id,
    "cuve"      => $cuve,
    "fw"        => $fw,
    "timestamp" => date('Y-m-d H:i:s', $now)
], JSON_UNESCAPED_UNICODE);
