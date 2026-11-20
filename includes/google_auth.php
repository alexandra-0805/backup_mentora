<?php
/**
 * "Continue with Google" (OAuth 2.0 / OpenID Connect) helpers.
 *
 * Flow: sign-up or sign-in page -> auth/google.php (sends you to Google)
 *       -> Google account chooser -> auth/google_callback.php (signs you in or creates the account).
 *
 * Settings come from environment variables or config/google.local.php
 * (copy config/google.local.example.php and fill in your Google Cloud OAuth client).
 */

declare(strict_types=1);

/**
 * ['client_id' => ..., 'client_secret' => ..., 'redirect_uri' => ...]; empty strings when not set.
 */
function mentora_google_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $localFile = __DIR__ . '/../config/google.local.php';
    $local = is_file($localFile) ? (array) require $localFile : [];
    $config = [];
    foreach (['client_id', 'client_secret', 'redirect_uri'] as $key) {
        $config[$key] = trim((string) (getenv('MENTORA_GOOGLE_' . strtoupper($key)) ?: ($local[$key] ?? '')));
    }

    return $config;
}

function mentora_google_enabled(): bool
{
    $config = mentora_google_config();

    return $config['client_id'] !== '' && $config['client_secret'] !== '';
}

/**
 * The callback URL Google sends people back to. It must match an "Authorized redirect URI"
 * in Google Cloud exactly, e.g. http://localhost/backup_mentora/auth/google_callback.php
 */
function mentora_google_redirect_uri(): string
{
    $configured = mentora_google_config()['redirect_uri'];
    if ($configured !== '') {
        return $configured;
    }

    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $authDir = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/auth/google.php'))), '/');

    return ($https ? 'https' : 'http') . '://' . $host . $authDir . '/google_callback.php';
}

/**
 * Call a Google endpoint and decode its JSON reply. POST when $form is given; Bearer auth when $accessToken is.
 * Returns null (and logs why) on any failure.
 */
function mentora_google_request(string $url, ?array $form = null, ?string $accessToken = null): ?array
{
    if (!function_exists('curl_init')) {
        error_log('Mentora Google sign-in: the PHP curl extension is not enabled.');
        return null;
    }

    $headers = ['Accept: application/json'];
    if ($accessToken !== null) {
        $headers[] = 'Authorization: Bearer ' . $accessToken;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    if ($form !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form));
    }

    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($body === false || $status >= 400) {
        error_log(sprintf('Mentora Google sign-in: %s failed (HTTP %d) %s %s', $url, $status, $curlError, is_string($body) ? $body : ''));
        return null;
    }

    $data = json_decode((string) $body, true);

    return is_array($data) ? $data : null;
}

/**
 * The "Continue with Google" button. It sits inside the page's main form and submits that form
 * to auth/google.php instead (formaction), so the CSRF token and the chosen pathway go with it.
 */
function mentora_google_button(string $intent): void
{
    ?>
    <button class="google-button" type="submit" formaction="google.php" formnovalidate name="intent" value="<?= $intent === 'signup' ? 'signup' : 'signin' ?>">
        <svg viewBox="0 0 48 48" aria-hidden="true" focusable="false">
            <path fill="#EA4335"
                d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/>
            <path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/>
            <path fill="#FBBC05"
                d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/>
            <path fill="#34A853"
                d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/>
        </svg>
        Continue with Google
    </button>
    <?php
}
