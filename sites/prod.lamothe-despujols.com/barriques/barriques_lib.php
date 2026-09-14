<?php
// barriques_lib.php

/* =========================================================
   PATHS
   ========================================================= */
function creuxOffsetsFilePath(): string {
    return __DIR__ . '/offsets_creux.json';
}

function lotHistoryFilePath(): string {
    return __DIR__ . '/lot_history.json';
}

/* =========================================================
   OFFSETS CREUX
   - offsets_creux.json : { "330989340": 0.7, "330989341": -0.3, ... }
   - offset en cm, appliqué sur creux_cm et dérivé en litres
   ========================================================= */
function loadCreuxOffsets(): array {
    $file = creuxOffsetsFilePath();
    if (!file_exists($file)) return [];
    $json = file_get_contents($file);
    $data = json_decode($json, true);
    return is_array($data) ? $data : [];
}

function saveCreuxOffsets(array $offsets): void {
    $file = creuxOffsetsFilePath();
    file_put_contents($file, json_encode($offsets, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function getCreuxOffsetForSensor(string $sensorId): float {
    static $cache = null;
    if ($cache === null) {
        $cache = loadCreuxOffsets();
    }
    $sensorId = (string)$sensorId;

    if (!isset($cache[$sensorId])) return 0.0;

    $v = $cache[$sensorId];
    if (is_numeric($v)) return (float)$v;

    return 0.0;
}

/* =========================================================
   INTERPRÉTATION RAW (palier) + offset creux en cm (optionnel)
   ========================================================= */
function interpret_raw(int $raw, float $offsetCm = 0.0): array {
    $raw = (int)$raw;

    // Grille d'origine
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
            'min' => 0,
            'max' => 499,
            'niveau' => 6,
            'couleur' => 'rouge_ultra',
            'creux_cm' => [7.0, 10.0],
            'creux_l'  => [8.0, 12.0],
        ],
    ];

    foreach ($paliers as $p) {
        if ($raw >= $p['min'] && $raw <= $p['max']) {

            $cmMin = $p['creux_cm'][0];
            $cmMax = $p['creux_cm'][1];
            $lMin  = $p['creux_l'][0];
            $lMax  = $p['creux_l'][1];

            // Offset cm (empêcher creux négatif)
            if ($cmMin !== null && $cmMax !== null) {
                $cmMin = (float)$cmMin + $offsetCm;
                $cmMax = (float)$cmMax + $offsetCm;
                if ($cmMin < 0) $cmMin = 0.0;
                if ($cmMax < 0) $cmMax = 0.0;
            }

            // Conversion offset -> litres (interpolation sur le palier)
            if ($lMin !== null && $lMax !== null && $p['creux_cm'][0] !== null && $p['creux_cm'][1] !== null) {
                $baseCmMin = (float)$p['creux_cm'][0];
                $baseCmMax = (float)$p['creux_cm'][1];
                $baseLCm   = ($baseCmMax > $baseCmMin)
                    ? (((float)$lMax - (float)$lMin) / ($baseCmMax - $baseCmMin))
                    : 0.0;

                $lMin = (float)$lMin + ($offsetCm * $baseLCm);
                $lMax = (float)$lMax + ($offsetCm * $baseLCm);
                if ($lMin < 0) $lMin = 0.0;
                if ($lMax < 0) $lMax = 0.0;
            }

            return [
                'niveau'       => $p['niveau'],
                'couleur'      => $p['couleur'],
                'raw'          => $raw,
                'creux_cm_min' => ($cmMin === null ? null : round((float)$cmMin, 1)),
                'creux_cm_max' => ($cmMax === null ? null : round((float)$cmMax, 1)),
                'creux_l_min'  => ($lMin  === null ? null : round((float)$lMin,  1)),
                'creux_l_max'  => ($lMax  === null ? null : round((float)$lMax,  1)),
            ];
        }
    }

    // Normalement inatteignable depuis tes min/max, mais on garde un fallback
    return [
        'niveau'       => 7,
        'couleur'      => 'erreur',
        'raw'          => $raw,
        'creux_cm_min' => null,
        'creux_cm_max' => null,
        'creux_l_min'  => null,
        'creux_l_max'  => null,
    ];
}

/* =========================================================
   LOT HISTORY
   Compatibilité :
   - Format A : { "id": [ {lot, from_ts, to_ts, barriques?}, ... ] }
   - Format "plat" (sécurité) : [ {id, lot, from_ts/start_ts, to_ts/end_ts, barriques?}, ... ]
   ========================================================= */
function loadLotHistory(): array {
    $file = lotHistoryFilePath();
    if (!file_exists($file)) return [];
    $json = file_get_contents($file);
    $data = json_decode($json, true);
    return is_array($data) ? $data : [];
}

function saveLotHistory(array $history): void {
    $file = lotHistoryFilePath();
    file_put_contents($file, json_encode($history, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

/**
 * Normalise lot_history en format assoc :
 *  [id] => [ ['lot'=>..., 'from_ts'=>..., 'to_ts'=>..., 'barriques'=>...], ... ]
 */
function normalizeLotHistory(array $hist): array {
    if (empty($hist)) return [];

    // Déjà assoc ?
    $isAssoc = array_keys($hist) !== range(0, count($hist) - 1);
    if ($isAssoc) {
        // s'assurer que chaque segment a barriques (option B : 0 si inconnu)
        foreach ($hist as $sid => &$periods) {
            if (!is_array($periods)) { $periods = []; continue; }
            foreach ($periods as &$p) {
                if (!is_array($p)) { $p = []; continue; }
                if (!isset($p['barriques'])) $p['barriques'] = 0;
                // harmoniser clés éventuelles
                if (!isset($p['from_ts']) && isset($p['start_ts'])) $p['from_ts'] = (int)$p['start_ts'];
                if (!array_key_exists('to_ts', $p) && array_key_exists('end_ts', $p)) $p['to_ts'] = $p['end_ts'];
            }
            unset($p);

            usort($periods, fn($a,$b) => ((int)($a['from_ts'] ?? 0)) <=> ((int)($b['from_ts'] ?? 0)));
        }
        unset($periods);

        return $hist;
    }

    // Format plat => reconstruire assoc
    $out = [];
    foreach ($hist as $entry) {
        if (!is_array($entry)) continue;

        $id  = trim((string)($entry['id'] ?? ''));
        $lot = trim((string)($entry['lot'] ?? ''));
        if ($id === '' || $lot === '') continue;

        $fromTs = 0;
        if (isset($entry['from_ts'])) $fromTs = (int)$entry['from_ts'];
        elseif (isset($entry['start_ts'])) $fromTs = (int)$entry['start_ts'];

        $toRaw = $entry['to_ts'] ?? ($entry['end_ts'] ?? null);
        $toTs  = ($toRaw === null) ? null : (int)$toRaw;

        if ($fromTs <= 0) continue;

        $bar = isset($entry['barriques']) ? (int)$entry['barriques'] : 0;

        $out[$id] ??= [];
        $out[$id][] = [
            'lot'       => $lot,
            'from_ts'   => $fromTs,
            'to_ts'     => $toTs,
            'barriques' => $bar,
        ];
    }

    foreach ($out as &$periods) {
        usort($periods, fn($a,$b) => ((int)$a['from_ts']) <=> ((int)$b['from_ts']));
        // option B : barriques toujours présent
        foreach ($periods as &$p) {
            if (!isset($p['barriques'])) $p['barriques'] = 0;
        }
        unset($p);
    }
    unset($periods);

    return $out;
}

/**
 * Retourne [id] => periods normalisés.
 */
function loadLotHistoryNormalized(): array {
    $hist = loadLotHistory();
    return normalizeLotHistory(is_array($hist) ? $hist : []);
}

/**
 * Retourne toutes les périodes d'un capteur (id), normalisées.
 */
function getLotPeriodsForSensor(string $sensorId, ?array $histNorm = null): array {
    $sensorId = (string)$sensorId;
    if ($histNorm === null) $histNorm = loadLotHistoryNormalized();
    $periods = $histNorm[$sensorId] ?? [];
    return is_array($periods) ? $periods : [];
}

/**
 * Retourne les segments pour un lot donné (tous capteurs), sous forme de liste :
 *  [ ['id'=>..., 'from_ts'=>..., 'to_ts'=>..., 'barriques'=>...], ... ]
 */
function getSegmentsForLot(string $lot, ?array $histNorm = null): array {
    $lot = trim((string)$lot);
    if ($lot === '') return [];
    if ($histNorm === null) $histNorm = loadLotHistoryNormalized();

    $out = [];
    foreach ($histNorm as $sid => $periods) {
        if (!is_array($periods)) continue;
        foreach ($periods as $p) {
            if (!is_array($p)) continue;
            $pLot = trim((string)($p['lot'] ?? ''));
            if ($pLot !== $lot) continue;

            $fromTs = (int)($p['from_ts'] ?? 0);
            $toTs   = array_key_exists('to_ts', $p) ? $p['to_ts'] : null;
            $toTs   = ($toTs === null) ? null : (int)$toTs;
            if ($fromTs <= 0) continue;

            $out[] = [
                'id'        => (string)$sid,
                'from_ts'   => $fromTs,
                'to_ts'     => $toTs,
                'barriques' => isset($p['barriques']) ? (int)$p['barriques'] : 0,
            ];
        }
    }

    usort($out, fn($a,$b) => ((int)$a['from_ts']) <=> ((int)$b['from_ts']));
    return $out;
}

/**
 * True si un lot a AU MOINS une période ouverte (to_ts=null).
 */
function lotHasOpenSegment(string $lot, ?array $histNorm = null): bool {
    $lot = trim((string)$lot);
    if ($lot === '') return false;
    if ($histNorm === null) $histNorm = loadLotHistoryNormalized();

    foreach ($histNorm as $sid => $periods) {
        if (!is_array($periods)) continue;
        foreach ($periods as $p) {
            if (!is_array($p)) continue;
            if (trim((string)($p['lot'] ?? '')) !== $lot) continue;
            if (!array_key_exists('to_ts', $p) || $p['to_ts'] === null) return true;
        }
    }
    return false;
}

/**
 * Update lot history on lot change
 * + option : figer "barriques" sur le segment fermé et écrire "barriques" sur le segment ouvert
 *
 * Option B : si barriques inconnu => 0.
 */
function updateLotHistoryOnLotChange(
    string $sensorId,
    string $oldLot,
    string $newLot,
    ?int $changeTs = null,
    ?int $oldLotBarriques = null,
    ?int $newLotBarriques = null
): void {
    $changeTs = $changeTs ?? time();
    $sensorId = (string)$sensorId;

    if ($oldLot === $newLot) return;

    $history  = loadLotHistory();              // on garde le fichier tel quel (format A attendu)
    $segments = $history[$sensorId] ?? [];

    // 1) Fermer le segment ouvert si existant
    if (!empty($segments)) {
        $lastIndex = count($segments) - 1;
        if (($segments[$lastIndex]['to_ts'] ?? null) === null) {
            $segLot = (string)($segments[$lastIndex]['lot'] ?? '');
            if ($oldLot === '' || $segLot === $oldLot) {
                $segments[$lastIndex]['to_ts'] = $changeTs - 1;

                // figer barriques sur le lot qu'on ferme (si info fournie)
                if ($oldLotBarriques !== null) {
                    $segments[$lastIndex]['barriques'] = (int)$oldLotBarriques;
                } else {
                    // si déjà présent on garde, sinon 0 (option B)
                    if (!isset($segments[$lastIndex]['barriques'])) {
                        $segments[$lastIndex]['barriques'] = 0;
                    }
                }
            }
        }
    }

    // 2) Ouvrir un segment pour le nouveau lot
    if ($newLot !== '') {
        $segments[] = [
            'lot'       => $newLot,
            'from_ts'   => $changeTs,
            'to_ts'     => null,
            'barriques' => (int)($newLotBarriques ?? 0), // option B : 0 si inconnu
        ];
    }

    $history[$sensorId] = $segments;
    saveLotHistory($history);
}

/* =========================================================
   LOG READER (optionnel mais pratique)
   ========================================================= */
function read_barriques_log_records(string $logFile): array {
    if (!file_exists($logFile)) return [];
    $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    return is_array($lines) ? $lines : [];
}