<?php
// roadmap_lib.php - Liste des chantiers/developpements a venir, PARTAGEE
// entre tous les modules (barriques, cuves, futurs modules). Source
// UNIQUE de verite pour ce suivi (remplace le suivi en memoire cote
// Claude Code, qui n'y pointe desormais que par reference).
//
// Deux familles d'entrees :
// - source "identifie" : trouve/discute lors d'une session de travail
//   (audit, discussion), range dans une categorie.
// - source "manuel" : ajoute directement par l'utilisateur depuis le
//   dashboard, sans passer par une session Claude Code. Affiche a part.

function roadmapFilePath(): string {
    return __DIR__ . '/roadmap.json';
}

function loadRoadmap(): array {
    $file = roadmapFilePath();
    if (!file_exists($file)) return [];
    $data = json_decode((string)file_get_contents($file), true);
    return is_array($data) ? $data : [];
}

function saveRoadmap(array $items): void {
    file_put_contents(
        roadmapFilePath(),
        json_encode($items, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
    );
}

/**
 * Ajoute un item saisi manuellement depuis le dashboard (pas de
 * categorie - affiche a part, avec sa date).
 */
function addRoadmapItem(string $titre, string $description): void {
    $titre = trim($titre);
    if ($titre === '') return;

    $items = loadRoadmap();
    $items[] = [
        'id'          => bin2hex(random_bytes(6)),
        'categorie'   => null,
        'titre'       => $titre,
        'description' => trim($description),
        'source'      => 'manuel',
        'date'        => date('Y-m-d'),
    ];
    saveRoadmap($items);
}

/**
 * Retourne :
 * - 'categories' => [ nom_categorie => [ items... ] ] (source "identifie",
 *   dans l'ordre d'apparition dans le fichier, categories dans l'ordre
 *   de premiere apparition)
 * - 'manuel' => [ items... ] (source "manuel", plus recent en premier)
 */
function getRoadmapGrouped(): array {
    $items = loadRoadmap();
    $categories = [];
    $manuel = [];

    foreach ($items as $item) {
        if (!is_array($item)) continue;
        if (($item['source'] ?? '') === 'manuel') {
            $manuel[] = $item;
            continue;
        }
        $cat = (string)($item['categorie'] ?? 'Autre');
        $categories[$cat] ??= [];
        $categories[$cat][] = $item;
    }

    usort($manuel, fn($a, $b) => strcmp((string)($b['date'] ?? ''), (string)($a['date'] ?? '')));

    return ['categories' => $categories, 'manuel' => $manuel];
}
