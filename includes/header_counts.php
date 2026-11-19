<?php
// Unread messages for the workspace top-bar badge. Same rules as account/messages.php:
// direct messages use messages.read_at; announcements (recipient_id NULL) are unread until this
// person has a row in message_reads; messages you sent yourself never count.
$headerUserId = (int) ($_SESSION['user']['id'] ?? 0);
$messageCountQuery = $headerUserId
    ? $pdo->prepare(
        'SELECT COUNT(*) FROM messages m
         WHERE (m.sender_id IS NULL OR m.sender_id <> ?)
           AND (
               (m.recipient_id = ? AND m.read_at IS NULL)
               OR (m.recipient_id IS NULL AND NOT EXISTS (
                   SELECT 1 FROM message_reads r WHERE r.message_id = m.id AND r.user_id = ?
               ))
           )'
    )
    : $pdo->prepare('SELECT COUNT(*) FROM messages WHERE recipient_id IS NULL AND read_at IS NULL');

if ($headerUserId) {
    $messageCountQuery->execute([$headerUserId, $headerUserId, $headerUserId]);
} else {
    $messageCountQuery->execute();
}

$unreadMessageCount = (int) $messageCountQuery->fetchColumn();
