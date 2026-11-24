<?php

/**
 * The bell panel's actions (includes/notification_panel.php):
 *   GET  ?id=12                       open one notification: mark it read, go to the page it is about
 *   POST mark_notifications_done=1    "Mark as done": mark all of them read, go back to the page you were on
 * Only your own notifications (or ones for everyone), and only pages inside Mentora.
 *
 * "Mark as done" is handled here (not on the page you're on), so pages with their own forms never
 * mistake it for theirs and answer "Your session expired".
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth_check.php';

$user = mentora_require_login();
$userId = (int) $user['id'];
$home = mentora_home_path($user);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($_SESSION['notification_action_token'] ?? '');
    $valid = isset($_POST['mark_notifications_done']) && $token !== '' && hash_equals($token, (string) ($_POST['notification_action_token'] ?? ''));
    if ($valid) {
        $pdo->prepare('UPDATE notifications SET read_at = NOW() WHERE (user_id = ? OR user_id IS NULL) AND read_at IS NULL')->execute([$userId]);
    }

    // The bell panel sends "Mark as done" in the background (Accept: application/json) and updates itself.
    if (str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')) {
        header('Content-Type: application/json');
        http_response_code($valid ? 200 : 400);
        echo json_encode(['ok' => $valid]);
        exit;
    }

    // Back to the page the panel was opened on: a path on this site only (no other sites, no line breaks).
    $back = (string) ($_POST['back'] ?? '');
    if (preg_match('~^/(?![/\\\\])[^\r\n\\\\]*$~', $back) === 1) {
        header('Location: ' . $back);
        exit;
    }
    mentora_redirect($home);
}

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$id) {
    mentora_redirect($home);
}

$query = $pdo->prepare('SELECT id, user_id, link FROM notifications WHERE id = ? AND (user_id = ? OR user_id IS NULL)');
$query->execute([$id, $userId]);
$notification = $query->fetch();
if (!$notification) {
    mentora_redirect($home);
}

// Personal notifications are marked read (ones for everyone keep the existing "Mark as done" behaviour).
if ($notification['user_id'] !== null) {
    $pdo->prepare('UPDATE notifications SET read_at = NOW() WHERE id = ? AND read_at IS NULL')->execute([$id]);
}

// A page inside Mentora only: "folder/page.php" with an optional simple query string and #section; no other sites.
$link = (string) ($notification['link'] ?? '');
$safe = preg_match('~^(?:[a-z0-9_-]+/)*[a-z0-9_&-]+\.php(?:\?[A-Za-z0-9_=&%.-]*)?(?:#[A-Za-z0-9_-]*)?$~i', $link) === 1 && !str_contains($link, '..');

mentora_redirect($safe ? $link : $home);
