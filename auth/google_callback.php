<?php
/**
 * Step 2 of "Continue with Google": Google sends the person back here.
 * - The Google email already has a Mentora account: sign in to it.
 * - No account yet and they came from sign-up: create the account with the chosen pathway,
 *   then open the qualifications form (same as signing up with a password).
 * - No account yet and they came from sign-in: send them to sign-up to choose a pathway.
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/google_auth.php';

mentora_session_start();

$saved = $_SESSION['google_oauth'] ?? null;
unset($_SESSION['google_oauth']);   // each Google round trip can be used once

$intent = is_array($saved) && ($saved['intent'] ?? '') === 'signup' ? 'signup' : 'signin';
$fail = static function (string $message, string $page) {
    $_SESSION['flash_error'] = $message;
    mentora_redirect($page);
};
$backTo = $intent === 'signup' ? 'auth/signup.php' : 'auth/signin.php';

$state = is_string($_GET['state'] ?? null) ? $_GET['state'] : '';
if (!is_array($saved) || !is_string($saved['state'] ?? null) || !hash_equals($saved['state'], $state)
    || time() - (int) ($saved['started'] ?? 0) > 600) {
    $fail('Your Google sign-in timed out. Please try again.', $backTo);
}
if (isset($_GET['error'])) {
    $fail('Google sign-in was cancelled.', $backTo);
}
$code = is_string($_GET['code'] ?? null) ? $_GET['code'] : '';
if ($code === '') {
    $fail('Google didn’t send a sign-in code. Please try again.', $backTo);
}

// Swap the one-time code for an access token, then ask Google who signed in.
$config = mentora_google_config();
$token = mentora_google_request('https://oauth2.googleapis.com/token', [
    'code' => $code,
    'client_id' => $config['client_id'],
    'client_secret' => $config['client_secret'],
    'redirect_uri' => mentora_google_redirect_uri(),
    'grant_type' => 'authorization_code',
]);
$profile = is_string($token['access_token'] ?? null)
    ? mentora_google_request('https://openidconnect.googleapis.com/v1/userinfo', null, $token['access_token'])
    : null;

$email = is_string($profile['email'] ?? null) ? strtolower(trim($profile['email'])) : '';
if ($profile === null || $email === '') {
    $fail('We couldn’t connect to Google right now. Please try again, or use your email and password.', $backTo);
}
if (($profile['email_verified'] ?? false) !== true || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
    $fail('That Google account’s email address isn’t verified, so it can’t be used to sign in.', $backTo);
}

$columns = 'SELECT id, user_code, first_name, last_name, email, pathway, password_hash, role FROM users';
$query = $pdo->prepare($columns . ' WHERE email = ?');
$query->execute([$email]);
$user = $query->fetch();

if ($user) {
    // Existing account (made with a password or with Google before): sign in to it.
    mentora_sign_in($user);
    mentora_redirect(mentora_after_sign_in_path($pdo, $_SESSION['user']));
}

$pathway = $saved['pathway'] ?? null;
if ($intent !== 'signup' || !in_array($pathway, ['employee_va', 'internship'], true)) {
    $fail('There’s no Mentora account for ' . $email . ' yet. Choose Employee VA or Intern, then continue with Google to create one.', 'auth/signup.php');
}

// New account from the Google profile. It gets a random password nobody knows;
// they sign in with Google, or set a password later with "Forgot your password?".
$firstName = trim((string) ($profile['given_name'] ?? ''));
$lastName = trim((string) ($profile['family_name'] ?? ''));
if ($firstName === '') {
    $firstName = trim((string) ($profile['name'] ?? '')) ?: strstr($email, '@', true);
}
$firstName = mb_substr($firstName, 0, 80);
$lastName = mb_substr($lastName, 0, 80);

try {
    $pdo->prepare('INSERT INTO users (first_name, last_name, email, pathway, password_hash) VALUES (?, ?, ?, ?, ?)')
        ->execute([$firstName, $lastName, $email, $pathway, password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT)]);
    $newId = (int) $pdo->lastInsertId();
} catch (PDOException $exception) {
    error_log('Mentora Google sign-up failed: ' . $exception->getMessage());
    $fail('We couldn’t create your account right now. Please try again.', 'auth/signup.php');
}

$query = $pdo->prepare($columns . ' WHERE id = ?');
$query->execute([$newId]);
mentora_sign_in($query->fetch());
$_SESSION['flash_notice'] = 'Your account is ready. Next, tell us about your qualifications so we can review your application.';
mentora_redirect(mentora_form_path($_SESSION['user']));
