<?php
$headerUserId = (int) ($_SESSION['user']['id'] ?? 0);
$messageCountQuery = $headerUserId
    ? $pdo->prepare('SELECT COUNT(*) FROM messages WHERE (recipient_id = ? OR recipient_id IS NULL) AND read_at IS NULL')
    : $pdo->prepare('SELECT COUNT(*) FROM messages WHERE recipient_id IS NULL AND read_at IS NULL');

if ($headerUserId) {
    $messageCountQuery->execute([$headerUserId]);
} else {
    $messageCountQuery->execute();
}

$unreadMessageCount = (int) $messageCountQuery->fetchColumn();
