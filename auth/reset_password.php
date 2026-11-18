<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth_check.php';

$error = '';
$updated = false;
$email = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? trim(mentora_post_string('email'))
    : trim(is_string($_GET['email'] ?? null) ? $_GET['email'] : '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code = trim(mentora_post_string('code'));
    $password = mentora_post_string('password');
    $confirmation = mentora_post_string('password_confirmation');

    if (!mentora_csrf_valid($_POST['csrf_token'] ?? null)) {
        $error = 'Your session expired. Please try again.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (!preg_match('/^\d{6}$/', $code)) {
        $error = 'Please enter the 6-digit verification code.';
    } elseif ($passwordError = mentora_password_error($password)) {
        $error = $passwordError;
    } elseif ($password !== $confirmation) {
        $error = 'The passwords do not match.';
    } else {
        $query = $pdo->prepare(
            'SELECT password_resets.id, password_resets.user_id, password_resets.token_hash
             FROM password_resets
             INNER JOIN users ON users.id = password_resets.user_id
             WHERE users.email = ? AND password_resets.used_at IS NULL AND password_resets.expires_at > NOW()
             ORDER BY password_resets.created_at DESC
             LIMIT 1'
        );
        $query->execute([$email]);
        $reset = $query->fetch();

        if (!$reset) {
            $error = 'That verification code is invalid or has expired.';
        } elseif (!hash_equals($reset['token_hash'], mentora_reset_code_hash((int) $reset['user_id'], $code))) {
            // A 6-digit code can be brute-forced, so a wrong guess cancels it.
            $pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE id = ?')->execute([$reset['id']]);
            $error = 'That verification code is incorrect. For your security it has been cancelled — please request a new code.';
        } else {
            try {
                $pdo->beginTransaction();
                $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                    ->execute([password_hash($password, PASSWORD_DEFAULT), $reset['user_id']]);
                $pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL')
                    ->execute([$reset['user_id']]);
                $pdo->commit();
                $updated = true;
            } catch (PDOException $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('Mentora password reset failed: ' . $exception->getMessage());
                $error = 'We could not change your password right now. Please try again.';
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
        <div class="form-actions"><a class="button button-primary" href="signin.php">Back to sign in</a></div>
    <?php else: ?>
        <?php if ($error): ?><div class="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
        <form method="post" data-validate>
            <?= mentora_csrf_field() ?>
            <div class="field"><label for="email">Email address</label><input id="email" type="email" name="email" required placeholder="name@gmail.com" data-check="email" aria-describedby="email-error" value="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>"><small class="field-error" id="email-error" role="alert"></small></div>
            <div class="field"><label for="code">Verification code</label><input id="code" type="text" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required placeholder="Enter 6-digit code"></div>
            <div class="field">
                <label for="password">New password</label>
                <input id="password" type="password" name="password" minlength="8" maxlength="64" required autocomplete="new-password" placeholder="Create a strong password" data-check="password" aria-describedby="password-rules password-error">
                <ul class="password-rules" id="password-rules" aria-label="Password requirements">
                    <?php foreach (array_keys(mentora_password_checks('')) as $n => $rule): ?>
                        <li data-rule="<?= $n ?>"><?= htmlspecialchars(ucfirst($rule), ENT_QUOTES, 'UTF-8') ?></li>
                    <?php endforeach; ?>
                </ul>
                <small class="field-error" id="password-error" role="alert"></small>
            </div>
            <div class="field">
                <label for="password_confirmation">Confirm new password</label>
                <input id="password_confirmation" type="password" name="password_confirmation" maxlength="64" required autocomplete="new-password" placeholder="Enter your new password again" data-check="match" data-match="password" aria-describedby="password_confirmation-error">
                <small class="field-error" id="password_confirmation-error" role="alert"></small>
            </div>
            <div class="form-actions"><button class="button button-primary" type="submit">Change password</button></div>
        </form>
    <?php endif; ?>
    <div class="auth-links"><a href="signin.php">Back to sign in</a><br><br>Need help? support@mentora.ph</div>
</section></main>
<?php require __DIR__ . '/../includes/footer.php'; ?>
