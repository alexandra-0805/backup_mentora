<?php
session_start();
require_once __DIR__ . '/../config/db.php';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $query = $pdo->prepare('SELECT id, first_name, last_name, email, password_hash, role FROM users WHERE email = ?');
    $query->execute([$email]);
    $user = $query->fetch();
    if (!$user || !password_verify($password, $user['password_hash'])) {
        $error = 'The email or password you entered is incorrect.';
    } else {
        session_regenerate_id(true);
        $_SESSION['user'] = ['id' => $user['id'], 'name' => $user['first_name'] . ' ' . $user['last_name'], 'email' => $user['email'], 'role' => $user['role']];
        header('Location: ../index.php');
        exit;
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
            <div class="field"><label for="email">Email address</label><input id="email" type="email" name="email" required placeholder="you@example.com"></div>
            <div class="field"><label for="password">Password</label><input id="password" type="password" name="password" required placeholder="Enter your password"></div>
            <div class="form-actions"><button class="button button-primary" type="submit">Sign in <span>→</span></button></div>
        </form>
        <div class="notice"><strong>One sign-in. The right workspace for you.</strong><br>Your account opens your application, team, or HR workspace with private, role-based access.</div>
        <div class="auth-links"><a href="forgot_password.php">Forgot your password? </a><br><br>New to Mentora? <a href="signup.php">Sign up ↗</a></div>
    </section>
</main>
<?php require __DIR__ . '/../includes/footer.php'; ?>