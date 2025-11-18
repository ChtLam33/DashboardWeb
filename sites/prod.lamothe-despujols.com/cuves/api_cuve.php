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
$data = json_decode($input, true);

if (!$data) {
    http_response_code(400);
    echo json_encode(["error" => "Données JSON invalides."]);
    exit;
}

// --- Données reçues depuis l'ESP32 ---
$id           = htmlspecialchars($data['id'] ?? 'sans_id');
$cuve         = htmlspecialchars($data['cuve'] ?? 'inconnue');
$distance     = floatval($data['distance'] ?? 0);
$volume       = floatval($data['volume'] ?? 0);
$capacite     = floatval($data['capacite'] ?? 0);
$pourcentage  = floatval($data['pourcentage'] ?? 0);
$correction   = floatval($data['correction'] ?? 0);
$hauteurPlein = intval($data['hauteurPlein'] ?? 0);
$hauteurCuve  = intval($data['hauteurCuve'] ?? 0);
$rssi         = intval($data['rssi'] ?? 0);  // force du signal Wi-Fi (dBm)

// --- Fichier de stockage ---
$file   = __DIR__ . "/data_cuves.csv";
$header = "datetime;id;cuve;distance_cm;volume_hl;capacite_hl;pourcentage;correction;hauteur_plein_cm;hauteur_cuve_cm;rssi";

// On va construire un tableau $lines qui contient toujours une première ligne = en-tête
$lines = [];

// 1) Si le fichier n'existe pas → on crée un tableau avec juste l'en-tête
if (!file_exists($file)) {
    $lines[] = $header;
} else {
    // 2) Le fichier existe → on lit les lignes (en ignorant les lignes totalement vides)
    $rawLines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    // a) Fichier vide → on remet l'en-tête
    if (empty($rawLines)) {
        $lines[] = $header;
    } else {
        // b) Si la première ligne ne commence pas par "datetime" → on préprend l'en-tête
        if (!str_starts_with($rawLines[0], 'datetime')) {
            $lines[] = $header;
            foreach ($rawLines as $ln) {
                $lines[] = $ln;
            }
        } else {
            // c) En-tête présente → on s'assure qu'elle contient bien "rssi"
            $first = $rawLines[0];
            if (!str_contains($first, 'rssi')) {
                $first = rtrim($first, " \t\r\n") . ';rssi';
            }
            $lines[] = $first;

            // On recopie le reste des lignes telles quelles
            for ($i = 1; $i < count($rawLines); $i++) {
                $lines[] = $rawLines[$i];
            }
        }
    }
}

// À partir d'ici, $lines[0] est garanti être une en-tête valide
$newLines = [];
$found    = false;

// Mise à jour ou ajout de la ligne correspondante
foreach ($lines as $line) {
    // On laisse passer l'en-tête telle quelle
    if (str_starts_with($line, 'datetime')) {
        $newLines[] = $line;
        continue;
    }

    $cols = str_getcsv($line, ';');

    if (isset($cols[1]) && $cols[1] === $id) {
        // On remplace la ligne existante pour ce capteur
        $newLine = sprintf(
            "%s;%s;%s;%d;%.2f;%.2f;%.2f;%.2f;%d;%d;%d",
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
        // On recopie les autres lignes telles quelles
        $newLines[] = $line;
    }
}

// Si pas encore présent, on ajoute une nouvelle ligne pour ce capteur
if (!$found) {
    $newLine = sprintf(
        "%s;%s;%s;%d;%.2f;%.2f;%.2f;%.2f;%d;%d;%d",
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
?>
