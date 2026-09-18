<?php
// api_cuve.php — Réception des données envoyées par ESP32 (POST JSON)
// Met à jour la ligne de la cuve correspondante (via identifiant matériel "id")
// et conserve uniquement la dernière mesure de chaque cuve.
//
// vFW patch (minimal + robuste) :
// - Ajout colonne fw (version firmware) dans data_cuves.csv
// - Compatibilité avec un CSV existant "ancien format" (sans fw) :
//   on complète les anciennes lignes avec une colonne vide pour éviter
//   que update_cache.php les ignore (sinon count(cols) < count(header)).

header("Content-Type: application/json; charset=utf-8");

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["error" => "Méthode non autorisée."]);
    exit;
}

$input = file_get_contents("php://input");
$data  = json_decode($input, true);

if (!$data || !is_array($data)) {
    http_response_code(400);
    echo json_encode(["error" => "Données JSON invalides."]);
    exit;
}

// --- Données reçues depuis l'ESP32 ---
$id          = htmlspecialchars(trim((string)($data['id']   ?? 'sans_id')));
$cuve        = htmlspecialchars(trim((string)($data['cuve'] ?? 'inconnue')));

$distance    = floatval($data['distance']    ?? 0);
$volume      = floatval($data['volume']      ?? 0);
$capacite    = floatval($data['capacite']    ?? 0);
$pourcentage = floatval($data['pourcentage'] ?? 0);
$correction  = floatval($data['correction']  ?? 0);

// ⚠ On garde les décimales
$hauteurPlein = round(floatval($data['hauteurPlein'] ?? 0), 1, PHP_ROUND_HALF_UP);
$hauteurCuve  = round(floatval($data['hauteurCuve']  ?? 0), 1, PHP_ROUND_HALF_UP);

$rssi = intval($data['rssi'] ?? 0); // force du signal Wi-Fi (dBm)

// ✅ Nouveau : version firmware (optionnel)
$fw = htmlspecialchars(trim((string)($data['fw'] ?? '')));

// --- Fichier de stockage ---
$file   = __DIR__ . "/data_cuves.csv";
$header = "datetime;id;cuve;distance_cm;volume_hl;capacite_hl;pourcentage;correction;hauteur_plein_cm;hauteur_cuve_cm;rssi;fw";

// Verrou sur TOUTE l'operation lecture+ecriture (pas juste l'ecriture finale) :
// plusieurs capteurs peuvent poster a quelques secondes d'intervalle (constate
// en pratique), et une simple lecture sans verrou pouvait faire perdre la mise
// a jour d'un autre capteur (celui qui ecrit en dernier ecrasait le travail de
// l'autre avec les donnees qu'il avait lues avant que l'autre n'ecrive).
$fp = fopen($file, 'c+');
if ($fp === false) {
    http_response_code(500);
    echo json_encode(["error" => "Impossible d'ouvrir le fichier de donnees."]);
    exit;
}
if (!flock($fp, LOCK_EX)) {
    fclose($fp);
    http_response_code(500);
    echo json_encode(["error" => "Impossible de verrouiller le fichier de donnees."]);
    exit;
}

// A partir d'ici et jusqu'au flock(LOCK_UN) plus bas, on est seul a pouvoir
// lire/ecrire ce fichier - toute autre requete concurrente attend son tour.
$content = stream_get_contents($fp);
if ($content === false || trim($content) === '') {
    // Fichier vide (ou tout juste cree) : on part avec juste l'en-tete
    $lines = [$header];
} else {
    $lines = preg_split('/\r\n|\r|\n/', $content, -1, PREG_SPLIT_NO_EMPTY);
}

$newLines = [];
$found    = false;

// On repart toujours d'un en-tête canonique, quoi qu'il arrive
$newLines[] = trim($header);

// Nombre de colonnes attendu (nouveau format)
$expectedCols = 12;

// Parcours des anciennes lignes, en ignorant l'ancienne première ligne
foreach ($lines as $index => $line) {
    // On saute l'ancien header (index 0 ou toute ligne qui commence par "datetime")
    if ($index === 0 || str_starts_with($line, 'datetime')) {
        continue;
    }

    $cols = str_getcsv($line, ';');

    // Compat : si ancienne ligne sans 'fw' (11 colonnes), on ajoute une colonne vide
    if (count($cols) === 11) {
        $cols[] = ""; // fw vide
        $line = implode(";", $cols);
    }

    // Si ligne vraiment invalide, on la garde telle quelle (ou on peut la skip)
    // Ici : on la garde uniquement si elle a au moins id + cuve
    if (count($cols) < 3) {
        continue;
    }

    // Mise à jour de la ligne du capteur
    if (isset($cols[1]) && $cols[1] === $id) {
        $newLine = sprintf(
            "%s;%s;%s;%d;%.2f;%.2f;%.2f;%.2f;%.1f;%.1f;%d;%s",
            date('Y-m-d H:i:s'),
            $id,
            $cuve,
            (int)$distance,
            $volume,
            $capacite,
            $pourcentage,
            $correction,
            $hauteurPlein,
            $hauteurCuve,
            $rssi,
            $fw
        );
        $newLines[] = $newLine;
        $found = true;
    } else {
        // On garde la ligne (déjà normalisée si besoin)
        // Sécurité : si après normalisation il manque encore des colonnes, on complète
        $cols2 = str_getcsv($line, ';');
        while (count($cols2) < $expectedCols) {
            $cols2[] = "";
        }
        $newLines[] = implode(";", $cols2);
    }
}

// Si pas encore présent, on ajoute une nouvelle ligne
if (!$found) {
    $newLine = sprintf(
        "%s;%s;%s;%d;%.2f;%.2f;%.2f;%.2f;%.1f;%.1f;%d;%s",
        date('Y-m-d H:i:s'),
        $id,
        $cuve,
        (int)$distance,
        $volume,
        $capacite,
        $pourcentage,
        $correction,
        $hauteurPlein,
        $hauteurCuve,
        $rssi,
        $fw
    );
    $newLines[] = $newLine;
}

// Écriture sécurisée du fichier CSV : meme handle, toujours sous le meme
// verrou pose plus haut (rien d'autre n'a pu lire/ecrire entre-temps).
rewind($fp);
ftruncate($fp, 0);
fwrite($fp, implode("\n", $newLines) . "\n");
fflush($fp);
flock($fp, LOCK_UN);
fclose($fp);

// Réponse JSON au capteur
echo json_encode([
    "status"    => "OK",
    "id"        => $id,
    "cuve"      => $cuve,
    "fw"        => $fw,
    "timestamp" => date('Y-m-d H:i:s')
], JSON_UNESCAPED_UNICODE);