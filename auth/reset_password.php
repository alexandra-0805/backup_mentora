<?php
require_once __DIR__ . '/../config/db.php';

$error = '';
$updated = false;
$email = trim((string) ($_POST['email'] ?? $_GET['email'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code = trim((string) ($_POST['code'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $confirmation = (string) ($_POST['password_confirmation'] ?? '');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (!preg_match('/^\d{6}$/', $code)) {
        $error = 'Please enter the 6-digit verification code.';
    } elseif (strlen($password) < 8) {
        $error = 'Your new password must be at least 8 characters.';
    } elseif ($password !== $confirmation) {
        $error = 'The passwords do not match.';
    } else {
        $query = $pdo->prepare(
            'SELECT password_resets.id, password_resets.token_hash
             FROM password_resets
             INNER JOIN users ON users.id = password_resets.user_id
             WHERE users.email = ? AND password_resets.used_at IS NULL AND password_resets.expires_at > NOW()
             ORDER BY password_resets.created_at DESC
             LIMIT 1'
        );
        $query->execute([$email]);
        $reset = $query->fetch();

        if (!$reset || !hash_equals($reset['token_hash'], hash('sha256', $code))) {
            $error = 'That verification code is invalid or has expired.';
        } else {
            $userQuery = $pdo->prepare('SELECT id FROM users WHERE email = ?');
            $userQuery->execute([$email]);
            $user = $userQuery->fetch();

            if (!$user) {
                $error = 'We could not complete the password reset. Please request a new code.';
            } else {
                $update = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
                $update->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
                $pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE id = ?')->execute([$reset['id']]);
                $updated = true;
            }
        }
    }
}

$pageTitle = 'Change password';
$headerStyle = 'signin-page';
require __DIR__ . '/../includes/header.php';
?>
<main class="auth-shell"><section class="auth-card">
    <p class="eyebrow">Your Mentora workspace</p><h1>Change your password.</h1><p class="intro">Enter the verification code sent to your email, then choose a new password for your account.</p>
    <?php if ($updated): ?>
        <div class="alert success">Your password has been changed. You can now sign in.</div>
        <div class="form-actions"><a class="button button-primary" href="signin.php">Back to sign in <span>→</span></a></div>
    <?php else: ?>
        <?php if ($error): ?><div class="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
        <form method="post">
            <div class="field"><label for="email">Email address</label><input id="email" type="email" name="email" required placeholder="you@example.com" value="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>"></div>
            <div class="field"><label for="code">Verification code</label><input id="code" type="text" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required placeholder="Enter 6-digit code"></div>
            <div class="field"><label for="password">New password</label><input id="password" type="password" name="password" minlength="8" required placeholder="At least 8 characters"></div>
            <div class="field"><label for="password_confirmation">Confirm new password</label><input id="password_confirmation" type="password" name="password_confirmation" minlength="8" required placeholder="Enter your new password again"></div>
            <div class="form-actions"><button class="button button-primary" type="submit">Change password <span>→</span></button></div>
        </form>
    <?php endif; ?>
    <div class="auth-links"><a href="signin.php">Back to sign in ↗</a><br><br>Need help? support@mentora.ph</div>
</section></main>
<?php require __DIR__ . '/../includes/footer.php'; ?>
