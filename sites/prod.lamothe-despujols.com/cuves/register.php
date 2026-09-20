<?php
// register.php — Premiere inscription d'un capteur cuve, obtention de sa
// cle API personnelle.
//
// POST { "id": "<id materiel>", "cuve": "<nom par defaut, optionnel>",
//        "secret": "<CUVE_PROVISIONING_SECRET, grave dans le firmware>" }
// -> { "status":"OK", "api_key":"..." } ou 403 si le secret est invalide.
//
// Le firmware n'appelle cet endpoint qu'une seule fois (tant que la cle
// recue est encore en memoire NVS, il ne le rappelle plus) - voir
// isValidCuveApiKey() dans cuves_lib.php pour la verification cote
// reception des mesures.
require __DIR__ . '/cuves_lib.php';
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
$cuve   = htmlspecialchars(trim((string)($data['cuve'] ?? '')));
$secret = (string)($data['secret'] ?? '');

if ($id === '') {
    http_response_code(400);
    echo json_encode(["error" => "Champ 'id' manquant."]);
    exit;
}

try {
    $apiKey = registerCuveSensor($id, $cuve, $secret);
} catch (\Throwable $e) {
    error_log('[cuves] register.php failed: ' . $e->getMessage());
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
