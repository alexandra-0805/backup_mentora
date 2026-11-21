<?php

/**
 * Choose Intern or Employee VA (after signing in or creating an account from a recruitment link).
 * Each pathway has its own form: interns go on to training, employees to the AI-reviewed form or resume.
 * The choice can be changed until the form is submitted.
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/recruitment.php';

$user = mentora_require_login(['applicant']);
$submitted = $pdo->prepare('SELECT 1 FROM applications WHERE user_id = ? LIMIT 1');
$submitted->execute([$user['id']]);
if ($submitted->fetchColumn()) {
    mentora_redirect(mentora_home_path($user)); // the pathway is fixed once a form is in
}
$current = $pdo->prepare('SELECT pathway, pathway_chosen_at FROM users WHERE id = ?');
$current->execute([$user['id']]);
$current = $current->fetch();
$chosen = $current['pathway_chosen_at'] !== null ? $current['pathway'] : '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pathway = mentora_post_string('pathway');
    if (!mentora_csrf_valid($_POST['csrf_token'] ?? null)) {
        $error = 'Your session expired. Please try again.';
    } elseif (!in_array($pathway, ['internship', 'employee_va'], true)) {
        $error = 'Please choose Intern or Employee VA.';
    } else {
        // The user code follows the pathway (INT-0042 / EMP-0042) unless HR gave a custom one.
        $pdo->prepare("UPDATE users SET pathway = ?, pathway_chosen_at = NOW(),
                user_code = IF(user_code REGEXP '^(INT|EMP)-', CONCAT(?, SUBSTRING(user_code, 4)), user_code)
            WHERE id = ?")
            ->execute([$pathway, $pathway === 'internship' ? 'INT' : 'EMP', $user['id']]);
        mentora_apply_referral($pdo, (int) $user['id']);
        $_SESSION['flash_notice'] = $pathway === 'internship'
            ? 'Intern pathway chosen. Fill in the internship form, then your training starts right away.'
            : 'Employee VA pathway chosen. Fill in the form or upload your resume for the AI-supported review.';
        mentora_redirect(mentora_form_path(['pathway' => $pathway]));
    }
}

$link = mentora_session_recruitment_link($pdo);
$pageTitle = 'Choose your pathway | Mentora';
$bodyClass = 'account-page';
$headerStyle = 'signin-page';
$h = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
require __DIR__ . '/../includes/header.php';
?>
<main class="auth-shell account-shell">
    <section class="auth-card wide account-card">
        <?php if ($link): ?><p class="eyebrow">Invited by <?= $h($link['agent_name']) ?></p><?php endif; ?>
        <h1>Choose your pathway.</h1>
        <p class="intro">Hi <?= $h(explode(' ', $user['name'])[0]) ?>! Are you applying as an intern or as an employee? Each pathway has its own form.</p>
        <?php if (!empty($_SESSION['flash_notice'])): ?><div class="alert success"><?= $h($_SESSION['flash_notice']) ?></div><?php unset($_SESSION['flash_notice']); endif; ?>
        <?php if ($error): ?><div class="alert"><?= $h($error) ?></div><?php endif; ?>
        <form method="post">
            <?= mentora_csrf_field() ?>
            <fieldset>
                <div class="pathway-choice">
                    <label>
                        <input type="radio" name="pathway" value="internship" required<?= $chosen === 'internship' ? ' checked' : '' ?>>
                        <span><strong>Intern</strong><small>Students completing OJT hours. Fill the internship form, then go straight to training; your agent tracks your progress and assessment.</small></span>
                    </label>
                    <label>
                        <input type="radio" name="pathway" value="employee_va" required<?= $chosen === 'employee_va' ? ' checked' : '' ?>>
                        <span><strong>Employee VA</strong><small>Professionals applying for a VA role. Fill the form or upload your resume; an AI-supported review matches you to the department that needs your skills.</small></span>
                    </label>
                </div>
            </fieldset>
            <div class="form-actions"><button class="button button-primary" type="submit">Continue</button></div>
        </form>
        <div class="auth-links"><a href="signout.php">Sign out</a></div>
    </section>
</main>
<?php require __DIR__ . '/../includes/footer.php'; ?>
