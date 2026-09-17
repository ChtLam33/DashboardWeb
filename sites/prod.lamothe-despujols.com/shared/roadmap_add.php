<?php
// roadmap_add.php - Ajoute un chantier saisi manuellement depuis le dashboard

require __DIR__ . '/roadmap_lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo "Méthode non autorisée";
    exit;
}

$titre       = (string)($_POST['titre'] ?? '');
$description = (string)($_POST['description'] ?? '');

addRoadmapItem($titre, $description);

$redirect = (string)($_POST['redirect'] ?? '/');
// Securite minimale : on ne redirige que vers un chemin local
if ($redirect === '' || $redirect[0] !== '/') {
    $redirect = '/';
}

header('Location: ' . $redirect);
exit;
