<?php
require __DIR__ . '/barriques_lib.php';

header('Content-Type: application/json');

// Lire données envoyées par JS
$postdata = json_decode(file_get_contents('php://input'), true);
if (!$postdata || !isset($postdata['endpoint'])) {
    echo json_encode(['status' => 'error', 'msg' => 'Invalid subscription']);
    exit;
}

// Ajoute l'abonnement s'il n'existe pas déjà (même endpoint)
addPushSubscriptionIfNew($postdata);

echo json_encode(['status' => 'ok']);
