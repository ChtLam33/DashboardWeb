<?php
// auth_lib.php deja charge par auto_protect.php (auto_prepend_file)
authStartSession();

$code  = $_GET['code']  ?? null;
$state = $_GET['state'] ?? null;

if ($code === null || $state === null || !isset($_SESSION['auth_state']) || !hash_equals($_SESSION['auth_state'], $state)) {
    http_response_code(400);
    echo "Requête de connexion invalide ou expirée. <a href=\"/auth/login.php\">Réessayer</a>.";
    exit;
}
unset($_SESSION['auth_state']);

// Echange du code contre un access_token (appel serveur-a-serveur avec le
// secret client, jamais expose au navigateur)
$ch = curl_init('https://oauth2.googleapis.com/token');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_POSTFIELDS     => http_build_query([
        'code'          => $code,
        'client_id'     => GOOGLE_CLIENT_ID,
        'client_secret' => GOOGLE_CLIENT_SECRET,
        'redirect_uri'  => GOOGLE_REDIRECT_URI,
        'grant_type'    => 'authorization_code',
    ]),
]);
$tokenResponse = curl_exec($ch);
$tokenHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$tokenData = json_decode((string)$tokenResponse, true);
if ($tokenHttpCode !== 200 || !is_array($tokenData) || empty($tokenData['access_token'])) {
    http_response_code(502);
    echo "Échec de l'authentification Google (échange du code). <a href=\"/auth/login.php\">Réessayer</a>.";
    exit;
}

// Recuperation de l'email verifie directement depuis l'API Google (pas
// besoin de verifier nous-memes un jeton signe : on parle a Google en
// direct, en HTTPS, avec l'access_token qu'on vient d'obtenir).
$ch = curl_init('https://www.googleapis.com/oauth2/v3/userinfo');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $tokenData['access_token']],
]);
$userResponse = curl_exec($ch);
$userHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$userData = json_decode((string)$userResponse, true);
if ($userHttpCode !== 200 || !is_array($userData) || empty($userData['email']) || empty($userData['email_verified'])) {
    http_response_code(502);
    echo "Échec de la récupération du compte Google. <a href=\"/auth/login.php\">Réessayer</a>.";
    exit;
}

$email = (string)$userData['email'];

if (!in_array($email, ALLOWED_EMAILS, true)) {
    http_response_code(403);
    echo "Ce compte Google (" . htmlspecialchars($email, ENT_QUOTES, 'UTF-8') . ") n'est pas autorisé à accéder à ce dashboard.";
    exit;
}

$_SESSION['auth_email'] = $email;

$redirectTo = $_SESSION['auth_redirect_after'] ?? '/';
unset($_SESSION['auth_redirect_after']);

header('Location: ' . $redirectTo);
exit;
