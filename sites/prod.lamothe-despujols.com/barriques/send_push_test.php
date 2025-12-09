<?php
// barriques/send_push_test.php

require __DIR__ . '/../vendor/autoload.php';

use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

// Fichier où push_subscribe.php stocke les abonnements
$subsFile = __DIR__ . '/subscriptions.json';

if (!file_exists($subsFile)) {
    die("Aucun fichier subscriptions.json\n");
}

$subs = json_decode(file_get_contents($subsFile), true);
if (!is_array($subs) || empty($subs)) {
    die("Aucun abonnement enregistré\n");
}

// Tes clés VAPID
$auth = [
    'VAPID' => [
        'subject' => 'mailto:contact@lamothe-despujols.com',
        'publicKey' => 'BDBBkJp-c7FZt5JJu5j3COn2Z3v42a54NlWngElS49Ogt0bd2nwaxnQvHGh7vbgH62ECDWhlPChglbSXgyvw4r0',
        'privateKey' => 'lwXO723hBCJacXilBJbQmJIxdcwyc3z9O-n_bVdEi_k',
    ],
];

$webPush = new WebPush($auth);

// Message de test
$payload = json_encode([
    'title' => 'Test alerte barriques',
    'body'  => 'Si tu lis ceci sur ton téléphone, le Web Push fonctionne ✅',
    'url'   => 'https://prod.lamothe-despujols.com/barriques/'
]);

foreach ($subs as $s) {
    // Crée l'objet Subscription à partir du tableau
    $subscription = Subscription::create($s);
    $webPush->queueNotification($subscription, $payload);
}

// Envoi effectif
foreach ($webPush->flush() as $report) {
    $endpoint = $report->getRequest()->getUri()->__toString();
    if ($report->isSuccess()) {
        echo "[OK] Notification envoyée à : {$endpoint}\n";
    } else {
        echo "[ERREUR] Envoi vers {$endpoint} : " . $report->getReason() . "\n";
    }
}
