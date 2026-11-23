<?php

/**
 * "Email confirmed" page. Reached two ways:
 *   - after typing the 6-digit code from the email (auth/signup.php or auth/track.php put the applicant in the session);
 *   - from a "Confirm my email" link in emails sent before codes replaced links (?token=...).
 * Either one marks the email confirmed and sends the application to HR (admin/applications.php).
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/account_invites.php';

mentora_session_start();

$token = is_string($_GET['token'] ?? null) ? $_GET['token'] : '';
$applicant = $token === '' && is_array($_SESSION['email_confirmed'] ?? null)
    ? $_SESSION['email_confirmed']
    : mentora_use_verification_link($pdo, $token);
unset($_SESSION['email_confirmed']);
if ($applicant) {
    unset($_SESSION['applied']);
}

$pageTitle = ($applicant ? 'Email confirmed' : 'Link can’t be used') . ' | Mentora';
$bodyClass = 'account-page';
$headerStyle = 'signin-page';
$h = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
require __DIR__ . '/../includes/header.php';
?>
<main class="auth-shell account-shell">
    <?php if ($applicant): ?>
        <section class="auth-card account-card application-status" aria-labelledby="verify-title">
            <span class="application-status-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" focusable="false"><path d="M20 6 9 17l-5-5"/></svg>
            </span>
            <h1 id="verify-title">Email confirmed.</h1>
            <p class="intro">
                Thanks, <?= $h($applicant['first_name']) ?>! Your <?= $h(mentora_pathway_label($applicant['pathway'])) ?> application
                is now with the Mentora HR team.
            </p>
            <ol class="application-steps">
                <li class="is-done"><b aria-hidden="true">✓</b><span><strong>Application sent</strong><small>We have your details.</small></span></li>
                <li class="is-done"><b aria-hidden="true">✓</b><span><strong>Email confirmed</strong><small><?= $h($applicant['email']) ?></small></span></li>
                <li class="is-current" aria-current="step"><b aria-hidden="true">3</b><span><strong>HR confirms your application</strong><small>An HR admin reviews it, usually within 1–3 working days.</small></span></li>
                <li><b aria-hidden="true">4</b><span><strong>Create your username and password</strong><small>We email you a link to create your account once you’re approved.</small></span></li>
            </ol>
            <div class="form-actions"><a class="button button-primary" href="track.php">Track your application</a></div>
            <div class="auth-links"><a href="../index.php">Back to home</a></div>
        </section>
    <?php else: ?>
        <section class="auth-card account-card application-status" aria-labelledby="verify-title">
            <span class="application-status-icon is-warning" aria-hidden="true">
                <svg viewBox="0 0 24 24" focusable="false">
                    <path d="M12 9v4M12 17h.01"/>
                    <path d="M10.3 3.9 2.4 17.5A2 2 0 0 0 4.1 20.5h15.8a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/>
                </svg>
            </span>
            <h1 id="verify-title">This link can’t be used.</h1>
            <p class="intro">
                It may have expired (links last <?= MENTORA_VERIFY_HOURS ?> hours), been used already, or been replaced by a newer one.
            </p>
            <div class="notice">
                <strong>Already confirmed?</strong> Your application is with HR. <a href="track.php">Track your application</a> to see where it is.<br>
                <strong>Not confirmed yet?</strong> <a href="track.php">Track your application</a> with your email, then type your code or choose “Email me a new code”.
            </div>
            <div class="auth-links"><a href="../index.php">Back to home</a></div>
        </section>
    <?php endif; ?>
</main>
<?php require __DIR__ . '/../includes/footer.php'; ?>
