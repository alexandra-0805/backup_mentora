<?php

/**
 * Track your application (public): an applicant enters their email and sees where their application is:
 *   pending   waiting for an HR admin to confirm it (admin/applications.php)
 *   approved  the confirmation link was sent (with its expiry); if it expired they can ask HR for a new one
 *   active    the account is ready: sign in
 *   rejected / disabled  not approved / turned off
 * Linked from the sign-up waiting screen, the "already waiting" message and the sign-in page.
 * No name or other details are shown, and lookups are limited per visitor so emails can't be swept.
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/account_invites.php';

const MENTORA_TRACK_LOOKUPS = 8;       // per visitor...
const MENTORA_TRACK_WINDOW = 600;      // ...every 10 minutes

mentora_session_start();
$email = strtolower(trim(mentora_post_string('email')));
if ($email === '' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $email = (string) ($_SESSION['applied']['email'] ?? ''); // just applied on this browser
}
$error = '';
$notice = '';
$codeError = '';   // wrong / expired confirmation code
$result = null; // ['status', 'pathway', 'applied_at', 'link_sent_at', 'link_expires_at', 'link_valid']

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $lookups = array_filter((array) ($_SESSION['track_lookups'] ?? []), static fn($t) => is_int($t) && $t > time() - MENTORA_TRACK_WINDOW);
    if (!mentora_csrf_valid($_POST['csrf_token'] ?? null)) {
        $error = 'Your session expired. Please try again.';
    } elseif (count($lookups) >= MENTORA_TRACK_LOOKUPS) {
        $error = 'Too many checks in a short time. Please wait a few minutes and try again.';
    } elseif (($emailError = mentora_email_error($email)) !== null) {
        $error = $emailError;
    } else {
        $lookups[] = time();
        $_SESSION['track_lookups'] = array_values($lookups);
        $query = $pdo->prepare(
            "SELECT u.id, u.first_name, u.last_name, u.pathway, u.status, u.created_at AS applied_at, u.email_verified_at,
                    (SELECT MAX(i.created_at) FROM account_invites i WHERE i.user_id = u.id) AS link_sent_at,
                    (SELECT MAX(i.expires_at) FROM account_invites i WHERE i.user_id = u.id AND i.used_at IS NULL) AS link_expires_at
             FROM users u WHERE u.email = ? AND u.role = 'applicant'"
        );
        $query->execute([$email]);
        $row = $query->fetch() ?: null;
        $result = $row ? $row + ['link_valid' => $row['link_expires_at'] !== null && strtotime($row['link_expires_at']) > time()] : ['status' => 'not_found'];

        // Email not confirmed yet: send a new "confirm your email" code.
        if ($row && ($_POST['action'] ?? '') === 'resend_verification' && $row['status'] === 'pending' && $row['email_verified_at'] === null) {
            $sent = mentora_send_verification_email($pdo, (int) $row['id']);
            if ($sent['ok']) {
                $notice = 'We emailed you a new code. Watch your inbox (and Spam / Promotions). Codes sent earlier no longer work.';
            } else {
                $error = $sent['error'];
            }
        }

        // Email not confirmed yet: the code from the email, typed here (e.g. on another device than the one they applied on).
        if ($row && ($_POST['action'] ?? '') === 'verify_code' && $row['status'] === 'pending' && $row['email_verified_at'] === null) {
            $checked = mentora_use_verification_code($pdo, (int) $row['id'], mentora_post_string('code'));
            if ($checked['ok']) {
                $_SESSION['email_confirmed'] = $checked['user'];
                mentora_redirect('auth/verify_email.php');
            }
            $codeError = $checked['error'];
        }

        // Link expired (or lost): ask HR for a new one, at most once a day per applicant.
        if ($row && ($_POST['action'] ?? '') === 'request_link' && $row['status'] === 'approved' && !$result['link_valid']) {
            $title = 'New link requested: ' . trim($row['first_name'] . ' ' . $row['last_name']);
            $recent = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE title = ? AND created_at > NOW() - INTERVAL 1 DAY');
            $recent->execute([$title]);
            if ((int) $recent->fetchColumn() === 0) {
                mentora_notify_roles($pdo, ['admin'], $title, mentora_pathway_label($row['pathway']) . ' · ' . $email . ' asked for a new confirmation link (the last one expired).', 'admin/applications.php?view=approved');
            }
            $notice = 'We asked the HR team to send you a new link. Watch your inbox (and Spam / Promotions).';
        }
    }
}

$date = static fn(?string $value): string => $value ? date('d M Y', strtotime($value)) : '';
$pageTitle = 'Track your application | Mentora';
$bodyClass = 'account-page';
$headerStyle = 'signin-page';
$h = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
require __DIR__ . '/../includes/header.php';

/** One step of the timeline: $state 'done' / 'current' / '' (upcoming). */
$step = static function (int $number, string $state, string $title, string $detail) use ($h): string {
    return '<li class="' . ($state === 'done' ? 'is-done' : ($state === 'current' ? 'is-current' : '')) . '"' . ($state === 'current' ? ' aria-current="step"' : '') . '>'
        . '<b aria-hidden="true">' . ($state === 'done' ? '✓' : $number) . '</b>'
        . '<span><strong>' . $h($title) . '</strong><small>' . $h($detail) . '</small></span></li>';
};
?>
<main class="auth-shell account-shell">
    <section class="auth-card account-card application-status" aria-labelledby="track-title">
        <h1 id="track-title">Track your application.</h1>
        <p class="intro">Enter the email you applied with to see where your application is.</p>
        <?php if ($error): ?><div class="alert"><?= $h($error) ?></div><?php endif; ?>
        <?php if ($notice): ?><div class="alert success"><?= $h($notice) ?></div><?php endif; ?>

        <form method="post" class="track-form">
            <?= mentora_csrf_field() ?>
            <div class="field">
                <label for="email">Email address</label>
                <input id="email" type="email" name="email" required maxlength="190" autocomplete="email" placeholder="name@gmail.com" value="<?= $h($email) ?>">
            </div>
            <div class="form-actions"><button class="button button-primary" type="submit">Check status</button></div>
        </form>

        <?php if ($result !== null): ?>
            <div class="track-result" role="status">
                <?php if ($result['status'] === 'not_found'): ?>
                    <div class="notice"><strong>No application found for <?= $h($email) ?>.</strong><br>Check the spelling, or <a href="signup.php">apply now</a>. If an agent sent you a link, open that link instead.</div>
                <?php elseif (in_array($result['status'], ['pending', 'approved'], true)):
                    $approved = $result['status'] === 'approved';
                    $unverified = !$approved && $result['email_verified_at'] === null; ?>
                    <p class="track-summary">
                        <span class="badge <?= $approved ? '' : 'warn' ?>"><?= $approved
                            ? ($result['link_valid'] ? 'Approved · link sent' : 'Approved · link expired')
                            : ($unverified ? 'Confirm your email' : 'Waiting for confirmation') ?></span>
                        <?= $h(mentora_pathway_label($result['pathway'])) ?> application · sent <?= $date($result['applied_at']) ?>
                    </p>
                    <ol class="application-steps">
                        <?= $step(1, 'done', 'Application sent', 'Received ' . $date($result['applied_at']) . '.') ?>
                        <?= $step(2, $unverified ? 'current' : 'done', 'Confirm your email', $unverified
                            ? 'Type the 6-digit code from the email we sent you. Check Spam / Promotions too.'
                            : 'Your email is confirmed.') ?>
                        <?= $step(3, $approved ? 'done' : ($unverified ? '' : 'current'), 'HR confirms your application', $approved ? 'Approved by the HR team.' : 'An HR admin reviews it. This usually takes 1–3 working days.') ?>
                        <?= $step(4, $approved ? 'current' : '', 'Create your username and password', $approved
                            ? ($result['link_valid']
                                ? 'Link sent ' . $date($result['link_sent_at']) . '. Click “Create my account” in the email (check Spam / Promotions too). It works until ' . $date($result['link_expires_at']) . '.'
                                : 'Your link expired. Ask for a new one below.')
                            : 'We email you a link to create your account once you’re approved.') ?>
                    </ol>
                    <?php if ($approved && $result['link_valid']): ?>
                        <?php // Sends the activation code in its own email, then opens the code screen (auth/activate.php). ?>
                        <form method="post" action="activate.php" class="notice activate-hint">
                            <?= mentora_csrf_field() ?><input type="hidden" name="action" value="send_code"><input type="hidden" name="email" value="<?= $h($email) ?>">
                            <span><strong>Link not working?</strong><br>We’ll email you an activation code instead.</span>
                            <button class="activate-hint-link" type="submit">Activate your account</button>
                        </form>
                    <?php endif; ?>
                    <?php if ($unverified): ?>
                        <form method="post" class="verify-code-form">
                            <?= mentora_csrf_field() ?><input type="hidden" name="email" value="<?= $h($email) ?>"><input type="hidden" name="action" value="verify_code">
                            <div class="field">
                                <label for="code">Confirmation code</label>
                                <input id="code" class="code-input" type="text" name="code" required inputmode="numeric" pattern="[0-9 \-]{6,8}" maxlength="8"
                                       autocomplete="one-time-code" placeholder="123456" aria-describedby="code-error"<?= $codeError !== '' ? ' aria-invalid="true" autofocus' : '' ?>>
                                <small class="field-error" id="code-error" role="alert"><?= $h($codeError) ?></small>
                            </div>
                            <div class="form-actions"><button class="button button-primary" type="submit">Confirm email</button></div>
                        </form>
                    <?php endif; ?>
                    <?php if ($unverified && mentora_mail_enabled()): ?>
                        <form method="post" class="notice resend-form">
                            <?= mentora_csrf_field() ?><input type="hidden" name="email" value="<?= $h($email) ?>"><input type="hidden" name="action" value="resend_verification">
                            <strong>No email?</strong> Search your inbox, Spam and Promotions for “Mentora confirmation code”, or
                            <button class="text-button" type="submit">email me a new code</button>.
                        </form>
                    <?php endif; ?>
                    <?php if ($approved && !$result['link_valid'] && $notice === ''): ?>
                        <form method="post">
                            <?= mentora_csrf_field() ?><input type="hidden" name="email" value="<?= $h($email) ?>"><input type="hidden" name="action" value="request_link">
                            <button class="button button-primary" type="submit">Ask HR for a new link</button>
                        </form>
                    <?php endif; ?>
                <?php elseif ($result['status'] === 'active'): ?>
                    <div class="alert success"><strong>Your account is ready.</strong> <a href="signin.php">Sign in</a> with your username or email to continue.</div>
                <?php else: ?>
                    <div class="notice"><?= $h(mentora_account_status_message((string) $result['status'])) ?></div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="auth-links">
            Already have an account? <a href="signin.php">Sign in</a><br><br>
            New here? <a href="signup.php">Apply now</a><br><br>
            <a href="../index.php">Back to home</a>
        </div>
    </section>
</main>
<?php require __DIR__ . '/../includes/footer.php'; ?>
