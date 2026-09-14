<?php
// get_config.php – Config globale pour les capteurs barriques
require __DIR__ . '/barriques_lib.php';

header('Content-Type: application/json; charset=utf-8');

$measureIntervalS = (int)getSetting('measure_interval_s', '600'); // 10 minutes par défaut

echo json_encode(['measure_interval_s' => $measureIntervalS]);
