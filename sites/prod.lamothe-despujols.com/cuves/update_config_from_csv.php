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
        $id      = trim($parts[1]); // 2e colonne = id ESP
        $nomCuve = trim($parts[2]); // 3e colonne = nom cuve
        // ✅ on ignore les mauvaises valeurs
        if ($id && $id !== "id" && $id !== "cuve" && stripos($id, "ESP") === 0) {
            $newIds[$id] = $nomCuve ?: "Cuve_?";
        }
    }
}

// --- Lecture du JSON actuel ---
$config = [];
if (file_exists($jsonFile)) {
    $config = json_decode(file_get_contents($jsonFile), true);
    if (!is_array($config)) $config = [];
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
        // ➕ Ajoute seulement les nouveaux
        $config[] = [
            "id"                 => $id,
            "nomCuve"            => $nomCuve,
            "lot"                => "",      // nouveau champ, vide par défaut
            "hauteurCapteurFond" => 200,
            "hauteurMaxLiquide"  => 50,
            "diametreCuve"       => 70,
            "AjustementHL"       => 0.00
        ];
        echo "➕ Nouveau capteur détecté : $id ($nomCuve)\n";
    }
}

// --- Sauvegarde si ajout(s) ---
file_put_contents($jsonFile, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

echo "✅ Vérification terminée (" . count($config) . " capteurs au total).";
?>