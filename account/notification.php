<?php

/**
 * Opening a notification from the bell panel (includes/notification_panel.php):
 * marks it as read and goes to the page it is about (notifications.link).
 * Only your own notifications (or ones for everyone), and only pages inside Mentora.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth_check.php';

$user = mentora_require_login();
$userId = (int) $user['id'];
$home = mentora_home_path($user);

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
