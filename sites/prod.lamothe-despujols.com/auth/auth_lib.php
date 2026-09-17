<?php
// auth_lib.php - Authentification Google, partagee entre tous les modules
// (barriques, cuves, futurs modules). A inclure et appeler requireLogin()
// tout en haut de chaque page humaine a proteger.
//
// Deux niveaux de protection independants :
// 1) L'app Google est en mode "Test" (console Google Cloud), seuls les
//    emails ajoutes comme testeurs peuvent meme tenter de se connecter.
// 2) ALLOWED_EMAILS ci-dessous, verifie ici cote serveur - reste actif
//    meme si l'app passait un jour en mode "Production" cote Google.

// Identifiants Google (secrets.local.php n'est JAMAIS commite dans Git -
// voir .gitignore - uniquement present sur le serveur live).
require __DIR__ . '/secrets.local.php';

const GOOGLE_REDIRECT_URI = 'https://prod.lamothe-despujols.com/auth/google_callback.php';

const ALLOWED_EMAILS = [
    'lamothe.sauternes@gmail.com',
];

function authStartSession(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => '',
            'secure'   => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

function isLoggedIn(): bool {
    authStartSession();
    return isset($_SESSION['auth_email']) && in_array($_SESSION['auth_email'], ALLOWED_EMAILS, true);
}

function getLoggedInEmail(): ?string {
    authStartSession();
    return $_SESSION['auth_email'] ?? null;
}

/**
 * A appeler tout en haut de chaque page humaine a proteger. Redirige vers
 * la connexion Google si pas connecte, en gardant l'URL demandee pour y
 * revenir automatiquement apres connexion.
 */
function requireLogin(): void {
    if (isLoggedIn()) {
        return;
    }
    authStartSession();
    $currentUrl = 'https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
    $_SESSION['auth_redirect_after'] = $currentUrl;
    header('Location: /auth/login.php');
    exit;
}

function logoutUser(): void {
    authStartSession();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}
