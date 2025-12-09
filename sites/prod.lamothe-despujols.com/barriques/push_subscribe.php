<?php
header('Content-Type: application/json');

// Dossier stockage abonnements
$file = __DIR__ . '/subscriptions.json';

// Lire abonnements existants
$subs = [];
if (file_exists($file)) {
    $subs = json_decode(file_get_contents($file), true);
    if (!is_array($subs)) $subs = [];
}

// Lire données envoyées par JS
$postdata = json_decode(file_get_contents('php://input'), true);
if (!$postdata || !isset($postdata['endpoint'])) {
    echo json_encode(['status' => 'error', 'msg' => 'Invalid subscription']);
    exit;
}

// Ajouter l'abonnement si pas déjà présent
$exists = false;
foreach ($subs as $s) {
    if ($s['endpoint'] === $postdata['endpoint']) {
        $exists = true;
        break;
    }
}

if (!$exists) {
    $subs[] = $postdata;
    file_put_contents($file, json_encode($subs, JSON_PRETTY_PRINT));
}

echo json_encode(['status' => 'ok']);