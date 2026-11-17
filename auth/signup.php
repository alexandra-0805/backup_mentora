<?php
require_once __DIR__ . '/../config/db.php';

$errors = [];
$values = [
    'first_name' => '',
    'last_name' => '',
    'email' => '',
    'pathway' => $_GET['pathway'] ?? 'employee_va',
    'mobile' => '',
    'location' => '',
    'school' => '',
    'course' => '',
    'required_hours' => '480',
    'desired_role' => '',
    'start_date' => '',
    'interest' => 'Customer Success',
    'skills' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($values as $key => $_) {
        $values[$key] = trim((string) ($_POST[$key] ?? ''));
    }

    $password = (string) ($_POST['password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

    if ($values['first_name'] === '' || $values['last_name'] === '') {
        $errors[] = 'Please enter your full name.';
    }
    if (!filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }
    if (!in_array($values['pathway'], ['employee_va', 'internship'], true)) {
        $errors[] = 'Please choose a valid pathway.';
    }
    if ($values['pathway'] === 'employee_va' && $values['desired_role'] === '') {
        $errors[] = 'Please enter your desired employee VA role.';
    }
    if ($values['pathway'] === 'internship'
        && ($values['school'] === '' || $values['course'] === '')) {
        $errors[] = 'Please enter your school and course.';
    }
    if (strlen($password) < 8
        || !preg_match('/\d/', $password)
        || !preg_match('/[^a-zA-Z\d]/', $password)) {
        $errors[] = 'Password must be at least 8 characters and include a number and symbol.';
    }
    if ($password !== $confirmPassword) {
        $errors[] = 'Passwords do not match.';
    }
    if (!isset($_POST['agree'])) {
        $errors[] = 'Please confirm that your information is accurate and accept the privacy notice.';
    }

    if (!$errors) {
        $check = $pdo->prepare('SELECT id FROM users WHERE email = ?');
        $check->execute([$values['email']]);
        if ($check->fetch()) {
            $errors[] = 'An account with that email already exists.';
        }
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();
            $insertUser = $pdo->prepare(
                'INSERT INTO users (first_name, last_name, email, pathway, password_hash)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $insertUser->execute([
                $values['first_name'],
                $values['last_name'],
                $values['email'],
                $values['pathway'],
                password_hash($password, PASSWORD_DEFAULT),
            ]);
            $userId = (int) $pdo->lastInsertId();

            $insertApplication = $pdo->prepare(
                'INSERT INTO applications
                    (user_id, mobile, location, school, course, required_hours,
                     desired_role, start_date, interest, skills)
                 VALUES (?, ?, ?, ?, ?, ?, ?, NULLIF(?, ""), NULLIF(?, ""), ?)'
            );
            $insertApplication->execute([
                $userId,
                $values['mobile'] ?: null,
                $values['location'] ?: null,
                $values['school'] ?: null,
                $values['course'] ?: null,
                $values['required_hours'] !== '' ? (int) $values['required_hours'] : null,
                $values['desired_role'] ?: null,
                $values['start_date'],
                $values['interest'],
                $values['skills'] ?: null,
            ]);
            $pdo->commit();

            $approvalEmail = getenv('MENTORA_APPROVAL_EMAIL') ?: '';
            if (filter_var($approvalEmail, FILTER_VALIDATE_EMAIL)) {
                $subject = 'Mentora application pending approval: '
                    . $values['first_name'] . ' ' . $values['last_name'];
                $message = implode("\n", [
                    'A new Mentora application is pending approval.',
                    '',
                    'Applicant: ' . $values['first_name'] . ' ' . $values['last_name'],
                    'Email: ' . $values['email'],
                    'Pathway: ' . $values['pathway'],
                    'Review it in the Mentora admin workspace.',
                ]);
                $headers = 'From: no-reply@mentora.ph';
                if (!mail($approvalEmail, $subject, $message, $headers)) {
                    error_log('Mentora approval email could not be sent.');
                }
            } else {
                error_log('Mentora approval email is not configured. Set MENTORA_APPROVAL_EMAIL.');
            }

            header('Location: ../index.php?submitted=1');
            exit;
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Mentora application creation failed: ' . $exception->getMessage());
            $errors[] = 'We could not submit your application right now. Please try again.';
        }
    }
}

$pageTitle = 'Sign up';
$bodyClass = 'account-page';
$headerStyle = 'signin-page';
require __DIR__ . '/../includes/header.php';
?>
<main class="auth-shell account-shell">
    <section class="auth-card wide account-card">
        <p class="eyebrow">One page · three simple steps</p>
        <h1>Create your Mentora account.</h1>
        <p class="intro">
            Complete your account and application together. We’ll review your details before approval.
        </p>

        <div class="account-steps" aria-label="Application steps">
            <span><b>1</b>Your details</span>
            <span><b>2</b>Your pathway</span>
            <span><b>3</b>Secure account</span>
        </div>

        <?php if ($errors): ?>
            <div class="alert"><?= htmlspecialchars(implode(' ', $errors), ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <form method="post">
            <fieldset>
                <legend>1. Tell us about yourself</legend>
                <div class="form-grid">
                    <div class="field">
                        <label for="first_name">First name *</label>
                        <input id="first_name" name="first_name" required value="<?= htmlspecialchars($values['first_name']) ?>">
                    </div>
                    <div class="field">
                        <label for="last_name">Last name *</label>
                        <input id="last_name" name="last_name" required value="<?= htmlspecialchars($values['last_name']) ?>">
                    </div>
                    <div class="field">
                        <label for="email">Email address *</label>
                        <input id="email" type="email" name="email" required value="<?= htmlspecialchars($values['email']) ?>">
                    </div>
                    <div class="field">
                        <label for="mobile">Mobile number</label>
                        <input id="mobile" name="mobile" value="<?= htmlspecialchars($values['mobile']) ?>">
                    </div>
                    <div class="field full">
                        <label for="location">City / province</label>
                        <input id="location" name="location" value="<?= htmlspecialchars($values['location']) ?>" placeholder="Where are you based?">
                    </div>
                </div>
            </fieldset>

            <fieldset>
                <legend>2. Choose your pathway</legend>
                <div class="pathway-choice">
                    <label>
                        <input type="radio" name="pathway" value="employee_va" <?= $values['pathway'] === 'employee_va' ? 'checked' : '' ?>>
                        Employee VA
                    </label>
                    <label>
                        <input type="radio" name="pathway" value="internship" <?= $values['pathway'] === 'internship' ? 'checked' : '' ?>>
                        Internship
                    </label>
                </div>
                <div class="form-grid account-pathway-fields">
                    <div class="field employee-field">
                        <label for="desired_role">Desired employee VA role</label>
                        <input id="desired_role" name="desired_role" value="<?= htmlspecialchars($values['desired_role']) ?>" placeholder="e.g. Customer support">
                    </div>
                    <div class="field intern-field">
                        <label for="school">School / university</label>
                        <input id="school" name="school" value="<?= htmlspecialchars($values['school']) ?>">
                    </div>
                    <div class="field intern-field">
                        <label for="course">Course / program</label>
                        <input id="course" name="course" value="<?= htmlspecialchars($values['course']) ?>">
                    </div>
                    <div class="field intern-field">
                        <label for="required_hours">Required internship hours</label>
                        <input id="required_hours" type="number" min="1" name="required_hours" value="<?= htmlspecialchars($values['required_hours']) ?>">
                    </div>
                    <div class="field">
                        <label for="start_date">Preferred start date</label>
                        <input id="start_date" type="date" name="start_date" value="<?= htmlspecialchars($values['start_date']) ?>">
                    </div>
                    <div class="field intern-field">
                        <label for="interest">Area of interest</label>
                        <select id="interest" name="interest">
                            <?php foreach (['Customer Success', 'Operations', 'Sales'] as $interest): ?>
                                <option <?= $values['interest'] === $interest ? 'selected' : '' ?>><?= $interest ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field full">
                        <label for="skills">Skills and background</label>
                        <textarea id="skills" name="skills" rows="4" placeholder="Tell us about your skills, tools, education, or experience."><?= htmlspecialchars($values['skills']) ?></textarea>
                    </div>
                </div>
            </fieldset>

            <fieldset>
                <legend>3. Secure your account</legend>
                <div class="form-grid">
                    <div class="field">
                        <label for="password">Password *</label>
                        <input id="password" type="password" name="password" required>
                        <small>At least 8 characters, including a number and symbol.</small>
                    </div>
                    <div class="field">
                        <label for="confirm_password">Confirm password *</label>
                        <input id="confirm_password" type="password" name="confirm_password" required>
                    </div>
                </div>
            </fieldset>

            <label class="check">
                <input type="checkbox" name="agree" required>
                I confirm the information is accurate and acknowledge the Data Privacy Notice.
            </label>
            <div class="form-actions">
                <button class="button button-primary" type="submit">Submit for approval <span>→</span></button>
            </div>
        </form>
        <div class="auth-links">Already have an account? <a href="signin.php">Sign in ↗</a></div>
    </section>
</main>
<script>
    const pathwayInputs = document.querySelectorAll('input[name="pathway"]');
    const updatePathwayFields = () => {
        const selected = document.querySelector('input[name="pathway"]:checked')?.value;
        document.querySelectorAll('.employee-field').forEach((field) => {
            field.hidden = selected !== 'employee_va';
        });
        document.querySelectorAll('.intern-field').forEach((field) => {
            field.hidden = selected !== 'internship';
        });
    };
    pathwayInputs.forEach((input) => input.addEventListener('change', updatePathwayFields));
    updatePathwayFields();
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
