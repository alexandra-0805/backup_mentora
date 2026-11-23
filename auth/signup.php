<?php

/**
 * Apply: pathway, name and email (no password yet). The application waits for an HR admin
 * (admin/applications.php), who sends a confirmation link from their Gmail. The link opens
 * auth/activate.php, where the applicant creates a username and password and enters the
 * applicant workspace. See includes/account_invites.php for the whole flow.
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/google_auth.php';
require_once __DIR__ . '/../includes/account_invites.php';

$signedIn = mentora_current_user();
$isStaff = $signedIn !== null && in_array($signedIn['role'], ['admin', 'agent'], true);

// Applicants who already have an account continue where they left off (form or workspace).
// Admins and agents may stay: they use this page to add applicants, without being signed out.
if ($signedIn !== null && !$isStaff) {
    mentora_redirect(mentora_after_sign_in_path($pdo, $signedIn));
}

$errors = [];
mentora_session_start();
if (!empty($_SESSION['flash_error']) && is_string($_SESSION['flash_error'])) {
    $errors[] = $_SESSION['flash_error'];   // e.g. from Continue with Google
}
unset($_SESSION['flash_error']);

// "Start a new application" from the waiting screen.
if (isset($_GET['new'])) {
    unset($_SESSION['applied']);
}
// After applying (here or with Google): show the waiting screen.
$applied = !$isStaff && isset($_GET['applied']) && is_array($_SESSION['applied'] ?? null) ? $_SESSION['applied'] : null;

$created = null;
$account = [
    'first_name' => '',
    'last_name' => '',
    'email' => '',
    'pathway' => (($_GET['pathway'] ?? '') === 'internship') ? 'internship' : 'employee_va',
];

$fieldErrors = [];   // field name => message, shown under that field
$resendMessage = null;   // ['ok' => bool, 'text' => string] after "Resend email"
$codeError = null;       // wrong / expired code typed on the confirm-your-email screen

// The 6-digit code from the email, typed on the confirm-your-email screen.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'verify_code') {
    $userId = (int) ($_SESSION['applied']['user_id'] ?? 0);
    if (!mentora_csrf_valid($_POST['csrf_token'] ?? null) || $userId <= 0) {
        $_SESSION['code_error'] = 'Your session expired. Open “Track your application”, enter your email and type the code there.';
    } else {
        $checked = mentora_use_verification_code($pdo, $userId, mentora_post_string('code'));
        if ($checked['ok']) {
            $_SESSION['email_confirmed'] = $checked['user'];
            mentora_redirect('auth/verify_email.php');
        }
        $_SESSION['code_error'] = $checked['error'];
    }
    mentora_redirect('auth/signup.php?applied=1');
}
if (is_string($_SESSION['code_error'] ?? null)) {
    $codeError = $_SESSION['code_error'];
}
unset($_SESSION['code_error']);

// "Resend email" on the confirm-your-email screen.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'resend_verification') {
    $userId = (int) ($_SESSION['applied']['user_id'] ?? 0);
    if (!mentora_csrf_valid($_POST['csrf_token'] ?? null) || $userId <= 0) {
        $_SESSION['resend_message'] = ['ok' => false, 'text' => 'Your session expired. Please apply again with the same email to get a new link.'];
    } else {
        $sent = mentora_send_verification_email($pdo, $userId);
        $_SESSION['resend_message'] = $sent['ok']
            ? ['ok' => true, 'text' => 'We sent you a new code. Codes sent earlier no longer work.']
            : ['ok' => false, 'text' => $sent['error']];
    }
    mentora_redirect('auth/signup.php?applied=1');
}
if (is_array($_SESSION['resend_message'] ?? null)) {
    $resendMessage = $_SESSION['resend_message'];
}
unset($_SESSION['resend_message']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($account as $key => $_) {
        // Trim and collapse repeated spaces, e.g. "Juan   Dela  Cruz" -> "Juan Dela Cruz".
        $account[$key] = preg_replace('/\s+/u', ' ', trim(mentora_post_string($key)));
    }
    $account['email'] = strtolower($account['email']);

    if (!mentora_csrf_valid($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please submit the form again.';
    }
    if (!in_array($account['pathway'], ['employee_va', 'internship'], true)) {
        $account['pathway'] = 'employee_va';
        $fieldErrors['pathway'] = 'Please choose Employee VA or Intern.';
    }
    $fieldErrors['first_name'] = mentora_name_error($account['first_name'], 'first name');
    $fieldErrors['last_name'] = mentora_name_error($account['last_name'], 'last name');
    $fieldErrors['email'] = mentora_email_error($account['email']);
    if (!isset($_POST['agree'])) {
        $fieldErrors['agree'] = 'Please accept the Data Privacy Notice to send your application.';
    }
    $fieldErrors = array_filter($fieldErrors);
    if ($fieldErrors) {
        $errors[] = 'Please fix the highlighted fields.';
    }

    if (!$errors) {
        try {
            $result = mentora_create_pending_applicant($pdo, $account['first_name'], $account['last_name'], $account['email'], $account['pathway']);
        } catch (PDOException $exception) {
            error_log('Mentora application failed: ' . $exception->getMessage());
            $result = ['ok' => false, 'error' => 'We could not send your application right now. Please try again.'];
        }

        // Applied before but never confirmed the email: send the "confirm your email" code again.
        if (!$result['ok'] && !empty($result['id']) && !$isStaff && mentora_mail_enabled() && !mentora_email_is_verified($pdo, (int) $result['id'])) {
            $resent = mentora_send_verification_email($pdo, (int) $result['id']);
            if ($resent['ok'] || $resent['too_soon']) {
                $_SESSION['applied'] = [
                    'user_id' => (int) $result['id'], 'name' => $account['first_name'], 'email' => $account['email'],
                    'pathway' => $account['pathway'], 'emailed' => true, 'verify_sent' => true,
                ];
                $_SESSION['resend_message'] = ['ok' => true, 'text' => $resent['ok']
                    ? 'You already applied with this email, so we sent you a new confirmation code.'
                    : 'You already applied with this email. We just sent your confirmation code, so please check your inbox.'];
                mentora_redirect('auth/signup.php?applied=1');
            }
        }

        if (!$result['ok']) {
            $fieldErrors['email'] = $result['error'];
            $errors[] = $result['error'];
            if (!empty($result['track']) && !$isStaff) {
                $_SESSION['applied'] = ['name' => $account['first_name'], 'email' => $account['email'], 'pathway' => $account['pathway']];
                $showTrack = true; // so the tracking page opens with this email filled in
            }
        } else {
            // Applicants get a "confirm your email" code first; HR is told once they type it in.
            // Staff adding someone vouch for the address, so it goes straight to the approval queue.
            $outcome = mentora_process_new_application($pdo, $result['id'], $account['first_name'], $account['last_name'], $account['email'], $account['pathway'], $isStaff);
            if ($isStaff) {
                // Staff adding someone: it joins the approval queue; clear the form for the next one.
                $created = $account;
                $account = ['first_name' => '', 'last_name' => '', 'email' => '', 'pathway' => $created['pathway']];
            } else {
                $_SESSION['applied'] = [
                    'user_id' => $result['id'], 'name' => $account['first_name'], 'email' => $account['email'],
                    'pathway' => $account['pathway'], 'emailed' => $outcome['emailed'], 'verify_sent' => $outcome['verify_sent'],
                ];
                mentora_redirect('auth/signup.php?applied=1');
            }
        }
    }
}

$pageTitle = $applied ? 'Application sent | Mentora' : 'Apply to Mentora';
$bodyClass = 'account-page';
$headerStyle = 'signin-page';
$h = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
// aria-invalid + the server's message under a field (the live checks in assets/js/forms.js reuse the same slot).
$invalid = static fn(string $field): string => isset($fieldErrors[$field]) ? ' aria-invalid="true"' : '';
$fieldError = static fn(string $field): string => '<small class="field-error" id="' . $field . '-error" role="alert">'
    . (isset($fieldErrors[$field]) ? $h($fieldErrors[$field]) : '') . '</small>';
require __DIR__ . '/../includes/header.php';
?>
<main class="auth-shell account-shell">
    <?php if ($applied && !empty($applied['verify_sent'])): ?>
        <!-- "Confirm your email" code sent -->
        <section class="auth-card account-card application-status" aria-labelledby="application-status-title">
            <span class="application-status-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" focusable="false">
                    <rect x="3" y="5" width="18" height="14" rx="2"/>
                    <path d="m3 7 9 6 9-6"/>
                </svg>
            </span>
            <h1 id="application-status-title">Confirm your email.</h1>
            <p class="intro">
                Thanks, <?= $h($applied['name']) ?>! We sent a 6-digit code to <strong><?= $h($applied['email']) ?></strong>.
                Type it below to confirm your email and send your application to HR.
            </p>
            <?php if ($resendMessage): ?>
                <div class="alert<?= $resendMessage['ok'] ? ' success' : '' ?>" role="status"><?= $h($resendMessage['text']) ?></div>
            <?php endif; ?>
            <form method="post" class="verify-code-form">
                <?= mentora_csrf_field() ?>
                <input type="hidden" name="action" value="verify_code">
                <div class="field">
                    <label for="code">Confirmation code</label>
                    <input id="code" class="code-input" type="text" name="code" required inputmode="numeric" pattern="[0-9 \-]{6,8}" maxlength="8"
                           autocomplete="one-time-code" placeholder="123456" aria-describedby="code-error"<?= $codeError ? ' aria-invalid="true" autofocus' : '' ?>>
                    <small class="field-error" id="code-error" role="alert"><?= $codeError ? $h($codeError) : '' ?></small>
                </div>
                <div class="form-actions"><button class="button button-primary" type="submit">Confirm email</button></div>
            </form>
            <ol class="application-steps">
                <li class="is-done">
                    <b aria-hidden="true">✓</b>
                    <span><strong>Application sent</strong><small>Your <?= $applied['pathway'] === 'internship' ? 'Intern' : 'Employee VA' ?> application was received.</small></span>
                </li>
                <li class="is-current" aria-current="step">
                    <b aria-hidden="true">2</b>
                    <span><strong>Confirm your email</strong><small>Open the email from Mentora HR and type the code above. It expires in <?= MENTORA_VERIFY_HOURS ?> hours.</small></span>
                </li>
                <li>
                    <b aria-hidden="true">3</b>
                    <span><strong>HR confirms your application</strong><small>An HR admin reviews and approves it.</small></span>
                </li>
                <li>
                    <b aria-hidden="true">4</b>
                    <span><strong>Create your username and password</strong><small>We email you a link once you’re approved.</small></span>
                </li>
            </ol>
            <form method="post" class="notice resend-form">
                <?= mentora_csrf_field() ?>
                <input type="hidden" name="action" value="resend_verification">
                <strong>No email?</strong> Search your inbox, Spam and Promotions for “Mentora confirmation code”, or
                <button class="text-button" type="submit">send a new code</button>.
            </form>
            <div class="auth-links">
                Used the wrong email? <a href="signup.php?new=1">Start a new application</a><br><br>
                <a href="../index.php">Back to home</a>
            </div>
        </section>
    <?php elseif ($applied): ?>
        <!-- Waiting for confirmation (email isn't set up, so HR approves first) -->
        <section class="auth-card account-card application-status" aria-labelledby="application-status-title">
            <span class="application-status-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" focusable="false">
                    <path d="M7 3h10M7 21h10M8 3v3.5a4 4 0 0 0 1.6 3.2L12 11.5l2.4-1.8A4 4 0 0 0 16 6.5V3M8 21v-3.5a4 4 0 0 1 1.6-3.2l2.4-1.8 2.4 1.8a4 4 0 0 1 1.6 3.2V21"/>
                </svg>
            </span>
            <h1 id="application-status-title">Waiting for confirmation.</h1>
            <p class="intro">
                Thanks, <?= $h($applied['name']) ?>! Your <?= $applied['pathway'] === 'internship' ? 'Intern' : 'Employee VA' ?> application
                was sent to the Mentora HR team.
            </p>
            <ol class="application-steps">
                <li class="is-done">
                    <b aria-hidden="true">✓</b>
                    <span><strong>Application sent</strong><small>We have your details.</small></span>
                </li>
                <li class="is-current" aria-current="step">
                    <b aria-hidden="true">2</b>
                    <span><strong>HR confirms your application</strong><small>An HR admin reviews and approves it.</small></span>
                </li>
                <li>
                    <b aria-hidden="true">3</b>
                    <span><strong>Check your email</strong><small>You’ll get a confirmation link at <?= $h($applied['email']) ?>.</small></span>
                </li>
                <li>
                    <b aria-hidden="true">4</b>
                    <span><strong>Create your username and password</strong><small>Then your applicant workspace opens.</small></span>
                </li>
            </ol>
            <div class="notice">
                <?php if (!empty($applied['emailed'])): ?>
                    <strong>We emailed you a confirmation of your application.</strong><br>
                    Your link to create your account comes in a second email once HR approves it. If you can’t find our emails, check your Spam or Promotions folder.
                <?php else: ?>
                    <strong>Keep an eye on your inbox.</strong><br>
                    Your link to create your account is emailed once HR approves your application. If you can’t find it, check your Spam or Promotions folder.
                <?php endif; ?>
            </div>
            <div class="form-actions"><a class="button button-primary" href="track.php">Track your application</a></div>
            <div class="auth-links">
                Used the wrong email? <a href="signup.php?new=1">Start a new application</a><br><br>
                <a href="../index.php">Back to home</a>
            </div>
        </section>
    <?php else: ?>
        <section class="auth-card wide account-card">
            <h1><?= $isStaff ? 'Add an applicant.' : 'Apply to Mentora.' ?></h1>
            <p class="intro">
                <?= $isStaff
                    ? 'The application joins the approval queue. Once an HR admin approves it, they send the applicant a link to create their username and password.'
                    : (mentora_mail_enabled()
                        ? 'Choose your pathway and tell us who you are. We’ll email you a link to confirm your email; once HR approves your application, we’ll email you a link to create your username and password.'
                        : 'Choose your pathway and tell us who you are. Once HR confirms your application, we’ll email you a link to create your username and password.') ?>
            </p>
            <?php if ($created): ?>
                <div class="alert success">
                    Application added for <?= $h($created['first_name'] . ' ' . $created['last_name']) ?> (<?= $h($created['email']) ?>)
                    as <?= $created['pathway'] === 'internship' ? 'an intern' : 'an employee VA' ?>.
                    <?php if ($signedIn['role'] === 'admin'): ?>
                        <a href="../admin/applications.php">Review it in Applications</a>.
                    <?php else: ?>
                        An HR admin will review it.
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <?php if ($errors): ?>
                <div class="alert"><?= $h(implode(' ', array_unique($errors))) ?><?php if (!empty($showTrack)): ?> <a href="track.php">Track your application</a><?php endif; ?></div>
            <?php endif; ?>

            <form method="post" id="signup-form" data-validate>
                <?= mentora_csrf_field() ?>
                <fieldset>
                    <div class="pathway-choice">
                        <label>
                            <input type="radio" name="pathway" value="employee_va" <?= $account['pathway'] === 'employee_va' ? 'checked' : '' ?>>
                            <span><strong>Employee VA</strong></span>
                        </label>
                        <label>
                            <input type="radio" name="pathway" value="internship" <?= $account['pathway'] === 'internship' ? 'checked' : '' ?>>
                            <span><strong>Intern</strong></span>
                        </label>
                    </div>
                    <?= isset($fieldErrors['pathway']) ? $fieldError('pathway') : '' ?>
                </fieldset>

                <fieldset>
                    <div class="form-grid">
                        <div class="field">
                            <label for="first_name">First name *</label>
                            <input id="first_name" name="first_name" required minlength="2" maxlength="80" autocomplete="given-name" placeholder="First Name"
                                   data-check="name" data-label="first name" aria-describedby="first_name-error"<?= $invalid('first_name') ?> value="<?= $h($account['first_name']) ?>">
                            <?= $fieldError('first_name') ?>
                        </div>
                        <div class="field">
                            <label for="last_name">Last name *</label>
                            <input id="last_name" name="last_name" required minlength="2" maxlength="80" autocomplete="family-name" placeholder="Last Name"
                                   data-check="name" data-label="last name" aria-describedby="last_name-error"<?= $invalid('last_name') ?> value="<?= $h($account['last_name']) ?>">
                            <?= $fieldError('last_name') ?>
                        </div>
                        <div class="field full">
                            <label for="email">Email address *</label>
                            <input id="email" type="email" name="email" required maxlength="190" autocomplete="email" placeholder="name@gmail.com"
                                   data-check="email" aria-describedby="email-hint email-error"<?= $invalid('email') ?> value="<?= $h($account['email']) ?>">
                            <small id="email-hint">Your confirmation link will be sent here, so use an email you can open.</small>
                            <?= $fieldError('email') ?>
                        </div>
                    </div>
                </fieldset>

                <label class="check">
                    <input type="checkbox" name="agree" required data-check="agree" aria-describedby="agree-error"<?= $invalid('agree') ?>>
                    I agree to Mentora collecting and using my information to review my application and create my account,
                    as described in the Data Privacy Notice.
                </label>
                <?= $fieldError('agree') ?>
                <div class="form-actions">
                    <button class="button button-primary" type="submit">
                        <?= $isStaff ? 'Add applicant' : 'Submit application' ?>
                    </button>
                </div>
            </form>

            <?php if (!$isStaff): ?>
                <div class="auth-divider"><span>or</span></div>
                <div class="google-auth">
                    <?php // Outside the main form, so it has its own; the chosen pathway is copied into it (see forms.js). ?>
                    <form method="post" action="google.php" id="google-signup">
                        <?= mentora_csrf_field() ?>
                        <input type="hidden" name="pathway" value="<?= $h($account['pathway']) ?>" data-pathway-from="signup-form">
                        <?php mentora_google_button('signup'); ?>
                    </form>
                    <small>With Google, your email is already confirmed. Your application still waits for HR to approve it.</small>
                </div>
                <div class="auth-links">Already have an account? <a href="signin.php">Sign in</a></div>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</main>
<?php require __DIR__ . '/../includes/footer.php'; ?>
