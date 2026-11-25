<?php

/**
 * Activate your account: the approved applicant (or invited staff member) creates their username and password,
 * then goes straight to their workspace. Two ways in:
 *   - the "Create my account" link from the approval email (?token=...; single-use, MENTORA_INVITE_DAYS days), or
 *   - when that link won't open: they enter their email here ("Activate your account" on Track your application
 *     sends them here with it), we email a 6-digit activation code in its own email, and they type it here
 *     (valid MENTORA_ACTIVATION_CODE_MINUTES minutes; see mentora_send_activation_code).
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/account_invites.php';

const MENTORA_ACTIVATE_TRIES = 10;     // code checks per visitor...
const MENTORA_ACTIVATE_WINDOW = 600;   // ...every 10 minutes

mentora_session_start();

$action = $_SERVER['REQUEST_METHOD'] === 'POST' ? mentora_post_string('action') : '';
$token = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? mentora_post_string('token')
    : (is_string($_GET['token'] ?? null) ? $_GET['token'] : '');
$invite = $token !== '' ? mentora_find_invite($pdo, $token) : null;

// "Use a different email" on the code screen.
if (isset($_GET['change'])) {
    unset($_SESSION['activate_code_email']);
}
// The email an activation code was sent to from this browser: the page then asks for the code.
$sentTo = is_string($_SESSION['activate_code_email'] ?? null) ? $_SESSION['activate_code_email'] : '';
$codeEmail = strtolower(trim(in_array($action, ['send_code', 'use_code'], true)
    ? mentora_post_string('email')
    : (is_string($_GET['email'] ?? null) ? $_GET['email'] : $sentTo)));
$codeError = '';   // wrong / expired code (code screen)
$sendError = '';   // couldn't send a code (email screen)
$codeNotice = is_array($_SESSION['activate_notice'] ?? null) ? $_SESSION['activate_notice'] : null;   // after sending
unset($_SESSION['activate_notice']);

// "Email me a code" and the code itself share one limit per visitor, so emails can't be swept or codes guessed.
if (!$invite && in_array($action, ['send_code', 'use_code'], true)) {
    $tries = array_filter((array) ($_SESSION['activate_tries'] ?? []), static fn($t) => is_int($t) && $t > time() - MENTORA_ACTIVATE_WINDOW);
    $problem = '';
    if (!mentora_csrf_valid($_POST['csrf_token'] ?? null)) {
        $problem = 'Your session expired. Please try again.';
    } elseif (count($tries) >= MENTORA_ACTIVATE_TRIES) {
        $problem = 'Too many tries in a short time. Please wait a few minutes and try again.';
    } else {
        $tries[] = time();
        $_SESSION['activate_tries'] = array_values($tries);
        if ($action === 'send_code') {
            // Email a new activation code in its own email, then ask for it on the code screen.
            if (($emailProblem = mentora_email_error($codeEmail)) !== null) {
                $problem = $emailProblem;
            } else {
                $sent = mentora_send_activation_code($pdo, $codeEmail);
                if ($sent['ok'] || $sent['too_soon']) {   // too_soon: the code sent a minute ago still works
                    $_SESSION['activate_code_email'] = $codeEmail;
                    $_SESSION['activate_notice'] = $sent['ok']
                        ? ['ok' => true, 'text' => 'We emailed a new activation code. It works for ' . MENTORA_ACTIVATION_CODE_MINUTES . ' minutes; codes sent earlier no longer work.']
                        : ['ok' => false, 'text' => $sent['error']];
                    mentora_redirect('auth/activate.php');
                }
                $problem = $sent['error'];
            }
        } else {
            // The activation code from that email.
            $checked = mentora_use_invite_code($pdo, $codeEmail, mentora_post_string('code'));
            if ($checked['ok']) {
                $_SESSION['activate_invite_id'] = (int) $checked['invite']['invite_id'];
                mentora_redirect('auth/activate.php');
            }
            $problem = $checked['error'];
        }
    }
    if ($action === 'send_code') {
        $sendError = $problem;
    } else {
        $codeError = $problem;
    }
}
// Code already checked on this browser: continue to the create-account form.
if (!$invite && !empty($_SESSION['activate_invite_id'])) {
    $invite = mentora_find_invite_by_id($pdo, (int) $_SESSION['activate_invite_id']);
    if (!$invite) {
        unset($_SESSION['activate_invite_id']);
    }
}

$errors = [];
$fieldErrors = [];
$username = '';

if ($invite && $_SERVER['REQUEST_METHOD'] === 'POST' && $action !== 'use_code') {
    $username = strtolower(trim(mentora_post_string('username')));
    $password = mentora_post_string('password');
    $confirmPassword = mentora_post_string('confirm_password');

    if (!mentora_csrf_valid($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    }
    $fieldErrors['username'] = mentora_username_error($pdo, $username, (int) $invite['id']);
    $fieldErrors['password'] = mentora_password_error($password);
    if ($confirmPassword === '') {
        $fieldErrors['confirm_password'] = 'Please enter your password again.';
    } elseif ($password !== $confirmPassword) {
        $fieldErrors['confirm_password'] = 'Passwords do not match.';
    }
    $fieldErrors = array_filter($fieldErrors);
    if ($fieldErrors) {
        $errors[] = 'Please fix the highlighted fields.';
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();
            // The status check makes the link single-use even if it's submitted twice at once.
            $activate = $pdo->prepare("UPDATE users SET username = ?, password_hash = ?, status = 'active' WHERE id = ? AND status = 'approved'");
            $activate->execute([$username, password_hash($password, PASSWORD_DEFAULT), $invite['id']]);
            if ($activate->rowCount() !== 1) {
                throw new RuntimeException('Account was not waiting for confirmation.');
            }
            $pdo->prepare('UPDATE account_invites SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL')->execute([$invite['id']]);
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Mentora account confirmation failed: ' . $exception->getMessage());
            $errors[] = $exception instanceof PDOException && str_contains($exception->getMessage(), 'uq_users_username')
                ? 'That username is already taken. Please choose another.'
                : 'We could not create your account right now. Please try again.';
        }

        if (!$errors) {
            $row = $pdo->prepare('SELECT id, user_code, username, first_name, last_name, email, pathway, password_hash, role FROM users WHERE id = ?');
            $row->execute([$invite['id']]);
            mentora_sign_in($row->fetch());
            unset($_SESSION['applied'], $_SESSION['activate_invite_id'], $_SESSION['activate_tries'], $_SESSION['activate_code_email']);
            $_SESSION['flash_notice'] = 'Welcome to Mentora, ' . $invite['first_name'] . '! Your account is ready. Next, tell us about your qualifications.';
            mentora_redirect(mentora_after_sign_in_path($pdo, $_SESSION['user']));
        }
    }
}

// Suggest a username from their name, e.g. "juan.delacruz".
if ($invite && $username === '') {
    // Plain letters only ("José" -> "jose"); if this server can't transliterate, keep what's already plain.
    $plain = static function (string $name): string {
        $ascii = function_exists('iconv') ? @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) : false;
        return strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '', $ascii !== false && $ascii !== '' ? $ascii : $name));
    };
    $suggestion = trim($plain($invite['first_name']) . '.' . $plain($invite['last_name']), '.');
    if (preg_match('/^[a-z][a-z0-9._-]{2,29}$/', $suggestion) && mentora_username_error($pdo, $suggestion, (int) $invite['id']) === null) {
        $username = $suggestion;
    }
}

$pageTitle = 'Create your account | Mentora';
$bodyClass = 'account-page';
$headerStyle = 'signin-page';
$h = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$invalid = static fn(string $field): string => isset($fieldErrors[$field]) ? ' aria-invalid="true"' : '';
$fieldError = static fn(string $field): string => '<small class="field-error" id="' . $field . '-error" role="alert">'
    . (isset($fieldErrors[$field]) ? $h($fieldErrors[$field]) : '') . '</small>';
require __DIR__ . '/../includes/header.php';
?>
<main class="auth-shell account-shell">
    <?php if (!$invite && $sentTo !== ''): ?>
        <!-- Code screen: an activation code was emailed (same layout as "Confirm your email" in auth/signup.php). -->
        <section class="auth-card account-card application-status" aria-labelledby="activate-title">
            <span class="application-status-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" focusable="false">
                    <rect x="3" y="5" width="18" height="14" rx="2"/>
                    <path d="m3 7 9 6 9-6"/>
                </svg>
            </span>
            <h1 id="activate-title">Check your email.</h1>
            <p class="intro">
                We sent a 6-digit activation code to <strong><?= $h($sentTo) ?></strong>.
                Type it below to create your username and password.
            </p>
            <?php if ($codeNotice): ?>
                <div class="alert<?= $codeNotice['ok'] ? ' success' : '' ?>" role="status"><?= $h($codeNotice['text']) ?></div>
            <?php endif; ?>
            <form method="post" class="verify-code-form" action="activate.php">
                <?= mentora_csrf_field() ?>
                <input type="hidden" name="action" value="use_code">
                <input type="hidden" name="email" value="<?= $h($sentTo) ?>">
                <div class="field">
                    <label for="code">Activation code</label>
                    <input id="code" class="code-input" type="text" name="code" required inputmode="numeric" pattern="[0-9 \-]{6,8}" maxlength="8"
                           autocomplete="one-time-code" placeholder="123456" aria-describedby="code-error"<?= $codeError !== '' ? ' aria-invalid="true"' : '' ?> autofocus>
                    <small class="field-error" id="code-error" role="alert"><?= $h($codeError) ?></small>
                </div>
                <div class="form-actions"><button class="button button-primary" type="submit">Continue</button></div>
            </form>
            <form method="post" class="notice activate-hint" action="activate.php">
                <?= mentora_csrf_field() ?>
                <input type="hidden" name="action" value="send_code">
                <input type="hidden" name="email" value="<?= $h($sentTo) ?>">
                <span><strong>No email?</strong><br>Search your inbox, Spam and Promotions for “Mentora activation code”.</span>
                <button class="activate-hint-link" type="submit">Send a new code</button>
            </form>
            <div class="auth-links">
                Wrong email? <a href="activate.php?change=1">Use a different email</a><br><br>
                Already created your account? <a href="signin.php">Sign in</a><br><br>
                <a href="../index.php">Back to home</a>
            </div>
        </section>
    <?php elseif (!$invite): ?>
        <!-- Email screen: we email an activation code (same layout as "Confirm your email" in auth/signup.php). -->
        <section class="auth-card account-card application-status" aria-labelledby="activate-title">
            <?php if ($token !== ''): ?>
                <span class="application-status-icon is-warning" aria-hidden="true">
                    <svg viewBox="0 0 24 24" focusable="false">
                        <path d="M12 9v4M12 17h.01"/>
                        <path d="M10.3 3.9 2.4 17.5A2 2 0 0 0 4.1 20.5h15.8a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/>
                    </svg>
                </span>
                <h1 id="activate-title">This link can’t be used.</h1>
                <p class="intro">
                    It may have expired (links last <?= MENTORA_INVITE_DAYS ?> days), been used already, or been replaced by a newer one.
                    If your application was approved, enter your email and we’ll email you an activation code instead.
                </p>
            <?php else: ?>
                <span class="application-status-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" focusable="false">
                        <rect x="3" y="5" width="18" height="14" rx="2"/>
                        <path d="m3 7 9 6 9-6"/>
                    </svg>
                </span>
                <h1 id="activate-title">Activate your account.</h1>
                <p class="intro">
                    Good news: your application was approved! Click <strong>Create my account</strong> in the email from Mentora HR.
                    Link not working? Enter your email and we’ll email you an activation code.
                </p>
            <?php endif; ?>
            <?php if ($codeError !== ''): ?>
                <div class="alert" role="alert"><?= $h($codeError) ?></div>
            <?php endif; ?>

            <form method="post" class="verify-code-form" action="activate.php">
                <?= mentora_csrf_field() ?>
                <input type="hidden" name="action" value="send_code">
                <div class="field">
                    <label for="email">Email address</label>
                    <input id="email" type="email" name="email" required maxlength="190" autocomplete="email" placeholder="name@gmail.com"
                           aria-describedby="email-error"<?= $sendError !== '' ? ' aria-invalid="true" autofocus' : '' ?> value="<?= $h($codeEmail) ?>">
                    <small class="field-error" id="email-error" role="alert"><?= $h($sendError) ?></small>
                </div>
                <div class="form-actions"><button class="button button-primary" type="submit">Email me a code</button></div>
            </form>

            <div class="notice activate-hint">
                <span><strong>Can’t find it?</strong><br>Track your application to ask HR for a new one.</span>
                <a class="activate-hint-link" href="track.php">Track your application</a>
            </div>
            <div class="auth-links">
                Already created your account? <a href="signin.php">Sign in</a><br><br>
                <a href="../index.php">Back to home</a>
            </div>
        </section>
    <?php else: ?>
        <section class="auth-card wide account-card">
            <p class="eyebrow">Your application was approved</p>
            <h1>Create your account.</h1>
            <p class="intro">
                Welcome, <?= $h($invite['first_name']) ?>! Choose a username and password for your
                <?= $invite['pathway'] === 'internship' ? 'Intern' : 'Employee VA' ?> workspace.
                You can sign in with your username or your email (<?= $h($invite['email']) ?>).
            </p>
            <?php if ($errors): ?>
                <div class="alert"><?= $h(implode(' ', array_unique($errors))) ?></div>
            <?php endif; ?>

            <form method="post" data-validate>
                <?= mentora_csrf_field() ?>
                <input type="hidden" name="token" value="<?= $h($token) ?>">
                <fieldset>
                    <div class="field">
                        <label for="username">Username *</label>
                        <input id="username" name="username" required minlength="3" maxlength="30" autocomplete="username"
                               autocapitalize="none" spellcheck="false" pattern="[A-Za-z][A-Za-z0-9._\-]{2,29}"
                               aria-describedby="username-hint username-error"<?= $invalid('username') ?> value="<?= $h($username) ?>">
                        <small id="username-hint">3–30 characters. Start with a letter; then letters, numbers, dots, dashes or underscores.</small>
                        <?= $fieldError('username') ?>
                    </div>
                </fieldset>
                <fieldset>
                    <div class="form-grid">
                        <div class="field">
                            <label for="password">Password *</label>
                            <input id="password" type="password" name="password" required minlength="8" maxlength="64" autocomplete="new-password"
                                   data-check="password" aria-describedby="password-rules password-error"<?= $invalid('password') ?>>
                            <ul class="password-rules" id="password-rules" aria-label="Password requirements">
                                <?php foreach (array_keys(mentora_password_checks('')) as $n => $rule): ?>
                                    <li data-rule="<?= $n ?>"><?= $h(ucfirst($rule)) ?></li>
                                <?php endforeach; ?>
                            </ul>
                            <?= $fieldError('password') ?>
                        </div>
                        <div class="field">
                            <label for="confirm_password">Confirm password *</label>
                            <input id="confirm_password" type="password" name="confirm_password" required maxlength="64" autocomplete="new-password"
                                   data-check="match" data-match="password" aria-describedby="confirm_password-error"<?= $invalid('confirm_password') ?>>
                            <?= $fieldError('confirm_password') ?>
                        </div>
                    </div>
                </fieldset>
                <div class="form-actions">
                    <button class="button button-primary" type="submit">Create account &amp; continue</button>
                </div>
            </form>
        </section>
    <?php endif; ?>
</main>
<?php require __DIR__ . '/../includes/footer.php'; ?>
