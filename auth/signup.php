<?php
/**
 * One-page sign-up: account + application form together.
 * Landing page -> this page -> intern workspace (Internship) or employee workspace (Employee VA).
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/application_fields.php';

$signedIn = mentora_current_user();
$isStaff = $signedIn !== null && in_array($signedIn['role'], ['admin', 'agent'], true);

// Applicants who already have an account continue where they left off.
// Admins and agents may stay: they use this page to add people, without being signed out.
if ($signedIn !== null && !$isStaff) {
    mentora_redirect(mentora_after_sign_in_path($pdo, $signedIn));
}

$errors = [];
$created = null;
$newUserId = 0;
$account = [
    'first_name' => '',
    'last_name' => '',
    'email' => '',
    'pathway' => (($_GET['pathway'] ?? '') === 'internship') ? 'internship' : 'employee_va',
];
$answers = mentora_application_values();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($account as $key => $_) {
        $account[$key] = trim(mentora_post_string($key));
    }
    $answers = mentora_application_from_post($answers);
    $password = mentora_post_string('password');
    $confirmPassword = mentora_post_string('confirm_password');

    if (!mentora_csrf_valid($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please submit the form again.';
    }
    if ($account['first_name'] === '' || $account['last_name'] === '') {
        $errors[] = 'Please enter your full name.';
    } elseif (mb_strlen($account['first_name']) > 80 || mb_strlen($account['last_name']) > 80) {
        $errors[] = 'First and last name must be 80 characters or fewer.';
    }
    if (!filter_var($account['email'], FILTER_VALIDATE_EMAIL) || mb_strlen($account['email']) > 190) {
        $errors[] = 'Please enter a valid email address.';
    }
    if (!in_array($account['pathway'], ['employee_va', 'internship'], true)) {
        $account['pathway'] = 'employee_va';
        $errors[] = 'Please choose Employee VA or Internship.';
    } else {
        array_push($errors, ...mentora_application_errors($answers, $account['pathway']));
    }
    if ($passwordError = mentora_password_error($password)) {
        $errors[] = $passwordError;
    }
    if ($password !== $confirmPassword) {
        $errors[] = 'Passwords do not match.';
    }
    if (!isset($_POST['agree'])) {
        $errors[] = 'Please confirm that your information is accurate and accept the Data Privacy Notice.';
    }

    if (!$errors) {
        $check = $pdo->prepare('SELECT id FROM users WHERE email = ?');
        $check->execute([$account['email']]);
        if ($check->fetch()) {
            $errors[] = 'An account with that email already exists. Please sign in instead.';
        }
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();
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
            mentora_save_application($pdo, $newUserId, mentora_application_row($answers, $account['pathway']));
            $pdo->commit();
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Mentora sign-up failed: ' . $exception->getMessage());
            $errors[] = 'We could not submit your application right now. Please try again.';
        }
    }

    if (!$errors) {
        $fullName = $account['first_name'] . ' ' . $account['last_name'];
        mentora_notify_new_application($fullName, $account['email'], $account['pathway']);

        if ($isStaff) {
            // Staff adding someone: keep the staff member signed in and clear the form.
            $created = $account;
            $account = ['first_name' => '', 'last_name' => '', 'email' => '', 'pathway' => $created['pathway']];
            $answers = mentora_application_values();
        } else {
            // New applicant: sign them in and open their pathway's workspace.
            $row = $pdo->prepare('SELECT id, user_code, first_name, last_name, email, pathway, password_hash, role FROM users WHERE id = ?');
            $row->execute([$newUserId]);
            mentora_sign_in($row->fetch());
            $_SESSION['flash_notice'] = 'Application submitted. Welcome to your '
                . ($account['pathway'] === 'internship' ? 'internship' : 'employee') . ' workspace — we’ll review your details and keep you updated here.';
            mentora_redirect(mentora_home_path($_SESSION['user']));
        }
    }
}

$pageTitle = 'Apply to Mentora';
$bodyClass = 'account-page';
$headerStyle = 'signin-page';
$h = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
require __DIR__ . '/../includes/header.php';
?>
<main class="auth-shell account-shell">
    <section class="auth-card wide account-card">
        <p class="eyebrow">One page · three simple steps</p>
        <h1>Apply to Mentora.</h1>
        <p class="intro">
            Create your account and complete your application in one go. When you submit,
            you’ll go straight to your internship or employee workspace.
        </p>

        <div class="account-steps" aria-label="Application steps">
            <span><b>1</b>Your details</span>
            <span><b>2</b>Your pathway</span>
            <span><b>3</b>Secure account</span>
        </div>

        <?php if ($created): ?>
            <div class="alert success">
                Account and application created for <?= $h($created['first_name'] . ' ' . $created['last_name']) ?> (<?= $h($created['email']) ?>).
            </div>
        <?php endif; ?>
        <?php if ($errors): ?>
            <div class="alert"><?= $h(implode(' ', $errors)) ?></div>
        <?php endif; ?>

        <form method="post" id="apply-form">
            <?= mentora_csrf_field() ?>
            <fieldset>
                <legend>1. Tell us about yourself</legend>
                <div class="form-grid">
                    <div class="field">
                        <label for="first_name">First name *</label>
                        <input id="first_name" name="first_name" required maxlength="80" autocomplete="given-name" value="<?= $h($account['first_name']) ?>">
                    </div>
                    <div class="field">
                        <label for="last_name">Last name *</label>
                        <input id="last_name" name="last_name" required maxlength="80" autocomplete="family-name" value="<?= $h($account['last_name']) ?>">
                    </div>
                    <div class="field full">
                        <label for="email">Email address *</label>
                        <input id="email" type="email" name="email" required maxlength="190" autocomplete="email" value="<?= $h($account['email']) ?>">
                    </div>
                </div>
            </fieldset>

            <fieldset>
                <legend>2. Choose your pathway</legend>
                <div class="pathway-choice">
                    <label>
                        <input type="radio" name="pathway" value="employee_va" <?= $account['pathway'] === 'employee_va' ? 'checked' : '' ?>>
                        Employee VA
                    </label>
                    <label>
                        <input type="radio" name="pathway" value="internship" <?= $account['pathway'] === 'internship' ? 'checked' : '' ?>>
                        Internship
                    </label>
                </div>
                <?php mentora_render_application_fields($answers, $account['pathway'], true); ?>
            </fieldset>

            <fieldset>
                <legend>3. Secure your account</legend>
                <div class="form-grid">
                    <div class="field">
                        <label for="password">Password *</label>
                        <input id="password" type="password" name="password" required minlength="8" autocomplete="new-password">
                        <small>At least 8 characters, including a number and symbol.</small>
                    </div>
                    <div class="field">
                        <label for="confirm_password">Confirm password *</label>
                        <input id="confirm_password" type="password" name="confirm_password" required minlength="8" autocomplete="new-password">
                    </div>
                </div>
            </fieldset>

            <label class="check">
                <input type="checkbox" name="agree" required>
                I confirm the information is accurate and acknowledge the Data Privacy Notice.
            </label>
            <div class="form-actions">
                <button class="button button-primary" type="submit">
                    <?= $isStaff ? 'Create account' : 'Submit &amp; open my workspace' ?> <span>→</span>
                </button>
            </div>
        </form>
        <div class="auth-links">Already have an account? <a href="signin.php">Sign in ↗</a></div>
    </section>
</main>
<script>
    // Show only the questions for the selected pathway. Hidden questions are disabled,
    // so they are neither required nor submitted.
    (() => {
        const form = document.getElementById('apply-form');
        const sync = () => {
            const selected = form.querySelector('input[name="pathway"]:checked')?.value;
            form.querySelectorAll('[data-pathway]').forEach((group) => {
                const active = group.dataset.pathway === selected;
                group.hidden = !active;
                group.querySelectorAll('input, select, textarea').forEach((input) => { input.disabled = !active; });
            });
        };
        form.querySelectorAll('input[name="pathway"]').forEach((input) => input.addEventListener('change', sync));
        sync();
    })();
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
