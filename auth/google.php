<?php
/**
 * Step 1 of "Continue with Google": remember what the person wants (sign up as which pathway,
 * or sign in), then send them to Google's account chooser.
 */
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/google_auth.php';

mentora_session_start();

$intent = mentora_post_string('intent') === 'signup' ? 'signup' : 'signin';
$pathway = mentora_post_string('pathway');
$pathway = in_array($pathway, ['employee_va', 'internship'], true) ? $pathway : null;
$backTo = $intent === 'signup'
    ? 'auth/signup.php' . ($pathway === 'internship' ? '?pathway=internship' : '')
    : 'auth/signin.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    mentora_redirect($backTo);
}
if (!mentora_csrf_valid($_POST['csrf_token'] ?? null)) {
    $_SESSION['flash_error'] = 'Your session expired. Please try again.';
    mentora_redirect($backTo);
}
if (!mentora_google_enabled()) {
    $_SESSION['flash_error'] = 'Continue with Google isn’t set up yet. Please use your email and password for now.';
    mentora_redirect($backTo);
}
if ($intent === 'signup' && $pathway === null) {
    $_SESSION['flash_error'] = 'Please choose Employee VA or Intern first.';
    mentora_redirect($backTo);
}

$state = bin2hex(random_bytes(16));
$_SESSION['google_oauth'] = [
    'state' => $state,
    'intent' => $intent,
    'pathway' => $pathway,
    'started' => time(),
];

$config = mentora_google_config();
header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
    'client_id' => $config['client_id'],
    'redirect_uri' => mentora_google_redirect_uri(),
    'response_type' => 'code',
    'scope' => 'openid email profile',
    'state' => $state,
    'prompt' => 'select_account',
]));
exit;
