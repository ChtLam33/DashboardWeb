<?php

/**
 * Analyse une mesure RAW ADC et renvoie :
 * - niveau (1–7)
 * - couleur
 * - creux en cm (min/max)
 * - creux en litres (min/max)
 */

function interpret_raw($raw)
{
    $raw = intval($raw);

    // Structure de la grille de paliers
    $paliers = [
        [
            'min' => 1600,
            'max' => 99999,
            'niveau' => 1,
            'couleur' => 'vert',
            'creux_cm' => [0.0, 2.5],
            'creux_l'  => [0.0, 0.9],
        ],
        [
            'min' => 1400,
            'max' => 1599,
            'niveau' => 2,
            'couleur' => 'jaune',
            'creux_cm' => [2.5, 3.5],
            'creux_l'  => [0.9, 2.0],
        ],
        [
            'min' => 1200,
            'max' => 1399,
            'niveau' => 3,
            'couleur' => 'orange',
            'creux_cm' => [3.5, 4.5],
            'creux_l'  => [2.0, 4.2],
        ],
        [
            'min' => 900,
            'max' => 1199,
            'niveau' => 4,
            'couleur' => 'rouge',
            'creux_cm' => [4.5, 5.5],
            'creux_l'  => [4.2, 6.2],
        ],
        [
            'min' => 500,
            'max' => 899,
            'niveau' => 5,
            'couleur' => 'rouge_vif',
            'creux_cm' => [5.5, 7.0],
            'creux_l'  => [6.2, 8.0],
        ],
        [
            'min' => 1,
            'max' => 499,
            'niveau' => 6,
            'couleur' => 'rouge_ultra',
            'creux_cm' => [7.0, 10.0],
            'creux_l'  => [8.0, 12.0],
        ],
        [
            'min' => 0,
            'max' => 0,
            'niveau' => 7,
            'couleur' => 'erreur',
            'creux_cm' => [null, null],
            'creux_l'  => [null, null],
        ],
    ];

    foreach ($paliers as $p) {
        if ($raw >= $p['min'] && $raw <= $p['max']) {
            return [
                'niveau'   => $p['niveau'],
                'couleur'  => $p['couleur'],
                'raw'      => $raw,
                'creux_cm_min' => $p['creux_cm'][0],
                'creux_cm_max' => $p['creux_cm'][1],
                'creux_l_min'  => $p['creux_l'][0],
                'creux_l_max'  => $p['creux_l'][1],
            ];
        }
    }

    // fallback
    return [
        'niveau' => 7,
        'couleur' => 'erreur',
        'raw' => $raw,
        'creux_cm_min' => null,
        'creux_cm_max' => null,
        'creux_l_min' => null,
        'creux_l_max' => null,
    ];
}

// ======================================================
//  Gestion de l'historique des lots par capteur
//  Fichier : lot_history.json
//  Structure :
//  {
//    "330989340": [
//       { "lot": "L24", "from_ts": 1764986000, "to_ts": 1767500000 },
//       { "lot": "L25", "from_ts": 1767500001, "to_ts": null }
//    ],
//    ...
//  }
// ======================================================

/**
 * Charge l'historique des lots.
 *
 * @return array
 */
function loadLotHistory(): array
{
    $file = __DIR__ . '/lot_history.json';
    if (!file_exists($file)) {
        return [];
    }
    $json = file_get_contents($file);
    $data = json_decode($json, true);
    return is_array($data) ? $data : [];
}

/**
 * Sauvegarde l'historique des lots.
 *
 * @param array $history
 * @return void
 */
function saveLotHistory(array $history): void
{
    $file = __DIR__ . '/lot_history.json';
    file_put_contents(
        $file,
        json_encode($history, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
    );
}

/**
 * Met à jour l'historique des lots pour un capteur donné
 * quand son lot change (L24 -> L25, ou vide -> L24, etc.).
 *
 * - Si $oldLot = '' et $newLot != '' :
 *      on crée un nouveau segment { lot: newLot, from_ts: now, to_ts: null }
 * - Si $oldLot != '' et $newLot = '' :
 *      on "ferme" le dernier segment (to_ts = now - 1)
 * - Si $oldLot != '' et $newLot != '' et différent :
 *      on ferme le dernier segment (to_ts = now - 1)
 *      puis on crée un nouveau segment pour $newLot
 *
 * @param string $sensorId
 * @param string $oldLot
 * @param string $newLot
 * @param int|null $changeTs timestamp UNIX (par défaut time())
 * @return void
 */
function updateLotHistoryOnLotChange(string $sensorId, string $oldLot, string $newLot, ?int $changeTs = null): void
{
    $changeTs = $changeTs ?? time();
    $sensorId = (string)$sensorId;

    // Pas de changement
    if ($oldLot === $newLot) {
        return;
    }

    $history = loadLotHistory();
    $segments = $history[$sensorId] ?? [];

    // On ferme le dernier segment si besoin
    if (!empty($segments)) {
        $lastIndex = count($segments) - 1;
        if ($segments[$lastIndex]['to_ts'] === null) {
            // On ferme seulement si le dernier lot correspond à l'ancien lot
            // ou si on passe à "aucun lot"
            if ($oldLot === '' || $segments[$lastIndex]['lot'] === $oldLot) {
                $segments[$lastIndex]['to_ts'] = $changeTs - 1;
            }
        }
    }

    // Si un nouveau lot est défini, on crée un segment "ouvert"
    if ($newLot !== '') {
        $segments[] = [
            'lot'     => $newLot,
            'from_ts'=> $changeTs,
            'to_ts'  => null,
        ];
    }

    // On ré-enregistre pour ce capteur
    $history[$sensorId] = $segments;
    saveLotHistory($history);
}