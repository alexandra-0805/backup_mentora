<?php

/**
 * Application approval and account confirmation.
 *
 *   1. Someone applies (auth/signup.php): a "pending" account is created, with no usable password.
 *   2. An HR admin approves it (admin/applications.php): status "approved" and a one-time
 *      confirmation link, which the admin sends from their own Gmail (or copies).
 *   3. The applicant opens the link (auth/activate.php), chooses a username and password:
 *      status "active", signed in, and sent to the applicant workspace.
 * Only "active" accounts can sign in, reset a password or use Continue with Google.
 */

declare(strict_types=1);

require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/application_fields.php';
require_once __DIR__ . '/mailer.php';

const MENTORA_INVITE_DAYS = 7;

/**
 * Absolute URL of a page, e.g. mentora_app_url('auth/activate.php?token=...').
 * Set MENTORA_APP_URL (e.g. https://mentora.example.com) to override the detected address.
 */
function mentora_app_url(string $pathFromRoot): string
{
    $base = rtrim((string) (getenv('MENTORA_APP_URL') ?: ''), '/');
    if ($base === '') {
        $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $directory = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php')));
        for ($levels = substr_count(mentora_root_prefix(), '../'); $levels > 0; $levels--) {
            $directory = dirname($directory);
        }
        $base = ($https ? 'https' : 'http') . '://' . $host . rtrim($directory, '/');
    }

    return $base . '/' . ltrim($pathFromRoot, '/');
}

/**
 * Create the pending account for a new application, or reopen a declined one.
 * Returns ['ok' => true, 'id' => int] or ['ok' => false, 'error' => message].
 */
function mentora_create_pending_applicant(PDO $pdo, string $firstName, string $lastName, string $email, string $pathway): array
{
    $existing = $pdo->prepare('SELECT id, status FROM users WHERE email = ?');
    $existing->execute([$email]);
    $row = $existing->fetch();

    if ($row) {
        return match ($row['status']) {
            'active' => ['ok' => false, 'error' => 'An account with this email already exists. Please sign in instead.'],
            'pending' => ['ok' => false, 'error' => 'An application with this email is already waiting for confirmation.'],
            'approved' => ['ok' => false, 'error' => 'This application was approved. Check your email for the link to create your username and password.'],
            default => (function () use ($pdo, $row, $firstName, $lastName, $pathway): array {
                // Declined before: a new application starts over.
                $pdo->prepare("UPDATE users SET first_name = ?, last_name = ?, pathway = ?, status = 'pending', created_at = NOW() WHERE id = ?")
                    ->execute([$firstName, $lastName, $pathway, $row['id']]);
                return ['ok' => true, 'id' => (int) $row['id']];
            })(),
        };
    }

    // Nobody knows this password: the applicant sets their own when they confirm the account.
    $pdo->prepare("INSERT INTO users (first_name, last_name, email, pathway, password_hash, role, status) VALUES (?, ?, ?, ?, ?, 'applicant', 'pending')")
        ->execute([$firstName, $lastName, $email, $pathway, password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT)]);

    return ['ok' => true, 'id' => (int) $pdo->lastInsertId()];
}

/** Tell every HR admin (workspace notification, plus email when MENTORA_APPROVAL_EMAIL is set). */
function mentora_notify_admins_new_applicant(PDO $pdo, string $name, string $email, string $pathway): void
{
    $label = $pathway === 'internship' ? 'Intern' : 'Employee VA';
    $admins = $pdo->query("SELECT id FROM users WHERE role = 'admin' AND status = 'active'")->fetchAll(PDO::FETCH_COLUMN);
    $notify = $pdo->prepare('INSERT INTO notifications (user_id, title, body, link) VALUES (?, ?, ?, ?)');
    foreach ($admins as $adminId) {
        $notify->execute([
            (int) $adminId,
            'New application: ' . $name,
            $label . ' · ' . $email . ' is waiting for confirmation in Applications.',
            'admin/applications.php?view=pending'
        ]);
    }
    // Optional email alert to MENTORA_APPROVAL_EMAIL (sent through the mail account when one is set up).
    $alertTo = getenv('MENTORA_APPROVAL_EMAIL') ?: '';
    if (filter_var($alertTo, FILTER_VALIDATE_EMAIL)) {
        mentora_deliver_mail($alertTo, 'Mentora application pending approval: ' . $name, implode("\n", [
            'A new Mentora application is waiting for confirmation.', '', 'Applicant: ' . $name, 'Email: ' . $email,
            'Pathway: ' . $label, '', 'Review it in the Mentora admin workspace: ' . mentora_app_url('admin/applications.php'),
        ]));
    }
}

/**
 * Approve (or re-send for) an applicant: a new one-time link replaces any earlier unused one.
 * Returns the full confirmation link (only ever shown to the admin who made it).
 */
function mentora_create_invite(PDO $pdo, int $userId, ?int $createdBy): string
{
    $token = bin2hex(random_bytes(32));
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM account_invites WHERE user_id = ? AND used_at IS NULL')->execute([$userId]);
        $pdo->prepare('INSERT INTO account_invites (user_id, token_hash, created_by, expires_at)
            VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ' . MENTORA_INVITE_DAYS . ' DAY))')
            ->execute([$userId, hash('sha256', $token), $createdBy]);
        $pdo->prepare("UPDATE users SET status = 'approved' WHERE id = ? AND status IN ('pending', 'approved')")->execute([$userId]);
        $pdo->commit();
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }

    return mentora_app_url('auth/activate.php?token=' . $token);
}

/**
 * The confirmation email: [subject, plain text, HTML]. Also used to pre-fill "Open in Gmail".
 */
function mentora_invite_email(string $firstName, string $pathway, string $link, string $adminName, string $role = 'applicant'): array
{
    $staff = in_array($role, ['agent', 'admin'], true);
    $pathwayLabel = $staff ? ($role === 'admin' ? 'HR administrator' : 'Agent') : ($pathway === 'internship' ? 'Intern' : 'Employee VA');
    $days = MENTORA_INVITE_DAYS;
    $subject = $staff ? 'You’re invited to Mentora: create your account' : 'Your Mentora application is approved: create your account';
    $intro = $staff ? "You've been invited to join the Mentora workspace as {$pathwayLabel}." : "Good news! Your Mentora {$pathwayLabel} application has been approved.";
    $text = "Hi {$firstName},\n\n"
        . "{$intro}\n\n"
        . "Create your username and password here:\n{$link}\n\n"
        . "This link works once and expires in {$days} days.\n\n"
        . "Welcome to Mentora,\n{$adminName}\nMentora HR";

    $e = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $html = '<!doctype html><html><body style="margin:0;padding:0;background:#f4f8f7;font-family:Arial,Helvetica,sans-serif;color:#1d1d1f;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f8f7;padding:28px 12px;"><tr><td align="center">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px;background:#ffffff;border-radius:18px;overflow:hidden;border:1px solid #d5e4e2;">'
        . '<tr><td style="background:#0b625d;background-image:linear-gradient(135deg,#063d3b,#0b625d 55%,#147b71);padding:22px 28px;color:#ffffff;font-size:20px;font-weight:bold;">Mentora</td></tr>'
        . '<tr><td style="padding:28px;">'
        . '<p style="margin:0 0 6px;font-size:12px;letter-spacing:.08em;text-transform:uppercase;color:#0b625d;font-weight:bold;">' . ($staff ? 'You’re invited' : 'Application approved') . '</p>'
        . '<h1 style="margin:0 0 14px;font-family:Georgia,serif;font-size:26px;font-weight:normal;">Hi ' . $e($firstName) . ', welcome to Mentora!</h1>'
        . '<p style="margin:0 0 22px;font-size:15px;line-height:1.6;color:#3a3a3c;">' . ($staff ? 'You’ve been invited to join Mentora as <strong>' . $e($pathwayLabel) . '</strong>. Create your username and password to open your workspace.' : 'Your <strong>' . $e($pathwayLabel) . '</strong> application has been approved. Create your username and password to open your applicant workspace.') . '</p>'
        . '<p style="margin:0 0 22px;"><a href="' . $e($link) . '" style="display:inline-block;background:#0b625d;color:#ffffff;text-decoration:none;font-weight:bold;font-size:15px;padding:13px 26px;border-radius:999px;">Create my account</a></p>'
        . '<p style="margin:0 0 6px;font-size:12px;color:#6e6e73;">Button not working? Copy this link into your browser:</p>'
        . '<p style="margin:0 0 22px;font-size:12px;word-break:break-all;"><a href="' . $e($link) . '" style="color:#0b625d;">' . $e($link) . '</a></p>'
        . '<p style="margin:0;font-size:12px;color:#6e6e73;">This link works once and expires in ' . $days . ' days.</p>'
        . '</td></tr>'
        . '<tr><td style="padding:18px 28px;border-top:1px solid #e5eeec;font-size:12px;color:#6e6e73;">' . $e($adminName) . ' · Mentora HR</td></tr>'
        . '</table></td></tr></table></body></html>';

    return [$subject, $text, $html];
}

/** Email the confirmation link. Returns ['ok' => bool, 'error' => string] (see includes/mailer.php). */
function mentora_send_invite_email(
    string $email,
    string $fullName,
    string $firstName,
    string $pathway,
    string $link,
    string $adminName,
    string $adminEmail = '',
    string $role = 'applicant'
): array
{
    [$subject, $text, $html] = mentora_invite_email($firstName, $pathway, $link, $adminName, $role);

    return mentora_send_mail($email, $fullName, $subject, $text, $html, $adminEmail);
}

/** The applicant a confirmation link belongs to, or null if it is unknown, used or expired. */
function mentora_find_invite(PDO $pdo, string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }
    $query = $pdo->prepare(
        "SELECT i.id AS invite_id, u.id, u.first_name, u.last_name, u.email, u.pathway
         FROM account_invites i
         JOIN users u ON u.id = i.user_id
         WHERE i.token_hash = ? AND i.used_at IS NULL AND i.expires_at > NOW() AND u.status = 'approved'"
    );
    $query->execute([hash('sha256', $token)]);

    return $query->fetch() ?: null;
}

/** Usernames: 3-30 characters, start with a letter, then letters, numbers, dots, dashes or underscores. */
function mentora_username_error(PDO $pdo, string $username, int $userId): ?string
{
    if ($username === '') {
        return 'Please choose a username.';
    }
    if (!preg_match('/^[a-z][a-z0-9._-]{2,29}$/', $username)) {
        return 'Usernames are 3–30 characters: start with a letter, then use letters, numbers, dots (.), dashes (-) or underscores (_).';
    }
    $taken = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username = ? AND id <> ?');
    $taken->execute([$username, $userId]);

    return (int) $taken->fetchColumn() > 0 ? 'That username is already taken. Please choose another.' : null;
}

/** What to tell someone whose account is not active yet when they try to sign in. */
function mentora_account_status_message(string $status): string
{
    return match ($status) {
        'pending' => 'Your application is still waiting for confirmation by HR. We’ll email you a link to create your username and password once it’s approved.',
        'approved' => 'Your application was approved! Open the confirmation link in your email to create your username and password.',
        'rejected' => 'Your application wasn’t approved. Please contact hello@mentora.ph if you have questions.',
        'disabled' => 'This account has been turned off by HR. Please contact hello@mentora.ph if you think this is a mistake.',
        default => 'This account isn’t available.',
    };
}
