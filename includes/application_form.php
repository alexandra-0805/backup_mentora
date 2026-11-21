<?php
/**
 * Workspace "Form" page: the qualifications form for the applicant's chosen pathway.
 *   Intern       internship form -> straight to the Training page
 *   Employee VA  fill in the form OR upload a resume -> AI-supported review (ai/resume_review.php), which
 *                notifies HR (and the department whose lacking skills they have)
 * Sign-in sends applicants without a submitted form here (after choosing a pathway, auth/pathway.php).
 * After submitting, they can come back to update it; an employee's update is reviewed again while HR
 * hasn't decided yet.
 *
 * Included by applicant/intern/form.php and applicant/employee/form.php with $pathway set
 * to 'intern' or 'employee'.
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/workflow.php';
require_once __DIR__ . '/application_fields.php';
require_once __DIR__ . '/../ai/bootstrap.php';

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
if (mentora_after_sign_in_path($pdo, $formUser) === 'auth/pathway.php') {
    mentora_redirect('auth/pathway.php'); // joined with a recruitment link: choose Intern or Employee VA first
}
if ($formUser['pathway'] !== $formPathway) {
    // Each applicant has one application: the one for their own pathway.
    mentora_redirect(mentora_form_path($formUser));
}

$existingQuery = $pdo->prepare('SELECT * FROM applications WHERE user_id = ? ORDER BY id DESC LIMIT 1');
$existingQuery->execute([$formUser['id']]);
$application = $existingQuery->fetch() ?: null;
$resume = null;
if (!empty($application['resume_document_id'])) {
    $resumeQuery = $pdo->prepare('SELECT id, original_name, stored_name FROM documents WHERE id = ? AND owner_id = ?');
    $resumeQuery->execute([$application['resume_document_id'], $formUser['id']]);
    $resume = $resumeQuery->fetch() ?: null;
}

$answers = mentora_application_values($application);
$formErrors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $answers = mentora_application_from_post($answers);
    if ($isIntern) {
        $answers['submission_mode'] = 'form';
    }

    if (!mentora_csrf_valid($_POST['csrf_token'] ?? null)) {
        $formErrors[] = 'Your session expired. Please submit the form again.';
    }
    // A resume chosen now (checked here, stored once everything else is valid).
    $upload = $_FILES['resume'] ?? null;
    $newResume = !$isIntern && is_array($upload) && ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    if ($newResume && !in_array(strtolower(pathinfo((string) $upload['name'], PATHINFO_EXTENSION)), MENTORA_RESUME_EXTENSIONS, true)) {
        $formErrors[] = 'Please upload your resume as a PDF, Word (.docx or .doc) or text file.';
    }
    array_push($formErrors, ...mentora_application_errors($answers, $formPathway, $newResume || $resume !== null));
    if (!isset($_POST['confirm'])) {
        $formErrors[] = 'Please confirm that the information you provided is accurate.';
    }

    $stored = null;
    if (!$formErrors && $newResume) {
        $stored = mentora_store_document('resume');
        if (!$stored['ok']) {
            $formErrors[] = $stored['error'];
        }
    }

    if (!$formErrors) {
        try {
            $pdo->beginTransaction();
            $applicationId = mentora_save_application(
                $pdo,
                (int) $formUser['id'],
                mentora_application_row($answers, $formPathway),
                $application ? (int) $application['id'] : null
            );
            if ($stored) {
                $pdo->prepare("INSERT INTO documents (name, owner_id, uploaded_by, category, status, stored_name, original_name, mime, size)
                    VALUES ('Resume', ?, ?, 'Application documents', 'in_review', ?, ?, ?, ?)")
                    ->execute([$formUser['id'], $formUser['id'], $stored['stored_name'], $stored['original_name'], $stored['mime'], $stored['size']]);
                $pdo->prepare('UPDATE applications SET resume_document_id = ? WHERE id = ?')->execute([(int) $pdo->lastInsertId(), $applicationId]);
                if ($resume) { // the new file replaces the old one
                    $pdo->prepare('DELETE FROM documents WHERE id = ?')->execute([$resume['id']]);
                }
            }
            $pdo->commit();
            if ($stored && $resume) {
                mentora_delete_document_file($resume['stored_name']);
            }
        } catch (PDOException $exception) {
            $pdo->rollBack();
            if ($stored) {
                mentora_delete_document_file($stored['stored_name']);
            }
            error_log('Mentora application save failed: ' . $exception->getMessage());
            $formErrors[] = 'We could not save your application right now. Please try again.';
        }
    }

    if (!$formErrors) {
        if ($isIntern) {
            if (!$application) {
                mentora_sync_training($pdo, (int) $formUser['id'], 'internship'); // training starts now
                mentora_notify_reviewers($pdo, mentora_person($pdo, (int) $formUser['id']), 'New intern started training: ' . $formUser['name'], 'They submitted the internship form and went straight to training.');
            }
            $_SESSION['flash_notice'] = $application
                ? 'Your application was updated.'
                : 'Internship form submitted. Your training starts now: open the first module below.';
            mentora_redirect($application ? mentora_home_path($formUser) : 'applicant/intern/training.php');
        }
        // Employee VA: AI-supported review while HR hasn't decided yet. A failure never loses the application.
        $status = $application['status'] ?? 'pending';
        if ($status === 'pending') {
            try {
                mentora_ai_review_application($pdo, (int) $formUser['id']);
            } catch (Throwable $exception) {
                error_log('Mentora AI review failed: ' . $exception->getMessage());
            }
        }
        $_SESSION['flash_notice'] = $application
            ? 'Your application was updated' . ($status === 'pending' ? ' and reviewed again.' : '.')
            : 'Application submitted. Our AI-supported review has summarized it for the Mentora HR team; we’ll keep you updated here.';
        mentora_redirect(mentora_home_path($formUser));
    }
}

$pageTitle = $isIntern ? 'Internship qualifications form' : 'Employee VA qualifications form';
$activePage = 'form.php';
require __DIR__ . '/applicant_layout.php';
?>
<div class="admin-heading">
    <div>
        <h1><?= $isIntern ? 'Internship qualifications' : 'Employee VA qualifications' ?></h1>
        <p><?= $isIntern
            ? 'Tell us about your school, your required hours and what you want to learn. Your training starts as soon as you submit.'
            : 'Fill in your qualifications or upload your resume, so we can match you with the department that needs your skills.' ?></p>
    </div>
    <span class="badge info"><?= $application ? 'Submitted · ' . $e(ucfirst((string) $application['status'])) : 'Last step' ?></span>
</div>

<div class="applicant-banner">
    <?php if ($application): ?>
        <strong>Your qualifications form has been received.</strong>You can update your details below at any time.
    <?php else: ?>
        <strong>Almost done, <?= $e(explode(' ', $formUser['name'])[0]) ?>.</strong>Fill in this form so the Mentora team can review your qualifications. Fields marked with * are required.
        <a href="../../auth/pathway.php">Change pathway</a>
    <?php endif; ?>
</div>

<?php if ($formErrors): ?>
    <div class="alert"><?= $e(implode(' ', $formErrors)) ?></div>
<?php endif; ?>

<form class="pathway-card applicant-form" method="post" enctype="multipart/form-data">
    <?= mentora_csrf_field() ?>
    <input type="hidden" name="MAX_FILE_SIZE" value="<?= MENTORA_DOCUMENT_MAX_BYTES ?>">
    <h3>Your qualifications</h3>
    <?php mentora_render_application_fields($answers, $formPathway, $resume); ?>
    <label><input type="checkbox" name="confirm" required> I confirm the information provided is accurate.</label>
    <div class="form-actions">
        <button class="admin-btn primary" type="submit"
            data-confirm-title="<?= $application ? 'Update your application?' : 'Submit your application?' ?>"
            data-confirm="<?= $application
                ? 'Your qualifications form will be updated with these details.'
                : ($isIntern ? 'Your training starts right after you submit.' : 'Your application is reviewed by AI and sent to the Mentora HR team. You can still update it later.') ?>"><?= $application ? 'Save changes' : 'Submit application' ?></button>
    </div>
</form>
<?php require __DIR__ . '/applicant_footer.php'; ?>
