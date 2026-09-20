<?php
// get_config.php — renvoie la configuration complète ou celle d'un ID spécifique
// (migration SQLite : lit la table config au lieu de config_cuves.json)
require __DIR__ . '/cuves_lib.php';
header("Content-Type: application/json; charset=utf-8");

function cuveConfigRowToApi(array $row, ?string $fw = null): array {
    return [
        "id"                 => $row['sensor_id'],
        "nomCuve"            => $row['nom_cuve'],
        "lot"                => $row['lot'],
        "hauteurCapteurFond" => (float)$row['hauteur_capteur_fond'],
        "hauteurMaxLiquide"  => (float)$row['hauteur_max_liquide'],
        "diametreCuve"       => (float)$row['diametre_cuve'],
        "AjustementHL"       => (float)$row['ajustement_hl'],
        "couleur"            => $row['couleur'],
        "fw"                 => $fw ?? '',
    ];
}

$id = $_GET['id'] ?? '';

// --- Si un ID est passé dans l'URL (utilisé par le firmware), renvoyer
//     uniquement cet élément - format inchangé pour ne pas risquer l'OTA ---
if ($id !== '') {
    $row = getCuveConfigById($id);
    if ($row === null) {
        echo json_encode(["error" => "Aucune configuration trouvée pour $id"]);
        exit;
    }
    echo json_encode(cuveConfigRowToApi($row, $row['last_fw'] ?? ''), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// --- Sinon, renvoyer tout le tableau (pour le dashboard), enrichi de la
//     derniere version firmware connue par capteur (config.last_fw) ---
$config = getCuvesConfig();

$out = [];
foreach ($config as $row) {
    $out[] = cuveConfigRowToApi($row, $row['last_fw'] ?? '');
}

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
