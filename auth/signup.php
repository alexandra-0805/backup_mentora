<?php

/**
 * Sign-up: creates the account only (name, email, pathway, password).
 * Landing page -> this page -> qualifications form for the chosen pathway
 * (applicant/intern/form.php or applicant/employee/form.php) -> intern or employee workspace.
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/google_auth.php';

$signedIn = mentora_current_user();
$isStaff = $signedIn !== null && in_array($signedIn['role'], ['admin', 'agent'], true);

// Applicants who already have an account continue where they left off (form or workspace).
// Admins and agents may stay: they use this page to add people, without being signed out.
if ($signedIn !== null && !$isStaff) {
    mentora_redirect(mentora_after_sign_in_path($pdo, $signedIn));
}

$errors = [];
mentora_session_start();
if (!empty($_SESSION['flash_error']) && is_string($_SESSION['flash_error'])) {
    $errors[] = $_SESSION['flash_error'];   // e.g. from Continue with Google
}
unset($_SESSION['flash_error']);
$created = null;
$account = [
    'first_name' => '',
    'last_name' => '',
    'email' => '',
    'pathway' => (($_GET['pathway'] ?? '') === 'internship') ? 'internship' : 'employee_va',
];

$fieldErrors = [];   // field name => message, shown under that field

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($account as $key => $_) {
        // Trim and collapse repeated spaces, e.g. "Juan   Dela  Cruz" -> "Juan Dela Cruz".
        $account[$key] = preg_replace('/\s+/u', ' ', trim(mentora_post_string($key)));
    }
    $account['email'] = strtolower($account['email']);
    $password = mentora_post_string('password');
    $confirmPassword = mentora_post_string('confirm_password');

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
    $fieldErrors['password'] = mentora_password_error($password);
    if ($confirmPassword === '') {
        $fieldErrors['confirm_password'] = 'Please enter your password again.';
    } elseif ($password !== $confirmPassword) {
        $fieldErrors['confirm_password'] = 'Passwords do not match.';
    }
    if (!isset($_POST['agree'])) {
        $fieldErrors['agree'] = 'Please accept the Data Privacy Notice to create an account.';
    }
    $fieldErrors = array_filter($fieldErrors);

    if (!$errors && !$fieldErrors) {
        $check = $pdo->prepare('SELECT id FROM users WHERE email = ?');
        $check->execute([$account['email']]);
        if ($check->fetch()) {
            $fieldErrors['email'] = 'An account with this email already exists. Please sign in instead.';
        }
    }
    if ($fieldErrors) {
        $errors[] = 'Please fix the highlighted fields.';
    }

    if (!$errors) {
        try {
            $pdo->prepare(
                'INSERT INTO users (first_name, last_name, email, pathway, password_hash)
                 VALUES (?, ?, ?, ?, ?)'
            )->execute([
                $account['first_name'],
                $account['last_name'],
                $account['email'],
                $account['pathway'],
                password_hash($password, PASSWORD_DEFAULT),
            ]);
            $newUserId = (int) $pdo->lastInsertId();
        } catch (PDOException $exception) {
            error_log('Mentora sign-up failed: ' . $exception->getMessage());
            $errors[] = 'We could not create your account right now. Please try again.';
        }
    }

    if (!$errors) {
        if ($isStaff) {
            // Staff adding someone: keep the staff member signed in and clear the form.
            // The new applicant fills in their qualifications form the first time they sign in.
            $created = $account;
            $account = ['first_name' => '', 'last_name' => '', 'email' => '', 'pathway' => $created['pathway']];
        } else {
            // New applicant: sign them in and take them to their pathway's qualifications form.
            $row = $pdo->prepare('SELECT id, user_code, first_name, last_name, email, pathway, password_hash, role FROM users WHERE id = ?');
            $row->execute([$newUserId]);
            mentora_sign_in($row->fetch());
            $_SESSION['flash_notice'] = 'Your account is ready. Next, tell us about your qualifications so we can review your application.';
            mentora_redirect(mentora_form_path($_SESSION['user']));
        }
    }
}

$pageTitle = 'Create your Mentora account';
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
    <section class="auth-card wide account-card">
        <h1><?= $isStaff ? 'Create an applicant account.' : 'Create your account.' ?></h1>
        <?php if ($created): ?>
            <div class="alert success">
                Account created for <?= $h($created['first_name'] . ' ' . $created['last_name']) ?> (<?= $h($created['email']) ?>)
                as <?= $created['pathway'] === 'internship' ? 'an intern' : 'an employee VA' ?>.
            </div>
        <?php endif; ?>
        <?php if ($errors): ?>
            <div class="alert"><?= $h(implode(' ', $errors)) ?></div>
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
                               data-check="email" aria-describedby="email-error"<?= $invalid('email') ?> value="<?= $h($account['email']) ?>">
                        <?= $fieldError('email') ?>
                    </div>
                </div>
            </fieldset>

            <fieldset>
                <div class="form-grid">
                    <div class="field">
                        <label for="password">Password</label>
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
                        <label for="confirm_password">Confirm password</label>
                        <input id="confirm_password" type="password" name="confirm_password" required maxlength="64" autocomplete="new-password"
                               data-check="match" data-match="password" aria-describedby="confirm_password-error"<?= $invalid('confirm_password') ?>>
                        <?= $fieldError('confirm_password') ?>
                    </div>
                </div>
            </fieldset>

            <label class="check">
                <input type="checkbox" name="agree" required data-check="agree" aria-describedby="agree-error"<?= $invalid('agree') ?>>
                I agree to Mentora collecting and using my information to create my account and process my application,
                as described in the Data Privacy Notice.
            </label>
            <?= $fieldError('agree') ?>
            <div class="form-actions">
                <button class="button button-primary" type="submit">
                    <?= $isStaff ? 'Create applicant account' : 'Create account &amp; continue' ?>
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
            </div>
            <div class="auth-links">Already have an account? <a href="signin.php">Sign in</a></div>
        <?php endif; ?>
    </section>

</main>
<?php require __DIR__ . '/../includes/footer.php'; ?>