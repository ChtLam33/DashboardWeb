<?php
// lock_lib.php — utilitaires de verrouillage partages par les fichiers
// cuves qui lisent/ecrivent config_cuves.json, data_cuves.csv ou
// history_lots.json. Meme principe que le correctif applique a
// api_cuve.php / update_cache.php : un verrou exclusif doit couvrir
// TOUTE l'operation lecture+ecriture (pas seulement l'ecriture finale),
// sinon une requete concurrente peut lire des donnees perimees et
// ecraser la mise a jour d'une autre requete (capteur ou action
// utilisateur) survenue entre-temps.

// Lecture protegee (verrou partage) d'un fichier texte quelconque.
// Renvoie le contenu (string, "" si fichier vide) ou null si le fichier
// n'existe pas / est illisible.
function lockedRead(string $path): ?string {
    if (!file_exists($path)) {
        return null;
    }
    $fp = fopen($path, 'r');
    if ($fp === false) {
        return null;
    }
    $content = null;
    if (flock($fp, LOCK_SH)) {
        $raw = stream_get_contents($fp);
        $content = ($raw === false) ? '' : $raw;
        flock($fp, LOCK_UN);
    }
    fclose($fp);
    return $content;
}

// Lecture + transformation + ecriture d'un fichier JSON (tableau), le
// tout sous un seul verrou exclusif continu. $modify reçoit le tableau
// decode (ou [] si fichier vide/absent/invalide) et doit renvoyer le
// nouveau tableau a sauvegarder.
function lockedReadModifyWriteJson(string $path, callable $modify): array {
    $fp = fopen($path, 'c+');
    if ($fp === false) {
        throw new RuntimeException("Impossible d'ouvrir $path");
    }
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        throw new RuntimeException("Impossible de verrouiller $path");
    }

    $content = stream_get_contents($fp);
    $data = [];
    if ($content !== false && trim($content) !== '') {
        $decoded = json_decode($content, true);
        if (is_array($decoded)) {
            $data = $decoded;
        }
    }

    $newData = $modify($data);

    rewind($fp);
    ftruncate($fp, 0);
    fwrite($fp, json_encode($newData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    return $newData;
}

// Meme principe que ci-dessus, pour un fichier texte brut (ex: CSV).
// $modify reçoit le contenu actuel (string, "" si fichier vide/absent)
// et doit renvoyer le nouveau contenu complet a ecrire.
function lockedReadModifyWriteText(string $path, callable $modify): string {
    $fp = fopen($path, 'c+');
    if ($fp === false) {
        throw new RuntimeException("Impossible d'ouvrir $path");
    }
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        throw new RuntimeException("Impossible de verrouiller $path");
    }

    $content = stream_get_contents($fp);
    $content = ($content === false) ? '' : $content;

    $newContent = $modify($content);

    rewind($fp);
    ftruncate($fp, 0);
    fwrite($fp, $newContent);
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    return $newContent;
}
