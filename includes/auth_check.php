<?php

/**
 * Shared session + authentication helpers.
 *
 * Usage from any page (before any output):
 *   require_once __DIR__ . '/../includes/auth_check.php';
 *   $authUser = mentora_require_login(['admin']);
 */

declare(strict_types=1);

const MENTORA_APP_ROOT = __DIR__ . '/..';

/**
 * Start the session once, with hardened cookie settings.
 */
function mentora_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

/**
 * Relative prefix ("", "../", "../../") from the running script back to the app root,
 * so redirects work no matter how deep the calling page is.
 */
function mentora_root_prefix(): string
{
    $root = realpath(MENTORA_APP_ROOT);
    $scriptDir = realpath(dirname((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')));

    if ($root === false || $scriptDir === false || stripos($scriptDir, $root) !== 0) {
        return '';
    }

    $relative = trim(str_replace('\\', '/', substr($scriptDir, strlen($root))), '/');

    return $relative === '' ? '' : str_repeat('../', substr_count($relative, '/') + 1);
}

/**
 * Workspace landing page (relative to the app root) for a signed-in user.
 */
function mentora_home_path(array $user): string
{
    switch ($user['role'] ?? '') {
        case 'admin':
            return 'admin/dashboard.php';
        case 'agent':
            return 'agent/dashboard.php';
        default:
            return ($user['pathway'] ?? '') === 'internship'
                ? 'applicant/intern/overview.php'
                : 'applicant/employee/overview.php';
    }
}

/**
 * The application form (step 2 of sign-up) for an applicant's pathway, relative to the app root.
 */
function mentora_form_path(array $user): string
{
    return ($user['pathway'] ?? '') === 'internship'
        ? 'applicant/intern/form.php'
        : 'applicant/employee/form.php';
}

/**
 * Where to send a user after signing in: applicants who have not submitted their
 * application form yet go to the form first; everyone else goes to their workspace.
 */
function mentora_after_sign_in_path(PDO $pdo, array $user): string
{
    if (($user['role'] ?? '') === 'applicant') {
        $query = $pdo->prepare('SELECT 1 FROM applications WHERE user_id = ? LIMIT 1');
        $query->execute([(int) $user['id']]);
        if (!$query->fetchColumn()) {
            return mentora_form_path($user);
        }
    }

    return mentora_home_path($user);
}

function mentora_redirect(string $pathFromRoot): never
{
    header('Location: ' . mentora_root_prefix() . $pathFromRoot);
    exit;
}

/**
 * Return the signed-in user, re-validated against the database so that deleted
 * accounts and role/pathway changes take effect immediately. Returns null if
 * nobody is signed in.
 */
function mentora_current_user(): ?array
{
    global $pdo;

    mentora_session_start();

    $sessionUser = $_SESSION['user'] ?? null;
    if (!is_array($sessionUser) || empty($sessionUser['id'])) {
        return null;
    }

    if (!isset($pdo)) {
        require MENTORA_APP_ROOT . '/config/db.php';
    }

    $query = $pdo->prepare('SELECT id, user_code, username, first_name, last_name, email, pathway, password_hash, role, status, avatar_path FROM users WHERE id = ?');
    $query->execute([(int) $sessionUser['id']]);
    $row = $query->fetch();

    // Account deleted, not active (e.g. still waiting for approval), or password changed since this
    // session signed in (e.g. via reset): sign it out.
    $fingerprint = $_SESSION['auth_fingerprint'] ?? '';
    if (!$row || ($row['status'] ?? 'active') !== 'active'
        || !is_string($fingerprint) || !hash_equals(mentora_password_fingerprint($row['password_hash']), $fingerprint)) {
        unset($_SESSION['user'], $_SESSION['auth_fingerprint']);
        return null;
    }

    $_SESSION['user'] = mentora_session_user($row);

    return $_SESSION['user'];
}

/**
 * Start an authenticated session for a user row (must include password_hash).
 */
function mentora_sign_in(array $row): void
{
    mentora_session_start();
    session_regenerate_id(true);
    $_SESSION['user'] = mentora_session_user($row);
    $_SESSION['auth_fingerprint'] = mentora_password_fingerprint($row['password_hash']);
}

/**
 * Ties a session to the password it was created with, so changing the password ends other sessions.
 */
function mentora_password_fingerprint(string $passwordHash): string
{
    return hash('sha256', $passwordHash);
}

/**
 * Read a POST field as a string; arrays and other non-string values become ''.
 */
function mentora_post_string(string $key): string
{
    $value = $_POST[$key] ?? '';

    return is_string($value) ? $value : '';
}

/**
 * Shape of $_SESSION['user'] used throughout the app.
 */
function mentora_session_user(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'user_code' => $row['user_code'] ?? null,
        'username' => $row['username'] ?? null,
        'name' => trim($row['first_name'] . ' ' . $row['last_name']),
        'email' => $row['email'],
        'role' => $row['role'],
        'pathway' => $row['pathway'],
        'avatar_path' => $row['avatar_path'] ?? null,
    ];
}

/**
 * Public URL of a stored profile photo, or null when there is none (initials are shown instead).
 * $prefix is the relative path from the current page back to the app root ("../", "../../").
 */
function mentora_avatar_url(mixed $path, string $prefix): ?string
{
    if (!is_string($path) || !preg_match('#^uploads/avatars/u\d+-[a-f0-9]{16}\.(jpg|png|webp)$#', $path)) {
        return null;
    }
    $file = MENTORA_APP_ROOT . '/' . $path;
    if (!is_file($file)) {
        return null;
    }

    return $prefix . $path . '?v=' . filemtime($file);
}

/**
 * Avatar markup: <span class="avatar"><img class="avatar-img" src alt></span>, or the initials.
 */
function mentora_avatar(?string $url, string $initials, string $alt = '', string $class = ''): string
{
    $h = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $classes = trim('avatar ' . $class);

    if ($url !== null) {
        return '<span class="' . $h($classes) . '"><img class="avatar-img" src="' . $h($url) . '" alt="' . $h($alt) . '"></span>';
    }

    return '<span class="' . $h($classes . ' avatar-initials') . '"' . ($alt === '' ? ' aria-hidden="true"' : ' role="img" aria-label="' . $h($alt) . '"') . '>'
        . $h($initials) . '</span>';
}

/**
 * Require a signed-in user, optionally restricted to the given roles.
 * Guests are sent to sign in; signed-in users without access are sent to their own workspace.
 */
function mentora_require_login(array $roles = []): array
{
    $user = mentora_current_user();

    if ($user === null) {
        mentora_redirect('auth/signin.php');
    }

    if ($roles && !in_array($user['role'], $roles, true)) {
        mentora_redirect(mentora_home_path($user));
    }

    return $user;
}

/**
 * Per-session CSRF token for forms.
 */
function mentora_csrf_token(): string
{
    mentora_session_start();

    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

/**
 * Hidden input carrying the CSRF token, for use inside a <form>.
 */
function mentora_csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(mentora_csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

function mentora_csrf_valid(mixed $token): bool
{
    return is_string($token) && $token !== '' && hash_equals(mentora_csrf_token(), $token);
}

/**
 * Hash stored in password_resets.token_hash. The column is UNIQUE, so the user id is
 * mixed in; a bare hash of a 6-digit code would collide once any code was reused.
 */
function mentora_reset_code_hash(int $userId, string $code): string
{
    return hash('sha256', $userId . ':' . $code);
}

/**
 * Password policy shared by sign-up and password reset.
 */
/**
 * Password rules for new passwords (sign-up and reset). The live checklist in
 * assets/js/forms.js uses the same rules, so keep the two in step.
 * Returns [rule label => passed?].
 */
function mentora_password_checks(string $password): array
{
    $length = mb_strlen($password);

    return [
        '8 to 64 characters' => $length >= 8 && $length <= 64,
        'an uppercase letter (A–Z)' => (bool) preg_match('/[A-Z]/', $password),
        'a lowercase letter (a–z)' => (bool) preg_match('/[a-z]/', $password),
        'a number (0–9)' => (bool) preg_match('/\d/', $password),
        'a symbol (e.g. ! @ # $ %)' => (bool) preg_match('/[^A-Za-z0-9\s]/', $password),
        'no spaces' => $password !== '' && !preg_match('/\s/', $password),
    ];
}

function mentora_password_error(string $password): ?string
{
    $missing = array_keys(array_filter(mentora_password_checks($password), static fn(bool $ok): bool => !$ok));

    return $missing ? 'Your password needs ' . implode(', ', $missing) . '.' : null;
}

/**
 * Validation for a person's first or last name. Letters (any language), spaces,
 * hyphens, apostrophes and periods only, starting with a letter.
 */
function mentora_name_error(string $name, string $label): ?string
{
    if ($name === '') {
        return "Please enter your {$label}.";
    }
    if (mb_strlen($name) < 2 || mb_strlen($name) > 80) {
        return ucfirst($label) . ' must be 2 to 80 characters.';
    }
    if (!preg_match("/^\\p{L}[\\p{L}\\p{M} .'’-]*$/u", $name)) {
        return ucfirst($label) . ' can only contain letters, spaces, hyphens (-), apostrophes (\') and periods.';
    }

    return null;
}

/**
 * Validation for an email address: valid format and a real-looking domain (name.tld).
 */
function mentora_email_error(string $email): ?string
{
    if ($email === '') {
        return 'Please enter your email address.';
    }
    if (mb_strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)
        || !preg_match('/@[A-Za-z0-9-]+(\.[A-Za-z0-9-]+)*\.[A-Za-z]{2,}$/', $email)) {
        return 'Please enter a valid email address, e.g. name@gmail.com.';
    }

    return null;
}
