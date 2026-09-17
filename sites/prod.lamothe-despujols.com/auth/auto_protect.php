<?php
// auto_protect.php - protege AUTOMATIQUEMENT toute page PHP du site
// (active via auto_prepend_file dans .user.ini a la racine du site),
// SAUF la liste d'exceptions ci-dessous.
//
// Principe : "tout est protege par defaut, sauf exception explicite"
// - plus sur que l'inverse (proteger fichier par fichier a la main,
// avec le risque d'oublier un nouveau fichier cree plus tard). Un
// nouveau module (vigne, commerce...) ou une nouvelle page ajoutee
// dans barriques/cuves sera protege automatiquement, sans rien faire.

require_once __DIR__ . '/auth_lib.php';

// Chemins EXACTS (tels que dans l'URL, relatifs au site) exemptes de
// la connexion Google :
// - endpoints appeles par les capteurs (ESP32, ne peuvent pas faire
//   d'authentification Google) ;
// - pages du mecanisme de connexion lui-meme (sinon boucle infinie
//   de redirection).
const AUTH_EXEMPT_PATHS = [
    '/barriques/get_config.php',
    '/barriques/api_post.php',
    '/cuves/get_config.php',
    '/cuves/api_cuve.php',
    '/cuves/ota_check.php',
    '/auth/login.php',
    '/auth/google_callback.php',
    '/auth/logout.php',
];

$requestedPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);

if (!in_array($requestedPath, AUTH_EXEMPT_PATHS, true)) {
    requireLogin();
}
