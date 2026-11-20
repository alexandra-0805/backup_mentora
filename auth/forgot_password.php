<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/mailer.php';
// Start the session before any output: the form's security token (mentora_csrf_field) is stored in it.
mentora_session_start();
$sent = false;
$error = '';
$email = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim(mentora_post_string('email'));
    if (!mentora_csrf_valid($_POST['csrf_token'] ?? null)) {
        $error = 'Your session expired. Please try again.';
    } elseif (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        // Only confirmed accounts: an application waiting for HR can't get a password this way.
        $query = $pdo->prepare("SELECT id FROM users WHERE email = ? AND status = 'active'");
        $query->execute([$email]);
        $user = $query->fetch();
        if ($user) {
            $code = (string) random_int(100000, 999999);
            // Replace this user's previous codes (also frees their hashes for the UNIQUE token_hash column).
            $pdo->prepare('DELETE FROM password_resets WHERE user_id = ?')->execute([$user['id']]);
            $insert = $pdo->prepare('INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 15 MINUTE))');
            $insert->execute([$user['id'], mentora_reset_code_hash((int) $user['id'], $code)]);

            $subject = 'Your Mentora password reset code';
            $message = "Your Mentora password reset code is: {$code}\n\nThis code expires in 15 minutes. If you did not request a password reset, you can ignore this email.";
            // Through the mail account in config/mail.local.php when it's set up; PHP's mail() otherwise.
            if (!mentora_deliver_mail($email, $subject, $message, getenv('MENTORA_FROM_EMAIL') ?: 'no-reply@mentora.ph')) {
                error_log('Mentora password reset email could not be sent for user ' . $user['id'] . '.');
            }
        }
        // Same response whether or not the account exists, so this form can't be used to discover registered emails.
        $sent = true;
    } else {
        $error = 'Please enter a valid email address.';
    }
}
$pageTitle = 'Forgot your password';
$headerStyle = 'signin-page';
require __DIR__ . '/../includes/header.php';
?>
<main class="auth-shell"><section class="auth-card">
    <p class="eyebrow">Your Mentora workspace</p>
    <h1>Forgot your password?</h1>
    <p class="intro">Enter the email address linked to your account. We’ll send you a verification code to change your password.</p>
    <?php if ($sent): ?>
    <div class="alert success">If an account exists for that email, a verification code is on its way. It expires in 15 minutes. <a href="reset_password.php?email=<?= urlencode($email) ?>">Enter the code to change your password.</a>
    </div>
    <?php endif; ?>
    <?php if ($error): ?><div class="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
    <form method="post">
        <?= mentora_csrf_field() ?>
        <div class="field">
            <label for="email">Email address</label><input id="email"
                type="email"
                name="email"
                required
                placeholder="you@example.com"
                value="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>"></div>
        <div class="form-actions"><button class="button button-primary" type="submit">Send verification code <span>→</span></button></div>
    </form>
    <div class="notice">
        <strong>A secure way back to your workspace.</strong><br>If an account exists for this email, you’ll receive a password reset code.</div>
    <div class="auth-links">Remember your password? <a href="signin.php">Back to sign in</a><br><br>Need help? support@mentora.ph</div>
</section></main>
<?php require __DIR__ . '/../includes/footer.php'; ?>