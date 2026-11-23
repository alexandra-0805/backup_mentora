<?php

/**
 * Activate your account: the approved applicant (or invited staff member) creates their username and password,
 * then goes straight to their workspace. Two ways in, both single-use and valid MENTORA_INVITE_DAYS days:
 *   - the 6-digit activation code from the email (typed here or on Track your application), or
 *   - the link HR can copy from the approval panel and send themselves (?token=...).
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
$codeEmail = strtolower(trim($action === 'use_code' ? mentora_post_string('email') : (is_string($_GET['email'] ?? null) ? $_GET['email'] : '')));
$codeError = '';

// The activation code from the email (this page's code form, or the one on Track your application).
if (!$invite && $action === 'use_code') {
    $tries = array_filter((array) ($_SESSION['activate_tries'] ?? []), static fn($t) => is_int($t) && $t > time() - MENTORA_ACTIVATE_WINDOW);
    if (!mentora_csrf_valid($_POST['csrf_token'] ?? null)) {
        $codeError = 'Your session expired. Please try again.';
    } elseif (count($tries) >= MENTORA_ACTIVATE_TRIES) {
        $codeError = 'Too many tries in a short time. Please wait a few minutes and try again.';
    } else {
        $tries[] = time();
        $_SESSION['activate_tries'] = array_values($tries);
        $checked = mentora_use_invite_code($pdo, $codeEmail, mentora_post_string('code'));
        if ($checked['ok']) {
            $_SESSION['activate_invite_id'] = (int) $checked['invite']['invite_id'];
            mentora_redirect('auth/activate.php');
        }
        $codeError = $checked['error'];
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
            unset($_SESSION['applied'], $_SESSION['activate_invite_id'], $_SESSION['activate_tries']);
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
    <?php if (!$invite): ?>
        <!-- Same layout as the "Confirm your email" screen (auth/signup.php): icon, heading, code form, steps. -->
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
                    If you have an activation code from Mentora HR, type it below instead.
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
                    Link not working? Type your email and the activation code from that email below.
                </p>
            <?php endif; ?>

            <form method="post" class="verify-code-form" action="activate.php">
                <?= mentora_csrf_field() ?>
                <input type="hidden" name="action" value="use_code">
                <div class="field">
                    <label for="email">Email address</label>
                    <input id="email" type="email" name="email" required maxlength="190" autocomplete="email" placeholder="name@gmail.com" value="<?= $h($codeEmail) ?>">
                </div>
                <div class="field">
                    <label for="code">Activation code</label>
                    <input id="code" class="code-input" type="text" name="code" required inputmode="numeric" pattern="[0-9 \-]{6,8}" maxlength="8"
                           autocomplete="one-time-code" placeholder="123456" aria-describedby="code-error"<?= $codeError !== '' ? ' aria-invalid="true" autofocus' : '' ?>>
                    <small class="field-error" id="code-error" role="alert"><?= $h($codeError) ?></small>
                </div>
                <div class="form-actions"><button class="button button-primary" type="submit">Continue</button></div>
            </form>

            <ol class="application-steps">
                <li class="is-done">
                    <b aria-hidden="true">✓</b>
                    <span><strong>Application sent</strong><small>Your application was received.</small></span>
                </li>
                <li class="is-done">
                    <b aria-hidden="true">✓</b>
                    <span><strong>Confirm your email</strong><small>Your email is confirmed.</small></span>
                </li>
                <li class="is-done">
                    <b aria-hidden="true">✓</b>
                    <span><strong>HR confirms your application</strong><small>Approved by the HR team.</small></span>
                </li>
                <li class="is-current" aria-current="step">
                    <b aria-hidden="true">4</b>
                    <span><strong>Create your username and password</strong><small>Use the link in the email “Your Mentora application is approved”, or its activation code. Both expire in <?= MENTORA_INVITE_DAYS ?> days.</small></span>
                </li>
            </ol>

            <div class="notice">
                <strong>Can’t find it?</strong> Check your Spam or Promotions folder for “Mentora”. Code expired or lost?
                <a href="track.php">Track your application</a> to ask HR for a new one.
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
                               placeholder="e.g. juan.delacruz" aria-describedby="username-hint username-error"<?= $invalid('username') ?> value="<?= $h($username) ?>">
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
