<?php
// update_cache.php — met à jour le cache JSON à partir de data_cuves.csv
// Appelé depuis le bouton "Actualiser" du dashboard

header("Content-Type: application/json; charset=utf-8");

$file       = __DIR__ . "/data_cuves.csv";
$cacheFile  = __DIR__ . "/cache_dashboard.json";
$configFile = __DIR__ . "/config_cuves.json";

$result     = [];

// ---------------------------------------------------------
// 1) Lecture du CSV de mesures (data_cuves.csv)
// ---------------------------------------------------------
if (file_exists($file)) {
    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    if (count($lines) > 1) {
        // Première ligne = en-tête CSV
        $header = str_getcsv(array_shift($lines), ';');

        foreach ($lines as $line) {
            $cols = str_getcsv($line, ';');

            // On s'assure que le nombre de colonnes correspond à l'en-tête
            if (count($cols) < count($header)) {
                continue;
            }

            $row = array_combine($header, $cols);
            if (!$row) {
                continue;
            }

            // Harmonisation des noms de clés pour correspondre au dashboard
            $normalized = [
                "id"           => $row["id"]       ?? "",
                "cuve"         => $row["cuve"]     ?? "",

                // datetime de la dernière mesure (string brute)
                "datetime"     => $row["datetime"] ?? "",

                // Valeurs numériques
                "distance_cm"  => isset($row["distance_cm"])      ? floatval($row["distance_cm"])      : null,
                "volume_hl"    => isset($row["volume_hl"])        ? floatval($row["volume_hl"])        : null,
                "capacite_hl"  => isset($row["capacite_hl"])      ? floatval($row["capacite_hl"])      : null,
                "pourcentage"  => isset($row["pourcentage"])      ? floatval($row["pourcentage"])      : null,
                "correction"   => isset($row["correction"])       ? floatval($row["correction"])       : null,
                "hauteurPlein" => isset($row["hauteur_plein_cm"]) ? floatval($row["hauteur_plein_cm"]) : null,
                "hauteurCuve"  => isset($row["hauteur_cuve_cm"])  ? floatval($row["hauteur_cuve_cm"])  : null,
                "rssi"         => isset($row["rssi"])             ? intval($row["rssi"])               : null,
            ];

            $result[] = $normalized;
        }
    }
}

// ---------------------------------------------------------
// 2) Lecture de config_cuves.json pour récupérer l'ordre voulu
//    (le même que dans le popup Paramètres)
// ---------------------------------------------------------
$orderIndex = []; // id_capteur => position

if (file_exists($configFile)) {
    $configJson = file_get_contents($configFile);
    $configData = json_decode($configJson, true);

    if (is_array($configData)) {
        foreach ($configData as $pos => $cuveCfg) {
            if (isset($cuveCfg['id'])) {
                $orderIndex[$cuveCfg['id']] = $pos; // 0,1,2,3...
            }
        }
    }
}

// ---------------------------------------------------------
// 3) Tri de $result selon l'ordre de config_cuves.json
// ---------------------------------------------------------
if (!empty($orderIndex) && !empty($result)) {
    usort($result, function ($a, $b) use ($orderIndex) {
        $idA = $a['id'] ?? '';
        $idB = $b['id'] ?? '';

        // Position dans la config, ou très grand si non trouvé
        $posA = $orderIndex[$idA] ?? PHP_INT_MAX;
        $posB = $orderIndex[$idB] ?? PHP_INT_MAX;

        if ($posA === $posB) {
            // En cas d'égalité (ou capteurs non trouvés dans la config),
            // on peut trier par nom de cuve pour garder quelque chose de stable
            return strcmp($a['cuve'] ?? '', $b['cuve'] ?? '');
        }
        return $posA <=> $posB;
    });
}

// ---------------------------------------------------------
// 4) Sauvegarde du cache (toujours les dernières valeurs)
// ---------------------------------------------------------
file_put_contents(
    $cacheFile,
    json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
);

// Réponse pour le dashboard
echo json_encode([
    "status"  => "OK",
    "updated" => count($result)
]);
?>
