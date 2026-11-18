<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/google_auth.php';

// Already signed in and just opening this page: go straight to the right workspace.
// A submitted form is always processed, so you can switch to another account without signing out first.
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && ($signedIn = mentora_current_user())) {
    mentora_redirect(mentora_after_sign_in_path($pdo, $signedIn));
}

$error = '';
mentora_session_start();
if (!empty($_SESSION['flash_error']) && is_string($_SESSION['flash_error'])) {
    $error = $_SESSION['flash_error'];   // e.g. from Continue with Google
}
unset($_SESSION['flash_error']);
$email = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim(mentora_post_string('email'));
    $password = mentora_post_string('password');

    if (!mentora_csrf_valid($_POST['csrf_token'] ?? null)) {
        $error = 'Your session expired. Please try signing in again.';
    } else {
        $query = $pdo->prepare('SELECT id, user_code, first_name, last_name, email, pathway, password_hash, role FROM users WHERE email = ?');
        $query->execute([$email]);
        $user = $query->fetch();

        // Verify against a dummy hash when the email is unknown so response time doesn't reveal which emails exist.
        $hash = $user ? $user['password_hash'] : '$2y$10$hvFgy6881nk50qVsp1kxXO6tADTPBwRbpAHIRfc0ZnPPK9ugVm8Ry';
        $passwordOk = password_verify($password, $hash);

        if (!$user || !$passwordOk) {
            $error = 'The email or password you entered is incorrect.';
        } else {
            if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
                $user['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
                $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                    ->execute([$user['password_hash'], $user['id']]);
            }

            mentora_sign_in($user);
            mentora_redirect(mentora_after_sign_in_path($pdo, $_SESSION['user']));
        }
    }
}
$pageTitle = 'Sign in';
$bodyClass = 'signin-page';
require __DIR__ . '/../includes/header.php';
?>
<main class="auth-shell">
    <section class="auth-card">
        <h1>Welcome back.</h1>
        <p class="intro">Sign in to continue your application, connect with your team, and keep your next steps in view.</p>
        <?php if (isset($_GET['registered'])): ?><div class="alert success">Your account is ready. Sign in to continue.</div><?php endif; ?>
        <?php if ($error): ?><div class="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
        <form method="post">
            <?= mentora_csrf_field() ?>
            <div class="field"><label for="email">Email address</label><input id="email" type="email" name="email" required autocomplete="username" placeholder="name@gmail.com" value="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>"></div>
            <div class="field"><label for="password">Password</label><input id="password" type="password" name="password" required autocomplete="current-password" placeholder="Enter your password"></div>
            <div class="form-actions"><button class="button button-primary" type="submit">Sign in</button></div>
            <div class="auth-divider"><span>or</span></div>
            <div class="google-auth"><?php mentora_google_button('signin'); ?></div>
        </form>
        <div class="notice"><strong>One sign-in. The right workspace for you.</strong><br>Your account opens your application, team, or HR workspace with private, role-based access.</div>
        <div class="auth-links"><a href="forgot_password.php">Forgot your password? </a><br><br>New to Mentora? <a href="signup.php">Sign up</a></div>
    </section>
</main>
<?php require __DIR__ . '/../includes/footer.php'; ?>