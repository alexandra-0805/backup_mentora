<?php

/**
 * Application approval and account confirmation.
 *
 *   1. Someone applies (auth/signup.php): a "pending" account is created, with no usable password,
 *      and a "confirm your email" link is emailed (auth/verify_email.php). Once it's clicked, HR is told.
 *   2. An HR admin approves it (admin/applications.php): status "approved" and a one-time
 *      confirmation link, which the admin sends from their own Gmail (or copies).
 *   3. The applicant opens the link (auth/activate.php), chooses a username and password:
 *      status "active", signed in, and sent to the applicant workspace.
 * Only "active" accounts can sign in, reset a password or use Continue with Google.
 */

declare(strict_types=1);

require_once __DIR__ . '/workflow.php';
require_once __DIR__ . '/application_fields.php';
require_once __DIR__ . '/mailer.php';

const MENTORA_INVITE_DAYS = 7;
const MENTORA_VERIFY_HOURS = 48;   // "confirm your email" links

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
            // 'track': the sign-up page offers auth/track.php to follow the application.
            // 'id': the sign-up page can email this applicant their confirmation link again.
            'pending' => ['ok' => false, 'track' => true, 'id' => (int) $row['id'], 'error' => 'An application with this email is already waiting for confirmation.'],
            'approved' => ['ok' => false, 'track' => true, 'id' => (int) $row['id'], 'error' => 'This application was approved. Check your email for the link to create your username and password.'],
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

/**
 * Tell every HR admin: a workspace notification, plus an email to MENTORA_APPROVAL_EMAIL or, when that isn't
 * set, to the HR mailbox that sends Mentora's emails (config/mail.local.php), so they can approve it quickly.
 */
function mentora_notify_admins_new_applicant(PDO $pdo, string $name, string $email, string $pathway): void
{
    $label = mentora_pathway_label($pathway);
    mentora_notify_roles($pdo, ['admin'], 'New application: ' . $name, $label . ' · ' . $email . ' is waiting for confirmation in Applications.', 'admin/applications.php?view=pending');
    $alertTo = getenv('MENTORA_APPROVAL_EMAIL') ?: (mentora_mail_enabled() ? mentora_mail_config()['from_email'] : '');
    if (filter_var($alertTo, FILTER_VALIDATE_EMAIL)) {
        mentora_deliver_mail($alertTo, 'Mentora application pending approval: ' . $name, implode("\n", [
            'A new Mentora application is waiting for confirmation.', '', 'Applicant: ' . $name, 'Email: ' . $email,
            'Pathway: ' . $label, '', 'Approve it so they get their confirmation link: ' . mentora_app_url('admin/applications.php?view=pending'),
        ]));
    }
}

/**
 * Right after someone applies (auth/signup.php, auth/google_callback.php).
 *   - Email already confirmed (Google sign-up, or a reopened application): straight to HR.
 *   - Otherwise: email a "confirm your email" link (auth/verify_email.php). HR is told once it's clicked.
 *   - Email not set up / not sent: nothing to confirm with, so straight to HR as before.
 * Returns ['verify_sent' => bool, 'emailed' => bool (any email reached them)].
 */
function mentora_process_new_application(PDO $pdo, int $userId, string $firstName, string $lastName, string $email, string $pathway, bool $emailAlreadyVerified = false): array
{
    if ($emailAlreadyVerified) {
        mentora_mark_email_verified($pdo, $userId);
    } elseif (!mentora_email_is_verified($pdo, $userId) && mentora_mail_enabled() && mentora_send_verification_email($pdo, $userId)['ok']) {
        return ['verify_sent' => true, 'emailed' => true];
    } else {
        mentora_mark_email_verified($pdo, $userId);
    }

    mentora_notify_admins_new_applicant($pdo, trim($firstName . ' ' . $lastName), $email, $pathway);
    return ['verify_sent' => false, 'emailed' => mentora_send_application_received_email($email, $firstName, $lastName, $pathway)];
}

function mentora_email_is_verified(PDO $pdo, int $userId): bool
{
    $query = $pdo->prepare('SELECT email_verified_at IS NOT NULL FROM users WHERE id = ?');
    $query->execute([$userId]);

    return (bool) $query->fetchColumn();
}

function mentora_mark_email_verified(PDO $pdo, int $userId): void
{
    $pdo->prepare('UPDATE users SET email_verified_at = COALESCE(email_verified_at, NOW()) WHERE id = ?')->execute([$userId]);
}

/**
 * Email a pending applicant a one-time "confirm your email" link (a new one replaces any earlier one).
 * At most one email a minute per applicant. Returns ['ok' => bool, 'error' => string, 'too_soon' => bool].
 */
function mentora_send_verification_email(PDO $pdo, int $userId): array
{
    $query = $pdo->prepare("SELECT id, first_name, last_name, email, pathway FROM users
        WHERE id = ? AND role = 'applicant' AND status = 'pending' AND email_verified_at IS NULL");
    $query->execute([$userId]);
    $user = $query->fetch();
    if (!$user) {
        return ['ok' => false, 'error' => 'This email is already confirmed.', 'too_soon' => false];
    }
    if (!mentora_mail_enabled()) {
        return ['ok' => false, 'error' => 'Automatic email isn’t set up yet.', 'too_soon' => false];
    }

    $recent = $pdo->prepare('SELECT COUNT(*) FROM email_verifications WHERE user_id = ? AND created_at > NOW() - INTERVAL 60 SECOND');
    $recent->execute([$userId]);
    if ((int) $recent->fetchColumn() > 0) {
        return ['ok' => false, 'error' => 'We just sent you an email. Please wait a minute before asking for another one.', 'too_soon' => true];
    }

    $token = bin2hex(random_bytes(32));
    $pdo->prepare('DELETE FROM email_verifications WHERE user_id = ? AND used_at IS NULL')->execute([$userId]);
    $pdo->prepare('INSERT INTO email_verifications (user_id, token_hash, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ' . MENTORA_VERIFY_HOURS . ' HOUR))')
        ->execute([$userId, hash('sha256', $token)]);
    $link = mentora_app_url('auth/verify_email.php?token=' . $token);

    [$subject, $text, $html] = mentora_verification_email($user['first_name'], $user['pathway'], $link);
    $result = mentora_send_mail($user['email'], trim($user['first_name'] . ' ' . $user['last_name']), $subject, $text, $html);
    if (!$result['ok']) {
        error_log('Mentora email confirmation to ' . $user['email'] . ' failed: ' . $result['error']);
        $pdo->prepare('DELETE FROM email_verifications WHERE user_id = ? AND used_at IS NULL')->execute([$userId]);
        return ['ok' => false, 'error' => 'We couldn’t send the email right now. Please try again in a few minutes.', 'too_soon' => false];
    }

    return ['ok' => true, 'error' => '', 'too_soon' => false];
}

/** The "confirm your email" message sent right after applying: [subject, plain text, HTML]. */
function mentora_verification_email(string $firstName, string $pathway, string $link): array
{
    $label = mentora_pathway_label($pathway);
    $hours = MENTORA_VERIFY_HOURS;
    $subject = 'Confirm your email for your Mentora application';
    $text = "Hi {$firstName},\n\n"
        . "Thanks for applying to Mentora as {$label}! Please confirm this is your email address:\n{$link}\n\n"
        . "Once it's confirmed, your application goes to the HR team. When they approve it, we'll email you a link to create your username and password.\n\n"
        . "This link expires in {$hours} hours. If you didn't apply to Mentora, you can ignore this email.\n\nMentora HR";

    $e = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $html = mentora_email_html(
        'Confirm your email',
        'Hi ' . $e($firstName) . ', please confirm your email',
        '<p style="margin:0 0 14px;font-size:15px;line-height:1.6;color:#3a3a3c;">Thanks for applying to Mentora as <strong>' . $e($label)
            . '</strong>! Click the button below to confirm this is your email address.</p>'
            . '<p style="margin:0 0 22px;font-size:14px;line-height:1.6;color:#3a3a3c;">Once it’s confirmed, your application goes to the HR team. '
            . 'When they approve it, we’ll email you a link to create your username and password.</p>',
        'Confirm my email',
        $link,
        'This link expires in ' . $hours . ' hours. If you didn’t apply to Mentora, you can ignore this email.',
        'Mentora HR'
    );

    return [$subject, $text, $html];
}

/**
 * Open a "confirm your email" link: marks the email confirmed and sends the application to HR.
 * Returns the applicant (id, first_name, email, pathway) or null when the link is unknown, used or expired.
 */
function mentora_use_verification_link(PDO $pdo, string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }
    $query = $pdo->prepare(
        "SELECT v.id AS verification_id, u.id, u.first_name, u.last_name, u.email, u.pathway
         FROM email_verifications v JOIN users u ON u.id = v.user_id
         WHERE v.token_hash = ? AND v.used_at IS NULL AND v.expires_at > NOW() AND u.status = 'pending'"
    );
    $query->execute([hash('sha256', $token)]);
    $user = $query->fetch();
    if (!$user) {
        return null;
    }

    // Only the request that actually marks the link used tells HR (opening the link twice at once is harmless).
    $use = $pdo->prepare('UPDATE email_verifications SET used_at = NOW() WHERE id = ? AND used_at IS NULL');
    $use->execute([$user['verification_id']]);
    if ($use->rowCount() === 1) {
        mentora_mark_email_verified($pdo, (int) $user['id']);
        mentora_notify_admins_new_applicant($pdo, trim($user['first_name'] . ' ' . $user['last_name']), $user['email'], $user['pathway']);
    }

    return $user;
}

/**
 * Email the applicant right after they apply: their application was received, what happens next, and
 * where to track it. The confirmation link itself comes later, when HR approves (mentora_send_invite_email).
 * Returns true when the email was sent.
 */
function mentora_send_application_received_email(string $email, string $firstName, string $lastName, string $pathway): bool
{
    if (!mentora_mail_enabled()) {
        return false;
    }
    $label = mentora_pathway_label($pathway);
    $trackUrl = mentora_app_url('auth/track.php');
    $subject = 'We received your Mentora application';
    $text = "Hi {$firstName},\n\n"
        . "Thank you for applying to Mentora as {$label}. Your application is now waiting for the HR team to confirm it.\n\n"
        . "What happens next:\n"
        . "1. An HR admin reviews your application (usually within 1-3 working days).\n"
        . "2. Once it's approved, we email you a link to create your username and password.\n\n"
        . "Track your application any time: {$trackUrl}\n\n"
        . "Please also check your Spam or Promotions folder for our emails.\n\nMentora HR";
    $e = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $html = mentora_email_html(
        'Application received',
        'Hi ' . $e($firstName) . ', thanks for applying!',
        '<p style="margin:0 0 14px;font-size:15px;line-height:1.6;color:#3a3a3c;">Your <strong>' . $e($label) . '</strong> application is now waiting for the Mentora HR team to confirm it.</p>'
            . '<ol style="margin:0 0 22px;padding-left:20px;font-size:14px;line-height:1.7;color:#3a3a3c;">'
            . '<li>An HR admin reviews your application (usually within 1–3 working days).</li>'
            . '<li>Once it’s approved, we email you a link to create your username and password.</li></ol>',
        'Track my application',
        $trackUrl,
        'Can’t find our emails? Check your Spam or Promotions folder.',
        'Mentora HR'
    );
    $result = mentora_send_mail($email, trim($firstName . ' ' . $lastName), $subject, $text, $html);
    if (!$result['ok']) {
        error_log('Mentora application-received email to ' . $email . ' failed: ' . $result['error']);
    }

    return $result['ok'];
}

/** Mentora's branded email layout: header bar, eyebrow, heading, body HTML, one button, a small note and footer. */
function mentora_email_html(string $eyebrow, string $headingHtml, string $bodyHtml, string $buttonText, string $buttonUrl, string $note, string $footer): string
{
    $e = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    return '<!doctype html><html><body style="margin:0;padding:0;background:#f4f8f7;font-family:Arial,Helvetica,sans-serif;color:#1d1d1f;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f8f7;padding:28px 12px;"><tr><td align="center">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px;background:#ffffff;border-radius:18px;overflow:hidden;border:1px solid #d5e4e2;">'
        . '<tr><td bgcolor="#0b625d" style="background:#0b625d;background-image:linear-gradient(135deg,#063d3b,#0b625d 55%,#147b71);padding:22px 28px;color:#ffffff;font-size:20px;font-weight:bold;">Mentora</td></tr>'
        . '<tr><td style="padding:28px;">'
        . '<p style="margin:0 0 6px;font-size:12px;letter-spacing:.08em;text-transform:uppercase;color:#0b625d;font-weight:bold;">' . $e($eyebrow) . '</p>'
        . '<h1 style="margin:0 0 14px;font-family:Georgia,serif;font-size:26px;font-weight:normal;">' . $headingHtml . '</h1>'
        . $bodyHtml
        . '<p style="margin:0 0 22px;"><a href="' . $e($buttonUrl) . '" style="display:inline-block;background:#0b625d;color:#ffffff;text-decoration:none;font-weight:bold;font-size:15px;padding:13px 26px;border-radius:999px;">' . $e($buttonText) . '</a></p>'
        . '<p style="margin:0 0 6px;font-size:12px;color:#6e6e73;">Button not working? Copy this link into your browser:</p>'
        . '<p style="margin:0 0 22px;font-size:12px;word-break:break-all;"><a href="' . $e($buttonUrl) . '" style="color:#0b625d;">' . $e($buttonUrl) . '</a></p>'
        . '<p style="margin:0;font-size:12px;color:#6e6e73;">' . $e($note) . '</p>'
        . '</td></tr>'
        . '<tr><td style="padding:18px 28px;border-top:1px solid #e5eeec;font-size:12px;color:#6e6e73;">' . $e($footer) . '</td></tr>'
        . '</table></td></tr></table></body></html>';
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
    $html = mentora_email_html(
        $staff ? 'You’re invited' : 'Application approved',
        'Hi ' . $e($firstName) . ', welcome to Mentora!',
        '<p style="margin:0 0 22px;font-size:15px;line-height:1.6;color:#3a3a3c;">' . ($staff
            ? 'You’ve been invited to join Mentora as <strong>' . $e($pathwayLabel) . '</strong>. Create your username and password to open your workspace.'
            : 'Your <strong>' . $e($pathwayLabel) . '</strong> application has been approved. Create your username and password to open your applicant workspace.') . '</p>',
        'Create my account',
        $link,
        'This link works once and expires in ' . $days . ' days. Check your Spam or Promotions folder if you can’t find our emails.',
        $adminName . ' · Mentora HR'
    );

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
