<?php
// update_config_from_csv.php — ajoute uniquement les nouveaux IDs depuis data_cuves.csv

$csvFile  = __DIR__ . "/data_cuves.csv";
$jsonFile = __DIR__ . "/config_cuves.json";

if (!file_exists($csvFile)) {
    echo "❌ Fichier CSV introuvable.";
    exit;
}

// --- Lecture des IDs présents dans le CSV ---
$lines  = file($csvFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$newIds = [];

foreach ($lines as $line) {
    $parts = str_getcsv($line, ";");
    if (count($parts) >= 3) {
        $id      = trim($parts[1]); // 2e colonne = id capteur
        $nomCuve = trim($parts[2]); // 3e colonne = nom cuve

        // On ignore la ligne d'en-tête et les IDs vides
        if ($id && $id !== "id" && $id !== "cuve") {
            // Si le nom de cuve est vide, on met un placeholder
            if ($nomCuve === "") {
                $nomCuve = "Cuve_?";
            }
            $newIds[$id] = $nomCuve;
        }
    }
}

// --- Lecture du JSON actuel ---
$config = [];
if (file_exists($jsonFile)) {
    $config = json_decode(file_get_contents($jsonFile), true);
    if (!is_array($config)) {
        $config = [];
    }
}

// --- Vérifie chaque ID du CSV ---
foreach ($newIds as $id => $nomCuve) {
    $exists = false;
    foreach ($config as $entry) {
        if (isset($entry['id']) && $entry['id'] === $id) {
            $exists = true;
            break;
        }
    }

    if (!$exists) {
        // ➕ Ajoute seulement les nouveaux capteurs
        $config[] = [
            "id"                 => $id,
            "nomCuve"            => $nomCuve,
            "hauteurCapteurFond" => 200,
            "hauteurMaxLiquide"  => 50,
            "diametreCuve"       => 70,
            "AjustementHL"       => 0.00
        ];
        echo "➕ Nouveau capteur détecté : $id ($nomCuve)\n";
    }
}

// --- Sauvegarde mise à jour ---
file_put_contents($jsonFile, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

echo "✅ Vérification terminée (" . count($config) . " capteurs au total).";
