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
        // Optional Google Apps Script relay (mentora_send_via_relay): set on free hosting, whose shared server
        // addresses Gmail distrusts, so the email is sent by Google itself instead.
        'relay_url' => $value('relay_url'),
        'relay_secret' => $value('relay_secret'),
    ];
    if ($config['from_email'] === '') {
        $config['from_email'] = $config['username'];   // Gmail sends as the signed-in account
    }

    return $config;
}

function mentora_mail_enabled(): bool
{
    $config = mentora_mail_config();

    if ($config['relay_url'] !== '' && $config['relay_secret'] !== '') {
        return true;
    }

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

    // Relay set up: Google sends it. If the relay can't (e.g. its daily quota is used up), fall back to SMTP below.
    if ($config['relay_url'] !== '' && $config['relay_secret'] !== '') {
        $relayed = mentora_send_via_relay($config, $to, $toName, $subject, $text, $html, $replyTo);
        mentora_mail_log($to, $subject, ($relayed['ok'] ? 'SENT via Google relay' : 'RELAY FAILED, trying SMTP: ' . $relayed['error']));
        if ($relayed['ok']) {
            return ['ok' => true, 'error' => ''];
        }
    }

    $fail = static function (string $message, string $detail = '') use (&$socket, $to, $subject): array {
        if (is_resource($socket)) {
            @fwrite($socket, "QUIT\r\n");
            @fclose($socket);
        }
        error_log('Mentora email: ' . $message . ($detail !== '' ? ' (' . $detail . ')' : ''));
        mentora_mail_log($to, $subject, 'FAILED: ' . $message . ($detail !== '' ? ' | ' . $detail : ''));
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
    mentora_mail_log($to, $subject, 'SENT: ' . $reply);   // Gmail's reply includes its message id ("250 2.0.0 OK ... gsmtp")

    return ['ok' => true, 'error' => ''];
}

/**
 * Hand one email to the Google Apps Script relay (deploy/mentora/mentora_mail_relay.gs, running under the Gmail
 * account). Google then sends it from its own servers. Returns ['ok' => bool, 'error' => string].
 */
function mentora_send_via_relay(array $config, string $to, string $toName, string $subject, string $text, ?string $html, string $replyTo): array
{
    $payload = json_encode([
        'secret' => $config['relay_secret'],
        'to' => $to,
        'toName' => $toName,
        'subject' => $subject,
        'text' => $text,
        'html' => $html ?? '',
        'name' => $config['from_name'],
        'replyTo' => $replyTo,
    ], JSON_UNESCAPED_UNICODE);

    // InfinityFree blocks DNS lookups of script.google.com (other Google names work). Google serves all its
    // sites from the same front-end servers, so connect to www.google.com's address while still asking for
    // the relay's own host name: TLS still checks the real certificate for script.google.com.
    $host = (string) parse_url($config['relay_url'], PHP_URL_HOST);
    if (function_exists('curl_init')) {
        $curl = curl_init($config['relay_url']);
        $options = [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_USERAGENT => 'Mentora-mailer',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,   // Apps Script answers with a redirect to the result (fetched with GET)
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 40,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];
        if ($host !== '' && gethostbyname($host) === $host && str_ends_with($host, '.google.com')) {
            $frontEnd = gethostbyname('www.google.com');
            if ($frontEnd !== 'www.google.com') {
                $options[CURLOPT_RESOLVE] = [$host . ':443:' . $frontEnd];
            }
        }
        curl_setopt_array($curl, $options);
        $body = curl_exec($curl);
        $error = $body === false ? curl_error($curl) : '';
        curl_close($curl);
        if ($body === false) {
            return ['ok' => false, 'error' => 'could not reach the relay (' . $error . ')'];
        }
        $answer = json_decode((string) $body, true);
        if (!is_array($answer)) {
            return ['ok' => false, 'error' => 'unexpected relay answer: ' . substr(trim(strip_tags((string) $body)), 0, 160)];
        }

        return !empty($answer['ok'])
            ? ['ok' => true, 'error' => '']
            : ['ok' => false, 'error' => (string) ($answer['error'] ?? 'relay refused the email')];
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nUser-Agent: Mentora-mailer\r\n",
            'content' => $payload,
            'timeout' => 30,
            'ignore_errors' => true,
            'follow_location' => 1,   // Apps Script answers with a redirect to the result
            'max_redirects' => 5,
        ],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $body = @file_get_contents($config['relay_url'], false, $context);
    if ($body === false) {
        return ['ok' => false, 'error' => 'could not reach the relay (' . (error_get_last()['message'] ?? 'network error') . ')'];
    }
    $answer = json_decode($body, true);
    if (!is_array($answer)) {
        return ['ok' => false, 'error' => 'unexpected relay answer: ' . substr(trim(strip_tags($body)), 0, 160)];
    }

    return !empty($answer['ok'])
        ? ['ok' => true, 'error' => '']
        : ['ok' => false, 'error' => (string) ($answer['error'] ?? 'relay refused the email')];
}

/**
 * One line per email in logs/mail.log: time, recipient, subject and the mail server's answer, so a
 * "never arrived" email can be traced (accepted by Gmail = delivered or filtered on the recipient's side).
 * logs/ is locked from the web (.htaccess). The file is trimmed to its newest half once it passes 256 KB.
 */
function mentora_mail_log(string $to, string $subject, string $result): void
{
    $dir = __DIR__ . '/../logs';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        return;
    }
    $file = $dir . '/mail.log';
    if (is_file($file) && filesize($file) > 262144) {
        $keep = substr((string) file_get_contents($file), -131072);
        @file_put_contents($file, substr($keep, (int) strpos($keep, "\n") + 1));
    }
    $clean = static fn(string $value): string => trim(preg_replace('/\s+/', ' ', $value) ?? '');
    $subject = preg_replace('/\b\d{6}\b/', '******', $subject) ?? $subject;   // never keep confirmation codes in the log
    @file_put_contents($file, date('Y-m-d H:i:s') . ' | to ' . $clean($to) . ' | ' . $clean($subject) . ' | ' . $clean($result) . "\n", FILE_APPEND | LOCK_EX);
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
