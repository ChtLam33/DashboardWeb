<?php
// auth_lib.php deja charge par auto_protect.php (auto_prepend_file)
authStartSession();

// Deja connecte ? on ne repasse pas par Google inutilement.
if (isLoggedIn()) {
    $redirectTo = $_SESSION['auth_redirect_after'] ?? '/';
    unset($_SESSION['auth_redirect_after']);
    header('Location: ' . $redirectTo);
    exit;
}

// Jeton anti-CSRF pour verifier que la reponse de Google correspond
// bien a CETTE tentative de connexion.
$state = bin2hex(random_bytes(16));
$_SESSION['auth_state'] = $state;

$params = http_build_query([
    'client_id'     => GOOGLE_CLIENT_ID,
    'redirect_uri'  => GOOGLE_REDIRECT_URI,
    'response_type' => 'code',
    'scope'         => 'openid email profile',
    'state'         => $state,
    'prompt'        => 'select_account',
]);

header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . $params);
exit;
