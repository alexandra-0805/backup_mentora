<?php

/**
 * Sending email through an SMTP account (e.g. Gmail with an App Password).
 * Settings: config/mail.local.php (see config/mail.local.example.php) or MENTORA_SMTP_* environment variables.
 *
 *   mentora_mail_enabled()                      is an SMTP account configured?
 *   mentora_send_mail($to, $name, $subject, $text, $html)   ['ok' => bool, 'error' => string]
 *   mentora_deliver_mail(...)                   SMTP when configured, otherwise PHP's mail() as before
 *
 * Small and dependency-free: STARTTLS (port 587) or SSL (port 465), AUTH LOGIN, one recipient,
 * plain-text + HTML (multipart/alternative, base64).
 */

declare(strict_types=1);

function mentora_mail_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $file = __DIR__ . '/../config/mail.local.php';
    $local = is_file($file) ? (array) require $file : [];
    $value = static fn(string $key, string $default = ''): string => trim((string) (getenv('MENTORA_SMTP_' . strtoupper($key)) ?: ($local[$key] ?? $default)));

    $config = [
        'host' => $value('host', 'smtp.gmail.com'),
        'port' => (int) ($value('port', '587') ?: 587),
        'encryption' => strtolower($value('encryption', 'tls')),   // tls (STARTTLS, 587), ssl (465) or none
        'username' => $value('username'),
        'password' => str_replace(' ', '', $value('password')),    // Gmail shows App Passwords in groups of 4
        'from_email' => $value('from_email'),
        'from_name' => $value('from_name', 'Mentora HR'),
    ];
    if ($config['from_email'] === '') {
        $config['from_email'] = $config['username'];   // Gmail sends as the signed-in account
    }

    return $config;
}

function mentora_mail_enabled(): bool
{
    $config = mentora_mail_config();

    return $config['host'] !== '' && $config['username'] !== '' && $config['password'] !== ''
        && filter_var($config['from_email'], FILTER_VALIDATE_EMAIL) !== false;
}

/** "Name" <email>, with the name encoded so any language works and nothing can break the header. */
function mentora_mail_address(string $email, string $name = ''): string
{
    $name = trim(preg_replace('/[\r\n]+/', ' ', $name) ?? '');

    return $name === '' ? '<' . $email . '>' : '=?UTF-8?B?' . base64_encode($name) . '?= <' . $email . '>';
}

/**
 * Send one email over SMTP. Returns ['ok' => true] or ['ok' => false, 'error' => message for an admin].
 * Details of failures are written to the PHP error log (never the password).
 */
function mentora_send_mail(string $to, string $toName, string $subject, string $text, ?string $html = null, string $replyTo = ''): array
{
    $config = mentora_mail_config();
    if (!mentora_mail_enabled()) {
        return ['ok' => false, 'error' => 'Automatic email isn’t set up yet.'];
    }
    if (!filter_var($to, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $to)) {
        return ['ok' => false, 'error' => 'The recipient’s email address isn’t valid.'];
    }

    $fail = static function (string $message, string $detail = '') use (&$socket): array {
        if (is_resource($socket)) {
            @fwrite($socket, "QUIT\r\n");
            @fclose($socket);
        }
        error_log('Mentora email: ' . $message . ($detail !== '' ? ' (' . $detail . ')' : ''));
        return ['ok' => false, 'error' => $message];
    };

    // Read one (possibly multi-line) SMTP reply: [code, text].
    $read = static function () use (&$socket): array {
        $text = '';
        while (is_resource($socket) && ($line = fgets($socket, 1024)) !== false) {
            $text .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') {
                break;
            }
        }
        return [(int) substr($text, 0, 3), trim($text)];
    };
    $command = static function (string $line, array $expect) use (&$socket, $read): array {
        fwrite($socket, $line . "\r\n");
        [$code, $reply] = $read();
        return [in_array($code, $expect, true), $code, $reply];
    };

    // Connect. A short network or DNS hiccup shouldn't fail the email, so retry once, then try the
    // server's other standard port (Gmail: 587 STARTTLS <-> 465 SSL; some networks block one of them).
    @set_time_limit(90);
    $routes = [[$config['encryption'], $config['port']], [$config['encryption'], $config['port']]];
    if ($config['encryption'] === 'tls' && $config['port'] === 587) {
        $routes[] = ['ssl', 465];
    } elseif ($config['encryption'] === 'ssl' && $config['port'] === 465) {
        $routes[] = ['tls', 587];
    }
    $context = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $config['host']]]);
    $socket = null;
    $errstr = '';
    $encryption = $config['encryption'];
    foreach ($routes as $attempt => [$routeEncryption, $routePort]) {
        if ($attempt > 0) {
            sleep(2);
        }
        $prefix = $routeEncryption === 'ssl' ? 'ssl://' : 'tcp://';
        $socket = @stream_socket_client($prefix . $config['host'] . ':' . $routePort, $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $context);
        if (is_resource($socket)) {
            $encryption = $routeEncryption;
            break;
        }
    }
    if (!is_resource($socket)) {
        $offline = stripos($errstr, 'getaddrinfo') !== false || stripos($errstr, 'No such host') !== false;
        return $fail(
            $offline
                ? 'This computer couldn’t reach the internet (the mail server ' . $config['host'] . ' wasn’t found). Check the Wi-Fi or internet connection, then try again.'
                : 'Couldn’t connect to the mail server (' . $config['host'] . '). Check the internet connection, or whether a firewall or antivirus blocks email ports 587 and 465.',
            $errstr
        );
    }
    stream_set_timeout($socket, 20);

    [$code, $reply] = $read();
    if ($code !== 220) {
        return $fail('The mail server didn’t accept the connection.', $reply);
    }
    $helloName = preg_replace('/[^A-Za-z0-9.-]/', '', (string) ($_SERVER['SERVER_NAME'] ?? 'localhost')) ?: 'localhost';
    [$ok, , $reply] = $command('EHLO ' . $helloName, [250]);
    if (!$ok) {
        return $fail('The mail server didn’t respond as expected.', $reply);
    }

    if ($encryption === 'tls') {
        [$ok, , $reply] = $command('STARTTLS', [220]);
        $secured = $ok && @stream_socket_enable_crypto(
            $socket,
            true,
            STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT') ? STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT : 0)
        );
        if (!$secured) {
            return $fail('Couldn’t open a secure connection to the mail server.', $reply);
        }
        [$ok, , $reply] = $command('EHLO ' . $helloName, [250]);
        if (!$ok) {
            return $fail('The mail server didn’t respond as expected.', $reply);
        }
    }

    [$ok, , $reply] = $command('AUTH LOGIN', [334]);
    if ($ok) {
        [$ok, , $reply] = $command(base64_encode($config['username']), [334]);
    }
    if ($ok) {
        [$ok, , $reply] = $command(base64_encode($config['password']), [235]);
    }
    if (!$ok) {
        return $fail('The mail server rejected the sign-in. For Gmail, use an App Password (not your normal password) in config/mail.local.php.', $reply);
    }

    [$ok, , $reply] = $command('MAIL FROM:<' . $config['from_email'] . '>', [250]);
    if ($ok) {
        [$ok, , $reply] = $command('RCPT TO:<' . $to . '>', [250, 251]);
    }
    if ($ok) {
        [$ok, , $reply] = $command('DATA', [354]);
    }
    if (!$ok) {
        return $fail('The mail server refused this message.', $reply);
    }

    // Build the message: plain text + optional HTML, each base64 (no line-length or encoding issues).
    $boundary = 'mentora-' . bin2hex(random_bytes(12));
    $domain = substr(strrchr($config['from_email'], '@') ?: '@localhost', 1);
    $headers = [
        'Date: ' . date('r'),
        'From: ' . mentora_mail_address($config['from_email'], $config['from_name']),
        'To: ' . mentora_mail_address($to, $toName),
        'Subject: =?UTF-8?B?' . base64_encode(preg_replace('/[\r\n]+/', ' ', $subject) ?? '') . '?=',
        'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . $domain . '>',
        'MIME-Version: 1.0',
    ];
    if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL) && !preg_match('/[\r\n]/', $replyTo)) {
        $headers[] = 'Reply-To: <' . $replyTo . '>';
    }
    $part = static fn(string $type, string $content): string => 'Content-Type: ' . $type . '; charset=UTF-8' . "\r\n"
        . 'Content-Transfer-Encoding: base64' . "\r\n\r\n" . rtrim(chunk_split(base64_encode($content), 76, "\r\n")) . "\r\n";

    if ($html === null) {
        $message = implode("\r\n", $headers) . "\r\n" . $part('text/plain', $text);
    } else {
        $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
        $message = implode("\r\n", $headers) . "\r\n\r\n"
            . '--' . $boundary . "\r\n" . $part('text/plain', $text)
            . '--' . $boundary . "\r\n" . $part('text/html', $html)
            . '--' . $boundary . "--\r\n";
    }
    // A line starting with "." would end the message early: double it (SMTP "dot-stuffing").
    $message = preg_replace('/^\./m', '..', str_replace(["\r\n", "\r"], "\n", $message)) ?? $message;
    fwrite($socket, str_replace("\n", "\r\n", $message) . "\r\n.\r\n");
    [$code, $reply] = $read();
    if ($code !== 250) {
        return $fail('The mail server didn’t accept the message.', $reply);
    }

    @fwrite($socket, "QUIT\r\n");
    @fclose($socket);

    return ['ok' => true, 'error' => ''];
}

/** Send through SMTP when it's configured; otherwise fall back to PHP's mail() (the old behaviour). */
function mentora_deliver_mail(string $to, string $subject, string $text, string $fallbackFrom = 'no-reply@mentora.ph'): bool
{
    if (mentora_mail_enabled()) {
        return mentora_send_mail($to, '', $subject, $text)['ok'];
    }
    // @: a mail-server warning must not be printed into the page; it is logged instead.
    $sent = @mail($to, $subject, $text, 'From: ' . $fallbackFrom);
    if (!$sent) {
        error_log('Mentora email could not be sent with mail(): ' . (error_get_last()['message'] ?? 'unknown error'));
    }

    return $sent;
}
