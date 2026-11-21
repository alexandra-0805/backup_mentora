<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth_check.php';
mentora_session_start();
// Show as Offline right away (not only after MENTORA_ONLINE_MINUTES).
if (!empty($_SESSION['user']['id'])) {
    $pdo->prepare('UPDATE users SET last_seen_at = NULL WHERE id = ?')->execute([(int) $_SESSION['user']['id']]);
}
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
}
session_destroy();
header('Location: signin.php');
exit;
