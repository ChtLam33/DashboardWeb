<?php
// migrate_mesures_sqlite.php
// Script UNIQUE (a supprimer apres usage) : copie logs/barriques.log
// (format TSV) vers la table SQLite "mesures". Idempotent : si la
// table contient deja des lignes, ne reimporte rien.

require __DIR__ . '/barriques_lib.php';

header('Content-Type: text/plain; charset=utf-8');

if (($_GET['run'] ?? '') !== '1') {
    echo "Ajoutez ?run=1 a l'URL pour lancer la migration.\n";
    exit;
}

$pdo = dbConnect(); // cree la table si besoin

$already = (int)$pdo->query('SELECT COUNT(*) FROM mesures')->fetchColumn();
if ($already > 0) {
    echo "La table 'mesures' contient deja $already ligne(s). Migration deja faite - rien reimporte (evite les doublons).\n";
    exit;
}

$logFile = __DIR__ . '/logs/barriques.log';
if (!file_exists($logFile)) {
    echo "Aucun fichier de log trouve ($logFile).\n";
    exit;
}

$lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$total = count($lines);
$inserted = 0;
$skipped = 0;

$pdo->beginTransaction();
$stmt = $pdo->prepare(
    'INSERT INTO mesures (sensor_id, date_iso, raw, battery_mv, rssi, fw, ts) VALUES (?, ?, ?, ?, ?, ?, ?)'
);

foreach ($lines as $line) {
    $parts = preg_split("/\t+/", trim($line));
    if (!$parts || count($parts) < 7) { $skipped++; continue; }

    [$dateIso, $id, $raw, $batt, $rssi, $fw, $ts] = $parts;
    $id = trim((string)$id);
    if ($id === '') { $skipped++; continue; }

    $stmt->execute([
        $id,
        trim((string)$dateIso),
        (int)$raw,
        ($batt === '' ? null : (int)$batt),
        ($rssi === '' ? null : (int)$rssi),
        ($fw === '' ? null : trim((string)$fw)),
        (int)$ts,
    ]);
    $inserted++;
}

$pdo->commit();

$finalCount = (int)$pdo->query('SELECT COUNT(*) FROM mesures')->fetchColumn();

echo "Lignes lues dans le log : $total\n";
echo "Lignes inserees : $inserted\n";
echo "Lignes ignorees (format invalide) : $skipped\n";
echo "Total dans la table mesures apres migration : $finalCount\n";
