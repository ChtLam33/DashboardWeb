<?php
// update_config_from_csv.php — ajoute uniquement les nouveaux IDs depuis data_cuves.csv

require __DIR__ . '/lock_lib.php';

$csvFile  = __DIR__ . "/data_cuves.csv";
$jsonFile = __DIR__ . "/config_cuves.json";

$csvContent = lockedRead($csvFile);
if ($csvContent === null) {
    echo "❌ Fichier CSV introuvable.";
    exit;
}

// --- Lecture des IDs présents dans le CSV ---
$lines  = ($csvContent === '') ? [] : preg_split('/\r\n|\r|\n/', $csvContent, -1, PREG_SPLIT_NO_EMPTY);
$newIds = [];

foreach ($lines as $line) {
    $parts = str_getcsv($line, ";");
    if (count($parts) >= 3) {
        $id      = trim($parts[1]); // 2e colonne = id ESP
        $nomCuve = trim($parts[2]); // 3e colonne = nom cuve
// ✅ on ignore les mauvaises valeurs (on évite juste l'en-tête et les trucs vides)
if ($id && $id !== "id" && $id !== "cuve") {
    $newIds[$id] = $nomCuve ?: "Cuve_?";
}
    }
}

// --- Vérifie chaque ID du CSV et ajoute les nouveaux, sous un seul
//     verrou continu (lecture + ajout + ecriture) pour ne pas ecraser
//     une modif concurrente de save_config.php / save_order.php / purge_cuves.php ---
$config = lockedReadModifyWriteJson($jsonFile, function ($config) use ($newIds) {
    if (!is_array($config)) $config = [];

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

    return $config;
});

echo "✅ Vérification terminée (" . count($config) . " capteurs au total).";
?>