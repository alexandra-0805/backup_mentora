<?php
/**
 * Workspace "Form" page: view and update your application.
 * New applicants fill in the same questions on the one-page sign-up (auth/signup.php);
 * this page also lets an applicant without an application yet (e.g. an account added by
 * hand in phpMyAdmin) complete it before using their workspace.
 *
 * Included by applicant/intern/form.php and applicant/employee/form.php with $pathway set
 * to 'intern' or 'employee'.
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/application_fields.php';

$pathway = ($pathway ?? 'employee') === 'intern' ? 'intern' : 'employee';
$isIntern = $pathway === 'intern';
$formPathway = $isIntern ? 'internship' : 'employee_va';

$formUser = mentora_current_user();
if ($formUser === null) {
    // No account yet: apply on the one-page sign-up.
    mentora_redirect('auth/signup.php?pathway=' . $formPathway);
}
if ($formUser['role'] !== 'applicant') {
    mentora_redirect(mentora_home_path($formUser));
}
if ($formUser['pathway'] !== $formPathway) {
    // Each applicant has one application: the one for their own pathway.
    mentora_redirect(mentora_form_path($formUser));
}

$existingQuery = $pdo->prepare('SELECT * FROM applications WHERE user_id = ? ORDER BY id DESC LIMIT 1');
$existingQuery->execute([$formUser['id']]);
$application = $existingQuery->fetch() ?: null;

$answers = mentora_application_values($application);
$formErrors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $answers = mentora_application_from_post($answers);

    if (!mentora_csrf_valid($_POST['csrf_token'] ?? null)) {
        $formErrors[] = 'Your session expired. Please submit the form again.';
    }
    array_push($formErrors, ...mentora_application_errors($answers, $formPathway));
    if (!isset($_POST['confirm'])) {
        $formErrors[] = 'Please confirm that the information you provided is accurate.';
    }

    if (!$formErrors) {
        try {
            mentora_save_application($pdo, (int) $formUser['id'], mentora_application_row($answers, $formPathway), $application ? (int) $application['id'] : null);
            if ($application) {
                $_SESSION['flash_notice'] = 'Your application was updated.';
            } else {
                mentora_notify_new_application($formUser['name'], $formUser['email'], $formPathway);
                $_SESSION['flash_notice'] = 'Application submitted. Welcome to your '
                    . ($isIntern ? 'internship' : 'employee') . ' workspace — we’ll review your details and keep you updated here.';
            }
            mentora_redirect(mentora_home_path($formUser));
        } catch (PDOException $exception) {
            error_log('Mentora application save failed: ' . $exception->getMessage());
            $formErrors[] = 'We could not save your application right now. Please try again.';
        }
    }
}

$pageTitle = $isIntern ? 'Internship application form' : 'Employee VA application form';
$activePage = 'form.php';
require __DIR__ . '/applicant_layout.php';
?>
<div class="admin-heading">
    <div>
        <h1><?= $isIntern ? 'Your internship application' : 'Your employee VA application' ?></h1>
        <p><?= $isIntern
            ? 'Your school requirements, placement interests and availability for supervised work experience.'
            : 'Your strengths, availability and the kind of work you want to do.' ?></p>
    </div>
    <span class="badge info"><?= $application ? 'Submitted · ' . $e(ucfirst((string) $application['status'])) : 'Not submitted yet' ?></span>
</div>

<div class="applicant-banner">
    <?php if ($application): ?>
        <strong>Your application has been received.</strong>You can update your details below at any time.
    <?php else: ?>
        <strong>One more step, <?= $e(explode(' ', $formUser['name'])[0]) ?>.</strong>Complete your application to start using your <?= $isIntern ? 'internship' : 'employee' ?> workspace. Fields marked with * are required.
    <?php endif; ?>
</div>

<?php if ($formErrors): ?>
    <div class="alert"><?= $e(implode(' ', $formErrors)) ?></div>
<?php endif; ?>

<form class="pathway-card applicant-form" method="post">
    <?= mentora_csrf_field() ?>
    <h3>Application details</h3>
    <?php mentora_render_application_fields($answers, $formPathway); ?>
    <label><input type="checkbox" name="confirm" required> I confirm the information provided is accurate.</label>
    <div class="form-actions">
        <button class="admin-btn primary" type="submit"><?= $application ? 'Save changes' : 'Submit application' ?></button>
    </div>
</form>
<?php require __DIR__ . '/applicant_footer.php'; ?>
