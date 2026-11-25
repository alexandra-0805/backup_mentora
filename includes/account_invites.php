<?php

/**
 * Application approval and account confirmation.
 *
 *   1. Someone applies (auth/signup.php): a "pending" account is created, with no usable password,
 *      and a 6-digit "confirm your email" code is emailed (no link: free-hosting links get filtered as spam).
 *      Once it's typed in (signup.php or track.php -> auth/verify_email.php), HR is told.
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
const MENTORA_VERIFY_HOURS = 48;   // "confirm your email" codes (and links sent before codes)
const MENTORA_VERIFY_TRIES = 5;    // wrong codes allowed before a new one must be sent

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
    // One account per email, strictly: an email (or a Gmail lookalike of it) that already has any account,
    // whatever its status, is refused. Nothing is re-sent or re-opened from the sign-up form.
    $row = mentora_find_account_by_email($pdo, $email);
    if ($row) {
        return [
            'ok' => false,
            'error' => mentora_email_taken_message($row),
            // 'track': the sign-up page offers auth/track.php to follow the application.
            'track' => in_array($row['status'], ['pending', 'approved'], true),
            // 'id': only used by Google sign-up, where Google has proven it's the same person's inbox.
            'id' => (int) $row['id'],
        ];
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
    if (!filter_var($alertTo, FILTER_VALIDATE_EMAIL)) {
        return;
    }

    $reviewUrl = mentora_app_url('admin/applications.php?view=pending');
    $appliedAt = date('M j, Y · g:i A');
    $subject = 'Mentora application pending approval: ' . $name;
    $text = implode("\n", [
        'A new Mentora application is waiting for your approval.', '',
        'Applicant: ' . $name, 'Email: ' . $email, 'Pathway: ' . $label, 'Applied: ' . $appliedAt, '',
        'Review it in Applications: ' . $reviewUrl, '',
        'Once you approve it, the applicant gets a link to create their username and password.',
    ]);
    if (!mentora_mail_enabled()) {
        mentora_deliver_mail($alertTo, $subject, $text);   // no SMTP / relay: plain text through mail()
        return;
    }

    // Same branded layout as the applicant emails (mentora_email_html), with the applicant's details in a card.
    $e = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $row = static fn(string $field, string $valueHtml, bool $last = false): string =>
        '<tr><td style="padding:10px 14px;' . ($last ? '' : 'border-bottom:1px solid #e5eeec;') . 'font-size:13px;color:#6e6e73;width:96px;vertical-align:top;">' . $field . '</td>'
        . '<td style="padding:10px 14px;' . ($last ? '' : 'border-bottom:1px solid #e5eeec;') . 'font-size:14px;color:#1d1d1f;font-weight:bold;word-break:break-all;">' . $valueHtml . '</td></tr>';
    $html = mentora_email_html(
        'New application',
        $e($name) . ' is waiting for approval',
        '<p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#3a3a3c;">A new <strong>' . $e($label) . '</strong> application has arrived and needs an HR admin to review it.</p>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 22px;background:#f4f8f7;border:1px solid #d5e4e2;border-radius:12px;border-collapse:separate;">'
            . $row('Applicant', $e($name))
            . $row('Email', '<a href="mailto:' . $e($email) . '" style="color:#0b625d;text-decoration:none;">' . $e($email) . '</a>')
            . $row('Pathway', $e($label))
            . $row('Applied', $e($appliedAt), true)
            . '</table>',
        'Review application',
        $reviewUrl,
        'Once you approve it, the applicant gets an email with a link to create their username and password.',
        'Mentora HR · Application alerts'
    );
    $result = mentora_send_mail($alertTo, 'Mentora HR', $subject, $text, $html);
    if (!$result['ok']) {
        error_log('Mentora new-application alert to ' . $alertTo . ' failed: ' . $result['error']);
    }
}

/**
 * Right after someone applies (auth/signup.php, auth/google_callback.php).
 *   - Email already confirmed (staff adding an applicant): straight to HR.
 *   - Otherwise: email a "confirm your email" code (mentora_use_verification_code). HR is told once it's entered.
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
 * Email a pending applicant a one-time 6-digit "confirm your email" code (a new one replaces any earlier one).
 * The email has no link on purpose: spam filters (Gmail especially) bury or block messages that link to
 * free-hosting domains such as *.free.nf, so the applicant types the code on the site instead.
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

    $code = sprintf('%06d', random_int(0, 999999));
    $pdo->prepare('DELETE FROM email_verifications WHERE user_id = ? AND used_at IS NULL')->execute([$userId]);
    // token_hash is required and unique; with codes it's just a random placeholder no one knows.
    $pdo->prepare('INSERT INTO email_verifications (user_id, token_hash, code_hash, expires_at) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ' . MENTORA_VERIFY_HOURS . ' HOUR))')
        ->execute([$userId, hash('sha256', bin2hex(random_bytes(32))), mentora_verification_code_hash($userId, $code)]);

    [$subject, $text, $html] = mentora_verification_email($user['first_name'], $user['pathway'], $code);
    $result = mentora_send_mail($user['email'], trim($user['first_name'] . ' ' . $user['last_name']), $subject, $text, $html);
    if (!$result['ok']) {
        error_log('Mentora email confirmation to ' . $user['email'] . ' failed: ' . $result['error']);
        $pdo->prepare('DELETE FROM email_verifications WHERE user_id = ? AND used_at IS NULL')->execute([$userId]);
        return ['ok' => false, 'error' => 'We couldn’t send the email right now. Please try again in a few minutes.', 'too_soon' => false];
    }

    return ['ok' => true, 'error' => '', 'too_soon' => false];
}

/** Stored form of a confirmation code (tied to the applicant, so equal codes for two people never match). */
function mentora_verification_code_hash(int $userId, string $code): string
{
    return hash('sha256', $userId . ':' . $code);
}

/**
 * The "confirm your email" message sent right after applying: [subject, plain text, HTML].
 * Deliberately link-free (see mentora_send_verification_email) and short, like any sign-up code email.
 */
function mentora_verification_email(string $firstName, string $pathway, string $code): array
{
    $label = mentora_pathway_label($pathway);
    $hours = MENTORA_VERIFY_HOURS;
    $subject = $code . ' is your Mentora confirmation code';
    $text = "Hi {$firstName},\n\n"
        . "Thanks for applying to Mentora as {$label}! Your confirmation code is:\n\n"
        . "    {$code}\n\n"
        . "Type this code on the Mentora page where you applied (or under \"Track your application\") to confirm your email.\n\n"
        . "Once it's confirmed, your application goes to the HR team. When they approve it, we'll email you the next step to create your username and password.\n\n"
        . "This code expires in {$hours} hours. If you didn't apply to Mentora, you can ignore this email.\n\nMentora HR";

    $e = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $html = mentora_email_html(
        'Confirm your email',
        'Hi ' . $e($firstName) . ', here’s your code',
        '<p style="margin:0 0 14px;font-size:15px;line-height:1.6;color:#3a3a3c;">Thanks for applying to Mentora as <strong>' . $e($label)
            . '</strong>! Type this code on the Mentora page where you applied to confirm your email:</p>'
            . '<p style="margin:0 0 18px;padding:16px;background:#f4f8f7;border:1px solid #d5e4e2;border-radius:12px;text-align:center;'
            . 'font-family:Consolas,Menlo,monospace;font-size:32px;font-weight:bold;letter-spacing:8px;color:#063d3b;">' . $e($code) . '</p>'
            . '<p style="margin:0 0 22px;font-size:14px;line-height:1.6;color:#3a3a3c;">Once it’s confirmed, your application goes to the HR team. '
            . 'When they approve it, we’ll email you the next step to create your username and password.</p>',
        '',
        '',
        'This code expires in ' . $hours . ' hours. Closed the page? Open “Track your application” on Mentora and enter your email to type the code there. '
            . 'If you didn’t apply to Mentora, you can ignore this email.',
        'Mentora HR'
    );

    return [$subject, $text, $html];
}

/**
 * A confirmation was used: mark the email confirmed and send the application to HR. Only the request that
 * actually marks it used tells HR, so submitting twice at once is harmless.
 */
function mentora_complete_verification(PDO $pdo, int $verificationId, array $user): void
{
    $use = $pdo->prepare('UPDATE email_verifications SET used_at = NOW() WHERE id = ? AND used_at IS NULL');
    $use->execute([$verificationId]);
    if ($use->rowCount() === 1) {
        mentora_mark_email_verified($pdo, (int) $user['id']);
        mentora_notify_admins_new_applicant($pdo, trim($user['first_name'] . ' ' . $user['last_name']), $user['email'], $user['pathway']);
    }
}

/**
 * Check a typed 6-digit confirmation code (auth/signup.php, auth/track.php). Spaces and dashes are ignored.
 * Returns ['ok' => true, 'user' => applicant] or ['ok' => false, 'error' => message to show].
 */
function mentora_use_verification_code(PDO $pdo, int $userId, string $code): array
{
    $code = preg_replace('/[\s-]+/', '', $code) ?? '';
    if (!preg_match('/^\d{6}$/', $code)) {
        return ['ok' => false, 'error' => 'Please enter the 6-digit code from the email.'];
    }
    $query = $pdo->prepare(
        "SELECT v.id AS verification_id, v.code_hash, v.attempts, v.expires_at > NOW() AS live,
                u.id, u.first_name, u.last_name, u.email, u.pathway
         FROM email_verifications v JOIN users u ON u.id = v.user_id
         WHERE v.user_id = ? AND v.used_at IS NULL AND v.code_hash IS NOT NULL AND u.status = 'pending' AND u.email_verified_at IS NULL
         ORDER BY v.id DESC LIMIT 1"
    );
    $query->execute([$userId]);
    $row = $query->fetch();
    if (!$row) {
        return mentora_email_is_verified($pdo, $userId)
            ? ['ok' => false, 'error' => 'Your email is already confirmed. Your application is with HR.']
            : ['ok' => false, 'error' => 'There’s no active code for this application. Please send a new code.'];
    }
    if (!$row['live']) {
        return ['ok' => false, 'error' => 'This code has expired. Please send a new code.'];
    }
    if ((int) $row['attempts'] >= MENTORA_VERIFY_TRIES) {
        return ['ok' => false, 'error' => 'Too many wrong codes. Please send a new code.'];
    }
    if (!hash_equals($row['code_hash'], mentora_verification_code_hash($userId, $code))) {
        $pdo->prepare('UPDATE email_verifications SET attempts = attempts + 1 WHERE id = ?')->execute([$row['verification_id']]);
        $left = MENTORA_VERIFY_TRIES - (int) $row['attempts'] - 1;
        return ['ok' => false, 'error' => $left > 0
            ? 'That code isn’t right. Check the newest email from Mentora HR (' . $left . ($left === 1 ? ' try' : ' tries') . ' left).'
            : 'That code isn’t right, and it can’t be used anymore. Please send a new code.'];
    }

    mentora_complete_verification($pdo, (int) $row['verification_id'], $row);

    return ['ok' => true, 'user' => $row];
}

/**
 * Open a "confirm your email" link (emails sent before codes replaced links still carry one).
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
    mentora_complete_verification($pdo, (int) $user['verification_id'], $user);

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

/**
 * Mentora's branded email layout: header bar, eyebrow, heading, body HTML, one button, a small note and footer.
 * An empty $buttonUrl leaves out the button and the copy-this-link line (e.g. the code-only confirmation email).
 */
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
        . ($buttonUrl === '' ? '' : '<p style="margin:0 0 22px;"><a href="' . $e($buttonUrl) . '" style="display:inline-block;background:#0b625d;color:#ffffff;text-decoration:none;font-weight:bold;font-size:15px;padding:13px 26px;border-radius:999px;">' . $e($buttonText) . '</a></p>'
            . '<p style="margin:0 0 6px;font-size:12px;color:#6e6e73;">Button not working? Copy this link into your browser:</p>'
            . '<p style="margin:0 0 22px;font-size:12px;word-break:break-all;"><a href="' . $e($buttonUrl) . '" style="color:#0b625d;">' . $e($buttonUrl) . '</a></p>')
        . '<p style="margin:0;font-size:12px;color:#6e6e73;">' . $e($note) . '</p>'
        . '</td></tr>'
        . '<tr><td style="padding:18px 28px;border-top:1px solid #e5eeec;font-size:12px;color:#6e6e73;">' . $e($footer) . '</td></tr>'
        . '</table></td></tr></table></body></html>';
}

/**
 * Approve (or re-send for) an applicant: a new one-time link replaces any earlier unused one (and any activation
 * code asked for with it). Returns the full confirmation link (only ever shown to the admin who made it).
 * The backup activation code isn't made here: the applicant asks for it on Activate your account, and it comes in
 * its own email (mentora_send_activation_code).
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
 * The "approved / invited: create your account" email: [subject, plain text, HTML], with the "Create my account"
 * button and the copyable link. Also used to pre-fill "Open in Gmail" (includes/invite_panel.php).
 * If the link won't open, the backup line points to Activate your account, which emails a code separately.
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
        . "Link not working? On Mentora, open \"Track your application\", enter your email and click \"Activate your account\": we'll email you an activation code.\n\n"
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
        'Link not working? On Mentora, open “Track your application”, enter your email and click “Activate your account”: '
            . 'we’ll email you an activation code. This link works once and expires in ' . $days . ' days. '
            . 'Check your Spam or Promotions folder if you can’t find our emails.',
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
    // The "Create my account" button + link email (it opens auth/activate.php, then the applicant workspace).
    [$subject, $text, $html] = mentora_invite_email($firstName, $pathway, $link, $adminName, $role);

    return mentora_send_mail($email, $fullName, $subject, $text, $html, $adminEmail);
}

/** Stored form of an activation code (tied to the person, and kept apart from email confirmation codes). */
function mentora_invite_code_hash(int $userId, string $code): string
{
    return hash('sha256', 'invite:' . $userId . ':' . $code);
}

/** Wrong tries allowed per activation code before it stops working (the applicant can ask for a new code). */
const MENTORA_INVITE_CODE_ATTEMPTS = 5;
/** How long an emailed activation code works. */
const MENTORA_ACTIVATION_CODE_MINUTES = 30;

/**
 * "Activate your account": email the approved applicant (or invited staff member) a 6-digit activation code, in its
 * own email, for when the "Create my account" link won't open. A new code replaces any earlier one; the link keeps
 * working. At most one email a minute per person. Returns ['ok' => bool, 'error' => string, 'too_soon' => bool].
 */
function mentora_send_activation_code(PDO $pdo, string $email): array
{
    $query = $pdo->prepare(
        "SELECT i.id AS invite_id, (i.code_sent_at > NOW() - INTERVAL 60 SECOND) AS sent_recently,
                u.id, u.first_name, u.last_name, u.email
         FROM account_invites i JOIN users u ON u.id = i.user_id
         WHERE LOWER(u.email) = ? AND u.status = 'approved' AND i.used_at IS NULL AND i.expires_at > NOW()
         ORDER BY i.id DESC LIMIT 1"
    );
    $query->execute([strtolower(trim($email))]);
    $invite = $query->fetch();
    if (!$invite) {
        return ['ok' => false, 'too_soon' => false, 'error' => 'There’s no approved application waiting for an account with this email. '
            . 'Check the spelling, or open Track your application: if your link expired, you can ask HR for a new one there.'];
    }
    if ((int) $invite['sent_recently'] === 1) {
        return ['ok' => false, 'too_soon' => true, 'error' => 'We just emailed you a code. Please wait a minute before asking for another one.'];
    }
    if (!mentora_mail_enabled()) {
        return ['ok' => false, 'too_soon' => false, 'error' => 'Automatic email isn’t set up yet, so we can’t send a code. Please ask the HR team for a new link.'];
    }

    $code = sprintf('%06d', random_int(0, 999999));
    $pdo->prepare('UPDATE account_invites SET code_hash = ?, attempts = 0, code_sent_at = NOW() WHERE id = ?')
        ->execute([mentora_invite_code_hash((int) $invite['id'], $code), $invite['invite_id']]);

    [$subject, $text, $html] = mentora_activation_code_email($invite['first_name'], $code);
    $result = mentora_send_mail($invite['email'], trim($invite['first_name'] . ' ' . $invite['last_name']), $subject, $text, $html);
    if (!$result['ok']) {
        error_log('Mentora activation code email to ' . $invite['email'] . ' failed: ' . $result['error']);
        // Nobody received this code: clear it so the applicant can try again straight away.
        $pdo->prepare('UPDATE account_invites SET code_hash = NULL, code_sent_at = NULL WHERE id = ?')->execute([$invite['invite_id']]);
        return ['ok' => false, 'too_soon' => false, 'error' => 'We couldn’t send the email right now. Please try again in a few minutes.'];
    }

    return ['ok' => true, 'too_soon' => false, 'error' => ''];
}

/** The activation code email: [subject, plain text, HTML]. Code only, no link (like the confirm-your-email code). */
function mentora_activation_code_email(string $firstName, string $code): array
{
    $minutes = MENTORA_ACTIVATION_CODE_MINUTES;
    $subject = $code . ' is your Mentora activation code';
    $text = "Hi {$firstName},\n\n"
        . "Your Mentora activation code is:\n\n"
        . "    {$code}\n\n"
        . "Type it on the \"Activate your account\" page to create your username and password.\n\n"
        . "This code expires in {$minutes} minutes. If you didn't ask for it, you can ignore this email.\n\nMentora HR";

    $e = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $html = mentora_email_html(
        'Activate your account',
        'Hi ' . $e($firstName) . ', here’s your code',
        '<p style="margin:0 0 14px;font-size:15px;line-height:1.6;color:#3a3a3c;">Type this code on the <strong>Activate your account</strong> page to create your username and password:</p>'
            . '<p style="margin:0 0 18px;padding:16px;background:#f4f8f7;border:1px solid #d5e4e2;border-radius:12px;text-align:center;'
            . 'font-family:Consolas,Menlo,monospace;font-size:32px;font-weight:bold;letter-spacing:8px;color:#063d3b;">' . $e($code) . '</p>',
        '',
        '',
        'This code expires in ' . $minutes . ' minutes. If you didn’t ask for it, you can ignore this email.',
        'Mentora HR'
    );

    return [$subject, $text, $html];
}

/**
 * Check an emailed activation code. Returns ['ok' => true, 'invite' => row like mentora_find_invite()] or
 * ['ok' => false, 'error' => message]. Wrong tries count against the code; too many and it stops working.
 */
function mentora_use_invite_code(PDO $pdo, string $email, string $code): array
{
    $code = preg_replace('/[\s-]+/', '', $code) ?? '';
    $query = $pdo->prepare(
        "SELECT i.id AS invite_id, i.code_hash, i.attempts,
                (i.code_sent_at > NOW() - INTERVAL " . MENTORA_ACTIVATION_CODE_MINUTES . " MINUTE) AS code_live,
                u.id, u.first_name, u.last_name, u.email, u.pathway
         FROM account_invites i JOIN users u ON u.id = i.user_id
         WHERE LOWER(u.email) = ? AND u.status = 'approved' AND i.used_at IS NULL AND i.expires_at > NOW()
         ORDER BY i.id DESC LIMIT 1"
    );
    $query->execute([strtolower(trim($email))]);
    $invite = $query->fetch();
    if (!$invite) {
        return ['ok' => false, 'error' => 'There’s no approved application waiting for an account with this email. Open Track your application to check it.'];
    }
    if ($invite['code_hash'] === null || (int) $invite['code_live'] !== 1) {
        return ['ok' => false, 'error' => 'This code has expired or was replaced. Send yourself a new code below.'];
    }
    if ((int) $invite['attempts'] >= MENTORA_INVITE_CODE_ATTEMPTS) {
        return ['ok' => false, 'error' => 'Too many wrong codes. Send yourself a new code below.'];
    }
    if (!preg_match('/^\d{6}$/', $code) || !hash_equals((string) $invite['code_hash'], mentora_invite_code_hash((int) $invite['id'], $code))) {
        $pdo->prepare('UPDATE account_invites SET attempts = attempts + 1 WHERE id = ?')->execute([$invite['invite_id']]);
        $left = MENTORA_INVITE_CODE_ATTEMPTS - (int) $invite['attempts'] - 1;
        return ['ok' => false, 'error' => $left > 0
            ? 'That code isn’t right. Check the latest “Mentora activation code” email (' . $left . ' ' . ($left === 1 ? 'try' : 'tries') . ' left).'
            : 'Too many wrong codes. Send yourself a new code below.'];
    }
    unset($invite['code_hash'], $invite['attempts'], $invite['code_live']);

    return ['ok' => true, 'invite' => $invite];
}

/** An invite that's still usable, by id (after its code was checked in this session). Same row as mentora_find_invite(). */
function mentora_find_invite_by_id(PDO $pdo, int $inviteId): ?array
{
    $query = $pdo->prepare(
        "SELECT i.id AS invite_id, u.id, u.first_name, u.last_name, u.email, u.pathway
         FROM account_invites i JOIN users u ON u.id = i.user_id
         WHERE i.id = ? AND i.used_at IS NULL AND i.expires_at > NOW() AND u.status = 'approved'"
    );
    $query->execute([$inviteId]);

    return $query->fetch() ?: null;
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
