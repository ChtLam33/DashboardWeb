<?php
require __DIR__ . '/barriques_lib.php';

/* =========================================================
   1) CONFIG LOTS (par capteur)
   ========================================================= */
$configLotsFile = __DIR__ . '/config_lots.json';
$configLots = [];

if (file_exists($configLotsFile)) {
    $json = file_get_contents($configLotsFile);
    $data = json_decode($json, true);
    if (is_array($data)) {
        // Format attendu : id_capteur => ['lot' => ..., 'barriques' => ...]
        $configLots = $data;
    }
}

/* =========================================================
   2) CONFIG NOTIFICATIONS (dashboard / alertes)
   ========================================================= */
$notifConfigFile = __DIR__ . '/notifications_config.json';
$notifConfig = [
    'mode'                  => 'weekly',   // off | daily | weekly
    'include_battery'       => true,
    'include_offline'       => true,
    'weekly_day'            => 2,          // 1 = lundi ... 7 = dimanche
    'measure_interval_days' => 7,
    'offline_grace_days'    => 1,
];

if (file_exists($notifConfigFile)) {
    $jsonNotif = file_get_contents($notifConfigFile);
    $dataNotif = json_decode($jsonNotif, true);
    if (is_array($dataNotif)) {
        $mode = $dataNotif['mode'] ?? 'weekly';
        if (!in_array($mode, ['off', 'daily', 'weekly'], true)) {
            $mode = 'weekly';
        }
        $notifConfig['mode']            = $mode;
        $notifConfig['include_battery'] = !empty($dataNotif['include_battery']);
        $notifConfig['include_offline'] = !empty($dataNotif['include_offline']);

        if (isset($dataNotif['weekly_day'])) {
            $wd = (int)$dataNotif['weekly_day'];
            if ($wd >= 1 && $wd <= 7) {
                $notifConfig['weekly_day'] = $wd;
            }
        }
        if (isset($dataNotif['measure_interval_days'])) {
            $notifConfig['measure_interval_days'] = max(1, (int)$dataNotif['measure_interval_days']);
        }
        if (isset($dataNotif['offline_grace_days'])) {
            $notifConfig['offline_grace_days'] = max(0, (int)$dataNotif['offline_grace_days']);
        }
    }
}

/* =========================================================
   2bis) CONFIG GLOBALE CAPTEURS (FIRMWARE)
   - Fichier: /barriques/config.json
   - Utilisé par les capteurs (OTA, deep-sleep)
   ========================================================= */
$barConfigFile = __DIR__ . '/config.json';
$barConfig = [
    'measure_interval_s'    => 604800, // 7 jours en secondes
    'maintenance'           => true,   // true = pas de deep-sleep (mode maintenance)
    'test_mode'             => false,  // mode test désactivé par défaut
    'measure_interval_days' => 7,      // dérivé pour le formulaire
];

if (file_exists($barConfigFile)) {
    $jsonBar = file_get_contents($barConfigFile);
    $dataBar = json_decode($jsonBar, true);
    if (is_array($dataBar)) {
        if (isset($dataBar['measure_interval_s'])) {
            $barConfig['measure_interval_s'] = max(60, (int)$dataBar['measure_interval_s']);
        }
        if (isset($dataBar['maintenance'])) {
            $barConfig['maintenance'] = (bool)$dataBar['maintenance'];
        }
        if (isset($dataBar['test_mode'])) {
            $barConfig['test_mode'] = (bool)$dataBar['test_mode'];
        }
    }
}

// Conversion secondes -> jours pour l'affichage
$barConfig['measure_interval_days'] = max(
    1,
    (int)round($barConfig['measure_interval_s'] / 86400)
);

/* =========================================================
   3) TRAITEMENT FORMULAIRES
   - a) submit_capteur : changement de lot pour un capteur
   - b) submit_lot     : modification du nb de barriques pour un lot
   ========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    /* ----- a) Modification par capteur (lot) + HISTORIQUE ----- */
    if (isset($_POST['submit_capteur']) && isset($_POST['lot'])) {
        $id = trim($_POST['submit_capteur']);

        if ($id !== '' && array_key_exists($id, $_POST['lot'])) {

            // Lot avant modification
            $oldLot = '';
            if (isset($configLots[$id]) && !empty($configLots[$id]['lot'])) {
                $oldLot = trim((string)$configLots[$id]['lot']);
            }

            // Nouveau lot saisi
            $lotPost = trim($_POST['lot'][$id] ?? '');

            if ($lotPost === '') {
                // Si on efface le lot, on supprime la config pour ce capteur
                unset($configLots[$id]);
                $newLot = '';
            } else {
                // On conserve l'info "barriques" existante si elle existe, sinon 0
                $oldBar = isset($configLots[$id]['barriques'])
                    ? (int)$configLots[$id]['barriques']
                    : 0;

                $configLots[$id] = [
                    'lot'       => $lotPost,
                    'barriques' => $oldBar,
                ];
                $newLot = $lotPost;
            }

            // Sauvegarde config_lots.json
            file_put_contents(
                $configLotsFile,
                json_encode($configLots, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
            );

            // Mise à jour de l'historique des lots pour ce capteur
            updateLotHistoryOnLotChange($id, $oldLot, $newLot);
        }

        header('Location: index.php');
        exit;
    }

    /* ----- b) Modification par lot (nb de barriques total) ----- */
    if (isset($_POST['submit_lot']) && isset($_POST['lot_barriques'])) {
        $lotName = $_POST['submit_lot']; // valeur brute, ex: "L24"
        $lotKey  = (string)$lotName;

        $barriquesPost = trim($_POST['lot_barriques'][$lotKey] ?? '');
        $barVal = ($barriquesPost === '') ? 0 : max(0, (int)$barriquesPost);

        // On applique cette valeur à tous les capteurs qui ont ce lot
        foreach ($configLots as $id => $cfg) {
            if (($cfg['lot'] ?? '') === $lotKey) {
                $configLots[$id]['barriques'] = $barVal;
            }
        }

        file_put_contents(
            $configLotsFile,
            json_encode($configLots, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );

        header('Location: index.php');
        exit;
    }
}

/* =========================================================
   4) LECTURE LOG UNIQUE
   ========================================================= */
$logFile  = __DIR__ . '/logs/barriques.log';
$capteurs = []; // id => infos dernière mesure
$logLines = [];
if (file_exists($logFile)) {
    $logLines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    foreach ($logLines as $line) {
        // Format : date_iso \t id \t raw \t batt \t rssi \t fw \t ts
        $parts = explode("\t", $line);
        if (count($parts) < 7) continue;

        list($dateIso, $id, $raw, $batt, $rssi, $fw, $ts) = $parts;

        $id = trim($id);
        if ($id === '') continue;

        // La dernière occurrence pour un id l'emporte
        $capteurs[$id] = [
            'date_iso' => trim($dateIso),
            'id'       => $id,
            'raw'      => (int)$raw,
            'batt'     => (int)$batt,
            'rssi'     => (int)$rssi,
            'fw'       => trim($fw),
            'ts'       => (int)$ts,
        ];
    }
}

// Tri par ID pour un affichage stable
ksort($capteurs);

/* =========================================================
   5) AGRÉGATION PAR LOT (ACTIFS)
   ========================================================= */
$lotsAgg = []; // lot_name => stats

foreach ($capteurs as $id => $info) {
    if (!isset($configLots[$id])) {
        continue;
    }

    $lotName     = $configLots[$id]['lot']       ?? '';
    $nbBarriques = $configLots[$id]['barriques'] ?? 0;
    $lotName     = trim($lotName);

    if ($lotName === '') {
        continue;
    }

    if (!isset($lotsAgg[$lotName])) {
        $lotsAgg[$lotName] = [
            'barriques_total'   => 0,
            'creux_l_min_sum'   => 0.0,
            'creux_l_max_sum'   => 0.0,
            'count_capteurs'    => 0,
            'hl_total'          => 0.0,
            'bouteilles_total'  => 0,
            'ouillage_min'      => null,
            'ouillage_max'      => null,
            // Champs pour Part des anges (remplis plus bas)
            'pda_min_bar'       => null,
            'pda_max_bar'       => null,
            'pda_min_total'     => null,
            'pda_max_total'     => null,
            'pda_count_cap'     => 0,
        ];
    }

    // barriques_total = max() pour éviter double comptage s'il y a 2 capteurs / lot
    $nbB = (int)$nbBarriques;
    if ($nbB > 0 && $nbB > $lotsAgg[$lotName]['barriques_total']) {
        $lotsAgg[$lotName]['barriques_total'] = $nbB;
    }

    // Interprétation du RAW pour récupérer creux (L)
    $interp = interpret_raw($info['raw']);

    if ($interp['creux_l_min'] !== null && $interp['creux_l_max'] !== null) {
        $lotsAgg[$lotName]['creux_l_min_sum'] += (float)$interp['creux_l_min'];
        $lotsAgg[$lotName]['creux_l_max_sum'] += (float)$interp['creux_l_max'];
        $lotsAgg[$lotName]['count_capteurs']  += 1;
    }
}

// Calcul des dérivés (hl_total, bouteilles_total, ouillage)
foreach (array_keys($lotsAgg) as $lotName) {
    $agg = &$lotsAgg[$lotName];

    $b = (int)$agg['barriques_total'];
    if ($b > 0) {
        // Volume total du lot
        $agg['hl_total']          = $b * 2.25;
        $agg['bouteilles_total']  = (int)round($agg['hl_total'] * 133);

        // Ouillage : on prend le creux moyen (L) des capteurs
        if ($agg['count_capteurs'] > 0) {
            $avgMin = $agg['creux_l_min_sum'] / $agg['count_capteurs'];
            $avgMax = $agg['creux_l_max_sum'] / $agg['count_capteurs'];

            $agg['ouillage_min'] = $b * $avgMin;
            $agg['ouillage_max'] = $b * $avgMax;
        }
    }

    unset($agg);
}

/* =========================================================
   5.1) PART DES ANGES (lots actifs)
   ========================================================= */
$historyById = [];

if (!empty($logLines)) {
    foreach ($logLines as $line) {
        $parts = explode("\t", $line);
        if (count($parts) < 7) continue;

        list($dateIso, $id, $raw, $batt, $rssi, $fw, $ts) = $parts;
        $id = trim($id);
        $ts = (int)$ts;
        if ($id === '' || $ts <= 0) continue;

        $interp = interpret_raw((int)$raw);
        if ($interp['creux_l_min'] === null || $interp['creux_l_max'] === null) {
            continue;
        }

        if (!isset($historyById[$id])) {
            $historyById[$id] = [];
        }
        $historyById[$id][] = [
            'ts'  => $ts,
            'min' => (float)$interp['creux_l_min'],
            'max' => (float)$interp['creux_l_max'],
        ];
    }

    foreach ($historyById as &$arr) {
        usort($arr, function ($a, $b) {
            return $a['ts'] <=> $b['ts'];
        });
    }
    unset($arr);
}

// Périodes de lots (lot_history.json)
$lotPeriods = [];
$lotHistoryFile = __DIR__ . '/lot_history.json';

if (file_exists($lotHistoryFile)) {
    $jsonH = file_get_contents($lotHistoryFile);
    $hist  = json_decode($jsonH, true);

    if (is_array($hist)) {
        $isAssoc = array_keys($hist) !== range(0, count($hist) - 1);

        if ($isAssoc) {
            // Format : id => [ { lot, from_ts|start_ts, to_ts|end_ts }, ... ]
            foreach ($hist as $idKey => $periods) {
                if (!is_array($periods)) continue;
                $idStr = (string)$idKey;

                foreach ($periods as $entry) {
                    if (!is_array($entry)) continue;

                    $lotName = isset($entry['lot']) ? (string)$entry['lot'] : '';
                    $fromTs  = isset($entry['start_ts'])
                        ? (int)$entry['start_ts']
                        : (isset($entry['from_ts']) ? (int)$entry['from_ts'] : 0);
                    $toRaw   = $entry['end_ts'] ?? ($entry['to_ts'] ?? null);
                    $toTs    = ($toRaw === null) ? null : (int)$toRaw;

                    if ($lotName === '' || $fromTs <= 0) continue;

                    if (!isset($lotPeriods[$idStr])) {
                        $lotPeriods[$idStr] = [];
                    }
                    if (!isset($lotPeriods[$idStr][$lotName])) {
                        $lotPeriods[$idStr][$lotName] = [];
                    }
                    $lotPeriods[$idStr][$lotName][] = [
                        'from_ts' => $fromTs,
                        'to_ts'   => $toTs,
                    ];
                }
            }
        } else {
            // Format plat : [ {id, lot, start_ts|from_ts, end_ts|to_ts}, ... ]
            foreach ($hist as $entry) {
                if (!is_array($entry)) continue;

                $idStr   = isset($entry['id']) ? (string)$entry['id'] : '';
                $lotName = isset($entry['lot']) ? (string)$entry['lot'] : '';
                $fromTs  = isset($entry['start_ts'])
                    ? (int)$entry['start_ts']
                    : (isset($entry['from_ts']) ? (int)$entry['from_ts'] : 0);
                $toRaw   = $entry['end_ts'] ?? ($entry['to_ts'] ?? null);
                $toTs    = ($toRaw === null) ? null : (int)$toRaw;

                if ($idStr === '' || $lotName === '' || $fromTs <= 0) continue;

                if (!isset($lotPeriods[$idStr])) {
                    $lotPeriods[$idStr] = [];
                }
                if (!isset($lotPeriods[$idStr][$lotName])) {
                    $lotPeriods[$idStr][$lotName] = [];
                }
                $lotPeriods[$idStr][$lotName][] = [
                    'from_ts' => $fromTs,
                    'to_ts'   => $toTs,
                ];
            }
        }
    }
}

// Calcul Part des anges par lot (en L)
foreach ($configLots as $id => $cfg) {
    $lotName = trim($cfg['lot'] ?? '');
    if ($lotName === '' || !isset($lotsAgg[$lotName])) {
        continue;
    }

    if (!isset($historyById[$id])) {
        continue;
    }
    $entries = $historyById[$id];

    // Déterminer le début pour ce capteur + lot
    $startTs = null;
    if (isset($lotPeriods[$id][$lotName])) {
        // période en cours (to_ts null), sinon la dernière période
        foreach ($lotPeriods[$id][$lotName] as $p) {
            if ($p['to_ts'] === null) {
                $startTs = $p['from_ts'];
                break;
            }
        }
        if ($startTs === null) {
            foreach ($lotPeriods[$id][$lotName] as $p) {
                if ($startTs === null || $p['from_ts'] > $startTs) {
                    $startTs = $p['from_ts'];
                }
            }
        }
    }

    // Filtrer les mesures à partir de startTs si défini
    $filtered = [];
    foreach ($entries as $e) {
        if ($startTs !== null && $e['ts'] < $startTs) {
            continue;
        }
        $filtered[] = $e;
    }
    if (count($filtered) < 2) {
        continue;
    }

    $prevMin = null;
    $prevMax = null;
    $pdaMinBar = 0.0;
    $pdaMaxBar = 0.0;

    foreach ($filtered as $e) {
        $curMin = $e['min'];
        $curMax = $e['max'];

        // On cumule UNIQUEMENT les diminutions de creux (on ouille)
        if ($prevMin !== null && $curMin < $prevMin) {
            $pdaMinBar += ($prevMin - $curMin);
        }
        if ($prevMax !== null && $curMax < $prevMax) {
            $pdaMaxBar += ($prevMax - $curMax);
        }

        $prevMin = $curMin;
        $prevMax = $curMax;
    }

    if ($pdaMinBar <= 0 && $pdaMaxBar <= 0) {
        continue;
    }

    // On accumule par lot, on fera la moyenne ensuite
    $lotsAgg[$lotName]['pda_min_bar']   += $pdaMinBar;
    $lotsAgg[$lotName]['pda_max_bar']   += $pdaMaxBar;
    $lotsAgg[$lotName]['pda_count_cap'] += 1;
}

// Finalisation : moyennes par barrique + totaux (L)
foreach (array_keys($lotsAgg) as $lotName) {
    $agg = &$lotsAgg[$lotName];

    $b = (int)$agg['barriques_total'];

    if ($agg['pda_count_cap'] > 0 && $b > 0) {
        $avgMinBar = $agg['pda_min_bar'] / $agg['pda_count_cap'];
        $avgMaxBar = $agg['pda_max_bar'] / $agg['pda_count_cap'];

        $agg['pda_min_bar']   = $avgMinBar;
        $agg['pda_max_bar']   = $avgMaxBar;
        $agg['pda_min_total'] = $avgMinBar * $b;
        $agg['pda_max_total'] = $avgMaxBar * $b;
    } else {
        $agg['pda_min_bar']   = null;
        $agg['pda_max_bar']   = null;
        $agg['pda_min_total'] = null;
        $agg['pda_max_total'] = null;
    }

    unset($agg);
}

/* =========================================================
   5bis) LECTURE HISTORIQUE DES LOTS (ARCHIVES)
   ========================================================= */
$archivedLots = [];
$lotHistoryFile = __DIR__ . '/lot_history.json';

if (file_exists($lotHistoryFile)) {
    $jsonH = file_get_contents($lotHistoryFile);
    $hist  = json_decode($jsonH, true);

    if (is_array($hist)) {
        // Deux formats possibles :
        // 1) Format "par capteur"
        // 2) Format "plat"
        $isAssoc = array_keys($hist) !== range(0, count($hist) - 1);

        if ($isAssoc) {
            // ---- Format 1 : par capteur ----
            foreach ($hist as $idKey => $periods) {
                if (!is_array($periods)) continue;

                foreach ($periods as $entry) {
                    if (!is_array($entry)) continue;

                    $lotName = trim((string)($entry['lot'] ?? ''));
                    $startTs = (int)($entry['start_ts'] ?? ($entry['from_ts'] ?? 0));
                    $endRaw  = $entry['end_ts'] ?? ($entry['to_ts'] ?? null);
                    $endTs   = ($endRaw === null) ? null : (int)$endRaw;

                    if ($lotName === '' || $startTs <= 0) continue;

                    if (!isset($archivedLots[$lotName])) {
                        $archivedLots[$lotName] = [
                            'min_start' => $startTs,
                            'max_end'   => $endTs ?? $startTs,
                            'has_open'  => ($endTs === null),
                        ];
                    } else {
                        if ($startTs < $archivedLots[$lotName]['min_start']) {
                            $archivedLots[$lotName]['min_start'] = $startTs;
                        }
                        $endForAgg = $endTs ?? $startTs;
                        if ($endForAgg > $archivedLots[$lotName]['max_end']) {
                            $archivedLots[$lotName]['max_end'] = $endForAgg;
                        }
                        if ($endTs === null) {
                            $archivedLots[$lotName]['has_open'] = true;
                        }
                    }
                }
            }
        } else {
            // ---- Format 2 : plat ----
            foreach ($hist as $entry) {
                if (!is_array($entry)) continue;

                $lotName = trim((string)($entry['lot'] ?? ''));
                $startTs = (int)($entry['start_ts'] ?? ($entry['from_ts'] ?? 0));
                $endRaw  = $entry['end_ts'] ?? ($entry['to_ts'] ?? null);
                $endTs   = ($endRaw === null) ? null : (int)$endRaw;

                if ($lotName === '' || $startTs <= 0) continue;

                if (!isset($archivedLots[$lotName])) {
                    $archivedLots[$lotName] = [
                        'min_start' => $startTs,
                        'max_end'   => $endTs ?? $startTs,
                        'has_open'  => ($endTs === null),
                    ];
                } else {
                    if ($startTs < $archivedLots[$lotName]['min_start']) {
                        $archivedLots[$lotName]['min_start'] = $startTs;
                    }
                    $endForAgg = $endTs ?? $startTs;
                    if ($endForAgg > $archivedLots[$lotName]['max_end']) {
                        $archivedLots[$lotName]['max_end'] = $endForAgg;
                    }
                    if ($endTs === null) {
                        $archivedLots[$lotName]['has_open'] = true;
                    }
                }
            }
        }
    }

    // Retirer les lots encore actifs
    foreach (array_keys($lotsAgg) as $activeLot) {
        unset($archivedLots[$activeLot]);
    }

    // Retirer les lots qui ont encore une période ouverte
    foreach ($archivedLots as $lotName => $info) {
        if (!empty($info['has_open'])) {
            unset($archivedLots[$lotName]);
        }
    }

    // Tri par nom de lot
    ksort($archivedLots);
}

/* =========================================================
   6) FONCTIONS AFFICHAGE
   ========================================================= */
function formatDateFr($iso) {
    if (empty($iso)) return '-';
    $ts = strtotime($iso);
    if ($ts === false) return htmlspecialchars($iso, ENT_QUOTES, 'UTF-8');
    return date('d/m/Y H\hi', $ts); // ex : 06/12/2025 03h34
}

function rssiClass($rssi) {
    if ($rssi >= -60) return 'rssi-good';
    if ($rssi >= -75) return 'rssi-mid';
    return 'rssi-bad';
}

function batteryDisplay($mv) {
    if ($mv <= 0) {
        return '-';
    }

    // Approx pour 18650 : 4200 plein, 3900 bon, 3700 moyen, <3700 bas
    if ($mv >= 4100) {
        $level = 4;
    } elseif ($mv >= 3900) {
        $level = 3;
    } elseif ($mv >= 3700) {
        $level = 2;
    } else {
        $level = 1;
    }

    $full  = str_repeat('█', $level);
    $empty = str_repeat('░', 4 - $level);
    $text  = $full . $empty;

    $class = 'battery-l' . $level;

    return '<span class="battery ' . $class . '">' . $text . '</span>';
}

// Pour labeliser le jour hebdo en clair (utilisé dans le panneau paramètres)
function labelJour($n) {
    $jours = [
        1 => 'Lundi',
        2 => 'Mardi',
        3 => 'Mercredi',
        4 => 'Jeudi',
        5 => 'Vendredi',
        6 => 'Samedi',
        7 => 'Dimanche',
    ];
    return $jours[$n] ?? 'Mardi';
}

/**
 * Capteur inactif ?
 * - ts : timestamp de la dernière mesure
 * - intervalDays : fréquence attendue (jours)
 * - graceDays : marge avant alerte (jours)
 */
function isCapteurInactive(int $ts, int $intervalDays, int $graceDays): bool {
    if ($ts <= 0) return false;
    $now = time();
    $ageSec   = $now - $ts;
    $limitSec = ($intervalDays + $graceDays) * 86400;
    return $ageSec > $limitSec;
}

// Paramètres pour l’inactivité dans le dashboard
$inactiveMeasureDays = max(1, (int)$barConfig['measure_interval_days']);
$inactiveGraceDays   = max(0, (int)$notifConfig['offline_grace_days']);

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Dashboard barriques - Capteurs</title>

    <!-- Favicon goutte jaune simple (inline SVG) -->
    <link rel="icon" type="image/svg+xml"
          href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Cpath fill='%23f3d26b' d='M32 6C26 16 18 24 18 34c0 8 6.3 14 14 14s14-6 14-14C46 24 38 16 32 6z'/%3E%3C/svg%3E">

    <style>
        :root {
            --bg-page: #050608;         /* noir très sombre, léger bleu nuit */
            --card-bg: #15171c;         /* gris anthracite */
            --card-border: #262a33;
            --text-main: #f5f5f7;
            --text-muted: #9ca3af;
            --accent: #d4af37;          /* doré discret */
            --table-header-bg: #1e222b;
            --table-row-alt: #171b22;
            --table-row-hover: #202633;
        }
        * {
            box-sizing: border-box;
        }
        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background: radial-gradient(circle at top, #101318 0, #050608 45%, #000000 100%);
            margin: 0;
            padding: 20px;
            color: var(--text-main);
        }
        .page {
            max-width: 1200px;
            margin: 0 auto;
        }
        .topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 16px;
            padding-bottom: 8px;
            border-bottom: 1px solid #262a33;
        }
        .topbar h1 {
            flex: 1;
            margin: 0;
            font-size: 24px;
            font-weight: 500;
            letter-spacing: 0.04em;
        }
        .topbar-left {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .topbar-actions {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .back-link {
            text-decoration: none;
            font-size: 22px;
            line-height: 1;
            color: var(--text-main);
        }
        .back-link:hover {
            color: var(--accent);
        }

        .icon-btn {
            background: transparent;
            border: none;
            cursor: pointer;
            font-size: 1.3rem;
            padding: 0.2rem 0.4rem;
            color: var(--accent);
        }
        .icon-btn:hover {
            transform: scale(1.08);
        }

        .header {
            margin-bottom: 15px;
            font-size: 13px;
            color: var(--text-muted);
        }
        .card {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 10px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.35);
            margin-bottom: 20px;
            overflow: hidden;
        }
        .card-header {
            padding: 10px 16px;
            border-bottom: 1px solid #262a33;
            background: linear-gradient(90deg, #181c23, #12151b);
            font-size: 14px;
            font-weight: 500;
        }
        .card-body {
            padding: 10px 16px 14px 16px;
        }
        table {
            border-collapse: collapse;
            width: 100%;
            font-size: 13px;
            color: var(--text-main);
        }
        th, td {
            padding: 6px 8px;
            border-bottom: 1px solid #262a33;
            text-align: left;
        }
        th {
            background: var(--table-header-bg);
            font-weight: 500;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: #e5e7eb;
        }
        tr:nth-child(even) td {
            background: var(--table-row-alt);
        }
        tr:hover td {
            background: var(--table-row-hover);
        }
        .badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 999px;
            font-size: 11px;
            color: #fff;
            text-transform: lowercase;
        }
        .vert { background: #22c55e; }
        .jaune { background: #eab308; }
        .orange { background: #f97316; }
        .rouge { background: #ef4444; }
        .rouge_vif { background: #b91c1c; }
        .rouge_ultra { background: #7f1d1d; }
        .erreur { background: #6b7280; }
        .rssi {
            font-family: monospace;
        }
        .rssi-good {
            color: #22c55e;
            font-weight: 600;
        }
        .rssi-mid {
            color: #eab308;
            font-weight: 600;
        }
        .rssi-bad {
            color: #ef4444;
            font-weight: 600;
        }
        .small {
            font-size: 11px;
            color: var(--text-muted);
        }
        .small.inactive {
            color: #ef4444;
            font-weight: 600;
        }
        .battery {
            font-family: monospace;
        }
        .battery-l1 { color: #ef4444; }   /* très bas */
        .battery-l2 { color: #eab308; }   /* moyen-bas */
        .battery-l3 { color: #f97316; }   /* moyen-haut */
        .battery-l4 { color: #22c55e; }   /* plein */

        .inline-input {
            width: 100%;
            padding: 3px 5px;
            font-size: 13px;
            border: 1px solid #3b3f4a;
            border-radius: 4px;
            background: #101218;
            color: var(--text-main);
        }
        .inline-input::placeholder {
            color: #6b7280;
        }
        .inline-input:focus {
            outline: none;
            border-color: var(--accent);
            background: #0b0e13;
        }

        .inline-input-number {
            width: 100%;
            max-width: 80px;
            padding: 3px 5px;
            font-size: 13px;
            border: 1px solid #3b3f4a;
            border-radius: 4px;
            background: #101218;
            color: var(--text-main);
        }
        .inline-input-number::placeholder {
            color: #6b7280;
        }
        .inline-input-number:focus {
            outline: none;
            border-color: var(--accent);
            background: #0b0e13;
        }

        .save-btn {
            background: none;
            border: none;
            padding: 0;
            margin: 0;
            font-size: 18px;
            cursor: pointer;
            line-height: 1;
            color: var(--accent);
        }
        .save-btn:hover {
            opacity: 0.7;
        }
        .section-title {
            font-size: 15px;
            font-weight: 500;
            margin: 0;
            color: var(--accent);
            letter-spacing: 0.03em;
        }

        /* Panneau paramètres */
        .settings-panel {
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.6);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 999;
        }
        .settings-content {
            background: #111;
            border: 1px solid #444;
            border-radius: 8px;
            padding: 1.5rem;
            min-width: 260px;
            max-width: 360px;
            color: #f5f5f5;
            box-shadow: 0 0 20px rgba(0,0,0,0.6);
        }
        .settings-content h2 {
            margin-top: 0;
            margin-bottom: 1rem;
            font-size: 1.2rem;
            color: #f3d26b;
        }
        .settings-content fieldset {
            border: 1px solid #333;
            padding: 0.8rem;
            margin-bottom: 0.8rem;
        }
        .settings-content legend {
            padding: 0 0.4rem;
        }
        .settings-actions {
            margin-top: 1rem;
            text-align: right;
        }
        .settings-actions .icon-btn {
            font-size: 1rem;
        }
        .settings-actions .icon-btn:first-child {
            color: var(--text-muted);
        }
        .settings-actions .icon-btn:last-child {
            color: var(--accent);
        }
        .settings-row {
            margin-top: 0.6rem;
            font-size: 0.9rem;
        }
        .settings-row label {
            display: block;
            margin-bottom: 0.25rem;
        }
        .settings-row input[type="number"],
        .settings-row select {
            width: 100%;
            padding: 4px 6px;
            border-radius: 4px;
            border: 1px solid #444;
            background: #0b0e13;
            color: #f5f5f5;
            font-size: 0.9rem;
        }
        input[disabled] {
            opacity: 0.5;
            cursor: not-allowed;
        }
    </style>
</head>
<body>
<div class="page">

    <div class="topbar">
        <div class="topbar-left">
            <a href="../" class="back-link" title="Retour au dashboard général">🚪</a>
            <h1>Dashboard barriques - Capteurs</h1>
        </div>
        <div class="topbar-actions">
            <button class="icon-btn" id="notify-btn" title="Activer les notifications">🔔</button>
            <button class="icon-btn" id="open-settings" title="Paramètres">⚙️</button>
        </div>
    </div>

    <div class="header">
        <?php if (!empty($capteurs)): ?>
            <?php $nbCapteurs = count($capteurs); ?>
            Capteurs actifs : <strong><?php echo $nbCapteurs; ?></strong>
        <?php else: ?>
            Aucune mesure trouvée dans le fichier log.
        <?php endif; ?>
    </div>

    <?php if (!empty($capteurs)): ?>

        <!-- Vue par capteur -->
        <div class="card">
            <div class="card-header">
                <span class="section-title">Vue par capteur</span>
            </div>
            <div class="card-body">
                <form method="post" action="index.php">
                    <table>
                        <thead>
                        <tr>
                            <th>ID capteur</th>
                            <th>Lot</th>
                            <th>Niveau</th>
                            <th>Couleur</th>
                            <th>Creux (cm)</th>
                            <th>Creux (L)</th>
                            <th>Temp. (°C)</th>
                            <th>RAW</th>
                            <th>RSSI</th>
                            <th>Batterie</th>
                            <th>Dernière mesure</th>
                            <th>FW</th>
                            <th>Actions</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($capteurs as $id => $info): ?>
                            <?php
                            // Interprétation du RAW
                            $interp = interpret_raw($info['raw']);
                            $classeCouleur = $interp['couleur'];
                            if (!in_array($classeCouleur, ['vert','jaune','orange','rouge','rouge_vif','rouge_ultra','erreur'])) {
                                $classeCouleur = 'erreur';
                            }

                            // Lot via config
                            $lotName = '';
                            if (isset($configLots[$id]) && !empty($configLots[$id]['lot'])) {
                                $lotName = $configLots[$id]['lot'];
                            }

                            // Température (prévu pour plus tard)
                            $tempAff = '-';

                            // Date FR
                            $dateAff = formatDateFr($info['date_iso']);

                            // Capteur inactif ?
                            $inactive = isCapteurInactive(
                                (int)$info['ts'],
                                $inactiveMeasureDays,
                                $inactiveGraceDays
                            );

                            // RSSI coloré
                            $rssiClassStr = rssiClass($info['rssi']);

                            // Batterie
                            $batteryHtml = batteryDisplay($info['batt']);
                            ?>
                            <tr>
                                <td><?php echo htmlspecialchars($id, ENT_QUOTES, 'UTF-8'); ?></td>
                                <td>
                                    <input
                                        type="text"
                                        name="lot[<?php echo htmlspecialchars($id, ENT_QUOTES, 'UTF-8'); ?>]"
                                        class="inline-input"
                                        placeholder="Nom du lot"
                                        value="<?php echo htmlspecialchars($lotName, ENT_QUOTES, 'UTF-8'); ?>"
                                    >
                                </td>
                                <td><?php echo $interp['niveau']; ?></td>
                                <td>
                                    <span class="badge <?php echo $classeCouleur; ?>">
                                        <?php echo htmlspecialchars($interp['couleur'], ENT_QUOTES, 'UTF-8'); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php
                                    if ($interp['creux_cm_min'] === null) {
                                        echo '-';
                                    } else {
                                        echo $interp['creux_cm_min'] . ' – ' . $interp['creux_cm_max'];
                                    }
                                    ?>
                                </td>
                                <td>
                                    <?php
                                    if ($interp['creux_l_min'] === null) {
                                        echo '-';
                                    } else {
                                        echo $interp['creux_l_min'] . ' – ' . $interp['creux_l_max'];
                                    }
                                    ?>
                                </td>
                                <td><?php echo $tempAff; ?></td>
                                <td><?php echo $info['raw']; ?></td>
                                <td class="rssi <?php echo $rssiClassStr; ?>"><?php echo $info['rssi']; ?> dBm</td>
                                <td><?php echo $batteryHtml; ?></td>
                                <td class="small<?php echo $inactive ? ' inactive' : ''; ?>">
                                    <?php echo htmlspecialchars($dateAff, ENT_QUOTES, 'UTF-8'); ?>
                                </td>
                                <td class="small"><?php echo htmlspecialchars($info['fw'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td>
                                    <button
                                        type="submit"
                                        name="submit_capteur"
                                        value="<?php echo htmlspecialchars($id, ENT_QUOTES, 'UTF-8'); ?>"
                                        class="save-btn"
                                        title="Enregistrer ce capteur"
                                    >💾</button>
                                    <a
                                        href="history_capteur.php?id=<?php echo urlencode($id); ?>"
                                        class="save-btn"
                                        title="Voir l'historique de ce capteur"
                                    >📈</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </form>
            </div>
        </div>

        <!-- Vue par lot (lots actifs) -->
        <?php if (!empty($lotsAgg)): ?>
            <div class="card">
                <div class="card-header">
                    <span class="section-title">Vue par lot</span>
                </div>
                <div class="card-body">
                    <form method="post" action="index.php">
                        <table>
                            <thead>
                            <tr>
                                <th>Lot</th>
                                <th>Barriques totales</th>
                                <th>Volume total (hl)</th>
                                <th>Équivalent bouteilles (75cl)</th>
                                <th>Ouillage (L)</th>
                                <th>Part des anges (L)</th>
                                <th>Actions</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($lotsAgg as $lotName => $agg): ?>
                                <?php
                                $b  = (int)$agg['barriques_total'];
                                $hl = (float)$agg['hl_total'];
                                $bt = (int)$agg['bouteilles_total'];

                                $ouillageText = '-';
                                if ($agg['ouillage_min'] !== null && $agg['ouillage_max'] !== null) {
                                    $ouMin = number_format($agg['ouillage_min'], 1, ',', ' ');
                                    $ouMax = number_format($agg['ouillage_max'], 1, ',', ' ');
                                    $ouillageText = $ouMin . ' – ' . $ouMax;
                                }

                                $pdaText = '-';
                                if ($agg['pda_min_total'] !== null && $agg['pda_max_total'] !== null) {
                                    $pdaMinTot = number_format($agg['pda_min_total'], 1, ',', ' ');
                                    $pdaMaxTot = number_format($agg['pda_max_total'], 1, ',', ' ');
                                    $pdaMinBar = number_format($agg['pda_min_bar'], 1, ',', ' ');
                                    $pdaMaxBar = number_format($agg['pda_max_bar'], 1, ',', ' ');
                                    $pdaText   = $pdaMinTot . ' – ' . $pdaMaxTot .
                                                 ' (' . $pdaMinBar . ' – ' . $pdaMaxBar . ' /bar)';
                                }
                                ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($lotName, ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td>
                                        <input
                                            type="number"
                                            class="inline-input-number"
                                            name="lot_barriques[<?php echo htmlspecialchars($lotName, ENT_QUOTES, 'UTF-8'); ?>]"
                                            min="0"
                                            step="1"
                                            value="<?php echo $b; ?>"
                                        >
                                    </td>
                                    <td><?php echo number_format($hl, 2, ',', ' '); ?></td>
                                    <td><?php echo $bt; ?></td>
                                    <td><?php echo $ouillageText; ?></td>
                                    <td><?php echo $pdaText; ?></td>
                                    <td>
                                        <button
                                            type="submit"
                                            name="submit_lot"
                                            value="<?php echo htmlspecialchars($lotName, ENT_QUOTES, 'UTF-8'); ?>"
                                            class="save-btn"
                                            title="Enregistrer ce lot"
                                        >💾</button>
                                        <a
                                            href="history_lot.php?lot=<?php echo urlencode($lotName); ?>"
                                            class="save-btn"
                                            title="Voir l'historique de ce lot"
                                        >📈</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <!-- Historique des anciens lots (archives) -->
        <?php if (!empty($archivedLots)): ?>
            <div class="card">
                <div class="card-header"
                     id="toggle-archives"
                     style="cursor:pointer;display:flex;align-items:center;gap:8px;">
                    <span id="archives-toggle-icon">➕</span>
                    <span class="section-title">Historique des anciens lots</span>
                </div>
                <div class="card-body" id="archives-body" style="display:none;">
                    <table>
                        <thead>
                        <tr>
                            <th>Lot</th>
                            <th>Période suivie</th>
                            <th>Actions</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($archivedLots as $lotName => $info): ?>
                            <?php
                            $start = date('d/m/Y', $info['min_start']);
                            $end   = date('d/m/Y', $info['max_end']);
                            ?>
                            <tr>
                                <td><?php echo htmlspecialchars($lotName, ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo $start . ' → ' . $end; ?></td>
                                <td>
                                    <a
                                        href="history_lot.php?lot=<?php echo urlencode($lotName); ?>"
                                        class="save-btn"
                                        title="Voir l'historique de ce lot"
                                    >📈</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

    <?php endif; // !empty($capteurs) ?>

</div>

<!-- Panneau paramètres -->
<div id="settings-panel" class="settings-panel">
    <div class="settings-content">
        <h2>Paramètres</h2>

        <form method="post" action="save_notifications_config.php">
            <fieldset>
                <legend>Notifications</legend>

                <label>
                    <input type="radio" name="mode" value="off"
                        <?php echo ($notifConfig['mode'] === 'off') ? 'checked' : ''; ?>>
                    Désactivées
                </label><br>
                <label>
                    <input type="radio" name="mode" value="daily"
                        <?php echo ($notifConfig['mode'] === 'daily') ? 'checked' : ''; ?>>
                    Quotidiennes
                </label><br>
                <label>
                    <input type="radio" name="mode" value="weekly"
                        <?php echo ($notifConfig['mode'] === 'weekly') ? 'checked' : ''; ?>>
                    Hebdomadaires
                </label>

                <div class="settings-row">
                    <label for="weekly_day">
                        Jour d’envoi (mode hebdo) :
                    </label>
                    <select name="weekly_day" id="weekly_day">
                        <?php
                        for ($d = 1; $d <= 7; $d++) {
                            $sel = ($notifConfig['weekly_day'] == $d) ? 'selected' : '';
                            echo "<option value=\"$d\" $sel>" . labelJour($d) . "</option>";
                        }
                        ?>
                    </select>
                </div>

                <br>

                <label>
                    <input type="checkbox" name="include_battery"
                        <?php echo $notifConfig['include_battery'] ? 'checked' : ''; ?>>
                    Inclure batterie faible
                </label><br>
                <label>
                    <input type="checkbox" name="include_offline"
                        <?php echo $notifConfig['include_offline'] ? 'checked' : ''; ?>>
                    Inclure capteurs inactifs
                </label>
            </fieldset>

            <fieldset>
                <legend>Capteurs (global)</legend>

                <div class="settings-row">
                    <label for="measure_interval_days">
                        Fréquence des mesures :
                    </label>

                    <div style="display:flex; flex-wrap:wrap; gap:10px; align-items:center;">
                        <!-- À gauche : intervalle en jours -->
                        <div style="display:flex; align-items:center; gap:4px;">
                            <input type="number"
                                   id="measure_interval_days"
                                   name="measure_interval_days"
                                   min="1"
                                   value="<?php echo (int)$barConfig['measure_interval_days']; ?>"
                                   <?php echo !empty($barConfig['test_mode']) ? 'disabled' : ''; ?>>
                            <span class="small">jours</span>
                        </div>

                        <!-- À droite : mode test -->
                        <label style="display:flex; align-items:center; gap:4px;">
                            <input type="checkbox"
                                   id="test_mode"
                                   name="test_mode"
                                   <?php echo !empty($barConfig['test_mode']) ? 'checked' : ''; ?>>
                            <span class="small">Mode test (mesure toutes les 20&nbsp;s)</span>
                        </label>
                    </div>

                    <div class="small" style="margin-top:4px;">
                        <span title="Fréquence des mesures avec sommeil profond dans l'intervalle.
Redémarrer le capteur pour forcer une nouvelle mesure ou prendre en compte une modification de ce paramètre.
Ce paramètre sert également à détecter des capteurs en retard de mesure (inactifs).">
                            ℹ️
                        </span>
                        &nbsp;Fréquence des mesures avec sommeil profond dans l'intervalle.
                        Redémarrer le capteur pour une nouvelle mesure ou après modification de ce paramètre.
                        Ce paramètre sert également à détecter des capteurs en retard de mesure (inactifs).
                    </div>
                </div>

                <div class="settings-row" style="margin-top:0.8rem;">
                    <label>
                        <input type="checkbox"
                               name="maintenance"
                               <?php echo $barConfig['maintenance'] ? 'checked' : ''; ?>>
                        Mode maintenance (désactive le deep-sleep pour tous les capteurs)
                    </label>
                    <div class="small">
                        En maintenance : les capteurs restent éveillés (utile pour tests, flash, mise au point).
                    </div>
                </div>

                <!-- Marge capteur inactif : fixée à offline_grace_days côté config, pas de champ ici -->
            </fieldset>

            <div class="settings-actions">
                <button type="button" id="close-settings" class="icon-btn">✖</button>
                <button type="submit" class="icon-btn">💾</button>
            </div>
        </form>
    </div>
</div>

<script>
// ---------------------------
// CONFIG Web Push
// ---------------------------
const VAPID_PUBLIC_KEY = "BDBBkJp-c7FZt5JJu5j3COn2Z3v42a54NlWngElS49Ogt0bd2nwaxnQvHGh7vbgH62ECDWhlPChglbSXgyvw4r0";

// Convertit la clé VAPID en Uint8Array
function urlBase64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - base64String.length % 4) % 4);
    const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    const raw = window.atob(base64);
    return Uint8Array.from([...raw].map(c => c.charCodeAt(0)));
}

// Demande la permission + abonnement
async function subscribeUser() {
    if (!("serviceWorker" in navigator)) {
        alert("Service workers non supportés sur ce navigateur.");
        return;
    }

    try {
        const reg = await navigator.serviceWorker.register("sw.js");
        console.log("Service worker enregistré", reg);

        const permission = await Notification.requestPermission();
        if (permission !== "granted") {
            alert("Les notifications sont désactivées.");
            return;
        }

        const sub = await reg.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: urlBase64ToUint8Array(VAPID_PUBLIC_KEY)
        });

        await fetch("push_subscribe.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify(sub)
        });

        alert("Notifications activées !");
    } catch (e) {
        console.error("Erreur d'abonnement Web Push :", e);
        alert("Impossible d'activer les notifications.");
    }
}

document.addEventListener("DOMContentLoaded", () => {
    // Bouton cloche
    const notifyBtn = document.getElementById("notify-btn");
    if (notifyBtn) {
        notifyBtn.addEventListener("click", subscribeUser);
    }

    // Panneau paramètres
    const openSettings = document.getElementById("open-settings");
    const closeSettings = document.getElementById("close-settings");
    const settingsPanel = document.getElementById("settings-panel");

    if (openSettings && settingsPanel) {
        openSettings.addEventListener("click", () => {
            settingsPanel.style.display = "flex";
        });
    }
    if (closeSettings && settingsPanel) {
        closeSettings.addEventListener("click", () => {
            settingsPanel.style.display = "none";
        });
    }

    // Mode test : griser / désactiver le champ jours
    const testModeCheckbox = document.getElementById("test_mode");
    const measureDaysInput = document.getElementById("measure_interval_days");
    if (testModeCheckbox && measureDaysInput) {
        const syncDisabled = () => {
            measureDaysInput.disabled = testModeCheckbox.checked;
        };
        syncDisabled();
        testModeCheckbox.addEventListener("change", syncDisabled);
    }

    // Bloc "Historique des anciens lots" repliable
    const archivesHeader = document.getElementById("toggle-archives");
    const archivesBody   = document.getElementById("archives-body");
    const archivesIcon   = document.getElementById("archives-toggle-icon");

    if (archivesHeader && archivesBody && archivesIcon) {
        archivesHeader.addEventListener("click", () => {
            const isHidden = archivesBody.style.display === "none" || archivesBody.style.display === "";
            archivesBody.style.display = isHidden ? "block" : "none";
            archivesIcon.textContent   = isHidden ? "➖" : "➕";
        });
    }
});
</script>

</body>
</html>