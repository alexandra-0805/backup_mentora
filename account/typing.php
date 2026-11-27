<?php

/**
 * "Ana is typing…" in group chats and General (the Messages page, account/messages.php).
 *
 *   GET  ?group_id=3                          who else is typing there right now: {"ok":true,"names":["Ana Cruz"]}
 *   POST action=typing group_id               you're typing (sent at most every few seconds while you type)
 *   POST action=stop   group_id               you sent the message or cleared the box
 * Only people in the group (everyone is in General). POSTs need the Messages page token. Answers are JSON.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/messaging.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

/** Seconds after the last keystroke that someone still shows as typing. */
const MENTORA_TYPING_SECONDS = 6;

$respond = static function (array $data, int $status = 200): never {
    http_response_code($status);
    echo json_encode($data);
    exit;
};

$me = mentora_current_user();
if ($me === null) {
    $respond(['ok' => false, 'error' => 'signed_out'], 401);
}
$userId = (int) $me['id'];
$token = (string) ($_SESSION['message_action_token'] ?? '');
session_write_close(); // checked every couple of seconds: don't hold the session lock

$groupId = (int) ($_REQUEST['group_id'] ?? 0);
if ($groupId <= 0 || !mentora_in_group($pdo, $groupId, $userId)) {
    $respond(['ok' => false, 'error' => 'not_found'], 404);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if ($token === '' || !hash_equals($token, (string) ($_POST['message_action_token'] ?? ''))) {
        $respond(['ok' => false, 'error' => 'session'], 403);
    }
    if (($_POST['action'] ?? '') === 'stop') {
        $pdo->prepare('DELETE FROM chat_typing WHERE group_id = ? AND user_id = ?')->execute([$groupId, $userId]);
    } else {
        $pdo->prepare('INSERT INTO chat_typing (group_id, user_id, typed_at) VALUES (?, ?, NOW())
            ON DUPLICATE KEY UPDATE typed_at = NOW()')->execute([$groupId, $userId]);
    }
    $respond(['ok' => true]);
}

// Old entries go now and then, so the table stays tiny.
if (random_int(1, 20) === 1) {
    $pdo->exec('DELETE FROM chat_typing WHERE typed_at < NOW() - INTERVAL 1 MINUTE');
}
$query = $pdo->prepare('SELECT u.first_name, u.last_name FROM chat_typing t JOIN users u ON u.id = t.user_id
    WHERE t.group_id = ? AND t.user_id <> ? AND t.typed_at >= NOW() - INTERVAL ? SECOND AND u.status = "active"
    ORDER BY t.typed_at DESC LIMIT 5');
$query->execute([$groupId, $userId, MENTORA_TYPING_SECONDS]);
$respond(['ok' => true, 'names' => array_map(
    static fn(array $person): string => trim($person['first_name'] . ' ' . $person['last_name']),
    $query->fetchAll()
)]);
