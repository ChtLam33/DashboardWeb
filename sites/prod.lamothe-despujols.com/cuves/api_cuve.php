<?php
// api_cuve.php — Réception des données envoyées par ESP32 (POST JSON)
// Met à jour la ligne de la cuve correspondante (via identifiant matériel "id")
// et conserve uniquement la dernière mesure de chaque cuve

header("Content-Type: application/json; charset=utf-8");

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["error" => "Méthode non autorisée."]);
    exit;
}

$input = file_get_contents("php://input");
$data  = json_decode($input, true);

if (!$data) {
    http_response_code(400);
    echo json_encode(["error" => "Données JSON invalides."]);
    exit;
}

// --- Données reçues depuis l'ESP32 ---
$id           = htmlspecialchars($data['id']   ?? 'sans_id');
$cuve         = htmlspecialchars($data['cuve'] ?? 'inconnue');
$distance     = floatval($data['distance']     ?? 0);
$volume       = floatval($data['volume']       ?? 0);
$capacite     = floatval($data['capacite']     ?? 0);
$pourcentage  = floatval($data['pourcentage']  ?? 0);
$correction   = floatval($data['correction']   ?? 0);

// ⚠ ICI : on passe en float pour garder les décimales
$hauteurPlein = round(floatval($data['hauteurPlein'] ?? 0), 1, PHP_ROUND_HALF_UP);
$hauteurCuve  = round(floatval($data['hauteurCuve']  ?? 0), 1, PHP_ROUND_HALF_UP);

$rssi         = intval($data['rssi'] ?? 0);  // force du signal Wi-Fi (dBm)

// --- Fichier de stockage ---
$file   = __DIR__ . "/data_cuves.csv";
$header = "datetime;id;cuve;distance_cm;volume_hl;capacite_hl;pourcentage;correction;hauteur_plein_cm;hauteur_cuve_cm;rssi";

// Si le fichier n'existe pas, on le crée avec l'en-tête complet
if (!file_exists($file)) {
    file_put_contents($file, $header . "\n");
}

// Lecture du CSV existant
$lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
if ($lines === false) {
    $lines = [];
}

$newLines = [];
$found    = false;

// On repart toujours d'un en-tête canonique, quoi qu'il arrive
$headerLine = trim($header);
$newLines[] = $headerLine;

// Parcours des anciennes lignes, en ignorant l'ancienne première ligne
foreach ($lines as $index => $line) {
    // On saute l'ancien header (index 0 ou toute ligne qui commence par "datetime")
    if ($index === 0 || str_starts_with($line, 'datetime')) {
        continue;
    }

    $cols = str_getcsv($line, ';');

    if (isset($cols[1]) && $cols[1] === $id) {
        // On remplace la ligne existante
        $newLine = sprintf(
            "%s;%s;%s;%d;%.2f;%.2f;%.2f;%.2f;%.1f;%.1f;%d",
            date('Y-m-d H:i:s'),
            $id,
            $cuve,
            $distance,
            $volume,
            $capacite,
            $pourcentage,
            $correction,
            $hauteurPlein,
            $hauteurCuve,
            $rssi
        );
        $newLines[] = $newLine;
        $found = true;
    } else {
        // On garde la ligne telle quelle
        $newLines[] = $line;
    }
}

// Si pas encore présent, on ajoute une nouvelle ligne
if (!$found) {
    $newLine = sprintf(
        "%s;%s;%s;%d;%.2f;%.2f;%.2f;%.2f;%.1f;%.1f;%d",
        date('Y-m-d H:i:s'),
        $id,
        $cuve,
        $distance,
        $volume,
        $capacite,
        $pourcentage,
        $correction,
        $hauteurPlein,
        $hauteurCuve,
        $rssi
    );
    $newLines[] = $newLine;
}

// Écriture sécurisée du fichier CSV
file_put_contents($file, implode("\n", $newLines) . "\n", LOCK_EX);

// Réponse JSON au capteur
echo json_encode([
    "status"    => "OK",
    "id"        => $id,
    "cuve"      => $cuve,
    "timestamp" => date('Y-m-d H:i:s')
]);