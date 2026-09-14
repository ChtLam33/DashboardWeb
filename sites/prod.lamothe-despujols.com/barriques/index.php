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
        $configLots = $data; // id => ['lot'=>..., 'barriques'=>...]
    }
}

/* =========================================================
   2) CONFIG NOTIFICATIONS (dashboard / alertes)
   ========================================================= */
$notifConfigFile = __DIR__ . '/notifications_config.json';
$notifConfig = [
    'mode'                  => 'weekly',
    'include_battery'       => true,
    'include_offline'       => true,
    'weekly_day'            => 2,
    'measure_interval_days' => 7,
    'offline_grace_days'    => 1,
];

if (file_exists($notifConfigFile)) {
    $jsonNotif = file_get_contents($notifConfigFile);
    $dataNotif = json_decode($jsonNotif, true);
    if (is_array($dataNotif)) {
        $mode = $dataNotif['mode'] ?? 'weekly';
        if (!in_array($mode, ['off','daily','weekly'], true)) $mode = 'weekly';

        $notifConfig['mode']            = $mode;
        $notifConfig['include_battery'] = !empty($dataNotif['include_battery']);
        $notifConfig['include_offline'] = !empty($dataNotif['include_offline']);

        if (isset($dataNotif['weekly_day'])) {
            $wd = (int)$dataNotif['weekly_day'];
            if ($wd >= 1 && $wd <= 7) $notifConfig['weekly_day'] = $wd;
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
   ========================================================= */
$barConfigFile = __DIR__ . '/config.json';
$barConfig = [
    'measure_interval_s'       => 604800, // 7 jours
    'measure_interval_days'    => 7,
    'measure_interval_minutes' => 0,
];

if (file_exists($barConfigFile)) {
    $jsonBar = file_get_contents($barConfigFile);
    $dataBar = json_decode($jsonBar, true);
    if (is_array($dataBar)) {
        if (isset($dataBar['measure_interval_s'])) $barConfig['measure_interval_s'] = max(60, (int)$dataBar['measure_interval_s']);
    }
}
$barConfig['measure_interval_days']    = intdiv((int)$barConfig['measure_interval_s'], 86400);
$barConfig['measure_interval_minutes'] = intdiv((int)$barConfig['measure_interval_s'] % 86400, 60);

/* =========================================================
   Offsets creux
   ========================================================= */
$offsetsCreux = loadCreuxOffsets();

/* =========================================================
   3) TRAITEMENT FORMULAIRES
   - submit_capteur : lot + offset
   - submit_lot     : barriques (lot actif)
   ========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // a) Modification capteur (lot + offset)
    if (isset($_POST['submit_capteur'])) {
        $id = trim((string)$_POST['submit_capteur']);

        if ($id !== '') {

            // LOT
            if (isset($_POST['lot']) && array_key_exists($id, $_POST['lot'])) {

                $oldLot = '';
                $oldBar = 0;
                if (isset($configLots[$id])) {
                    $oldLot = trim((string)($configLots[$id]['lot'] ?? ''));
                    $oldBar = (int)($configLots[$id]['barriques'] ?? 0);
                }

                $lotPost = trim((string)($_POST['lot'][$id] ?? ''));

                if ($lotPost === '') {
                    unset($configLots[$id]);
                    $newLot = '';
                    $newBar = 0;
                } else {
                    $configLots[$id] = [
                        'lot'       => $lotPost,
                        'barriques' => $oldBar, // on conserve la valeur existante
                    ];
                    $newLot = $lotPost;
                    $newBar = $oldBar;
                }

                file_put_contents($configLotsFile, json_encode($configLots, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

                // Historique : figer barriques sur segment fermé + écrire barriques sur segment ouvert
                updateLotHistoryOnLotChange($id, $oldLot, $newLot, null, $oldBar, $newBar);
            }

            // OFFSET
            if (isset($_POST['offset_cm']) && array_key_exists($id, $_POST['offset_cm'])) {
                $offRaw = str_replace(',', '.', trim((string)$_POST['offset_cm'][$id]));
                $offVal = ($offRaw === '') ? 0.0 : (float)$offRaw;

                // bornes de sécurité
                if ($offVal < -5.0) $offVal = -5.0;
                if ($offVal >  5.0) $offVal =  5.0;

                $offsetsCreux[$id] = $offVal;
                saveCreuxOffsets($offsetsCreux);
            }
        }

        header('Location: index.php');
        exit;
    }

    // b) Modification lot (barriques total)
    if (isset($_POST['submit_lot']) && isset($_POST['lot_barriques'])) {
        $lotKey = (string)$_POST['submit_lot'];

        $barriquesPost = trim((string)($_POST['lot_barriques'][$lotKey] ?? ''));
        $barVal = ($barriquesPost === '') ? 0 : max(0, (int)$barriquesPost);

        foreach ($configLots as $sid => $cfg) {
            if (($cfg['lot'] ?? '') === $lotKey) {
                $configLots[$sid]['barriques'] = $barVal;
            }
        }

        file_put_contents($configLotsFile, json_encode($configLots, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        header('Location: index.php');
        exit;
    }
}

/* =========================================================
   4) LECTURE LOG (dernière mesure par capteur + lignes brutes)
   ========================================================= */
$logFile  = __DIR__ . '/logs/barriques.log';
$capteurs = []; // dernière mesure
$logLines = [];

if (file_exists($logFile)) {
    $logLines = read_barriques_log_records($logFile);

    foreach ($logLines as $line) {
        $parts = explode("\t", $line);
        if (count($parts) < 7) continue;

        [$dateIso, $id, $raw, $batt, $rssi, $fw, $ts] = $parts;

        $id = trim((string)$id);
        if ($id === '') continue;

        $capteurs[$id] = [
            'date_iso' => trim((string)$dateIso),
            'id'       => $id,
            'raw'      => (int)$raw,
            'batt'     => (int)$batt,
            'rssi'     => (int)$rssi,
            'fw'       => trim((string)$fw),
            'ts'       => (int)$ts,
        ];
    }
}
ksort($capteurs);

/* =========================================================
   5) AGRÉGATION PAR LOT (ACTIFS)
   ========================================================= */
$lotsAgg = []; // lots actifs

foreach ($capteurs as $id => $info) {
    if (!isset($configLots[$id])) continue;

    $lotName     = trim((string)($configLots[$id]['lot'] ?? ''));
    $nbBarriques = (int)($configLots[$id]['barriques'] ?? 0);
    if ($lotName === '') continue;

    if (!isset($lotsAgg[$lotName])) {
        $lotsAgg[$lotName] = [
            'barriques_total'  => 0,
            'creux_l_min_sum'  => 0.0,
            'creux_l_max_sum'  => 0.0,
            'count_capteurs'   => 0,
            'hl_total'         => 0.0,
            'bouteilles_total' => 0,
            'ouillage_min'     => null,
            'ouillage_max'     => null,

            // PDA (actifs)
            'pda_min_bar'      => 0.0,
            'pda_max_bar'      => 0.0,
            'pda_min_total'    => null,
            'pda_max_total'    => null,
            'pda_count_cap'    => 0,
        ];
    }

    // barriques_total = max() pour éviter double comptage
    if ($nbBarriques > 0 && $nbBarriques > $lotsAgg[$lotName]['barriques_total']) {
        $lotsAgg[$lotName]['barriques_total'] = $nbBarriques;
    }

    // Interprétation (dernière mesure)
    $offset = getCreuxOffsetForSensor((string)$id);
    $interp = interpret_raw((int)$info['raw'], (float)$offset);

    if ($interp['creux_l_min'] !== null && $interp['creux_l_max'] !== null) {
        $lotsAgg[$lotName]['creux_l_min_sum'] += (float)$interp['creux_l_min'];
        $lotsAgg[$lotName]['creux_l_max_sum'] += (float)$interp['creux_l_max'];
        $lotsAgg[$lotName]['count_capteurs']  += 1;
    }
}

// Dérivés (hl_total, bouteilles, ouillage)
foreach (array_keys($lotsAgg) as $lotName) {
    $agg = &$lotsAgg[$lotName];
    $b = (int)$agg['barriques_total'];

    if ($b > 0) {
        $agg['hl_total']         = $b * 2.25;
        $agg['bouteilles_total'] = (int)round($agg['hl_total'] * 133);

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
   5.1) PART DES ANGES (lots actifs) — AVEC OFFSETS
   ========================================================= */
$historyById = []; // id => [ {ts,min,max}, ... ] en litres

if (!empty($logLines)) {
    foreach ($logLines as $line) {
        $parts = explode("\t", $line);
        if (count($parts) < 7) continue;

        [$dateIso, $id, $raw, $batt, $rssi, $fw, $ts] = $parts;
        $id = trim((string)$id);
        $ts = (int)$ts;
        if ($id === '' || $ts <= 0) continue;

        $offset = getCreuxOffsetForSensor((string)$id);
        $interp = interpret_raw((int)$raw, (float)$offset);
        if ($interp['creux_l_min'] === null || $interp['creux_l_max'] === null) continue;

        $historyById[$id] ??= [];
        $historyById[$id][] = [
            'ts'  => $ts,
            'min' => (float)$interp['creux_l_min'],
            'max' => (float)$interp['creux_l_max'],
        ];
    }

    foreach ($historyById as &$arr) {
        usort($arr, fn($a, $b) => $a['ts'] <=> $b['ts']);
    }
    unset($arr);
}

// Périodes de lots via lot_history.json
$lotHistoryFile = __DIR__ . '/lot_history.json';
$lotPeriods = []; // [id][lot] => [ {from_ts,to_ts,barriques?}, ... ]

if (file_exists($lotHistoryFile)) {
    $jsonH = file_get_contents($lotHistoryFile);
    $hist  = json_decode($jsonH, true);

    if (is_array($hist)) {
        $isAssoc = array_keys($hist) !== range(0, count($hist) - 1);

        if ($isAssoc) {
            foreach ($hist as $idKey => $periods) {
                if (!is_array($periods)) continue;
                $idStr = (string)$idKey;

                foreach ($periods as $entry) {
                    if (!is_array($entry)) continue;

                    $lotName = (string)($entry['lot'] ?? '');
                    $fromTs  = (int)($entry['from_ts'] ?? ($entry['start_ts'] ?? 0));
                    $toRaw   = $entry['to_ts'] ?? ($entry['end_ts'] ?? null);
                    $toTs    = ($toRaw === null) ? null : (int)$toRaw;
                    $bar     = isset($entry['barriques']) ? (int)$entry['barriques'] : 0;

                    if ($lotName === '' || $fromTs <= 0) continue;

                    $lotPeriods[$idStr] ??= [];
                    $lotPeriods[$idStr][$lotName] ??= [];
                    $lotPeriods[$idStr][$lotName][] = [
                        'from_ts'   => $fromTs,
                        'to_ts'     => $toTs,
                        'barriques' => $bar,
                    ];
                }
            }
        } else {
            // format plat éventuel (sécurité)
            foreach ($hist as $entry) {
                if (!is_array($entry)) continue;

                $idStr   = (string)($entry['id'] ?? '');
                $lotName = (string)($entry['lot'] ?? '');
                $fromTs  = (int)($entry['from_ts'] ?? ($entry['start_ts'] ?? 0));
                $toRaw   = $entry['to_ts'] ?? ($entry['end_ts'] ?? null);
                $toTs    = ($toRaw === null) ? null : (int)$toRaw;
                $bar     = isset($entry['barriques']) ? (int)$entry['barriques'] : 0;

                if ($idStr === '' || $lotName === '' || $fromTs <= 0) continue;

                $lotPeriods[$idStr] ??= [];
                $lotPeriods[$idStr][$lotName] ??= [];
                $lotPeriods[$idStr][$lotName][] = [
                    'from_ts'   => $fromTs,
                    'to_ts'     => $toTs,
                    'barriques' => $bar,
                ];
            }
        }

        // tri des périodes par capteur/lot
        foreach ($lotPeriods as $sid => $lots) {
            foreach ($lots as $ln => $plist) {
                usort($lotPeriods[$sid][$ln], fn($a,$b) => ((int)$a['from_ts']) <=> ((int)$b['from_ts']));
            }
        }
    }
}

// PDA calculé pour les lots actifs
foreach ($configLots as $id => $cfg) {
    $lotName = trim((string)($cfg['lot'] ?? ''));
    if ($lotName === '' || !isset($lotsAgg[$lotName])) continue;
    if (!isset($historyById[$id])) continue;

    $entries = $historyById[$id];

    // Début : période ouverte si existe, sinon dernière période
    $startTs = null;
    if (isset($lotPeriods[$id][$lotName])) {
        foreach ($lotPeriods[$id][$lotName] as $p) {
            if ($p['to_ts'] === null) {
                $startTs = (int)$p['from_ts'];
                break;
            }
        }
        if ($startTs === null) {
            foreach ($lotPeriods[$id][$lotName] as $p) {
                $ft = (int)$p['from_ts'];
                if ($startTs === null || $ft > $startTs) $startTs = $ft;
            }
        }
    }

    $filtered = [];
    foreach ($entries as $e) {
        if ($startTs !== null && $e['ts'] < $startTs) continue;
        $filtered[] = $e;
    }
    if (count($filtered) < 2) continue;

    $prevMin = null;
    $prevMax = null;
    $pdaMinBar = 0.0;
    $pdaMaxBar = 0.0;

    foreach ($filtered as $e) {
        $curMin = $e['min'];
        $curMax = $e['max'];

        // On cumule UNIQUEMENT les diminutions de creux
        if ($prevMin !== null && $curMin < $prevMin) $pdaMinBar += ($prevMin - $curMin);
        if ($prevMax !== null && $curMax < $prevMax) $pdaMaxBar += ($prevMax - $curMax);

        $prevMin = $curMin;
        $prevMax = $curMax;
    }

    if ($pdaMinBar <= 0 && $pdaMaxBar <= 0) continue;

    $lotsAgg[$lotName]['pda_min_bar']   += $pdaMinBar;
    $lotsAgg[$lotName]['pda_max_bar']   += $pdaMaxBar;
    $lotsAgg[$lotName]['pda_count_cap'] += 1;
}

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
        $agg['pda_min_total'] = null;
        $agg['pda_max_total'] = null;
        $agg['pda_min_bar']   = null;
        $agg['pda_max_bar']   = null;
    }
    unset($agg);
}

/* =========================================================
   5bis) HISTORIQUE DES ANCIENS LOTS (ARCHIVES) — AVEC COLONNES
   ========================================================= */
$archivedLots = []; // lot => [min_start,max_end,barriques_total,hl_total,bouteilles_total,pda_min/max...]

$archivePeriodsByLot = []; // lot => [ ['id'=>..., 'from'=>..., 'to'=>..., 'bar'=>...], ... ]

if (!empty($lotPeriods)) {
    foreach ($lotPeriods as $sid => $lots) {
        foreach ($lots as $ln => $plist) {
            foreach ($plist as $p) {
                if ($p['to_ts'] === null) continue;

                $archivePeriodsByLot[$ln] ??= [];
                $archivePeriodsByLot[$ln][] = [
                    'id'   => (string)$sid,
                    'from' => (int)$p['from_ts'],
                    'to'   => (int)$p['to_ts'],
                    'bar'  => isset($p['barriques']) ? (int)$p['barriques'] : 0,
                ];
            }
        }
    }
}

if (file_exists($lotHistoryFile)) {
    $jsonH = file_get_contents($lotHistoryFile);
    $hist  = json_decode($jsonH, true);

    if (is_array($hist)) {
        $hasOpenByLot = []; // lot => true si une période ouverte existe

        if (array_keys($hist) !== range(0, count($hist) - 1)) {
            foreach ($hist as $idKey => $periods) {
                if (!is_array($periods)) continue;
                foreach ($periods as $entry) {
                    if (!is_array($entry)) continue;
                    $ln = trim((string)($entry['lot'] ?? ''));
                    if ($ln === '') continue;
                    $toRaw = $entry['to_ts'] ?? ($entry['end_ts'] ?? null);
                    if ($toRaw === null) $hasOpenByLot[$ln] = true;
                }
            }
        }

        if (array_keys($hist) !== range(0, count($hist) - 1)) {
            foreach ($hist as $idKey => $periods) {
                if (!is_array($periods)) continue;
                foreach ($periods as $entry) {
                    if (!is_array($entry)) continue;

                    $ln = trim((string)($entry['lot'] ?? ''));
                    $startTs = (int)($entry['from_ts'] ?? ($entry['start_ts'] ?? 0));
                    $toRaw   = $entry['to_ts'] ?? ($entry['end_ts'] ?? null);
                    $endTs   = ($toRaw === null) ? null : (int)$toRaw;

                    if ($ln === '' || $startTs <= 0) continue;
                    if (isset($lotsAgg[$ln])) continue;
                    if (!empty($hasOpenByLot[$ln])) continue;
                    if ($endTs === null) continue;

                    if (!isset($archivedLots[$ln])) {
                        $archivedLots[$ln] = [
                            'min_start' => $startTs,
                            'max_end'   => $endTs,
                            'barriques_total'  => 0,
                            'hl_total'         => 0.0,
                            'bouteilles_total' => 0,
                            'pda_min_bar'      => null,
                            'pda_max_bar'      => null,
                            'pda_min_total'    => null,
                            'pda_max_total'    => null,
                        ];
                    } else {
                        if ($startTs < $archivedLots[$ln]['min_start']) $archivedLots[$ln]['min_start'] = $startTs;
                        if ($endTs   > $archivedLots[$ln]['max_end'])   $archivedLots[$ln]['max_end']   = $endTs;
                    }
                }
            }
        }
    }
}

if (!empty($archivedLots)) {
    foreach ($archivedLots as $ln => &$ainfo) {

        $plist = $archivePeriodsByLot[$ln] ?? [];

        // barriques figées
        $barMax = 0;
        foreach ($plist as $p) {
            $barMax = max($barMax, (int)($p['bar'] ?? 0));
        }
        $ainfo['barriques_total'] = $barMax;

        if ($barMax > 0) {
            $ainfo['hl_total']         = $barMax * 2.25;
            $ainfo['bouteilles_total'] = (int)round($ainfo['hl_total'] * 133);
        }

        // --- Part des anges sur la période : cumule des diminutions ---
        $pdaMinSumBar = 0.0;
        $pdaMaxSumBar = 0.0;
        $pdaCount     = 0;

        foreach ($plist as $p) {
            $sid = (string)$p['id'];
            $from = (int)$p['from'];
            $to   = (int)$p['to'];

            if (!isset($historyById[$sid])) continue;

            $filtered = [];
            foreach ($historyById[$sid] as $e) {
                $ts = (int)$e['ts'];
                if ($ts < $from || $ts > $to) continue;
                $filtered[] = $e;
            }
            if (count($filtered) < 2) continue;

            usort($filtered, fn($a,$b) => $a['ts'] <=> $b['ts']);

            $prevMin = null; $prevMax = null;
            $pdaMinBar = 0.0; $pdaMaxBar = 0.0;

            foreach ($filtered as $e) {
                $curMin = (float)$e['min'];
                $curMax = (float)$e['max'];

                if ($prevMin !== null && $curMin < $prevMin) $pdaMinBar += ($prevMin - $curMin);
                if ($prevMax !== null && $curMax < $prevMax) $pdaMaxBar += ($prevMax - $curMax);

                $prevMin = $curMin;
                $prevMax = $curMax;
            }

            if ($pdaMinBar <= 0.0 && $pdaMaxBar <= 0.0) continue;

            $pdaMinSumBar += $pdaMinBar;
            $pdaMaxSumBar += $pdaMaxBar;
            $pdaCount++;
        }

        if ($barMax > 0 && $pdaCount > 0) {
            $avgMinBar = $pdaMinSumBar / $pdaCount;
            $avgMaxBar = $pdaMaxSumBar / $pdaCount;

            $ainfo['pda_min_bar']   = $avgMinBar;
            $ainfo['pda_max_bar']   = $avgMaxBar;
            $ainfo['pda_min_total'] = $avgMinBar * $barMax;
            $ainfo['pda_max_total'] = $avgMaxBar * $barMax;
        }
    }
    unset($ainfo);

    ksort($archivedLots);
}

/* =========================================================
   6) FONCTIONS AFFICHAGE
   ========================================================= */
function formatDateFr($iso) {
    if (empty($iso)) return '-';
    $ts = strtotime($iso);
    if ($ts === false) return htmlspecialchars($iso, ENT_QUOTES, 'UTF-8');
    return date('d/m/Y H\hi', $ts);
}

function rssiClass($rssi) {
    if ($rssi >= -60) return 'rssi-good';
    if ($rssi >= -75) return 'rssi-mid';
    return 'rssi-bad';
}

function batteryDisplay($mv) {
    if ($mv <= 0) return '-';
    if ($mv >= 4100) $level = 4;
    elseif ($mv >= 3900) $level = 3;
    elseif ($mv >= 3700) $level = 2;
    else $level = 1;

    $full  = str_repeat('█', $level);
    $empty = str_repeat('░', 4 - $level);
    $class = 'battery-l' . $level;
    return '<span class="battery ' . $class . '">' . ($full . $empty) . '</span>';
}

function labelJour($n) {
    $jours = [1=>'Lundi',2=>'Mardi',3=>'Mercredi',4=>'Jeudi',5=>'Vendredi',6=>'Samedi',7=>'Dimanche'];
    return $jours[$n] ?? 'Mardi';
}

function isCapteurInactive(int $ts, int $intervalDays, int $graceDays): bool {
    if ($ts <= 0) return false;
    $ageSec   = time() - $ts;
    $limitSec = ($intervalDays + $graceDays) * 86400;
    return $ageSec > $limitSec;
}

$inactiveMeasureDays = max(1, (int)$barConfig['measure_interval_days']);
$inactiveGraceDays   = max(0, (int)$notifConfig['offline_grace_days']);

// Texte bannière
$intervalParts = [];
if ((int)$barConfig['measure_interval_days'] > 0) {
    $intervalParts[] = (int)$barConfig['measure_interval_days'] . ' j';
}
if ((int)$barConfig['measure_interval_minutes'] > 0) {
    $intervalParts[] = (int)$barConfig['measure_interval_minutes'] . ' min';
}
if (empty($intervalParts)) {
    $intervalParts[] = '0 min';
}
$modeParts = [];
$modeParts[] = 'Intervalle: ' . implode(' ', $intervalParts);
$modeBanner = implode(' • ', $modeParts);

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Dashboard barriques - Capteurs</title>

    <link rel="icon" type="image/svg+xml"
          href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Cpath fill='%23f3d26b' d='M32 6C26 16 18 24 18 34c0 8 6.3 14 14 14s14-6 14-14C46 24 38 16 32 6z'/%3E%3C/svg%3E">

    <style>
        :root {
            --bg-page:#050608;
            --card-bg:#15171c;
            --card-border:#262a33;
            --text-main:#f5f5f7;
            --text-muted:#9ca3af;
            --accent:#d4af37;
            --table-header-bg:#1e222b;
            --table-row-alt:#171b22;
            --table-row-hover:#202633;
        }
        *{box-sizing:border-box;}
        body{
            font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
            background:radial-gradient(circle at top,#101318 0,#050608 45%,#000 100%);
            margin:0;padding:20px;color:var(--text-main);
        }
        .page{max-width:1200px;margin:0 auto;}
        .topbar{
            display:flex;align-items:center;justify-content:space-between;
            gap:10px;margin-bottom:10px;padding-bottom:8px;border-bottom:1px solid #262a33;
        }
        .topbar h1{flex:1;margin:0;font-size:24px;font-weight:500;letter-spacing:.04em;}
        .topbar-left{display:flex;align-items:center;gap:10px;}
        .topbar-actions{display:flex;align-items:center;gap:8px;}
        .back-link{text-decoration:none;font-size:22px;line-height:1;color:var(--text-main);}
        .back-link:hover{color:var(--accent);}
        .icon-btn{background:transparent;border:none;cursor:pointer;font-size:1.3rem;padding:.2rem .4rem;color:var(--accent);}
        .icon-btn:hover{transform:scale(1.08);}

        /* BANNIÈRE MODE */
        .mode-banner{
            background: linear-gradient(90deg, rgba(212,175,55,0.18), rgba(0,0,0,0));
            border: 1px solid rgba(212,175,55,0.25);
            color: #e5e7eb;
            font-size: 12px;
            padding: 8px 10px;
            border-radius: 10px;
            margin: 0 0 14px 0;
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:10px;
        }
        .mode-banner .left{
            display:flex;align-items:center;gap:10px;flex-wrap:wrap;
        }
        .pill{
            display:inline-block;
            padding: 2px 8px;
            border-radius: 999px;
            border: 1px solid rgba(212,175,55,0.35);
            color: #f3d26b;
            font-size: 11px;
        }

        .header{margin-bottom:15px;font-size:13px;color:var(--text-muted);}
        .card{
            background:var(--card-bg);border:1px solid var(--card-border);
            border-radius:10px;box-shadow:0 10px 25px rgba(0,0,0,.35);
            margin-bottom:20px;overflow:hidden;
        }
        .card-header{
            padding:10px 16px;border-bottom:1px solid #262a33;
            background:linear-gradient(90deg,#181c23,#12151b);
            font-size:14px;font-weight:500;
        }
        .card-body{padding:10px 16px 14px 16px;}
        table{border-collapse:collapse;width:100%;font-size:13px;color:var(--text-main);}
        th,td{padding:6px 8px;border-bottom:1px solid #262a33;text-align:left;}
        th{
            background:var(--table-header-bg);font-weight:500;font-size:11px;
            text-transform:uppercase;letter-spacing:.04em;color:#e5e7eb;
        }
        tr:nth-child(even) td{background:var(--table-row-alt);}
        tr:hover td{background:var(--table-row-hover);}
        .badge{display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;color:#fff;text-transform:lowercase;}
        .vert{background:#22c55e;} .jaune{background:#eab308;} .orange{background:#f97316;}
        .rouge{background:#ef4444;} .rouge_vif{background:#b91c1c;} .rouge_ultra{background:#7f1d1d;} .erreur{background:#6b7280;}
        .rssi{font-family:monospace;}
        .rssi-good{color:#22c55e;font-weight:600;} .rssi-mid{color:#eab308;font-weight:600;} .rssi-bad{color:#ef4444;font-weight:600;}
        .small{font-size:11px;color:var(--text-muted);}
        .small.inactive{color:#ef4444;font-weight:600;}
        .battery{font-family:monospace;}
        .battery-l1{color:#ef4444;} .battery-l2{color:#eab308;} .battery-l3{color:#f97316;} .battery-l4{color:#22c55e;}
        .inline-input{
            width:100%;padding:3px 5px;font-size:13px;border:1px solid #3b3f4a;border-radius:4px;background:#101218;color:var(--text-main);
        }
        .inline-input:focus{outline:none;border-color:var(--accent);background:#0b0e13;}
        .inline-input-number{
            width:100%;max-width:90px;padding:3px 5px;font-size:13px;border:1px solid #3b3f4a;border-radius:4px;background:#101218;color:var(--text-main);
        }
        .inline-input-number:focus{outline:none;border-color:var(--accent);background:#0b0e13;}
        .save-btn{background:none;border:none;padding:0;margin:0;font-size:18px;cursor:pointer;line-height:1;color:var(--accent);}
        .save-btn:hover{opacity:.7;}
        .section-title{font-size:15px;font-weight:500;margin:0;color:var(--accent);letter-spacing:.03em;}

        .settings-panel{position:fixed;inset:0;background:rgba(0,0,0,.6);display:none;align-items:center;justify-content:center;z-index:999;}
        .settings-content{background:#111;border:1px solid #444;border-radius:8px;padding:1.5rem;min-width:260px;max-width:360px;color:#f5f5f5;box-shadow:0 0 20px rgba(0,0,0,.6);}
        .settings-content h2{margin-top:0;margin-bottom:1rem;font-size:1.2rem;color:#f3d26b;}
        .settings-content fieldset{border:1px solid #333;padding:.8rem;margin-bottom:.8rem;}
        .settings-content legend{padding:0 .4rem;}
        .settings-actions{margin-top:1rem;text-align:right;}
        .settings-actions .icon-btn{font-size:1rem;}
        .settings-actions .icon-btn:first-child{color:var(--text-muted);}
        .settings-actions .icon-btn:last-child{color:var(--accent);}
        .settings-row{margin-top:.6rem;font-size:.9rem;}
        .settings-row label{display:block;margin-bottom:.25rem;}
        .settings-row input[type="number"], .settings-row select{width:100%;padding:4px 6px;border-radius:4px;border:1px solid #444;background:#0b0e13;color:#f5f5f5;font-size:.9rem;}
        input[disabled]{opacity:.5;cursor:not-allowed;}
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

    <!-- BANNIÈRE MODE -->
    <div class="mode-banner">
        <div class="left">
            <span class="pill">Mode</span>
            <span><?php echo htmlspecialchars($modeBanner, ENT_QUOTES, 'UTF-8'); ?></span>
        </div>
        <div class="right small">
            <?php
            $notifTxt = 'Notifications: ' . strtoupper((string)$notifConfig['mode']);
            echo htmlspecialchars($notifTxt, ENT_QUOTES, 'UTF-8');
            ?>
        </div>
    </div>

    <div class="header">
        <?php if (!empty($capteurs)): ?>
            Capteurs actifs : <strong><?php echo count($capteurs); ?></strong>
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
                            <th>Offset (cm)</th>
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
                            $offset = getCreuxOffsetForSensor((string)$id);
                            $interp = interpret_raw((int)$info['raw'], (float)$offset);

                            $classeCouleur = $interp['couleur'];
                            if (!in_array($classeCouleur, ['vert','jaune','orange','rouge','rouge_vif','rouge_ultra','erreur'], true)) {
                                $classeCouleur = 'erreur';
                            }

                            $lotName = '';
                            if (isset($configLots[$id]) && !empty($configLots[$id]['lot'])) {
                                $lotName = (string)$configLots[$id]['lot'];
                            }

                            $tempAff = '-';
                            $dateAff = formatDateFr($info['date_iso']);

                            $inactive = isCapteurInactive((int)$info['ts'], $inactiveMeasureDays, $inactiveGraceDays);
                            $rssiClassStr = rssiClass($info['rssi']);
                            $batteryHtml = batteryDisplay($info['batt']);
                            ?>
                            <tr>
                                <td><?php echo htmlspecialchars($id, ENT_QUOTES, 'UTF-8'); ?></td>
                                <td>
                                    <input type="text"
                                           name="lot[<?php echo htmlspecialchars($id, ENT_QUOTES, 'UTF-8'); ?>]"
                                           class="inline-input"
                                           placeholder="Nom du lot"
                                           value="<?php echo htmlspecialchars($lotName, ENT_QUOTES, 'UTF-8'); ?>">
                                </td>

                                <td>
                                    <input type="number" step="0.1"
                                           name="offset_cm[<?php echo htmlspecialchars($id, ENT_QUOTES, 'UTF-8'); ?>]"
                                           class="inline-input-number"
                                           value="<?php echo htmlspecialchars((string)$offset, ENT_QUOTES, 'UTF-8'); ?>"
                                           title="Offset appliqué au creux en cm (ex: -1.0 ou 1.0)">
                                </td>

                                <td><?php echo htmlspecialchars((string)$interp['niveau'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td>
                                    <span class="badge <?php echo htmlspecialchars($classeCouleur, ENT_QUOTES, 'UTF-8'); ?>">
                                        <?php echo htmlspecialchars((string)$interp['couleur'], ENT_QUOTES, 'UTF-8'); ?>
                                    </span>
                                </td>

                                <td>
                                    <?php
                                    if ($interp['creux_cm_min'] === null) echo '-';
                                    else echo htmlspecialchars((string)$interp['creux_cm_min'], ENT_QUOTES, 'UTF-8') . ' – ' .
                                              htmlspecialchars((string)$interp['creux_cm_max'], ENT_QUOTES, 'UTF-8');
                                    ?>
                                </td>
                                <td>
                                    <?php
                                    if ($interp['creux_l_min'] === null) echo '-';
                                    else echo htmlspecialchars((string)$interp['creux_l_min'], ENT_QUOTES, 'UTF-8') . ' – ' .
                                              htmlspecialchars((string)$interp['creux_l_max'], ENT_QUOTES, 'UTF-8');
                                    ?>
                                </td>

                                <td><?php echo $tempAff; ?></td>
                                <td><?php echo (int)$info['raw']; ?></td>

                                <td class="rssi <?php echo htmlspecialchars($rssiClassStr, ENT_QUOTES, 'UTF-8'); ?>">
                                    <?php echo (int)$info['rssi']; ?> dBm
                                </td>

                                <td><?php echo $batteryHtml; ?></td>

                                <td class="small<?php echo $inactive ? ' inactive' : ''; ?>">
                                    <?php echo htmlspecialchars($dateAff, ENT_QUOTES, 'UTF-8'); ?>
                                </td>

                                <td class="small"><?php echo htmlspecialchars((string)$info['fw'], ENT_QUOTES, 'UTF-8'); ?></td>

                                <td>
                                    <button type="submit"
                                            name="submit_capteur"
                                            value="<?php echo htmlspecialchars($id, ENT_QUOTES, 'UTF-8'); ?>"
                                            class="save-btn"
                                            title="Enregistrer ce capteur (lot + offset)">💾</button>
                                    <a href="history_capteur.php?id=<?php echo urlencode($id); ?>"
                                       class="save-btn"
                                       title="Voir l'historique de ce capteur">📈</a>
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
                <div class="card-header"><span class="section-title">Vue par lot</span></div>
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
                                    $ouMin = number_format((float)$agg['ouillage_min'], 1, ',', ' ');
                                    $ouMax = number_format((float)$agg['ouillage_max'], 1, ',', ' ');
                                    $ouillageText = $ouMin . ' – ' . $ouMax;
                                }

                                $pdaText = '-';
                                if ($agg['pda_min_total'] !== null && $agg['pda_max_total'] !== null) {
                                    $pdaMinTot = number_format((float)$agg['pda_min_total'], 1, ',', ' ');
                                    $pdaMaxTot = number_format((float)$agg['pda_max_total'], 1, ',', ' ');
                                    $pdaMinBar = number_format((float)$agg['pda_min_bar'], 1, ',', ' ');
                                    $pdaMaxBar = number_format((float)$agg['pda_max_bar'], 1, ',', ' ');
                                    $pdaText   = $pdaMinTot . ' – ' . $pdaMaxTot . ' (' . $pdaMinBar . ' – ' . $pdaMaxBar . ' /bar)';
                                }
                                ?>
                                <tr>
                                    <td><?php echo htmlspecialchars((string)$lotName, ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td>
                                        <input type="number"
                                               class="inline-input-number"
                                               name="lot_barriques[<?php echo htmlspecialchars((string)$lotName, ENT_QUOTES, 'UTF-8'); ?>]"
                                               min="0" step="1"
                                               value="<?php echo (int)$b; ?>">
                                    </td>
                                    <td><?php echo number_format($hl, 2, ',', ' '); ?></td>
                                    <td><?php echo (int)$bt; ?></td>
                                    <td><?php echo htmlspecialchars((string)$ouillageText, ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars((string)$pdaText, ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td>
                                        <button type="submit"
                                                name="submit_lot"
                                                value="<?php echo htmlspecialchars((string)$lotName, ENT_QUOTES, 'UTF-8'); ?>"
                                                class="save-btn"
                                                title="Enregistrer ce lot">💾</button>
                                        <a href="history_lot.php?lot=<?php echo urlencode((string)$lotName); ?>"
                                           class="save-btn"
                                           title="Voir l'historique de ce lot">📈</a>
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
                            <th>Barriques</th>
                            <th>Volume (hl)</th>
                            <th>Éq. bouteilles</th>
                            <th>Part des anges (L)</th>
                            <th>Actions</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($archivedLots as $lotName => $info): ?>
                            <?php
                            $start = date('d/m/Y', (int)$info['min_start']);
                            $end   = date('d/m/Y', (int)$info['max_end']);

                            $b  = (int)($info['barriques_total'] ?? 0);
                            $hl = (float)($info['hl_total'] ?? 0.0);
                            $bt = (int)($info['bouteilles_total'] ?? 0);

                            // ✅ ICI : on ne calcule PLUS d'ouillage pour les archives (colonne supprimée)

                            $pdaText = '-';
                            if (($info['pda_min_total'] ?? null) !== null && ($info['pda_max_total'] ?? null) !== null) {
                                $pdaMinTot = number_format((float)$info['pda_min_total'], 1, ',', ' ');
                                $pdaMaxTot = number_format((float)$info['pda_max_total'], 1, ',', ' ');
                                $pdaMinBar = number_format((float)$info['pda_min_bar'], 1, ',', ' ');
                                $pdaMaxBar = number_format((float)$info['pda_max_bar'], 1, ',', ' ');
                                $pdaText   = $pdaMinTot . ' – ' . $pdaMaxTot . ' (' . $pdaMinBar . ' – ' . $pdaMaxBar . ' /bar)';
                            }
                            ?>
                            <tr>
                                <td><?php echo htmlspecialchars((string)$lotName, ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars($start . ' → ' . $end, ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo (int)$b; ?></td>
                                <td><?php echo number_format((float)$hl, 2, ',', ' '); ?></td>
                                <td><?php echo (int)$bt; ?></td>
                                <!-- ✅ ICI : on affiche bien la PDA dans la bonne colonne -->
                                <td><?php echo htmlspecialchars((string)$pdaText, ENT_QUOTES, 'UTF-8'); ?></td>
                                <td>
                                    <a href="history_lot.php?lot=<?php echo urlencode((string)$lotName); ?>"
                                       class="save-btn"
                                       title="Voir l'historique de ce lot">📈</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

    <?php endif; // capteurs ?>

</div>

<!-- Panneau paramètres -->
<div id="settings-panel" class="settings-panel">
    <div class="settings-content">
        <h2>Paramètres</h2>

        <form method="post" action="save_notifications_config.php">
            <fieldset>
                <legend>Notifications</legend>

                <label><input type="radio" name="mode" value="off"   <?php echo ($notifConfig['mode']==='off')?'checked':''; ?>> Désactivées</label><br>
                <label><input type="radio" name="mode" value="daily" <?php echo ($notifConfig['mode']==='daily')?'checked':''; ?>> Quotidiennes</label><br>
                <label><input type="radio" name="mode" value="weekly"<?php echo ($notifConfig['mode']==='weekly')?'checked':''; ?>> Hebdomadaires</label>

                <div class="settings-row">
                    <label for="weekly_day">Jour d’envoi (mode hebdo) :</label>
                    <select name="weekly_day" id="weekly_day">
                        <?php
                        for ($d=1; $d<=7; $d++) {
                            $sel = ((int)$notifConfig['weekly_day'] === $d) ? 'selected' : '';
                            echo "<option value=\"$d\" $sel>" . labelJour($d) . "</option>";
                        }
                        ?>
                    </select>
                </div>

                <br>

                <label><input type="checkbox" name="include_battery" <?php echo $notifConfig['include_battery']?'checked':''; ?>> Inclure batterie faible</label><br>
                <label><input type="checkbox" name="include_offline" <?php echo $notifConfig['include_offline']?'checked':''; ?>> Inclure capteurs inactifs</label>
            </fieldset>

            <fieldset>
                <legend>Capteurs (global)</legend>

                <div class="settings-row">
                    <label for="measure_interval_days">Fréquence des mesures :</label>

                    <div style="display:flex; flex-wrap:wrap; gap:10px; align-items:center;">
                        <div style="display:flex; align-items:center; gap:4px;">
                            <input type="number" id="measure_interval_days" name="measure_interval_days" min="0"
                                   value="<?php echo (int)$barConfig['measure_interval_days']; ?>" style="width:70px;">
                            <span class="small">jours</span>
                        </div>
                        <div style="display:flex; align-items:center; gap:4px;">
                            <input type="number" id="measure_interval_minutes" name="measure_interval_minutes" min="0" max="1439"
                                   value="<?php echo (int)$barConfig['measure_interval_minutes']; ?>" style="width:70px;">
                            <span class="small">minutes</span>
                        </div>
                    </div>
                </div>
            </fieldset>

            <div class="settings-actions">
                <button type="button" id="close-settings" class="icon-btn">✖</button>
                <button type="submit" class="icon-btn">💾</button>
            </div>
        </form>

        <fieldset>
            <legend>Estimation d'autonomie batterie</legend>

            <div class="settings-row">
                <label for="batt_capacity_mah">Capacité pile (mAh)</label>
                <input type="number" id="batt_capacity_mah" min="0" step="1" value="3000">
            </div>
            <div class="settings-row">
                <label for="batt_active_ma">Courant actif (mA)</label>
                <input type="number" id="batt_active_ma" min="0" step="1" value="120">
            </div>
            <div class="settings-row">
                <label for="batt_active_s">Durée active par mesure (s)</label>
                <input type="number" id="batt_active_s" min="0" step="1" value="5">
            </div>
            <div class="settings-row">
                <label for="batt_sleep_ua">Courant de veille (µA)</label>
                <input type="number" id="batt_sleep_ua" min="0" step="1" value="40">
                <span class="small">inclut ~19 µA du pont diviseur batterie, en continu</span>
            </div>
            <div class="settings-row">
                <label for="batt_selfdischarge_pct">Autodécharge pile (%/mois)</label>
                <input type="number" id="batt_selfdischarge_pct" min="0" step="0.5" value="3">
            </div>

            <div class="settings-row" style="margin-top:0.8rem; font-size:1rem; color:#f3d26b;">
                <span id="autonomie-result">-</span>
            </div>
        </fieldset>
    </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", () => {
    const openSettings = document.getElementById("open-settings");
    const closeSettings = document.getElementById("close-settings");
    const settingsPanel = document.getElementById("settings-panel");

    if (openSettings && settingsPanel) openSettings.addEventListener("click", () => settingsPanel.style.display="flex");
    if (closeSettings && settingsPanel) closeSettings.addEventListener("click", () => settingsPanel.style.display="none");

    function calcAutonomie() {
        const resultEl = document.getElementById("autonomie-result");
        if (!resultEl) return;

        const jours   = parseInt(document.getElementById("measure_interval_days").value, 10) || 0;
        const minutes = parseInt(document.getElementById("measure_interval_minutes").value, 10) || 0;
        const intervalS = Math.max(60, jours * 86400 + minutes * 60);

        const capaciteMah     = parseFloat(document.getElementById("batt_capacity_mah").value) || 0;
        const activeMa        = parseFloat(document.getElementById("batt_active_ma").value) || 0;
        const activeS         = parseFloat(document.getElementById("batt_active_s").value) || 0;
        const veilleUa        = parseFloat(document.getElementById("batt_sleep_ua").value) || 0;
        const autodechargePct = parseFloat(document.getElementById("batt_selfdischarge_pct").value) || 0;

        if (capaciteMah <= 0) {
            resultEl.textContent = "-";
            return;
        }

        const cyclesParJour           = 86400 / intervalS;
        const chargeActiveParCycle    = (activeMa * activeS) / 3600;
        const tempsVeilleParCycleS    = Math.max(0, intervalS - activeS);
        const chargeVeilleParCycle    = (veilleUa / 1000) * tempsVeilleParCycleS / 3600;
        const chargeParCycleMah       = chargeActiveParCycle + chargeVeilleParCycle;

        const consoMesuresJourMah = chargeParCycleMah * cyclesParJour;
        const autodechargeJourMah = capaciteMah * (autodechargePct / 100) / 30;
        const consoTotaleJourMah  = consoMesuresJourMah + autodechargeJourMah;

        if (consoTotaleJourMah <= 0) {
            resultEl.textContent = "-";
            return;
        }

        const autonomieJours = capaciteMah / consoTotaleJourMah;
        const nbCycles        = autonomieJours * cyclesParJour;

        resultEl.innerHTML =
            "≈ " + Math.round(nbCycles).toLocaleString('fr-FR') + " mesures possibles<br>" +
            "≈ " + Math.round(autonomieJours).toLocaleString('fr-FR') + " jours" +
            " (" + (autonomieJours / 30).toFixed(1) + " mois / " + (autonomieJours / 365).toFixed(2) + " ans)";
    }

    ["measure_interval_days", "measure_interval_minutes", "batt_capacity_mah", "batt_active_ma",
     "batt_active_s", "batt_sleep_ua", "batt_selfdischarge_pct"].forEach((id) => {
        const el = document.getElementById(id);
        if (el) el.addEventListener("input", calcAutonomie);
    });
    calcAutonomie();

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