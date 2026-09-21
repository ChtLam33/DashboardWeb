<?php
// register.php — Premiere inscription d'un capteur barrique, obtention de
// sa cle API personnelle.
//
// POST { "id": "<id materiel>",
//        "secret": "<BARRIQUE_PROVISIONING_SECRET, grave dans le firmware>" }
// -> { "status":"OK", "api_key":"..." } ou 403 si le secret est invalide.
//
// Le firmware n'appelle cet endpoint que si aucune cle n'est encore
// stockee en NVS (voir isValidBarriqueApiKey() dans barriques_lib.php
// pour la verification cote reception des mesures).
require __DIR__ . '/barriques_lib.php';
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["error" => "Méthode non autorisée."]);
    exit;
}

$input = file_get_contents('php://input');
$data  = json_decode($input, true);

if (!$data || !is_array($data)) {
    http_response_code(400);
    echo json_encode(["error" => "Données JSON invalides."]);
    exit;
}

$id     = htmlspecialchars(trim((string)($data['id'] ?? '')));
$secret = (string)($data['secret'] ?? '');

if ($id === '') {
    http_response_code(400);
    echo json_encode(["error" => "Champ 'id' manquant."]);
    exit;
}

try {
    $apiKey = registerBarriqueSensor($id, $secret);
} catch (\Throwable $e) {
    error_log('[barriques] register.php failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(["error" => "Erreur serveur."]);
    exit;
}

if ($apiKey === null) {
    http_response_code(403);
    echo json_encode(["error" => "Secret invalide."]);
    exit;
}

echo json_encode(["status" => "OK", "id" => $id, "api_key" => $apiKey]);
