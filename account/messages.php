<?php

/**
 * Messages: conversations grouped by person, with replies, read tracking and a responsive layout
 * (desktop: list | conversation | details; tablet: list | conversation; phone: list, then conversation).
 *
 * Data: messages(sender_id, recipient_id NULL = announcement to everyone, subject, body, read_at),
 * message_reads(message_id, user_id) for per-person reads of announcements (see config/db.php).
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/messaging.php';

$me = mentora_require_login();
$userId = (int) $me['id'];
$messageActionToken = $_SESSION['message_action_token'] ?? bin2hex(random_bytes(32));
$_SESSION['message_action_token'] = $messageActionToken;
$messageError = '';
$draftBody = '';
$h = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$tokenValid = static fn(): bool => hash_equals($messageActionToken, (string) ($_POST['message_action_token'] ?? ''));

// ---------- Attachments ----------
const MENTORA_MESSAGE_MAX_FILES = 5;
const MENTORA_MESSAGE_MAX_BYTES = 10 * 1024 * 1024;
// Allowed file extensions => [file contents we accept (finfo), kind]. Office files are zip/OLE inside.
const MENTORA_MESSAGE_FILE_RULES = [
    'jpg' => [['image/jpeg'], 'image'],
    'jpeg' => [['image/jpeg'], 'image'],
    'png' => [['image/png'], 'image'],
    'webp' => [['image/webp'], 'image'],
    'gif' => [['image/gif'], 'image'],
    'pdf' => [['application/pdf'], 'file'],
    'doc' => [['application/msword', 'application/vnd.ms-office', 'application/CDFV2', 'application/x-ole-storage'], 'file'],
    'docx' => [['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'], 'file'],
    'xls' => [['application/vnd.ms-excel', 'application/vnd.ms-office', 'application/CDFV2', 'application/x-ole-storage'], 'file'],
    'xlsx' => [['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'], 'file'],
    'ppt' => [['application/vnd.ms-powerpoint', 'application/vnd.ms-office', 'application/CDFV2', 'application/x-ole-storage'], 'file'],
    'pptx' => [['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip', 'application/octet-stream'], 'file'],
    'txt' => [['text/plain'], 'file'],
    'csv' => [['text/csv', 'text/plain', 'application/csv'], 'file'],
    'zip' => [['application/zip', 'application/x-zip-compressed'], 'file'],
];

// Optional GIF search (config/giphy.local.php or MENTORA_GIPHY_API_KEY; see config/giphy.local.example.php).
$giphyKey = (string) (getenv('MENTORA_GIPHY_API_KEY') ?: '');
if ($giphyKey === '' && is_file(__DIR__ . '/../config/giphy.local.php')) {
    $giphyConfig = require __DIR__ . '/../config/giphy.local.php';
    $giphyKey = is_array($giphyConfig) ? trim((string) ($giphyConfig['api_key'] ?? '')) : '';
}

$formatBytes = static function (int $bytes): string {
    if ($bytes >= 1048576) {
        return round($bytes / 1048576, 1) . ' MB';
    }
    return $bytes >= 1024 ? round($bytes / 1024) . ' KB' : $bytes . ' B';
};

/** The files posted as attachments[] as a simple list (empty file slots skipped). */
$postedFiles = static function (): array {
    $files = $_FILES['attachments'] ?? null;
    if (!is_array($files) || !is_array($files['name'] ?? null)) {
        return [];
    }
    $list = [];
    foreach (array_keys($files['name']) as $index) {
        $error = $files['error'][$index] ?? UPLOAD_ERR_NO_FILE;
        if ($error === UPLOAD_ERR_NO_FILE || !is_int($error) || !is_string($files['tmp_name'][$index] ?? null)) {
            continue;
        }
        $list[] = [
            'name' => (string) $files['name'][$index],
            'tmp' => $files['tmp_name'][$index],
            'error' => $error,
            'size' => (int) ($files['size'][$index] ?? 0),
        ];
    }
    return $list;
};

/** Check one upload; returns [prepared file, null] or [null, error message]. */
$checkFile = static function (array $file) use ($formatBytes): array {
    $label = '"' . mb_substr($file['name'], 0, 60) . '"';
    if (in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || $file['size'] > MENTORA_MESSAGE_MAX_BYTES) {
        return [null, "$label is larger than " . $formatBytes(MENTORA_MESSAGE_MAX_BYTES) . '.'];
    }
    if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp'])) {
        return [null, "$label couldn't be uploaded. Please try again."];
    }
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $rule = MENTORA_MESSAGE_FILE_RULES[$extension] ?? null;
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp']);
    if ($rule === null || !in_array($mime, $rule[0], true)) {
        return [null, "$label isn't a supported file type."];
    }
    $width = $height = null;
    if ($rule[1] === 'image') {
        $size = @getimagesize($file['tmp']);
        if ($size === false || image_type_to_mime_type($size[2]) !== $mime) {
            return [null, "$label couldn't be read as an image."];
        }
        [$width, $height] = [min((int) $size[0], 65535), min((int) $size[1], 65535)];
    }
    $originalName = trim(preg_replace('/[\x00-\x1F\x7F\/\\\\]+/u', '', basename($file['name'])) ?? '');
    return [[
        'tmp' => $file['tmp'],
        'extension' => $extension === 'jpeg' ? 'jpg' : $extension,
        'kind' => $rule[1],
        'mime' => $mime,
        'name' => mb_substr($originalName !== '' ? $originalName : 'attachment.' . $extension, 0, 190),
        'size' => $file['size'],
        'width' => $width,
        'height' => $height,
    ], null];
};

/** A GIF picked from GIPHY: only GIPHY media addresses are accepted. */
$postedGif = static function (): ?array {
    $url = is_string($_POST['gif_url'] ?? null) ? trim($_POST['gif_url']) : '';
    if ($url === '') {
        return null;
    }
    if (strlen($url) > 500 || !preg_match('#^https://(media\d?|i)\.giphy\.com/[A-Za-z0-9._/-]+\.(gif|webp)(\?[A-Za-z0-9=&._-]*)?$#', $url)) {
        return ['error' => true];
    }
    $title = is_string($_POST['gif_title'] ?? null) ? mb_substr(trim($_POST['gif_title']), 0, 190) : '';
    $dimension = static fn(string $key): ?int => is_numeric($_POST[$key] ?? null) ? max(1, min(2000, (int) $_POST[$key])) : null;
    return ['url' => $url, 'title' => $title !== '' ? $title : 'GIF', 'width' => $dimension('gif_width'), 'height' => $dimension('gif_height')];
};

// ---------- Who you can start a conversation with ----------
// HR admins and agents can message everyone; interns and employees can message HR and agents
// (applicants don't see each other's names or emails). Anyone can reply to whoever messaged them.
// Admins can also send an announcement to everyone (recipient_id NULL).
$isAdmin = $me['role'] === 'admin';
$contactRoles = mentora_message_contact_roles($me); // includes/messaging.php (also used for video calls)
$contactQuery = $pdo->prepare(
    'SELECT id, first_name, last_name, email, role, pathway, avatar_path FROM users
     WHERE id <> ? AND status = "active" AND role IN (' . implode(',', array_fill(0, count($contactRoles), '?')) . ')
     ORDER BY FIELD(role, "admin", "agent", "applicant"), pathway, first_name, last_name'
);
$contactQuery->execute([$userId, ...$contactRoles]);
$contacts = [];
foreach ($contactQuery->fetchAll() as $contact) {
    $contacts[(int) $contact['id']] = $contact;
}

/** Can this person send a message to $recipientId? (your contacts, or anyone who wrote to you; includes/messaging.php) */
$canMessage = static fn(int $recipientId): bool => isset($contacts[$recipientId]) || mentora_can_message($pdo, $me, $recipientId);

/** Mark everything you received in one conversation as read. */
$markConversationRead = static function (string $conversation) use ($pdo, $userId): bool {
    if (preg_match('/^u(\d+)$/', $conversation, $match)) {
        $otherId = (int) $match[1];
        $pdo->prepare('UPDATE messages SET read_at = NOW() WHERE recipient_id = ? AND sender_id = ? AND read_at IS NULL')
            ->execute([$userId, $otherId]);
        $pdo->prepare('INSERT IGNORE INTO message_reads (message_id, user_id) SELECT id, ? FROM messages WHERE recipient_id IS NULL AND group_id IS NULL AND sender_id = ?')
            ->execute([$userId, $otherId]);
        return true;
    }
    if (preg_match('/^g(\d+)$/', $conversation, $match)) {
        // Group chat: only one you're in.
        if (!mentora_in_group($pdo, (int) $match[1], $userId)) {
            return false;
        }
        $pdo->prepare('INSERT IGNORE INTO message_reads (message_id, user_id) SELECT id, ? FROM messages WHERE group_id = ? AND (sender_id IS NULL OR sender_id <> ?)')
            ->execute([$userId, (int) $match[1], $userId]);
        return true;
    }
    if ($conversation === 'support') {
        $pdo->prepare('UPDATE messages SET read_at = NOW() WHERE recipient_id = ? AND sender_id IS NULL AND read_at IS NULL')
            ->execute([$userId]);
        $pdo->prepare('INSERT IGNORE INTO message_reads (message_id, user_id) SELECT id, ? FROM messages WHERE recipient_id IS NULL AND group_id IS NULL AND sender_id IS NULL')
            ->execute([$userId]);
        return true;
    }
    return $conversation === 'all'; // your own announcements: nothing to mark
};

// ---------- Background actions from the page (JSON) ----------
//   mark_read            conversation           opened: mark it read
//   archive              conversation archived  archive (1) or bring back (0), for you only
//   delete_conversation  conversation           hide everything in it so far, for you only
//   delete_message       message_id             delete a message you sent, for everyone
//   hide_message         message_id             "Delete for me": any message you can see, gone from your chat only
$backgroundAction = $_SERVER['REQUEST_METHOD'] === 'POST' ? (string) ($_POST['action'] ?? '') : '';
if (in_array($backgroundAction, ['mark_read', 'archive', 'delete_conversation', 'delete_message', 'hide_message'], true)) {
    header('Content-Type: application/json');
    $reply = static function (bool $ok, int $status = 200): never {
        http_response_code($status);
        exit(json_encode(['ok' => $ok]));
    };
    if (!$tokenValid()) {
        $reply(false, 403);
    }

    if ($backgroundAction === 'delete_message') {
        $messageId = (int) ($_POST['message_id'] ?? 0);
        $files = $pdo->prepare('SELECT a.stored_name FROM message_attachments a JOIN messages m ON m.id = a.message_id
            WHERE m.id = ? AND m.sender_id = ? AND a.stored_name IS NOT NULL');
        $files->execute([$messageId, $userId]);
        $storedNames = $files->fetchAll(PDO::FETCH_COLUMN);
        $delete = $pdo->prepare('DELETE FROM messages WHERE id = ? AND sender_id = ?'); // attachments and reads go with it
        $delete->execute([$messageId, $userId]);
        if ($delete->rowCount() !== 1) {
            $reply(false, 404);
        }
        foreach ($storedNames as $storedName) {
            if (preg_match('/^[A-Za-z0-9._-]+$/', (string) $storedName) === 1) {
                @unlink(MENTORA_APP_ROOT . '/uploads/messages/' . $storedName);
            }
        }
        $reply(true);
    }

    if ($backgroundAction === 'hide_message') {
        // Only a message you can see: yours, to you, an announcement, or in a group chat you're in.
        $find = $pdo->prepare('SELECT id, sender_id, recipient_id, group_id FROM messages WHERE id = ?');
        $find->execute([(int) ($_POST['message_id'] ?? 0)]);
        $message = $find->fetch();
        $visible = $message && ($message['group_id'] !== null
            ? mentora_in_group($pdo, (int) $message['group_id'], $userId)
            : ((int) ($message['sender_id'] ?? 0) === $userId || (int) ($message['recipient_id'] ?? 0) === $userId || $message['recipient_id'] === null));
        if (!$visible) {
            $reply(false, 404);
        }
        $pdo->prepare('INSERT IGNORE INTO message_hidden (message_id, user_id) VALUES (?, ?)')->execute([(int) $message['id'], $userId]);
        // A hidden message never counts as unread.
        if ((int) ($message['recipient_id'] ?? 0) === $userId) {
            $pdo->prepare('UPDATE messages SET read_at = NOW() WHERE id = ? AND read_at IS NULL')->execute([(int) $message['id']]);
        } elseif ($message['recipient_id'] === null) {
            $pdo->prepare('INSERT IGNORE INTO message_reads (message_id, user_id) VALUES (?, ?)')->execute([(int) $message['id'], $userId]);
        }
        $reply(true);
    }

    $conversation = (string) ($_POST['conversation'] ?? '');
    if (!mentora_valid_conversation_key($conversation)) {
        $reply(false, 400);
    }
    if ($backgroundAction === 'mark_read') {
        $reply($markConversationRead($conversation), 200);
    }

    // Archive / delete work from the conversation's newest message: anything newer (a reply) is not affected.
    [$condition, $values] = mentora_conversation_condition($conversation, $userId);
    $newest = $pdo->prepare("SELECT MAX(id) FROM messages WHERE $condition");
    $newest->execute($values);
    $newestId = $newest->fetchColumn();
    $newestId = $newestId === null || $newestId === false ? null : (int) $newestId;

    if ($backgroundAction === 'archive') {
        $archive = ($_POST['archived'] ?? '') === '1';
        if ($archive && $newestId === null) {
            $reply(false, 404);
        }
        $pdo->prepare('INSERT INTO message_conversations (user_id, conversation_key, archived_through) VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE archived_through = VALUES(archived_through)')
            ->execute([$userId, $conversation, $archive ? $newestId : null]);
        if ($archive) {
            $markConversationRead($conversation); // nothing waiting in the archive
        }
        $reply(true);
    }

    // delete_conversation
    if ($newestId === null) {
        $reply(false, 404);
    }
    $pdo->prepare('INSERT INTO message_conversations (user_id, conversation_key, cleared_through, archived_through) VALUES (?, ?, ?, NULL)
        ON DUPLICATE KEY UPDATE cleared_through = VALUES(cleared_through), archived_through = NULL')
        ->execute([$userId, $conversation, $newestId]);
    $markConversationRead($conversation);
    $reply(true);
}

// ---------- Create a group chat (New group dialog): a name and the people in it (your contacts) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_group'])) {
    $groupName = trim((string) preg_replace('/\s+/u', ' ', is_string($_POST['group_name'] ?? null) ? $_POST['group_name'] : ''));
    $memberIds = array_values(array_unique(array_filter(
        array_map('intval', is_array($_POST['members'] ?? null) ? $_POST['members'] : []),
        static fn(int $id): bool => isset($contacts[$id]) // only people you can message
    )));
    if (!$tokenValid()) {
        $messageError = 'Your session expired. Please reload the page and try again.';
    } elseif ($groupName === '' || mb_strlen($groupName) > 80) {
        $messageError = 'Give the group a name (up to 80 characters).';
    } elseif (!$memberIds) {
        $messageError = 'Choose at least one person for the group.';
    } elseif (count($memberIds) > 200) {
        $messageError = 'A group can have up to 200 people.';
    } else {
        $pdo->beginTransaction();
        $pdo->prepare('INSERT INTO chat_groups (name, created_by) VALUES (?, ?)')->execute([$groupName, $userId]);
        $groupId = (int) $pdo->lastInsertId();
        $addMember = $pdo->prepare('INSERT INTO chat_group_members (group_id, user_id, role) VALUES (?, ?, ?)');
        $addMember->execute([$groupId, $userId, 'owner']);
        foreach ($memberIds as $memberId) {
            $addMember->execute([$groupId, $memberId, 'member']);
        }
        $pdo->commit();
        header('Location: messages.php?c=g' . $groupId);
        exit;
    }
}

// A note in a group chat ("Ana added Ben.", "Ana left the group."): a group message with no sender,
// shown centred instead of as a bubble. The person who caused it has already seen it.
$addGroupNote = static function (int $groupId, string $text, ?int $seenBy) use ($pdo): void {
    $pdo->prepare('INSERT INTO messages (sender_id, recipient_id, group_id, subject, body) VALUES (NULL, NULL, ?, "Message", ?)')
        ->execute([$groupId, $text]);
    if ($seenBy !== null) {
        $pdo->prepare('INSERT IGNORE INTO message_reads (message_id, user_id) VALUES (?, ?)')->execute([(int) $pdo->lastInsertId(), $seenBy]);
    }
};
$myName = trim((string) ($me['name'] ?? '')) ?: 'Someone';

/** A group you can change (not General) and are in, or null. */
$ownGroup = static function (int $groupId) use ($pdo, $userId): ?array {
    $query = $pdo->prepare('SELECT g.id, g.name FROM chat_groups g JOIN chat_group_members m ON m.group_id = g.id AND m.user_id = ?
        WHERE g.id = ? AND g.is_general = 0');
    $query->execute([$userId, $groupId]);
    return $query->fetch() ?: null;
};

// ---------- Leave a group (background, JSON) ----------
// The owner leaving hands the group to whoever has been in it longest; the last person leaving deletes it.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'leave_group') {
    header('Content-Type: application/json');
    $groupId = (int) ($_POST['group_id'] ?? 0);
    if (!$tokenValid() || $ownGroup($groupId) === null) {
        http_response_code(!$tokenValid() ? 403 : 404);
        exit(json_encode(['ok' => false]));
    }
    $pdo->beginTransaction();
    $role = $pdo->prepare('SELECT role FROM chat_group_members WHERE group_id = ? AND user_id = ? FOR UPDATE');
    $role->execute([$groupId, $userId]);
    $wasOwner = $role->fetchColumn() === 'owner';
    $pdo->prepare('DELETE FROM chat_group_members WHERE group_id = ? AND user_id = ?')->execute([$groupId, $userId]);
    $next = $pdo->prepare('SELECT user_id FROM chat_group_members WHERE group_id = ? ORDER BY joined_at, user_id LIMIT 1');
    $next->execute([$groupId]);
    $nextId = $next->fetchColumn();
    $orphanFiles = [];
    if ($nextId === false) {
        $files = $pdo->prepare('SELECT a.stored_name FROM message_attachments a JOIN messages m ON m.id = a.message_id
            WHERE m.group_id = ? AND a.stored_name IS NOT NULL');
        $files->execute([$groupId]);
        $orphanFiles = $files->fetchAll(PDO::FETCH_COLUMN);
        $pdo->prepare('DELETE FROM chat_groups WHERE id = ?')->execute([$groupId]); // its messages go with it
    } else {
        if ($wasOwner) {
            $pdo->prepare('UPDATE chat_group_members SET role = "owner" WHERE group_id = ? AND user_id = ?')->execute([$groupId, (int) $nextId]);
        }
        $addGroupNote($groupId, $myName . ' left the group.', null);
    }
    $pdo->prepare('DELETE FROM message_conversations WHERE user_id = ? AND conversation_key = ?')->execute([$userId, 'g' . $groupId]);
    $pdo->commit();
    foreach ($orphanFiles as $storedName) {
        if (preg_match('/^[A-Za-z0-9._-]+$/', (string) $storedName) === 1) {
            @unlink(MENTORA_APP_ROOT . '/uploads/messages/' . $storedName);
        }
    }
    exit(json_encode(['ok' => true]));
}

// ---------- Rename a group (background, JSON): only the group's owner ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'rename_group') {
    header('Content-Type: application/json');
    $groupId = (int) ($_POST['group_id'] ?? 0);
    $newName = trim((string) preg_replace('/\s+/u', ' ', is_string($_POST['name'] ?? null) ? $_POST['name'] : ''));
    $owned = $pdo->prepare('SELECT g.name FROM chat_group_members m JOIN chat_groups g ON g.id = m.group_id
        WHERE m.group_id = ? AND m.user_id = ? AND m.role = "owner" AND g.is_general = 0');
    $owned->execute([$groupId, $userId]);
    $oldName = $owned->fetchColumn();
    if (!$tokenValid() || $oldName === false) {
        http_response_code(!$tokenValid() ? 403 : 404);
        exit(json_encode(['ok' => false]));
    }
    if ($newName === '' || mb_strlen($newName) > 80) {
        http_response_code(400);
        exit(json_encode(['ok' => false, 'error' => 'Give the group a name (up to 80 characters).']));
    }
    if ($newName !== $oldName) {
        $pdo->beginTransaction();
        $pdo->prepare('UPDATE chat_groups SET name = ? WHERE id = ?')->execute([$newName, $groupId]);
        $addGroupNote($groupId, $myName . ' renamed the group to “' . $newName . '”.', $userId);
        $pdo->commit();
    }
    exit(json_encode(['ok' => true]));
}

// ---------- Remove someone from a group (background, JSON): only the group's owner, never themselves ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'remove_member') {
    header('Content-Type: application/json');
    $groupId = (int) ($_POST['group_id'] ?? 0);
    $memberId = (int) ($_POST['user_id'] ?? 0);
    $owner = $pdo->prepare('SELECT 1 FROM chat_group_members m JOIN chat_groups g ON g.id = m.group_id
        WHERE m.group_id = ? AND m.user_id = ? AND m.role = "owner" AND g.is_general = 0');
    $owner->execute([$groupId, $userId]);
    if (!$tokenValid() || !$owner->fetchColumn() || $memberId === $userId) {
        http_response_code(!$tokenValid() ? 403 : 404);
        exit(json_encode(['ok' => false]));
    }
    $name = $pdo->prepare('SELECT u.first_name, u.last_name FROM chat_group_members m JOIN users u ON u.id = m.user_id WHERE m.group_id = ? AND m.user_id = ?');
    $name->execute([$groupId, $memberId]);
    $removed = $name->fetch();
    if (!$removed) {
        http_response_code(404);
        exit(json_encode(['ok' => false]));
    }
    $pdo->beginTransaction();
    $pdo->prepare('DELETE FROM chat_group_members WHERE group_id = ? AND user_id = ?')->execute([$groupId, $memberId]);
    $addGroupNote($groupId, $myName . ' removed ' . trim($removed['first_name'] . ' ' . $removed['last_name']) . '.', $userId);
    $pdo->commit();
    exit(json_encode(['ok' => true]));
}

// ---------- Add people to a group you're in (Add people dialog) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_members'])) {
    $groupId = (int) ($_POST['group_id'] ?? 0);
    $group = $ownGroup($groupId);
    $memberIds = array_values(array_unique(array_filter(
        array_map('intval', is_array($_POST['members'] ?? null) ? $_POST['members'] : []),
        static fn(int $id): bool => isset($contacts[$id]) // only people you can message
    )));
    if (!$tokenValid()) {
        $messageError = 'Your session expired. Please reload the page and try again.';
    } elseif ($group === null) {
        $messageError = "You can't add people to that group.";
    } elseif (!$memberIds) {
        $messageError = 'Choose at least one person to add.';
    } else {
        $existing = $pdo->prepare('SELECT user_id FROM chat_group_members WHERE group_id = ?');
        $existing->execute([$groupId]);
        $already = array_map('intval', $existing->fetchAll(PDO::FETCH_COLUMN));
        $newIds = array_values(array_diff($memberIds, $already));
        if (count($already) + count($newIds) > 200) {
            $messageError = 'A group can have up to 200 people.';
        } else {
            if ($newIds) {
                $pdo->beginTransaction();
                $add = $pdo->prepare('INSERT IGNORE INTO chat_group_members (group_id, user_id, role) VALUES (?, ?, "member")');
                foreach ($newIds as $newId) {
                    $add->execute([$groupId, $newId]);
                }
                $names = array_map(static fn(int $id): string => trim($contacts[$id]['first_name'] . ' ' . $contacts[$id]['last_name']), $newIds);
                $list = count($names) > 1 ? implode(', ', array_slice($names, 0, -1)) . ' and ' . end($names) : $names[0];
                $addGroupNote($groupId, $myName . ' added ' . $list . '.', $userId);
                $pdo->commit();
            }
            header('Location: messages.php?c=g' . $groupId);
            exit;
        }
    }
}

// ---------- Send a reply ----------
// recipient_id: a person's id, "g{id}" for a group chat, or "all" (HR's old "Everyone": now the General chat).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_message'])) {
    $recipientField = is_string($_POST['recipient_id'] ?? null) ? $_POST['recipient_id'] : '';
    $groupId = $recipientField === 'all' ? mentora_general_group_id($pdo) : (preg_match('/^g(\d+)$/', $recipientField, $groupMatch) ? (int) $groupMatch[1] : 0);
    $toGroup = $groupId > 0;
    $recipientId = !$toGroup ? (int) $recipientField : 0;
    $subject = trim(is_string($_POST['subject'] ?? null) ? $_POST['subject'] : '');
    $draftBody = trim(is_string($_POST['body'] ?? null) ? $_POST['body'] : '');

    $files = $postedFiles();
    $gif = $postedGif();
    $attachAgain = $files ? ' Please attach your files again.' : '';

    if (!$tokenValid()) {
        // Also what happens when the upload exceeds PHP's post_max_size (PHP drops the whole form).
        $messageError = empty($_POST) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0
            ? 'Those files are too large to send together. Please send fewer or smaller files.'
            : 'Your session expired. Please reload the page and try again.';
    } elseif ((!$recipientId && !$toGroup) || $subject === '') {
        $messageError = 'Choose a conversation before sending.';
    } elseif ($draftBody === '' && !$files && $gif === null) {
        $messageError = 'Write a message or add an attachment before sending.';
    } elseif (mb_strlen($draftBody) > 5000) {
        $messageError = 'Messages must be 5,000 characters or fewer.' . $attachAgain;
    } elseif (count($files) > MENTORA_MESSAGE_MAX_FILES) {
        $messageError = 'You can attach up to ' . MENTORA_MESSAGE_MAX_FILES . ' files at a time.' . $attachAgain;
    } elseif (isset($gif['error'])) {
        $messageError = "That GIF couldn't be added. Please choose another one.";
    } else {
        // Your contacts, anyone who has messaged you, or a group chat you're in.
        $allowed = $toGroup ? mentora_in_group($pdo, $groupId, $userId) : $canMessage($recipientId);

        $prepared = [];
        if (!$allowed) {
            $messageError = $toGroup ? "You're not in that group chat." : "You can't send messages to that person.";
        } else {
            foreach ($files as $file) {
                [$ok, $problem] = $checkFile($file);
                if ($problem !== null) {
                    $messageError = $problem . $attachAgain;
                    break;
                }
                $prepared[] = $ok;
            }
        }

        if ($messageError === '') {
            $folder = MENTORA_APP_ROOT . '/uploads/messages';
            $stored = [];
            try {
                if ($prepared && !is_dir($folder) && !mkdir($folder, 0755, true) && !is_dir($folder)) {
                    throw new RuntimeException('Could not create ' . $folder);
                }
                // Mentions ("@[Ana Cruz](12)", picked from the list): only people in the group, with their real names.
                $mentionedIds = [];
                $storedBody = $draftBody;
                if ($toGroup) {
                    [$storedBody, $mentionedIds] = mentora_resolve_mentions($pdo, $groupId, $draftBody, $userId);
                } else {
                    $storedBody = mentora_plain_mentions($draftBody); // no mentions in a one-to-one chat
                }
                $pdo->beginTransaction();
                $pdo->prepare('INSERT INTO messages (sender_id, recipient_id, group_id, subject, body) VALUES (?, ?, ?, ?, ?)')
                    ->execute([$userId, $toGroup ? null : $recipientId, $toGroup ? $groupId : null, mb_substr($subject, 0, 190), $storedBody]);
                $messageId = (int) $pdo->lastInsertId();
                if ($toGroup) {
                    $groupInfo = $pdo->prepare('SELECT name, is_general FROM chat_groups WHERE id = ?');
                    $groupInfo->execute([$groupId]);
                    $groupRow = $groupInfo->fetch();
                    // @everyone: everyone in the chat gets a bell notification. In General only HR admins can do
                    // this (it reaches the whole company); in other groups any member can.
                    $everyone = mentora_mentions_everyone($storedBody) && ($isAdmin || (int) $groupRow['is_general'] === 0);
                    if ($everyone) {
                        mentora_notify_group_mention($pdo, $groupId, $me, (string) $groupRow['name'], $storedBody);
                    } else {
                        mentora_notify_mentions($pdo, $groupId, $me, (string) $groupRow['name'], $mentionedIds, $storedBody);
                    }
                }
                $addAttachment = $pdo->prepare(
                    'INSERT INTO message_attachments (message_id, kind, stored_name, url, original_name, mime, size, width, height)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                foreach ($prepared as $file) {
                    $storedName = sprintf('m%d-%s.%s', $messageId, bin2hex(random_bytes(8)), $file['extension']);
                    if (!move_uploaded_file($file['tmp'], $folder . '/' . $storedName)) {
                        throw new RuntimeException('Could not store ' . $storedName);
                    }
                    $stored[] = $folder . '/' . $storedName;
                    $addAttachment->execute([
                        $messageId,
                        $file['kind'],
                        $storedName,
                        null,
                        $file['name'],
                        $file['mime'],
                        $file['size'],
                        $file['width'],
                        $file['height']
                    ]);
                }
                if ($gif !== null) {
                    $addAttachment->execute([$messageId, 'gif', null, $gif['url'], $gif['title'], 'image/gif', 0, $gif['width'], $gif['height']]);
                }
                $pdo->commit();
                header('Location: messages.php?c=' . ($toGroup ? 'g' . $groupId : 'u' . $recipientId));
                exit;
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                foreach ($stored as $path) {
                    @unlink($path);
                }
                error_log('Mentora message send failed: ' . $exception->getMessage());
                $messageError = "Your message couldn't be sent. Please try again." . $attachAgain;
            }
        }
    }
}

// ---------- Load every message to, from or broadcast to this person, and the group chats they're in ----------
$myGroups = mentora_user_groups($pdo, $userId); // includes/messaging.php: General + your groups
$query = $pdo->prepare(
    'SELECT m.id, m.sender_id, m.recipient_id, m.group_id, m.subject, m.body, m.created_at,
            CASE
                WHEN m.sender_id <=> ? THEN 0
                WHEN m.recipient_id IS NULL THEN r.user_id IS NULL
                ELSE m.read_at IS NULL
            END AS is_unread
     FROM messages m
     LEFT JOIN message_reads r ON r.message_id = m.id AND r.user_id = ?
     WHERE ((m.group_id IS NULL AND (m.recipient_id = ? OR m.recipient_id IS NULL OR m.sender_id = ?))
        OR m.group_id IN (' . mentora_my_groups_sql() . '))
       AND NOT EXISTS (SELECT 1 FROM message_hidden x WHERE x.user_id = ? AND x.message_id = m.id) -- "Delete for me"
     ORDER BY m.created_at, m.id'
);
$query->execute([$userId, $userId, $userId, $userId, $userId, $userId]);
$rows = $query->fetchAll();

// Attachments of those messages, grouped by message.
$attachments = [];
if ($rows) {
    $messageIds = array_map(static fn(array $row): int => (int) $row['id'], $rows);
    $placeholders = implode(',', array_fill(0, count($messageIds), '?'));
    $attachmentQuery = $pdo->prepare(
        "SELECT id, message_id, kind, url, original_name, size, width, height
         FROM message_attachments WHERE message_id IN ($placeholders) ORDER BY id"
    );
    $attachmentQuery->execute($messageIds);
    foreach ($attachmentQuery->fetchAll() as $attachment) {
        $attachments[(int) $attachment['message_id']][] = $attachment;
    }
}

// Short text for the chat list when a message has no words, e.g. "📷 2 photos", "📎 report.pdf", "GIF".
$attachmentSummary = static function (array $items): string {
    $images = array_values(array_filter($items, static fn(array $a): bool => $a['kind'] === 'image'));
    $gifs = array_filter($items, static fn(array $a): bool => $a['kind'] === 'gif');
    $files = array_values(array_filter($items, static fn(array $a): bool => $a['kind'] === 'file'));
    $parts = [];
    if ($images) {
        $parts[] = count($images) === 1 ? '📷 Photo' : '📷 ' . count($images) . ' photos';
    }
    if ($gifs) {
        $parts[] = 'GIF';
    }
    if ($files) {
        $parts[] = '📎 ' . (count($files) === 1 ? $files[0]['original_name'] : count($files) . ' files');
    }
    return implode(' · ', $parts);
};

// People taking part (for names, roles and photos), including the members of your groups.
$personIds = [];
foreach ($rows as $row) {
    foreach (['sender_id', 'recipient_id'] as $column) {
        if ($row[$column] !== null && (int) $row[$column] !== $userId) {
            $personIds[(int) $row[$column]] = true;
        }
    }
}
foreach ($myGroups as $group) {
    foreach ($group['members'] as $memberId) {
        if ($memberId !== $userId) {
            $personIds[$memberId] = true;
        }
    }
}
$people = [];
if ($personIds) {
    $placeholders = implode(',', array_fill(0, count($personIds), '?'));
    $peopleQuery = $pdo->prepare("SELECT id, first_name, last_name, email, role, pathway, avatar_path FROM users WHERE id IN ($placeholders)");
    $peopleQuery->execute(array_keys($personIds));
    foreach ($peopleQuery->fetchAll() as $person) {
        $people[(int) $person['id']] = $person;
    }
}

$roleLabel = static fn(array $person): string => match ($person['role']) {
    'admin' => 'HR administrator',
    'agent' => 'Agent',
    default => $person['pathway'] === 'internship' ? 'Intern' : 'Employee VA',
};
$initialsOf = static function (string $name): string {
    $letters = '';
    foreach (array_slice(preg_split('/\s+/', trim($name)) ?: [], 0, 2) as $part) {
        $letters .= mb_strtoupper(mb_substr($part, 0, 1));
    }
    return $letters !== '' ? $letters : 'M';
};

// Group into conversations: one per other person, one for Mentora support announcements.
// "Delete conversation" hides its messages up to then (for you only); archived ones go to the Archived tab.
$conversationStates = mentora_conversation_states($pdo, $userId);
$conversations = [];

// A group chat's entry in the list (General: everyone; other groups: their members).
$activeUserCount = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE status = 'active'")->fetchColumn(); // everyone is in General
$groupConversation = static function (array $group) use ($people, $userId, $h, $activeUserCount): array {
    $isGeneral = (int) $group['is_general'] === 1;
    $memberNames = [];
    foreach ($group['members'] as $memberId) {
        $memberNames[] = $memberId === $userId ? 'You' : trim(($people[$memberId]['first_name'] ?? '') . ' ' . ($people[$memberId]['last_name'] ?? ''));
    }
    $memberCount = count($group['members']);
    $initials = '';
    foreach (array_slice(preg_split('/\s+/', trim((string) $group['name'])) ?: [], 0, 2) as $part) {
        $initials .= mb_strtoupper(mb_substr($part, 0, 1));
    }
    return [
        'key' => 'g' . (int) $group['id'],
        'name' => (string) $group['name'],
        'role' => $isGeneral ? 'Everyone in Mentora' : $memberCount . ' member' . ($memberCount === 1 ? '' : 's'),
        'email' => '',
        'avatar' => $isGeneral
            ? '<span class="avatar avatar-initials chat-group-avatar is-general" aria-hidden="true"><svg class="chat-svg" viewBox="0 0 24 24" focusable="false"><path d="M3 11v2a1 1 0 0 0 1 1h2l5 4V6L6 10H4a1 1 0 0 0-1 1Z"/><path d="M15.5 8.5a5 5 0 0 1 0 7M18.5 5.5a9 9 0 0 1 0 13"/></svg></span>'
            : '<span class="avatar avatar-initials chat-group-avatar" aria-hidden="true">' . $h($initials !== '' ? $initials : 'G') . '</span>',
        'messages' => [],
        'unread' => 0,
        'canReply' => true, // you're in it
        'recipientId' => 'g' . (int) $group['id'],
        'replySubject' => 'Message',
        'isGroup' => true,
        'isGeneral' => $isGeneral,
        'members' => $isGeneral ? '' : implode(', ', array_filter($memberNames)),
        'memberIds' => $isGeneral ? '' : implode(',', $group['members']),
        'memberCount' => $isGeneral ? $activeUserCount : $memberCount,
        'ownerId' => $isGeneral ? '' : (string) ($group['owner'] ?? ''),
        'startedAt' => (string) $group['created_at'],
    ];
};

foreach ($rows as $row) {
    $mine = (int) ($row['sender_id'] ?? 0) === $userId;
    $groupId = $row['group_id'] !== null ? (int) $row['group_id'] : null;
    $otherId = $mine ? $row['recipient_id'] : $row['sender_id'];
    $key = $groupId !== null ? 'g' . $groupId : ($otherId === null ? ($mine ? 'all' : 'support') : 'u' . (int) $otherId);
    if (mentora_message_cleared($conversationStates, $key, (int) $row['id'])) {
        continue;
    }
    if ($groupId !== null) {
        if (!isset($myGroups[$groupId])) {
            continue;
        }
        $conversations[$key] ??= $groupConversation($myGroups[$groupId]);
        $conversations[$key]['messages'][] = $row + ['mine' => $mine];
        $conversations[$key]['unread'] += (int) $row['is_unread'];
        continue;
    }

    if (!isset($conversations[$key])) {
        $person = $otherId !== null ? ($people[(int) $otherId] ?? null) : null;
        $name = match (true) {
            $key === 'support' => 'Mentora support',
            $key === 'all' => 'Everyone',
            $person !== null => trim($person['first_name'] . ' ' . $person['last_name']),
            default => 'Former member',
        };
        $conversations[$key] = [
            'key' => $key,
            'name' => $name,
            'role' => $person !== null ? $roleLabel($person) : ($key === 'all' ? 'Announcement' : 'Mentora team'),
            'email' => $person['email'] ?? '',
            'avatar' => mentora_avatar(mentora_avatar_url($person['avatar_path'] ?? null, '../'), $initialsOf($name)),
            'messages' => [],
            'unread' => 0,
            // Contacts can always be messaged (so you can follow up before they reply); admins can post to everyone.
            'canReply' => $key === 'all' ? $isAdmin : ($otherId !== null && isset($contacts[(int) $otherId])),
            'recipientId' => $key === 'all' ? 'all' : ($otherId !== null ? (string) (int) $otherId : '0'),
            'replySubject' => '',
            'isGroup' => false,
            'isGeneral' => false,
            'members' => '',
            'memberIds' => '',
            'memberCount' => 0,
            'ownerId' => '',
            'startedAt' => (string) $row['created_at'],
        ];
    }

    $conversations[$key]['messages'][] = $row + ['mine' => $mine];
    $conversations[$key]['unread'] += (int) $row['is_unread'];
    if (!$mine && $otherId !== null) {
        $conversations[$key]['canReply'] = true; // same rule as the send check above
        $subject = trim((string) $row['subject']);
        $conversations[$key]['replySubject'] = preg_match('/^re:/i', $subject) ? $subject : 'Re: ' . $subject;
    }
}
// Group chats with nothing (left) to show still belong in the list: General for everyone, a new group, a cleared one.
foreach ($myGroups as $groupId => $group) {
    $conversations['g' . $groupId] ??= $groupConversation($group);
}
foreach ($conversations as &$conversation) {
    $last = $conversation['messages'] ? end($conversation['messages']) : null;
    if ($conversation['replySubject'] === '' && $last) {
        $conversation['replySubject'] = (string) $last['subject'];
    }
    // Archived until a newer message arrives (then it's back in Chats by itself).
    $archivedThrough = $conversationStates[$conversation['key']]['archived_through'] ?? null;
    $conversation['archived'] = $archivedThrough !== null && $last !== null && (int) $last['id'] <= $archivedThrough;
    // Voice and video calls: one person you can message, or a group call in a group chat / General
    // (not announcements or Mentora support).
    $conversation['canCall'] = (str_starts_with($conversation['key'], 'u') || str_starts_with($conversation['key'], 'g')) && $conversation['canReply'];
    $conversation['sortTime'] = $last ? (string) $last['created_at'] : $conversation['startedAt'];
}
unset($conversation);
// Most recent conversation first.
uasort($conversations, static fn(array $a, array $b): int => strcmp($b['sortTime'], $a['sortTime']));

$totalUnread = array_sum(array_column($conversations, 'unread'));
$selectedKey = is_string($_GET['c'] ?? null) && isset($conversations[$_GET['c']]) ? $_GET['c'] : '';

$now = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
$dayLabel = static function (DateTimeImmutable $date) use ($now): string {
    $diff = (int) $now->setTime(0, 0)->diff($date->setTime(0, 0))->format('%r%a');
    return match ($diff) {
        0 => 'Today',
        -1 => 'Yesterday',
        default => $date->format('D, d M Y'),
    };
};
$listTime = static fn(DateTimeImmutable $date): string => $date->format('Y-m-d') === $now->format('Y-m-d') ? $date->format('g:i A') : $date->format('d M');
$toDate = static fn(string $value): DateTimeImmutable => new DateTimeImmutable($value, new DateTimeZone('Asia/Manila'));
// A message's text, escaped, with "@everyone" and "@Ana Cruz" highlighted (a mention of you stands out more).
$formatBody = static function (string $body) use ($h, $userId): string {
    $html = (string) preg_replace('/(?<![\w@])@everyone\b/i', '<mark class="chat-mention is-everyone">$0</mark>', $h($body));
    return (string) preg_replace_callback(MENTORA_MENTION_PATTERN, static fn(array $m): string => '<mark class="chat-mention'
        . ((int) $m[2] === $userId ? ' is-me' : '') . '" data-user="' . (int) $m[2] . '">@' . $m[1] . '</mark>', $html);
};

// People you can @mention (the list that opens when you type "@" in a group chat): your contacts and the
// people in your groups. Groups offer their members; General offers your contacts.
$mentionPeople = [];
foreach ($contacts + $people as $personId => $person) {
    if ((int) $personId !== $userId) {
        $mentionPeople[(int) $personId] = [
            'name' => str_replace(['[', ']'], '', trim($person['first_name'] . ' ' . $person['last_name'])),
            'role' => $roleLabel($person),
        ];
    }
}

$homeHref = '../' . mentora_home_path($me);

$pageTitle = 'Messages | Mentora';
$bodyClass = 'account-page messages-page';
$hideTopbar = true; // no top header: the chat uses the full screen ("Back to workspace" is the way out)
$themeFollowsDevice = false; // use the person's Light / Dark / Auto choice from Settings, like the workspace pages
require __DIR__ . '/../includes/header.php';
?>
<style>
    /* ---------- Page frame ---------- */
    body.messages-page {
        background: var(--mist);
        color: var(--ink);
    }

    .chat-page {
        display: flex;
        flex-direction: column;
        gap: 14px;
        height: 100dvh;
        min-height: 520px;
        padding: 20px clamp(12px, 3vw, 32px) 22px;
    }

    .chat-page-bar {
        align-items: center;
        display: flex;
        flex-wrap: wrap;
        gap: 10px 16px;
    }

    .chat-page-bar h1 {
        color: var(--ink);
        font: 30px/1.1 Georgia, "Times New Roman", serif;
        letter-spacing: -0.03em;
        margin: 0;
    }

    .chat-back-link {
        align-items: center;
        background: var(--white);
        border: 1px solid var(--line);
        border-radius: 999px;
        color: var(--teal);
        display: inline-flex;
        font-size: 12px;
        font-weight: 700;
        gap: 6px;
        padding: 8px 14px 8px 10px;
        text-decoration: none;
        transition: background 160ms ease, box-shadow 160ms ease;
    }

    .chat-back-link:hover {
        background: var(--pale);
        box-shadow: 0 6px 14px rgba(11, 98, 93, 0.12);
    }

    .chat-page-unread {
        background: var(--teal);
        border-radius: 999px;
        color: #fff;
        font-size: 11px;
        font-weight: 700;
        margin-left: auto;
        padding: 5px 12px;
    }

    .chat-page-unread[hidden] {
        display: none;
    }

    .chat-page-tools {
        align-items: center;
        display: flex;
        gap: 10px;
        margin-left: auto;
    }

    .chat-page-tools .chat-page-unread {
        margin-left: 0;
    }

    .chat-svg {
        fill: none;
        height: 16px;
        stroke: currentColor;
        stroke-linecap: round;
        stroke-linejoin: round;
        stroke-width: 2;
        width: 16px;
    }

    /* ---------- Three panes ---------- */
    .account-page .chat-workspace {
        background: var(--white);
        border: 1px solid var(--line);
        border-radius: 18px;
        box-shadow: 0 12px 32px rgba(15, 72, 71, 0.08);
        display: grid;
        flex: 1;
        grid-template-columns: 320px minmax(0, 1fr) 260px;
        min-height: 0;
        overflow: hidden;
    }

    .account-page .chat-sidebar,
    .account-page .chat-info {
        background: var(--pale);
        border-color: var(--line);
        color: var(--ink);
        grid-column: auto;
        min-height: 0;
    }

    .account-page .chat-sidebar {
        border-bottom: 0;
        border-right: 1px solid var(--line);
        display: flex;
        flex-direction: column;
    }

    .account-page .chat-sidebar-heading {
        padding: 16px 16px 10px;
    }

    .account-page .chat-sidebar-heading h2 {
        color: var(--ink);
        font-size: 20px;
        margin: 0;
    }

    .account-page .chat-icon-button {
        align-items: center;
        background: var(--white);
        border: 1px solid var(--line);
        border-radius: 50%;
        color: var(--deep);
        cursor: pointer;
        display: inline-flex;
        height: 34px;
        justify-content: center;
        margin: 0;
        padding: 0;
        width: 34px;
    }

    .account-page .chat-icon-button:hover {
        background: var(--teal);
        border-color: var(--teal);
        color: #fff;
    }

    .account-page .chat-search {
        background: var(--white);
        border: 1px solid var(--line);
        color: var(--muted);
        margin: 0 12px 10px;
    }

    .account-page .chat-search:focus-within {
        border-color: var(--teal);
        box-shadow: 0 0 0 3px rgba(47, 133, 128, 0.15);
    }

    .account-page .chat-search input {
        color: var(--ink);
        font: inherit;
        font-size: 13px;
        padding: 2px 0;
    }

    .account-page .chat-tabs {
        padding: 0 12px 10px;
    }

    .account-page .chat-tabs button {
        color: var(--deep);
        font: inherit;
        font-size: 12px;
        font-weight: 700;
    }

    .account-page .chat-tabs button.active,
    .account-page .chat-tabs button:hover {
        background: var(--teal);
        color: #fff;
    }

    .account-page .chat-conversation-list {
        border-top: 1px solid var(--line);
        flex: 1;
        max-height: none;
        min-height: 0;
        overflow-y: auto;
    }

    .account-page .chat-conversation {
        border-bottom: 1px solid rgba(15, 72, 71, 0.06);
        color: var(--ink);
        font: inherit;
        gap: 12px;
        padding: 12px 14px;
    }

    .account-page .chat-conversation[hidden] {
        display: none;
    }

    .account-page .chat-conversation:hover {
        background: rgba(255, 255, 255, 0.6);
    }

    .account-page .chat-conversation.selected {
        background: var(--white);
        box-shadow: inset 3px 0 0 var(--teal);
    }

    .account-page .chat-conversation:focus-visible {
        outline: 2px solid var(--teal);
        outline-offset: -2px;
    }

    .account-page .chat-avatar,
    .account-page .chat-conversation .avatar {
        align-items: center;
        background: var(--teal);
        border-radius: 50%;
        color: #fff;
        display: inline-flex;
        flex: 0 0 42px;
        font-size: 14px;
        font-weight: 700;
        height: 42px;
        justify-content: center;
        overflow: hidden;
        width: 42px;
    }

    .account-page .chat-conversation-copy strong,
    .account-page .chat-conversation-copy span {
        color: var(--ink);
    }

    /* Profile photos (avatar markup from includes/auth_check.php) */
    .account-page .avatar-img {
        border-radius: inherit;
        display: block;
        height: 100%;
        object-fit: cover;
        width: 100%;
    }

    .account-page .chat-conversation-copy .chat-conversation-top {
        align-items: baseline;
        display: flex;
        gap: 8px;
        justify-content: space-between;
        margin-top: 0;
    }

    .chat-conversation-top strong {
        min-width: 0;
    }

    .chat-conversation-top time {
        color: var(--muted);
        flex: 0 0 auto;
        font-size: 10px;
    }

    .account-page .chat-conversation-copy small {
        color: var(--muted);
    }

    .account-page .chat-conversation.unread strong,
    .account-page .chat-conversation.unread .chat-conversation-copy > span {
        font-weight: 800;
    }

    .chat-unread-count {
        background: var(--teal);
        border-radius: 999px;
        color: #fff;
        flex: 0 0 auto;
        font-size: 10px;
        font-weight: 800;
        min-width: 20px;
        padding: 3px 6px;
        text-align: center;
    }

    .account-page .chat-empty-list {
        color: var(--muted);
    }

    /* Conversation */
    .account-page .chat-thread {
        background: var(--white);
        color: var(--ink);
        display: flex;
        flex-direction: column;
        min-height: 0;
        min-width: 0;
        position: relative;
    }

    .account-page .chat-thread-header {
        background: var(--white);
        border-bottom: 1px solid var(--line);
        gap: 12px;
        min-height: 68px;
        padding: 12px 18px;
    }

    .account-page .chat-thread-header strong {
        color: var(--ink);
        font-size: 15px;
    }

    .account-page .chat-thread-header small {
        color: var(--muted);
    }

    .chat-thread-person {
        min-width: 0;
    }

    .chat-thread-person strong,
    .chat-thread-person small {
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .account-page .chat-back {
        display: none;
    }

    .account-page .chat-thread-header .chat-avatar:empty {
        display: none;
    }

    .account-page .chat-thread-body {
        flex: 1;
        min-height: 0;
        overflow-y: auto;
        padding: 18px clamp(14px, 3vw, 28px);
    }

    .account-page .chat-thread-empty {
        color: var(--muted);
        flex-direction: column;
        gap: 10px;
        min-height: 100%;
    }

    .account-page .chat-thread-empty[hidden],
    .chat-thread-messages[hidden] {
        display: none;
    }

    .chat-thread-messages {
        display: flex;
        flex-direction: column;
    }

    .chat-day {
        align-self: center;
        background: var(--mist);
        border-radius: 999px;
        color: var(--muted);
        font-size: 11px;
        font-weight: 700;
        margin: 12px 0 8px;
        padding: 4px 12px;
    }

    .chat-bubble {
        border-radius: 18px;
        margin: 4px 0;
        max-width: min(78%, 560px);
        padding: 10px 14px 8px;
    }

    .chat-bubble-theirs {
        align-self: flex-start;
        background: var(--pale);
        border-bottom-left-radius: 6px;
        color: var(--deep);
    }

    .chat-bubble-mine {
        align-self: flex-end;
        background: var(--mentora-teal);
        border-bottom-right-radius: 6px;
        color: #fff;
    }

    .chat-bubble-subject {
        font-size: 12px;
        font-weight: 800;
        margin: 0 0 3px;
        opacity: .85;
    }

    .account-page .chat-bubble .chat-message-text {
        color: inherit;
        font-size: 14px;
        line-height: 1.5;
        margin: 0;
        overflow-wrap: anywhere;
        white-space: pre-wrap;
    }

    .chat-bubble time {
        display: block;
        font-size: 10px;
        margin-top: 4px;
        opacity: .7;
        text-align: right;
    }

    /* Keep the site-wide "lift on hover" effect off chat text */
    .messages-page .chat-workspace p:hover,
    .messages-page .chat-workspace h2:hover,
    .messages-page .chat-page-bar h1:hover {
        text-shadow: none;
        transform: none;
    }

    /* Composer */
    .account-page .chat-error {
        background: #fff0ee;
        border-top: 1px solid #f5c2bd;
        color: #b42318;
        font-size: 12px;
        font-weight: 600;
        margin: 0;
        padding: 9px 18px;
    }

    .account-page .chat-composer {
        border-top: 1px solid var(--line);
        display: block;
        padding: 12px 16px;
    }

    .chat-composer-row {
        align-items: flex-end;
        display: flex;
        gap: 8px;
    }

    .account-page .chat-composer.is-dragging {
        background: color-mix(in srgb, var(--teal) 8%, transparent);
        box-shadow: inset 0 0 0 2px var(--teal);
    }

    .account-page .chat-composer .chat-tool {
        align-items: center;
        background: transparent;
        border: 0;
        border-radius: 50%;
        color: var(--teal);
        cursor: pointer;
        display: inline-flex;
        flex: 0 0 38px;
        height: 42px;
        justify-content: center;
        padding: 0;
        transition: background 160ms ease, color 160ms ease;
        width: 38px;
    }

    .account-page .chat-composer .chat-tool .chat-svg {
        height: 20px;
        width: 20px;
    }

    .account-page .chat-composer .chat-tool:hover:not(:disabled),
    .account-page .chat-composer .chat-tool[aria-expanded="true"] {
        background: var(--pale);
    }

    .account-page .chat-composer .chat-tool:disabled {
        cursor: not-allowed;
        opacity: .4;
    }

    .account-page .chat-composer .chat-tool:focus-visible {
        outline: 2px solid var(--teal);
        outline-offset: 1px;
    }

    /* Attachments waiting to be sent */
    .chat-composer-attachments {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin-bottom: 10px;
    }

    .chat-composer-attachments[hidden],
    .chat-composer-note[hidden] {
        display: none;
    }

    .chat-chip {
        align-items: center;
        background: var(--mist);
        border: 1px solid var(--line);
        border-radius: 12px;
        display: inline-flex;
        gap: 8px;
        max-width: 220px;
        padding: 6px 6px 6px 6px;
        position: relative;
    }

    .chat-chip-thumb {
        background: var(--pale) center / cover no-repeat;
        border-radius: 8px;
        flex: 0 0 44px;
        height: 44px;
        width: 44px;
    }

    .chat-chip-type {
        align-items: center;
        background: var(--teal);
        border-radius: 8px;
        color: #fff;
        display: inline-flex;
        flex: 0 0 44px;
        font-size: 10px;
        font-weight: 800;
        height: 44px;
        justify-content: center;
        width: 44px;
    }

    .chat-chip-copy {
        display: flex;
        flex-direction: column;
        min-width: 0;
    }

    .chat-chip-copy strong {
        color: var(--ink);
        font-size: 12px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .chat-chip-copy small {
        color: var(--muted);
        font-size: 10px;
    }

    .account-page .chat-composer .chat-chip-remove {
        align-items: center;
        background: var(--deep);
        border: 2px solid var(--white);
        border-radius: 50%;
        color: #fff;
        cursor: pointer;
        display: inline-flex;
        font-size: 13px;
        height: 22px;
        justify-content: center;
        line-height: 1;
        padding: 0 0 1px;
        position: absolute;
        right: -8px;
        top: -8px;
        width: 22px;
    }

    .chat-composer-note {
        background: #fff0ee;
        border-radius: 10px;
        color: #b42318;
        font-size: 12px;
        font-weight: 600;
        margin: 0 0 10px;
        padding: 8px 12px;
    }

    /* Attachments inside messages */
    .chat-media {
        display: grid;
        gap: 4px;
        margin: 2px 0 6px;
        max-width: 300px;
    }

    .chat-media-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .chat-media-item {
        border-radius: 12px;
        display: block;
        overflow: hidden;
        position: relative;
    }

    .chat-media-item img {
        background: rgba(255, 255, 255, 0.4);
        display: block;
        height: auto;
        max-height: 320px;
        object-fit: cover;
        width: 100%;
    }

    .chat-media-grid .chat-media-item img {
        aspect-ratio: 1;
        height: 100%;
    }

    .chat-gif-badge {
        background: rgba(0, 0, 0, 0.55);
        border-radius: 6px;
        bottom: 6px;
        color: #fff;
        font-size: 9px;
        font-weight: 800;
        left: 6px;
        letter-spacing: .06em;
        padding: 2px 6px;
        position: absolute;
    }

    .chat-file {
        align-items: center;
        background: rgba(255, 255, 255, 0.55);
        border: 1px solid rgba(15, 72, 71, 0.12);
        border-radius: 12px;
        color: inherit;
        display: flex;
        gap: 10px;
        margin: 2px 0 6px;
        max-width: 300px;
        padding: 8px 12px 8px 8px;
        text-decoration: none;
        transition: background 160ms ease;
    }

    .chat-file:hover {
        background: rgba(255, 255, 255, 0.85);
    }

    .chat-bubble-mine .chat-file {
        background: rgba(255, 255, 255, 0.14);
        border-color: rgba(255, 255, 255, 0.25);
    }

    .chat-bubble-mine .chat-file:hover {
        background: rgba(255, 255, 255, 0.24);
    }

    .chat-file-type {
        align-items: center;
        background: var(--teal);
        border-radius: 8px;
        color: #fff;
        display: inline-flex;
        flex: 0 0 40px;
        font-size: 10px;
        font-weight: 800;
        height: 40px;
        justify-content: center;
        width: 40px;
    }

    .chat-bubble-mine .chat-file-type {
        background: rgba(255, 255, 255, 0.92);
        color: var(--deep);
    }

    .chat-file-copy {
        display: flex;
        flex-direction: column;
        min-width: 0;
    }

    .chat-file-copy strong {
        font-size: 13px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .chat-file-copy small {
        font-size: 11px;
        opacity: .8;
    }

    /* Emoji + GIF picker */
    .chat-picker {
        background: var(--white);
        border: 1px solid var(--line);
        border-radius: 16px;
        bottom: 80px;
        box-shadow: 0 18px 44px rgba(15, 72, 71, 0.22);
        display: flex;
        flex-direction: column;
        left: 14px;
        max-height: min(380px, 60vh);
        position: absolute;
        width: min(360px, calc(100% - 28px));
        z-index: 6;
    }

    .chat-picker[hidden] {
        display: none;
    }

    .chat-picker-tabs {
        align-items: center;
        border-bottom: 1px solid var(--line);
        display: flex;
        gap: 4px;
        padding: 8px;
    }

    .chat-picker-tabs button {
        background: transparent;
        border: 0;
        border-radius: 999px;
        color: var(--deep);
        cursor: pointer;
        font: inherit;
        font-size: 12px;
        font-weight: 700;
        padding: 7px 12px;
    }

    .chat-picker-tabs button[aria-selected="true"] {
        background: var(--teal);
        color: #fff;
    }

    .chat-picker-tabs .chat-picker-close {
        font-size: 20px;
        line-height: 1;
        margin-left: auto;
        padding: 2px 10px 6px;
    }

    .chat-picker-panel {
        display: flex;
        flex-direction: column;
        min-height: 0;
    }

    .chat-picker-panel[hidden] {
        display: none;
    }

    .chat-emoji-categories {
        border-bottom: 1px solid var(--line);
        display: flex;
        gap: 2px;
        overflow-x: auto;
        padding: 6px 8px;
        scrollbar-width: none;
    }

    .chat-emoji-categories button {
        background: transparent;
        border: 0;
        border-radius: 8px;
        cursor: pointer;
        flex: 0 0 auto;
        font-size: 18px;
        height: 32px;
        width: 34px;
    }

    .chat-emoji-categories button[aria-pressed="true"] {
        background: var(--pale);
    }

    .chat-emoji-grid {
        display: grid;
        gap: 2px;
        grid-template-columns: repeat(auto-fill, minmax(38px, 1fr));
        overflow-y: auto;
        padding: 8px;
    }

    .chat-emoji-grid p {
        color: var(--muted);
        font-size: 11px;
        font-weight: 700;
        grid-column: 1 / -1;
        margin: 4px 2px 2px;
        text-transform: uppercase;
        letter-spacing: .06em;
    }

    .chat-emoji-grid button {
        background: transparent;
        border: 0;
        border-radius: 8px;
        cursor: pointer;
        font-size: 22px;
        height: 38px;
        line-height: 1;
        transition: background 120ms ease, transform 120ms ease;
    }

    .chat-emoji-grid button:hover,
    .chat-emoji-grid button:focus-visible {
        background: var(--pale);
        outline: none;
        transform: scale(1.12);
    }

    .chat-gif-search {
        align-items: center;
        background: var(--mist);
        border: 1px solid var(--line);
        border-radius: 999px;
        color: var(--muted);
        display: flex;
        gap: 8px;
        margin: 10px 10px 6px;
        padding: 6px 12px;
    }

    .chat-gif-search input {
        background: transparent;
        border: 0;
        color: var(--ink);
        flex: 1;
        font: inherit;
        font-size: 13px;
        min-width: 0;
        outline: 0;
    }

    .chat-gif-grid {
        display: grid;
        gap: 6px;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        overflow-y: auto;
        padding: 4px 10px 6px;
    }

    .chat-gif-grid button {
        background: var(--pale);
        border: 0;
        border-radius: 10px;
        cursor: pointer;
        overflow: hidden;
        padding: 0;
    }

    .chat-gif-grid img {
        display: block;
        height: 110px;
        object-fit: cover;
        width: 100%;
    }

    .chat-gif-grid p,
    .chat-gif-credit {
        color: var(--muted);
        font-size: 11px;
        grid-column: 1 / -1;
        margin: 6px 0;
        text-align: center;
    }

    .chat-gif-off {
        padding: 22px 20px;
        text-align: center;
    }

    .chat-gif-off strong {
        color: var(--ink);
        display: block;
        font-size: 14px;
        margin-bottom: 6px;
    }

    .chat-gif-off p {
        color: var(--deep);
        font-size: 13px;
        margin: 0 0 10px;
    }

    .chat-gif-off small {
        color: var(--muted);
        font-size: 11px;
        line-height: 1.45;
    }

    .account-page .chat-composer textarea {
        background: var(--mist);
        border: 1px solid var(--line);
        border-radius: 20px;
        color: var(--ink);
        flex: 1;
        font: inherit;
        font-size: 14px;
        line-height: 1.4;
        max-height: 160px;
        min-height: 42px;
        min-width: 0;
        outline: 0;
        padding: 10px 16px;
        resize: none;
    }

    .account-page .chat-composer textarea:focus {
        border-color: var(--teal);
        box-shadow: 0 0 0 3px rgba(47, 133, 128, 0.15);
    }

    .account-page .chat-composer textarea:disabled {
        cursor: not-allowed;
        opacity: .7;
    }

    .account-page .chat-composer .chat-send-button {
        align-items: center;
        background: var(--mentora-teal);
        border: 0;
        border-radius: 50%;
        color: #fff;
        cursor: pointer;
        display: inline-flex;
        flex: 0 0 42px;
        height: 42px;
        justify-content: center;
        transition: background 160ms ease, opacity 160ms ease, transform 160ms ease;
        width: 42px;
    }

    .account-page .chat-composer .chat-send-button:hover:not(:disabled) {
        background: var(--mentora-gradient);
        transform: translateY(-1px);
    }

    .account-page .chat-composer .chat-send-button:disabled {
        cursor: not-allowed;
        opacity: .4;
    }

    .account-page .chat-composer[hidden] {
        display: none;
    }

    /* Details */
    .account-page .chat-info {
        border-left: 1px solid var(--line);
        border-top: 0;
        overflow-y: auto;
        padding: 26px 18px;
        text-align: center;
    }

    .account-page .chat-info .avatar,
    .account-page .chat-info-avatar {
        align-items: center;
        background: var(--mentora-gradient);
        border-radius: 50%;
        color: #fff;
        display: inline-flex;
        font-size: 26px;
        font-weight: 700;
        height: 76px;
        justify-content: center;
        margin: 0 auto 12px;
        overflow: hidden;
        width: 76px;
    }

    .account-page .chat-info h2 {
        color: var(--ink);
        font-size: 17px;
        margin: 0;
    }

    .account-page .chat-info > small {
        color: var(--muted);
        display: block;
        margin: 4px 0 4px;
    }

    .chat-info-email {
        color: var(--teal);
        font-size: 12px;
        font-weight: 700;
        overflow-wrap: anywhere;
    }

    .chat-info-stats {
        color: var(--muted);
        font-size: 12px;
        margin: 14px 0 6px;
    }

    .account-page .chat-info h3 {
        border-color: var(--line);
        color: var(--ink);
    }

    .account-page .chat-collapse-content {
        color: var(--deep);
        font-size: 12px;
        line-height: 1.45;
        margin: 8px 0 0;
        text-align: left;
    }

    .account-page .chat-collapse-toggle {
        cursor: pointer;
    }

    .account-page .chat-collapse-toggle.is-collapsed span {
        display: inline-block;
        transform: rotate(-90deg);
    }

    .account-page .chat-collapse-content[hidden],
    .chat-shared-group[hidden] {
        display: none;
    }

    /* Shared photos & files (details panel) */
    .chat-shared-count {
        background: var(--teal);
        border-radius: 999px;
        color: #fff;
        font-size: 10px;
        font-style: normal;
        font-weight: 800;
        margin-left: 6px;
        padding: 2px 7px;
        vertical-align: 1px;
    }

    .chat-shared-count:empty {
        display: none;
    }

    .chat-shared-empty {
        color: var(--muted);
        font-size: 12px;
        margin: 0;
    }

    .chat-shared-media {
        display: grid;
        gap: 4px;
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }

    .chat-shared-media a {
        aspect-ratio: 1;
        background: var(--white);
        border-radius: 8px;
        display: block;
        overflow: hidden;
        position: relative;
    }

    .chat-shared-media img {
        display: block;
        height: 100%;
        object-fit: cover;
        transition: transform 200ms ease;
        width: 100%;
    }

    .chat-shared-media a:hover img {
        transform: scale(1.06);
    }

    .chat-shared-media a:focus-visible,
    .chat-shared-file:focus-visible {
        outline: 2px solid var(--teal);
        outline-offset: 1px;
    }

    .chat-shared-media .chat-gif-badge {
        bottom: 4px;
        left: 4px;
    }

    .account-page .chat-info .chat-shared-file {
        background: var(--white);
        color: var(--ink);
        margin: 0 0 6px;
        max-width: none;
        padding: 6px 10px 6px 6px;
    }

    .account-page .chat-info .chat-shared-file:hover {
        background: var(--mist);
    }

    .chat-shared-file .chat-file-type {
        flex-basis: 34px;
        height: 34px;
        width: 34px;
    }

    .chat-shared-file .chat-file-copy strong {
        font-size: 12px;
    }

    .chat-shared-file .chat-file-copy small {
        color: var(--muted);
        font-size: 10px;
    }

    html[data-theme="dark"] .account-page .chat-info .chat-shared-file {
        background: rgba(255, 255, 255, 0.06);
        border-color: rgba(255, 255, 255, 0.12);
        color: #e4f1ef;
    }

    html[data-theme="dark"] .account-page .chat-info .chat-shared-file:hover {
        background: rgba(255, 255, 255, 0.12);
    }

    html[data-theme="dark"] .chat-shared-media a {
        background: rgba(255, 255, 255, 0.06);
    }

    /* ---------- New message ---------- */
    .chat-new-hint {
        align-self: center;
        color: var(--muted);
        font-size: 13px;
        margin: 10px 0;
        text-align: center;
    }

    .chat-sidebar-actions {
        display: flex;
        gap: 6px;
    }

    .account-page .chat-new-button {
        background: var(--teal);
        border-color: var(--teal);
        color: #fff;
    }

    .account-page .chat-new-button:hover {
        background: var(--deep);
        border-color: var(--deep);
    }

    .account-page .chat-empty-list {
        align-items: center;
        display: flex;
        flex-direction: column;
        gap: 12px;
    }

    .account-page .chat-empty-list p {
        margin: 0;
    }

    .account-page .chat-empty-list[hidden] {
        display: none;
    }

    .account-page .chat-thread-empty {
        align-items: center;
        display: flex;
        flex-direction: column;
        gap: 8px;
        justify-content: center;
        padding: 20px;
        text-align: center;
    }

    .account-page .chat-thread-empty strong {
        color: var(--ink);
        font-size: 16px;
    }

    .account-page .chat-thread-empty > span:not(.chat-thread-empty-icon) {
        font-size: 13px;
        margin-bottom: 6px;
        max-width: 320px;
    }

    .chat-thread-empty-icon {
        align-items: center;
        background: var(--pale);
        border-radius: 50%;
        color: var(--teal);
        display: inline-flex;
        height: 64px;
        justify-content: center;
        margin-bottom: 4px;
        width: 64px;
    }

    .chat-thread-empty-icon .chat-svg {
        height: 28px;
        width: 28px;
    }

    .chat-new-dialog {
        width: min(520px, calc(100vw - 32px));
    }

    .chat-new-field {
        display: flex;
        flex-direction: column;
        gap: 6px;
        margin: 4px 0 12px;
    }

    .chat-new-field span {
        color: var(--ink);
        font-size: 13px;
        font-weight: 700;
    }

    .chat-new-field small {
        color: var(--muted);
        font-weight: 400;
    }

    .chat-new-field input {
        background: var(--mist);
        border: 1px solid var(--line);
        border-radius: 12px;
        color: var(--ink);
        font: inherit;
        font-size: 14px;
        padding: 10px 14px;
    }

    .chat-new-field input:focus,
    .chat-new-search:focus-within {
        border-color: var(--teal);
        box-shadow: 0 0 0 3px rgba(47, 133, 128, 0.15);
        outline: 0;
    }

    .chat-new-search {
        align-items: center;
        background: var(--mist);
        border: 1px solid var(--line);
        border-radius: 999px;
        color: var(--muted);
        display: flex;
        gap: 8px;
        padding: 9px 14px;
    }

    .chat-new-search input {
        background: transparent;
        border: 0;
        color: var(--ink);
        flex: 1;
        font: inherit;
        font-size: 14px;
        min-width: 0;
        outline: 0;
    }

    .chat-contact-list {
        margin: 12px -10px 0;
        max-height: min(46vh, 420px);
        overflow-y: auto;
        padding: 0 4px 4px;
    }

    .chat-contact-group {
        color: var(--muted);
        font-size: 10px;
        font-weight: 800;
        letter-spacing: .08em;
        margin: 12px 8px 4px;
        text-transform: uppercase;
    }

    .chat-contact {
        align-items: center;
        background: transparent;
        border: 0;
        border-radius: 12px;
        color: inherit;
        cursor: pointer;
        display: flex;
        font: inherit;
        gap: 12px;
        padding: 9px 8px;
        text-align: left;
        width: 100%;
    }

    .chat-contact[hidden],
    .chat-contact-group[hidden],
    .chat-contact-empty[hidden] {
        display: none;
    }

    .chat-contact:hover,
    .chat-contact:focus-visible {
        background: var(--pale);
        outline: none;
    }

    .chat-contact .avatar {
        align-items: center;
        background: var(--teal);
        border-radius: 50%;
        color: #fff;
        display: inline-flex;
        flex: 0 0 40px;
        font-size: 14px;
        font-weight: 700;
        height: 40px;
        justify-content: center;
        overflow: hidden;
        width: 40px;
    }

    .chat-contact .chat-contact-everyone {
        background: var(--mentora-gradient);
    }

    .chat-contact-copy {
        display: flex;
        flex-direction: column;
        min-width: 0;
    }

    .chat-contact-copy strong {
        color: var(--ink);
        font-size: 14px;
    }

    .chat-contact-copy small {
        color: var(--muted);
        font-size: 12px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .chat-contact-empty {
        color: var(--muted);
        font-size: 13px;
        margin: 18px 8px;
        text-align: center;
    }

    html[data-theme="dark"] .account-page .chat-thread-empty strong,
    html[data-theme="dark"] :is(.chat-new-field span, .chat-contact-copy strong, .chat-new-field input, .chat-new-search input) {
        color: #eef6f5;
    }

    html[data-theme="dark"] :is(.chat-new-field input, .chat-new-search) {
        background: #0f2221;
        border-color: rgba(255, 255, 255, 0.14);
    }

    html[data-theme="dark"] :is(.chat-contact:hover, .chat-contact:focus-visible) {
        background: rgba(143, 239, 218, 0.08);
    }

    html[data-theme="dark"] .chat-thread-empty-icon {
        background: rgba(143, 239, 218, 0.1);
        color: var(--mentora-mint);
    }

    /* ---------- Dark mode ---------- */
    html[data-theme="dark"] body.messages-page {
        background: #0b1a19;
    }

    html[data-theme="dark"] .chat-page-bar h1 {
        color: #eef6f5;
    }

    html[data-theme="dark"] .chat-back-link,
    html[data-theme="dark"] .account-page .chat-icon-button {
        background: rgba(255, 255, 255, 0.06);
        border-color: rgba(255, 255, 255, 0.12);
        color: #cfeee9;
    }

    html[data-theme="dark"] .account-page .chat-workspace {
        background: #132a29;
        border-color: rgba(255, 255, 255, 0.1);
        box-shadow: 0 12px 32px rgba(0, 0, 0, 0.35);
    }

    html[data-theme="dark"] .account-page .chat-conversation.selected,
    html[data-theme="dark"] .account-page .chat-conversation:hover {
        background: rgba(143, 239, 218, 0.08);
    }

    html[data-theme="dark"] .account-page .chat-conversation {
        border-bottom-color: rgba(255, 255, 255, 0.05);
    }

    html[data-theme="dark"] .chat-day {
        background: rgba(255, 255, 255, 0.06);
        color: #9cb7b4;
    }

    html[data-theme="dark"] .chat-bubble-theirs {
        background: #183533;
        color: #e4f1ef;
    }

    html[data-theme="dark"] .account-page .chat-composer textarea {
        background: #0f2221;
        border-color: rgba(255, 255, 255, 0.14);
        color: #e4f1ef;
    }

    html[data-theme="dark"] .chat-info-email {
        color: var(--mentora-mint);
    }

    html[data-theme="dark"] .account-page .chat-error {
        background: rgba(240, 68, 56, 0.14);
        border-top-color: rgba(240, 68, 56, 0.3);
        color: #fda29b;
    }

    html[data-theme="dark"] .account-page .chat-collapse-content {
        color: #9cb7b4;
    }

    html[data-theme="dark"] :is(.chat-picker, .chat-chip-remove) {
        border-color: rgba(255, 255, 255, 0.12);
    }

    html[data-theme="dark"] .chat-picker {
        background: #132a29;
        box-shadow: 0 18px 44px rgba(0, 0, 0, 0.5);
    }

    html[data-theme="dark"] :is(.chat-picker-tabs, .chat-emoji-categories) {
        border-color: rgba(255, 255, 255, 0.1);
    }

    html[data-theme="dark"] .chat-picker-tabs button:not([aria-selected="true"]) {
        color: #cfeee9;
    }

    html[data-theme="dark"] :is(.chat-emoji-grid button:hover, .chat-emoji-grid button:focus-visible, .chat-emoji-categories button[aria-pressed="true"]) {
        background: rgba(143, 239, 218, 0.1);
    }

    html[data-theme="dark"] .account-page .chat-composer .chat-tool {
        color: var(--mentora-mint);
    }

    html[data-theme="dark"] .account-page .chat-composer .chat-tool:hover:not(:disabled),
    html[data-theme="dark"] .account-page .chat-composer .chat-tool[aria-expanded="true"] {
        background: rgba(143, 239, 218, 0.1);
    }

    html[data-theme="dark"] :is(.chat-chip, .chat-gif-search) {
        background: #0f2221;
        border-color: rgba(255, 255, 255, 0.12);
    }

    html[data-theme="dark"] :is(.chat-chip-copy strong, .chat-gif-search input, .chat-gif-off strong) {
        color: #eef6f5;
    }

    html[data-theme="dark"] .chat-gif-off p {
        color: #cfe3e0;
    }

    html[data-theme="dark"] .account-page .chat-composer .chat-chip-remove {
        border-color: #132a29;
    }

    html[data-theme="dark"] .chat-composer-note {
        background: rgba(240, 68, 56, 0.14);
        color: #fda29b;
    }

    html[data-theme="dark"] .chat-bubble-theirs .chat-file {
        background: rgba(255, 255, 255, 0.06);
        border-color: rgba(255, 255, 255, 0.12);
    }

    html[data-theme="dark"] .chat-bubble-theirs .chat-file:hover {
        background: rgba(255, 255, 255, 0.12);
    }

    /* ---------- Tablet: list | conversation ---------- */
    @media (max-width: 1024px) {
        .account-page .chat-workspace {
            grid-template-columns: 280px minmax(0, 1fr);
        }

        .account-page .chat-info {
            display: none;
        }
    }

    /* ---------- Phone: list, then the conversation full-width with a Back button ---------- */
    @media (max-width: 760px) {
        .chat-page {
            gap: 10px;
            height: 100dvh;
            min-height: 460px;
            padding: 10px 10px 12px;
        }

        .chat-page-bar h1 {
            font-size: 24px;
        }

        .chat-back-link {
            padding: 7px 12px 7px 9px;
        }

        .account-page .chat-workspace {
            border-radius: 16px;
            grid-template-columns: 1fr;
        }

        .account-page .chat-workspace:not(.is-thread-open) .chat-thread,
        .account-page .chat-workspace.is-thread-open .chat-sidebar {
            display: none;
        }

        .account-page .chat-sidebar {
            border-right: 0;
        }

        .account-page .chat-back {
            display: inline-flex;
        }

        .account-page .chat-thread-header {
            gap: 10px;
            padding: 10px 12px;
        }

        .chat-bubble {
            max-width: 88%;
        }

        .account-page .chat-composer {
            padding: 10px;
        }
    }
</style>

<main class="chat-page" data-chat data-selected="<?= $h($selectedKey) ?>" data-token="<?= $h($messageActionToken) ?>" data-user="<?= $userId ?>">
    <div class="chat-page-bar">
        <a class="chat-back-link" href="<?= $h($homeHref) ?>">
            <svg class="chat-svg" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m15 6-6 6 6 6"/></svg>  
        </a>
        <h1>Messages</h1>
        <div class="chat-page-tools">
            <span class="chat-page-unread" data-total-unread<?= $totalUnread ? '' : ' hidden' ?>><?= $totalUnread ?> unread</span>
        </div>
    </div>

    <div class="chat-workspace">
        <aside class="chat-sidebar" aria-label="Conversations">
            <div class="chat-sidebar-heading">
                <h2>Chats</h2>
                <div class="chat-sidebar-actions">
                    <button class="chat-icon-button chat-new-button"
                        type="button"
                        data-bs-toggle="dialog"
                        data-bs-target="#chatNewDialog"
                        aria-label="New message"
                        title="New message">
                        <svg class="chat-svg" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                            <path d="M12 20h9"/>
                            <path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/>
                        </svg>
                    </button>
                </div>
            </div>
            <label class="chat-search">
                <svg class="chat-svg" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="11" cy="11" r="6.5"/><path d="m16 16 4.5 4.5"/></svg>
                <input class="chat-search-input" type="search" placeholder="Search messages" aria-label="Search messages">
            </label>
            <div class="chat-tabs" role="group" aria-label="Filter conversations">
                <button class="active" type="button" data-filter="all" aria-pressed="true">All</button>
                <button type="button" data-filter="unread" aria-pressed="false">Unread</button>
                <button type="button" data-filter="archived" aria-pressed="false">Archived</button>
            </div>
            <div class="chat-conversation-list">
                <?php if (!$conversations): ?>
                    <div class="chat-empty-list" data-empty-list>
                        <p>No conversations yet.</p>
                        <button class="btn-solid theme-primary" type="button" data-bs-toggle="dialog" data-bs-target="#chatNewDialog">New message</button>
                    </div>
                <?php else: ?>
                    <?php foreach ($conversations as $conversation):
                        $last = $conversation['messages'] ? end($conversation['messages']) : null;
                        $searchText = mb_strtolower($conversation['name'] . ' ' . $conversation['members'] . ' ' . implode(' ', array_map(
                            static fn(array $message): string => $message['subject'] . ' ' . mentora_plain_mentions((string) $message['body']) . ' '
                                . implode(' ', array_column($attachments[(int) $message['id']] ?? [], 'original_name')),
                            $conversation['messages']
                        )));
                        $lastPreview = $last === null ? 'No messages yet' : (trim((string) $last['body']) !== ''
                            ? mentora_plain_mentions((string) $last['body'])
                            : $attachmentSummary($attachments[(int) $last['id']] ?? []));
                        // Who wrote the newest message: "You: …", or in a group "Ana: …".
                        $lastAuthor = '';
                        if ($last !== null && $last['mine']) {
                            $lastAuthor = 'You: ';
                        } elseif ($last !== null && $conversation['isGroup'] && isset($people[(int) $last['sender_id']])) {
                            $lastAuthor = $people[(int) $last['sender_id']]['first_name'] . ': ';
                        }
                        $listDate = $last['created_at'] ?? $conversation['startedAt'];
                        $empty = $last === null;
                    ?>
                        <?php // Row: the conversation, plus Archive / Delete (slide it left, or the ⋯ menu; on every device). ?>
                        <div class="chat-conversation-row<?= $empty ? ' is-empty' : '' ?>" data-conversation-row<?= $conversation['archived'] ? ' hidden' : '' ?>>
                        <?php if (!$empty): // nothing to archive or delete in a chat with no messages ?>
                        <div class="chat-row-actions" data-row-actions>
                            <button class="chat-row-action" type="button" data-row-archive
                                aria-label="<?= $conversation['archived'] ? 'Move back to Chats' : 'Archive conversation' ?>"
                                title="<?= $conversation['archived'] ? 'Move back to Chats' : 'Archive' ?>" tabindex="-1">
                                <svg class="chat-svg" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><rect x="3" y="4" width="18" height="4.5" rx="1"/><path d="M5 8.5V18a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8.5"/><path d="M10 12.5h4"/></svg>
                            </button>
                            <button class="chat-row-action is-danger" type="button" data-row-delete aria-label="Delete conversation" title="Delete" tabindex="-1">
                                <svg class="chat-svg" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4 7h16"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M6 7l1 12a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-12"/><path d="M9 7V4h6v3"/></svg>
                            </button>
                        </div>
                        <?php endif; ?>
                        <button
                            class="chat-conversation<?= $conversation['unread'] ? ' unread' : '' ?>"
                            type="button"
                            aria-controls="chat-thread-panel"
                            data-conversation="<?= $h($conversation['key']) ?>"
                            data-name="<?= $h($conversation['name']) ?>"
                            data-role="<?= $h($conversation['role']) ?>"
                            data-email="<?= $h($conversation['email']) ?>"
                            data-count="<?= count($conversation['messages']) ?>"
                            data-unread="<?= (int) $conversation['unread'] ?>"
                            data-can-reply="<?= $conversation['canReply'] ? '1' : '0' ?>"
                            data-recipient="<?= $h($conversation['recipientId']) ?>"
                            data-reply-subject="<?= $h($conversation['replySubject']) ?>"
                            data-support="<?= $conversation['key'] === 'support' || $conversation['role'] === 'Mentora team' ? '1' : '0' ?>"
                            data-archived="<?= $conversation['archived'] ? '1' : '0' ?>"
                            data-can-call="<?= $conversation['canCall'] ? '1' : '0' ?>"
                            data-group="<?= $conversation['isGeneral'] ? 'general' : ($conversation['isGroup'] ? 'group' : '') ?>"
                            data-members="<?= $h($conversation['members']) ?>"
                            data-member-ids="<?= $h($conversation['memberIds']) ?>"
                            data-member-count="<?= (int) $conversation['memberCount'] ?>"
                            data-owner-id="<?= $h($conversation['ownerId']) ?>"
                            data-search="<?= $h($searchText) ?>">
                            <?= $conversation['avatar'] ?>
                            <span class="chat-conversation-copy">
                                <span class="chat-conversation-top">
                                    <strong><?= $h($conversation['name']) ?></strong>
                                    <time datetime="<?= $h($listDate) ?>"><?= $h($listTime($toDate($listDate))) ?></time>
                                </span>
                                <span><?= $h($conversation['isGroup'] ? $conversation['role'] : ($last['subject'] ?? '')) ?></span>
                                <small><?= $h($lastAuthor . $lastPreview) ?></small>
                            </span>
                            <?php if ($conversation['unread']): ?>
                                <span class="chat-unread-count"
                                    aria-label="<?= (int) $conversation['unread'] ?> unread"><?= (int) $conversation['unread'] ?></span>
                            <?php endif; ?>
                        </button>
                        <?php if (!$empty): ?>
                        <button class="chat-row-more" type="button" data-row-more aria-haspopup="menu" aria-expanded="false"
                            aria-label="More options for <?= $h($conversation['name']) ?>" title="More options">
                            <svg class="chat-svg" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="5.5" cy="12" r="1.3"/><circle cx="12" cy="12" r="1.3"/><circle cx="18.5" cy="12" r="1.3"/></svg>
                        </button>
                        <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                    <p class="chat-empty-list" data-no-results hidden>No conversations match.</p>
                <?php endif; ?>
            </div>
        </aside>

        <section class="chat-thread" id="chat-thread-panel" aria-label="Conversation">
            <header class="chat-thread-header">
                <button class="chat-icon-button chat-back" type="button" data-action="back" aria-label="Back to chats">
                    <svg class="chat-svg" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m15 6-6 6 6 6"/></svg>
                </button>
                <span class="chat-avatar" data-thread-avatar></span>
                <div class="chat-thread-person">
                    <strong data-thread-name>Messages</strong>
                    <small data-thread-role>Choose a conversation</small>
                </div>
                <div class="chat-thread-actions" data-thread-actions hidden>
                    <?php // Group chats you made or joined (not General): members (the owner can remove people), add people, leave.
                          // On desktop these are in the details panel instead (CSS .chat-header-group-button). ?>
                    <button class="chat-icon-button chat-header-group-button" type="button" data-group-members aria-label="Members" title="Members" hidden>
                        <svg class="chat-svg" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="9" cy="8" r="3.2"/><path d="M3.5 19a5.5 5.5 0 0 1 11 0"/><circle cx="17" cy="9" r="2.5"/><path d="M15.5 14.2A4.5 4.5 0 0 1 21 18.5"/></svg>
                    </button>
                    <button class="chat-icon-button chat-header-group-button" type="button" data-group-add aria-label="Add people" title="Add people" hidden>
                        <svg class="chat-svg" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="9" cy="8" r="3.2"/><path d="M3.5 19a5.5 5.5 0 0 1 11 0"/><path d="M18 8v6M15 11h6"/></svg>
                    </button>
                    <button class="chat-icon-button chat-danger-button chat-header-group-button" type="button" data-group-leave aria-label="Leave group" title="Leave group" hidden>
                        <svg class="chat-svg" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M14 4h4a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-4"/><path d="M10 16l-4-4 4-4"/><path d="M6 12h10"/></svg>
                    </button>
                    <?php // Voice and video calls (assets/js/video-call.js): only with one person you can message. ?>
                    <button class="chat-icon-button" type="button" data-call-start="voice" aria-label="Voice call" title="Voice call" hidden>
                        <svg class="chat-svg" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M5 4h3.5l1.5 4.5-2.2 1.4a11 11 0 0 0 6.3 6.3l1.4-2.2L20 15.5V19a1.5 1.5 0 0 1-1.6 1.5A16 16 0 0 1 3.5 5.6 1.5 1.5 0 0 1 5 4Z"/></svg>
                    </button>
                    <button class="chat-icon-button" type="button" data-call-start="video" aria-label="Video call" title="Video call" hidden>
                        <svg class="chat-svg" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><rect x="3" y="6.5" width="12.5" height="11" rx="2.5"/><path d="m15.5 10.5 5-3v9l-5-3"/></svg>
                    </button>
                </div>
            </header>

            <?php // A group call going on in this group chat (assets/js/video-call.js keeps it up to date). ?>
            <div class="chat-call-banner" data-group-call-banner hidden>
                <span class="chat-call-banner-dot" aria-hidden="true"></span>
                <span data-group-call-text></span>
                <button class="chat-call-banner-join" type="button" data-group-call-join>Join</button>
            </div>

            <div class="chat-thread-body" data-thread-body aria-live="polite">
                <div class="chat-thread-empty">
                    <span class="chat-thread-empty-icon" aria-hidden="true">
                        <svg class="chat-svg" viewBox="0 0 24 24" focusable="false">
                            <path d="M20 11.5a7.5 7.5 0 0 1-8 7.5 8.8 8.8 0 0 1-3-.5L4 20l1.5-4A7.3 7.3 0 0 1 4.5 12 7.5 7.5 0 0 1 12 4.5a7.5 7.5 0 0 1 8 7Z"/>
                        </svg>
                    </span>
                    <strong><?= $conversations ? 'Select a conversation' : 'Start a conversation' ?></strong>
                    <span><?= $conversations
                        ? 'Or start a new one with someone in your workspace.'
                        : 'Message HR, agents' . (in_array('applicant', $contactRoles, true) ? ', interns and employees' : '') . ' in your workspace.' ?></span>
                    <button class="btn-solid theme-primary" type="button" data-bs-toggle="dialog" data-bs-target="#chatNewDialog">New message</button>
                </div>
                <?php foreach ($conversations as $conversation): ?>
                    <div class="chat-thread-messages" data-thread="<?= $h($conversation['key']) ?>" hidden>
                        <?php if (!$conversation['messages']): ?>
                            <p class="chat-new-hint"><?= $h($conversation['isGeneral']
                                ? 'This is General: everyone in Mentora can read and write here.' . ($isAdmin ? ' Write @everyone to notify all of them.' : '')
                                : 'No messages yet. Say hello 👋') ?></p>
                        <?php endif; ?>
                        <?php
                        $previousDay = '';
                        $previousSubject = '';
                        $previousAuthor = null;
                        foreach ($conversation['messages'] as $message):
                            $date = $toDate($message['created_at']);
                            $day = $dayLabel($date);
                            $subject = trim((string) $message['subject']);
                            // Group chats: who wrote it, above the first of their messages in a row.
                            $author = $message['mine'] || !$conversation['isGroup'] ? null : (int) ($message['sender_id'] ?? 0);
                            $showAuthor = $author !== null && ($author !== $previousAuthor || $day !== $previousDay);
                            $previousAuthor = $message['mine'] ? null : $author;
                        ?>
                            <?php if ($day !== $previousDay): $previousDay = $day; ?>
                                <p class="chat-day"><?= $h($day) ?></p>
                            <?php endif; ?>
                            <?php if ($conversation['isGroup'] && $message['sender_id'] === null): // "Ana added Ben.", "Ana left the group." ?>
                                <p class="chat-system-note" data-message-id="<?= (int) $message['id'] ?>"><?= $h($message['body']) ?></p>
                                <?php $previousAuthor = null; continue; ?>
                            <?php endif; ?>
                            <article class="chat-bubble <?= $message['mine'] ? 'chat-bubble-mine' : 'chat-bubble-theirs' ?>" data-message-id="<?= (int) $message['id'] ?>">
                                <?php // Delete: "for me" on any message; "for everyone" too on your own. ?>
                                <button class="chat-bubble-delete" type="button" data-delete-message="<?= (int) $message['id'] ?>" aria-label="Delete this message" title="Delete message">
                                    <svg class="chat-svg" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4 7h16"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M6 7l1 12a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-12"/><path d="M9 7V4h6v3"/></svg>
                                </button>
                                <?php if ($showAuthor): ?>
                                    <span class="chat-bubble-author"><?= $h(isset($people[$author]) ? trim($people[$author]['first_name'] . ' ' . $people[$author]['last_name']) : ($author ? 'Former member' : 'Mentora')) ?></span>
                                <?php endif; ?>
                                <?php if ($subject !== '' && strcasecmp(preg_replace('/^re:\s*/i', '', $subject), 'Message') !== 0
                                    && preg_replace('/^re:\s*/i', '', $subject) !== preg_replace('/^re:\s*/i', '', $previousSubject)): ?>
                                    <h3 class="chat-bubble-subject"><?= $h($subject) ?></h3>
                                <?php endif; $previousSubject = $subject; ?>
                                <?php
                                $items = $attachments[(int) $message['id']] ?? [];
                                $pictures = array_values(array_filter($items, static fn(array $a): bool => $a['kind'] !== 'file'));
                                $documents = array_values(array_filter($items, static fn(array $a): bool => $a['kind'] === 'file'));
                                ?>
                                <?php if ($pictures): ?>
                                    <div class="chat-media<?= count($pictures) > 1 ? ' chat-media-grid' : '' ?>">
                                        <?php foreach ($pictures as $picture): ?>
                                            <?php if ($picture['kind'] === 'gif'): ?>
                                                <span class="chat-media-item chat-media-gif">
                                                    <img src="<?= $h($picture['url']) ?>" alt="<?= $h($picture['original_name']) ?>" loading="lazy" referrerpolicy="no-referrer"
                                                        <?= $picture['width']
                                                            ? 'width="' . (int) $picture['width'] . '" height="' . (int) $picture['height'] . '"'
                                                            : '' ?>>
                                                    <span class="chat-gif-badge">GIF</span>
                                                </span>
                                            <?php else: ?>
                                                <a class="chat-media-item"
                                                    href="attachment.php?id=<?= (int) $picture['id'] ?>"
                                                    target="_blank"
                                                    rel="noopener"
                                                    title="Open <?= $h($picture['original_name']) ?>">
                                                    <img src="attachment.php?id=<?= (int) $picture['id'] ?>" alt="<?= $h($picture['original_name']) ?>" loading="lazy"
                                                        <?= $picture['width']
                                                            ? 'width="' . (int) $picture['width'] . '" height="' . (int) $picture['height'] . '"'
                                                            : '' ?>>
                                                </a>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                                <?php foreach ($documents as $document):
                                    $extension = strtoupper(pathinfo((string) $document['original_name'], PATHINFO_EXTENSION)) ?: 'FILE';
                                ?>
                                    <a class="chat-file" href="attachment.php?id=<?= (int) $document['id'] ?>&amp;download=1" download>
                                        <span class="chat-file-type"><?= $h(mb_substr($extension, 0, 4)) ?></span>
                                        <span class="chat-file-copy">
                                            <strong><?= $h($document['original_name']) ?></strong>
                                            <small><?= $h($formatBytes((int) $document['size'])) ?> · Download</small>
                                        </span>
                                    </a>
                                <?php endforeach; ?>
                                <?php if (trim((string) $message['body']) !== ''): ?>
                                    <p class="chat-message-text"><?= $formatBody($message['body']) ?></p>
                                <?php endif; ?>
                                <time datetime="<?= $h($message['created_at']) ?>"><?= $h($date->format('g:i A')) ?></time>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php // "Ana is typing…" (group chats and General; account/typing.php) ?>
            <div class="chat-typing" data-typing aria-live="polite" hidden>
                <span class="chat-typing-dots" aria-hidden="true"><i></i><i></i><i></i></span>
                <span data-typing-text></span>
            </div>
            <?php if ($messageError): ?><p class="chat-error" role="alert"><?= $h($messageError) ?></p><?php endif; ?>
            <!-- Emoji + GIF picker (opens above the message box) -->
            <div class="chat-picker" data-picker role="dialog" aria-label="Emoji and GIFs" hidden>
                <div class="chat-picker-tabs" role="tablist">
                    <button type="button" role="tab" aria-selected="true" data-picker-tab="emoji">😊 Emoji</button>
                    <button type="button" role="tab" aria-selected="false" data-picker-tab="gif">GIF</button>
                    <button class="chat-picker-close" type="button" data-picker-close aria-label="Close">×</button>
                </div>
                <div class="chat-picker-panel" data-picker-panel="emoji">
                    <div class="chat-emoji-categories" data-emoji-categories></div>
                    <div class="chat-emoji-grid" data-emoji-grid></div>
                </div>
                <div class="chat-picker-panel" data-picker-panel="gif" hidden>
                    <?php if ($giphyKey !== ''): ?>
                        <label class="chat-gif-search">
                            <svg class="chat-svg" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                <circle cx="11" cy="11" r="6.5"/>
                                <path d="m16 16 4.5 4.5"/>
                            </svg>
                            <input type="search" placeholder="Search GIFs" aria-label="Search GIFs" data-gif-search autocomplete="off">
                        </label>
                        <div class="chat-gif-grid" data-gif-grid aria-live="polite"></div>
                        <p class="chat-gif-credit">Powered by GIPHY</p>
                    <?php else: ?>
                        <div class="chat-gif-off">
                            <strong>GIF search isn't set up yet</strong>
                            <p>You can still send a GIF file: use the 📎 button.</p>
                            <small>For administrators: add a free GIPHY API key in config/giphy.local.php (see config/giphy.local.example.php).</small>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <form class="chat-composer chat-composer-form" method="post" enctype="multipart/form-data" data-composer>
                <input type="hidden" name="message_action_token" value="<?= $h($messageActionToken) ?>">
                <?php // Not on the Send button: it is disabled while sending (no double sends), and browsers leave out disabled buttons. ?>
                <input type="hidden" name="send_message" value="1">
                <input class="chat-recipient-id" type="hidden" name="recipient_id" value="">
                <input class="chat-subject" type="hidden" name="subject" value="">
                <input type="hidden" name="gif_url" value="" data-gif-field="url">
                <input type="hidden" name="gif_title" value="" data-gif-field="title">
                <input type="hidden" name="gif_width" value="" data-gif-field="width">
                <input type="hidden" name="gif_height" value="" data-gif-field="height">
                <input class="sr-only" type="file" name="attachments[]" multiple data-attach-input tabindex="-1" aria-hidden="true"
                    accept="image/jpeg,image/png,image/webp,image/gif,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv,.zip">

                <div class="chat-composer-attachments" data-attachments hidden></div>
                <p class="chat-composer-note" data-composer-note role="alert" hidden></p>

                <div class="chat-composer-row">
                    <button class="chat-tool"
                        type="button"
                        data-attach
                        aria-label="Attach files or photos"
                        title="Attach files or photos (up to <?= MENTORA_MESSAGE_MAX_FILES ?>, <?= $h($formatBytes(MENTORA_MESSAGE_MAX_BYTES)) ?> each)"
                        disabled>
                        <svg class="chat-svg" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                            <path d="m20 11.5-8.2 8.2a5 5 0 0 1-7.1-7.1l8.6-8.6a3.4 3.4 0 0 1 4.8 4.8l-8.6 8.6a1.7 1.7 0 0 1-2.4-2.4l8-8"/>
                        </svg>
                    </button>
                    <button class="chat-tool"
                        type="button"
                        data-picker-toggle
                        aria-label="Emoji and GIFs"
                        aria-expanded="false"
                        title="Emoji and GIFs"
                        disabled>
                        <svg class="chat-svg" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                            <circle cx="12" cy="12" r="9"/>
                            <path d="M8.5 14.5a4.5 4.5 0 0 0 7 0M9 9.5h.01M15 9.5h.01"/>
                        </svg>
                    </button>
                    <textarea class="chat-message-input"
                        name="body"
                        rows="1"
                        maxlength="5000"
                        placeholder="Choose a conversation or start a new one"
                        aria-label="Write a message"
                        disabled><?= $h($draftBody) ?></textarea>
                    <button class="chat-send-button" type="submit" aria-label="Send message" title="Send (Enter)" disabled>
                        <svg class="chat-svg" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4 12 20 4l-6 16-3-7-7-1Z"/></svg>
                    </button>
                </div>
            </form>
        </section>

        <aside class="chat-info" aria-label="Conversation details">
            <div data-info-avatar><span class="chat-info-avatar">M</span></div>
            <h2 class="chat-info-sender" data-info-name>Messages</h2>
            <small data-info-role>Mentora</small>
            <a class="chat-info-email" data-info-email hidden></a>
            <p class="chat-info-stats" data-info-stats></p>
            <?php // Group chats (not General): members, add people, leave. Phones and tablets have them in the conversation header. ?>
            <div class="chat-info-group-actions" data-info-group-actions hidden>
                <button class="chat-info-action" type="button" data-group-members>
                    <span class="chat-info-action-icon" aria-hidden="true"><svg class="chat-svg" viewBox="0 0 24 24" focusable="false"><circle cx="9" cy="8" r="3.2"/><path d="M3.5 19a5.5 5.5 0 0 1 11 0"/><circle cx="17" cy="9" r="2.5"/><path d="M15.5 14.2A4.5 4.5 0 0 1 21 18.5"/></svg></span>
                    <span>Members</span>
                </button>
                <button class="chat-info-action" type="button" data-group-add>
                    <span class="chat-info-action-icon" aria-hidden="true"><svg class="chat-svg" viewBox="0 0 24 24" focusable="false"><circle cx="9" cy="8" r="3.2"/><path d="M3.5 19a5.5 5.5 0 0 1 11 0"/><path d="M18 8v6M15 11h6"/></svg></span>
                    <span>Add people</span>
                </button>
                <button class="chat-info-action is-danger" type="button" data-group-leave>
                    <span class="chat-info-action-icon" aria-hidden="true"><svg class="chat-svg" viewBox="0 0 24 24" focusable="false"><path d="M14 4h4a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-4"/><path d="M10 16l-4-4 4-4"/><path d="M6 12h10"/></svg></span>
                    <span>Leave</span>
                </button>
            </div>
            <?php
            // Everything shared in each conversation, newest first: photos/GIFs and files.
            $shared = [];
            foreach ($conversations as $conversation) {
                $shared[$conversation['key']] = ['media' => [], 'files' => []];
                foreach (array_reverse($conversation['messages']) as $message) {
                    foreach ($attachments[(int) $message['id']] ?? [] as $attachment) {
                        $shared[$conversation['key']][$attachment['kind'] === 'file' ? 'files' : 'media'][] = $attachment + ['sent_at' => $message['created_at']];
                    }
                }
            }
            ?>
            <h3 class="chat-collapse-toggle" tabindex="0" role="button" aria-expanded="true">Photos &amp; GIFs<em class="chat-shared-count" data-shared-count="media"></em> <span aria-hidden="true">⌄</span></h3>
            <div class="chat-collapse-content">
                <p class="chat-shared-empty chat-shared-group" data-shared="" data-shared-type="media">Choose a conversation to see its photos.</p>
                <?php foreach ($shared as $key => $items): ?>
                    <div class="chat-shared-group" data-shared="<?= $h($key) ?>" data-shared-type="media" data-count="<?= count($items['media']) ?>" hidden>
                        <?php if (!$items['media']): ?>
                            <p class="chat-shared-empty">No photos or GIFs shared yet.</p>
                        <?php else: ?>
                            <div class="chat-shared-media">
                                <?php foreach ($items['media'] as $picture): ?>
                                    <?php if ($picture['kind'] === 'gif'): ?>
                                        <a href="<?= $h($picture['url']) ?>" target="_blank" rel="noopener noreferrer" title="<?= $h($picture['original_name']) ?>" data-shared-message="<?= (int) $picture['message_id'] ?>">
                                            <img src="<?= $h($picture['url']) ?>" alt="<?= $h($picture['original_name']) ?>" loading="lazy" referrerpolicy="no-referrer">
                                            <span class="chat-gif-badge">GIF</span>
                                        </a>
                                    <?php else: ?>
                                        <a href="attachment.php?id=<?= (int) $picture['id'] ?>" target="_blank" rel="noopener" title="<?= $h($picture['original_name']) ?>" data-shared-message="<?= (int) $picture['message_id'] ?>">
                                            <img src="attachment.php?id=<?= (int) $picture['id'] ?>" alt="<?= $h($picture['original_name']) ?>" loading="lazy">
                                        </a>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <h3 class="chat-collapse-toggle" tabindex="0" role="button" aria-expanded="true">Files<em class="chat-shared-count" data-shared-count="files"></em> <span aria-hidden="true">⌄</span></h3>
            <div class="chat-collapse-content">
                <p class="chat-shared-empty chat-shared-group" data-shared="" data-shared-type="files">Choose a conversation to see its files.</p>
                <?php foreach ($shared as $key => $items): ?>
                    <div class="chat-shared-group" data-shared="<?= $h($key) ?>" data-shared-type="files" data-count="<?= count($items['files']) ?>" hidden>
                        <?php if (!$items['files']): ?>
                            <p class="chat-shared-empty">No files shared yet.</p>
                        <?php endif; ?>
                        <?php foreach ($items['files'] as $document):
                            $extension = strtoupper(pathinfo((string) $document['original_name'], PATHINFO_EXTENSION)) ?: 'FILE';
                        ?>
                            <a class="chat-file chat-shared-file" href="attachment.php?id=<?= (int) $document['id'] ?>&amp;download=1" download title="<?= $h($document['original_name']) ?>" data-shared-message="<?= (int) $document['message_id'] ?>">
                                <span class="chat-file-type"><?= $h(mb_substr($extension, 0, 4)) ?></span>
                                <span class="chat-file-copy">
                                    <strong><?= $h($document['original_name']) ?></strong>
                                    <small><?= $h($formatBytes((int) $document['size'])) ?> · <?= $h($toDate($document['sent_at'])->format('d M Y')) ?></small>
                                </span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </aside>
    </div>

    <!-- New message: choose who to write to -->
    <?php
    $contactGroups = [];
    foreach ($contacts as $contact) {
        $group = match ($contact['role']) {
            'admin' => 'HR',
            'agent' => 'Agents',
            default => $contact['pathway'] === 'internship' ? 'Interns' : 'Employees',
        };
        $contactGroups[$group][] = $contact;
    }
    ?>
    <dialog class="dialog dialog-slide-down chat-new-dialog" id="chatNewDialog" aria-labelledby="chatNewTitle">
        <div class="dialog-header">
            <h1 class="dialog-title" id="chatNewTitle">New message</h1>
            <button type="button" class="btn-close" data-bs-dismiss="dialog" aria-label="Close"></button>
        </div>
        <div class="dialog-body">
            <label class="chat-new-field">
                <span>Topic <small>(optional)</small></span>
                <input type="text" maxlength="120" data-contact-topic>
            </label>
            <label class="chat-new-search">
                <svg class="chat-svg" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="11" cy="11" r="6.5"/><path d="m16 16 4.5 4.5"/></svg>
                <input type="search" placeholder="Search people" aria-label="Search people" data-contact-search autocomplete="off">
            </label>
            <div class="chat-contact-list" data-contact-list>
                <p class="chat-contact-group">Group chats</p>
                <?php // General: everyone's in it (HR admins: write @everyone to notify all). Opens the chat already in the list. ?>
                <button class="chat-contact"
                    type="button"
                    data-contact="g<?= mentora_general_group_id($pdo) ?>"
                    data-recipient="g<?= mentora_general_group_id($pdo) ?>"
                    data-name="General"
                    data-role="Everyone in Mentora"
                    data-email=""
                    data-search="general everyone announcement all group">
                    <span class="avatar avatar-initials chat-contact-everyone" aria-hidden="true">
                        <svg class="chat-svg" viewBox="0 0 24 24" focusable="false">
                            <path d="M3 11v2a1 1 0 0 0 1 1h2l5 4V6L6 10H4a1 1 0 0 0-1 1Z"/>
                            <path d="M15.5 8.5a5 5 0 0 1 0 7M18.5 5.5a9 9 0 0 1 0 13"/>
                        </svg>
                    </span>
                    <span class="chat-contact-copy"><strong>General</strong><small><?= $isAdmin ? 'Everyone in Mentora · write @everyone to notify all' : 'Everyone in Mentora' ?></small></span>
                </button>
                <button class="chat-contact chat-contact-new-group" type="button" data-new-group data-search="new group chat create">
                    <span class="avatar avatar-initials chat-group-avatar" aria-hidden="true">
                        <svg class="chat-svg" viewBox="0 0 24 24" focusable="false"><circle cx="9" cy="8" r="3.2"/><path d="M3.5 19a5.5 5.5 0 0 1 11 0"/><path d="M18 8v6M15 11h6"/></svg>
                    </span>
                    <span class="chat-contact-copy"><strong>New group</strong><small>Name it and choose who's in it</small></span>
                </button>
                <?php foreach ($contactGroups as $group => $groupContacts): ?>
                    <p class="chat-contact-group" data-contact-group><?= $h($group) ?></p>
                    <?php foreach ($groupContacts as $contact):
                        $contactName = trim($contact['first_name'] . ' ' . $contact['last_name']);
                    ?>
                        <button class="chat-contact" type="button"
                            data-contact="u<?= (int) $contact['id'] ?>"
                            data-recipient="<?= (int) $contact['id'] ?>"
                            data-name="<?= $h($contactName) ?>"
                            data-role="<?= $h($roleLabel($contact)) ?>"
                            data-email="<?= $h($contact['email']) ?>"
                            data-search="<?= $h(mb_strtolower($contactName . ' ' . $contact['email'] . ' ' . $roleLabel($contact) . ' ' . $group)) ?>">
                            <?= mentora_avatar(mentora_avatar_url($contact['avatar_path'] ?? null, '../'), $initialsOf($contactName)) ?>
                            <span class="chat-contact-copy">
                                <strong><?= $h($contactName) ?></strong>
                                <small><?= $h($roleLabel($contact)) ?> · <?= $h($contact['email']) ?></small>
                            </span>
                        </button>
                    <?php endforeach; ?>
                <?php endforeach; ?>
                <p class="chat-contact-empty" data-contact-empty hidden>No one matches your search.</p>
            </div>
        </div>
    </dialog>

    <!-- Delete a message: "Delete for me" (only your chat) or, on your own messages, "Delete for everyone". -->
    <dialog class="dialog dialog-slide-down chat-delete-dialog" id="chatDeleteDialog" aria-labelledby="chatDeleteTitle" aria-describedby="chatDeleteText">
        <div class="dialog-header">
            <h1 class="dialog-title" id="chatDeleteTitle">Delete this message?</h1>
            <button type="button" class="btn-close" data-bs-dismiss="dialog" aria-label="Close"></button>
        </div>
        <div class="dialog-body">
            <p id="chatDeleteText" data-delete-text></p>
        </div>
        <div class="dialog-footer chat-delete-choices">
            <button type="button" class="btn-solid theme-secondary" data-bs-dismiss="dialog" data-delete-cancel>Cancel</button>
            <button type="button" class="btn-solid theme-secondary" data-delete-choice="me">Delete for me</button>
            <button type="button" class="btn-solid theme-danger" data-delete-choice="everyone">Delete for everyone</button>
        </div>
    </dialog>

    <!-- Members of the open group; its owner sees Remove next to everyone else (filled in by the page's script). -->
    <dialog class="dialog dialog-slide-down chat-new-dialog" id="chatMembersDialog" aria-labelledby="chatMembersTitle">
        <div class="dialog-header">
            <h1 class="dialog-title" id="chatMembersTitle" data-members-title>Members</h1>
            <button type="button" class="btn-close" data-bs-dismiss="dialog" aria-label="Close"></button>
        </div>
        <div class="dialog-body">
            <?php // The owner can rename the group here. ?>
            <form class="chat-rename" data-rename-form hidden>
                <label class="chat-new-field">
                    <span>Group name</span>
                    <span class="chat-rename-row">
                        <input type="text" maxlength="80" required autocomplete="off" data-rename-input aria-label="Group name">
                        <button class="btn-solid theme-primary" type="submit" data-rename-save disabled>Save</button>
                    </span>
                </label>
                <p class="chat-rename-error" data-rename-error role="alert" hidden></p>
            </form>
            <p class="chat-group-picked" data-members-note></p>
            <ul class="chat-member-list" data-members-list></ul>
        </div>
        <div class="dialog-footer">
            <button type="button" class="btn-solid theme-secondary" data-bs-dismiss="dialog">Close</button>
        </div>
    </dialog>

    <!-- Add people to the open group (people already in it are left out). Adds them and reopens the group. -->
    <dialog class="dialog dialog-slide-down chat-new-dialog" id="chatAddPeopleDialog" aria-labelledby="chatAddPeopleTitle">
        <form method="post" data-add-form>
            <input type="hidden" name="message_action_token" value="<?= $h($messageActionToken) ?>">
            <input type="hidden" name="add_members" value="1">
            <input type="hidden" name="group_id" value="" data-add-group-id>
            <div class="dialog-header">
                <h1 class="dialog-title" id="chatAddPeopleTitle" data-add-title>Add people</h1>
                <button type="button" class="btn-close" data-bs-dismiss="dialog" aria-label="Close"></button>
            </div>
            <div class="dialog-body">
                <label class="chat-new-search">
                    <svg class="chat-svg" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="11" cy="11" r="6.5"/><path d="m16 16 4.5 4.5"/></svg>
                    <input type="search" placeholder="Search people" aria-label="Search people to add" data-add-search autocomplete="off">
                </label>
                <p class="chat-group-picked" data-add-picked aria-live="polite">Choose who to add.</p>
                <div class="chat-contact-list chat-group-people">
                    <?php foreach ($contactGroups as $group => $groupContacts): ?>
                        <p class="chat-contact-group" data-add-heading><?= $h($group) ?></p>
                        <?php foreach ($groupContacts as $contact):
                            $contactName = trim($contact['first_name'] . ' ' . $contact['last_name']);
                        ?>
                            <label class="chat-contact chat-group-person" data-add-person
                                data-search="<?= $h(mb_strtolower($contactName . ' ' . $contact['email'] . ' ' . $roleLabel($contact) . ' ' . $group)) ?>">
                                <input type="checkbox" name="members[]" value="<?= (int) $contact['id'] ?>" data-name="<?= $h($contactName) ?>">
                                <?= mentora_avatar(mentora_avatar_url($contact['avatar_path'] ?? null, '../'), $initialsOf($contactName)) ?>
                                <span class="chat-contact-copy">
                                    <strong><?= $h($contactName) ?></strong>
                                    <small><?= $h($roleLabel($contact)) ?> · <?= $h($contact['email']) ?></small>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                    <p class="chat-contact-empty" data-add-empty hidden>No one else to add.</p>
                </div>
            </div>
            <div class="dialog-footer">
                <button type="button" class="btn-solid theme-secondary" data-bs-dismiss="dialog">Cancel</button>
                <button type="submit" class="btn-solid theme-primary" data-add-submit disabled>Add</button>
            </div>
        </form>
    </dialog>

    <!-- New group: a name and the people in it (anyone you can message). Creates it and opens it (?c=g{id}). -->
    <dialog class="dialog dialog-slide-down chat-new-dialog" id="chatGroupDialog" aria-labelledby="chatGroupTitle">
        <form method="post" data-group-form>
            <input type="hidden" name="message_action_token" value="<?= $h($messageActionToken) ?>">
            <input type="hidden" name="create_group" value="1">
            <div class="dialog-header">
                <h1 class="dialog-title" id="chatGroupTitle">New group</h1>
                <button type="button" class="btn-close" data-bs-dismiss="dialog" aria-label="Close"></button>
            </div>
            <div class="dialog-body">
                <label class="chat-new-field">
                    <span>Group name</span>
                    <input type="text" name="group_name" maxlength="80" required autocomplete="off" placeholder="e.g. Web Development team" data-group-name>
                </label>
                <label class="chat-new-search">
                    <svg class="chat-svg" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="11" cy="11" r="6.5"/><path d="m16 16 4.5 4.5"/></svg>
                    <input type="search" placeholder="Search people" aria-label="Search people to add" data-group-search autocomplete="off">
                </label>
                <p class="chat-group-picked" data-group-picked aria-live="polite">Choose who's in the group.</p>
                <div class="chat-contact-list chat-group-people" data-group-people>
                    <?php foreach ($contactGroups as $group => $groupContacts): ?>
                        <p class="chat-contact-group" data-group-heading><?= $h($group) ?></p>
                        <?php foreach ($groupContacts as $contact):
                            $contactName = trim($contact['first_name'] . ' ' . $contact['last_name']);
                        ?>
                            <label class="chat-contact chat-group-person"
                                data-search="<?= $h(mb_strtolower($contactName . ' ' . $contact['email'] . ' ' . $roleLabel($contact) . ' ' . $group)) ?>">
                                <input type="checkbox" name="members[]" value="<?= (int) $contact['id'] ?>" data-name="<?= $h($contactName) ?>">
                                <?= mentora_avatar(mentora_avatar_url($contact['avatar_path'] ?? null, '../'), $initialsOf($contactName)) ?>
                                <span class="chat-contact-copy">
                                    <strong><?= $h($contactName) ?></strong>
                                    <small><?= $h($roleLabel($contact)) ?> · <?= $h($contact['email']) ?></small>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                    <p class="chat-contact-empty" data-group-empty <?= $contacts ? 'hidden' : '' ?>><?= $contacts ? 'No one matches your search.' : "There's no one you can add yet." ?></p>
                </div>
            </div>
            <div class="dialog-footer">
                <button type="button" class="btn-solid theme-secondary" data-bs-dismiss="dialog">Cancel</button>
                <button type="submit" class="btn-solid theme-primary" data-group-create disabled>Create group</button>
            </div>
        </form>
    </dialog>

    <!-- The ⋯ menu of a conversation in the list (one menu, placed next to the ⋯ that opened it) -->
    <div class="chat-row-menu" data-row-menu role="menu" aria-label="Conversation options" hidden>
        <button type="button" role="menuitem" data-menu-archive>
            <svg class="chat-svg" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><rect x="3" y="4" width="18" height="4.5" rx="1"/><path d="M5 8.5V18a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8.5"/><path d="M10 12.5h4"/></svg>
            <span>Archive</span>
        </button>
        <button class="is-danger" type="button" role="menuitem" data-menu-delete>
            <svg class="chat-svg" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4 7h16"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M6 7l1 12a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-12"/><path d="M9 7V4h6v3"/></svg>
            <span>Delete</span>
        </button>
    </div>

    <!-- Voice / video call (assets/js/video-call.js). data-kind: video | voice.
         data-state: outgoing | incoming | connecting | connected | ended -->
    <div class="chat-call" data-call data-mode="single" data-kind="video" data-state="ended" role="dialog" aria-modal="true" aria-labelledby="chatCallName" hidden>
        <video class="chat-call-remote" data-call-remote autoplay playsinline></video>
        <?php // Group calls (data-mode="group"): one tile per person in the call. ?>
        <div class="chat-call-grid" data-call-grid></div>
        <video class="chat-call-local" data-call-local autoplay playsinline muted></video>
        <div class="chat-call-info">
            <span class="chat-call-avatar" data-call-avatar aria-hidden="true"></span>
            <strong id="chatCallName" data-call-name></strong>
            <span class="chat-call-status" data-call-status aria-live="polite"></span>
        </div>
        <div class="chat-call-controls">
            <button class="chat-call-button is-decline" type="button" data-call-decline aria-label="Decline call">
                <svg class="chat-svg" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M3.5 14.5c4.7-4 12.3-4 17 0l-2 2.5-3.5-1.5V13a10 10 0 0 0-6 0v2.5L5.5 17Z"/></svg>
                <span>Decline</span>
            </button>
            <button class="chat-call-button is-accept" type="button" data-call-accept aria-label="Accept call">
                <svg class="chat-svg chat-call-icon-video" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><rect x="3" y="6.5" width="12.5" height="11" rx="2.5"/><path d="m15.5 10.5 5-3v9l-5-3"/></svg>
                <svg class="chat-svg chat-call-icon-voice" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M5 4h3.5l1.5 4.5-2.2 1.4a11 11 0 0 0 6.3 6.3l1.4-2.2L20 15.5V19a1.5 1.5 0 0 1-1.6 1.5A16 16 0 0 1 3.5 5.6 1.5 1.5 0 0 1 5 4Z"/></svg>
                <span>Accept</span>
            </button>
            <button class="chat-call-button" type="button" data-call-mute aria-pressed="false" aria-label="Mute microphone">
                <svg class="chat-svg" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><rect x="9" y="3" width="6" height="11" rx="3"/><path d="M5.5 11a6.5 6.5 0 0 0 13 0M12 17.5V21"/><path class="chat-call-slash" d="m4 4 16 16"/></svg>
                <span>Mute</span>
            </button>
            <button class="chat-call-button" type="button" data-call-camera aria-pressed="false" aria-label="Turn camera off">
                <svg class="chat-svg" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><rect x="3" y="6.5" width="12.5" height="11" rx="2.5"/><path d="m15.5 10.5 5-3v9l-5-3"/><path class="chat-call-slash" d="m3 3 18 18"/></svg>
                <span>Camera</span>
            </button>
            <button class="chat-call-button is-decline" type="button" data-call-hangup aria-label="End call">
                <svg class="chat-svg" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M3.5 14.5c4.7-4 12.3-4 17 0l-2 2.5-3.5-1.5V13a10 10 0 0 0-6 0v2.5L5.5 17Z"/></svg>
                <span>End</span>
            </button>
        </div>
    </div>
</main>

<script>
    (() => {
        const root = document.querySelector('[data-chat]');
        if (!root) {
            return;
        }
        const workspace = root.querySelector('.chat-workspace');
        const items = [...root.querySelectorAll('.chat-conversation')];
        const threads = [...root.querySelectorAll('[data-thread]')];
        const body = root.querySelector('[data-thread-body]');
        const emptyState = root.querySelector('.chat-thread-empty');
        const noResults = root.querySelector('[data-no-results]');
        const searchInput = root.querySelector('.chat-search-input');
        const filterButtons = [...root.querySelectorAll('.chat-tabs button')];
        const composer = root.querySelector('[data-composer]');
        const composerInput = root.querySelector('.chat-message-input');
        const composerButton = root.querySelector('.chat-send-button');
        const recipientInput = root.querySelector('.chat-recipient-id');
        const subjectInput = root.querySelector('.chat-subject');
        const totalUnread = root.querySelector('[data-total-unread]');
        const phone = window.matchMedia('(max-width: 760px)');
        const threadActions = root.querySelector('[data-thread-actions]');
        const callButtons = [...root.querySelectorAll('[data-call-start]')]; // voice, video
        let current = null;

        // The row around a conversation in the list (it also holds Archive / Delete).
        const rowOf = (item) => item.closest('[data-conversation-row]') || item;

        // Details panel line: "3 messages in this conversation · 5 members" (members: group chats and General).
        function infoStats(item) {
            const count = Number(item.dataset.count || 0);
            const members = Number(item.dataset.memberCount || 0);
            return `${count} message${count === 1 ? '' : 's'} in this conversation`
                + (item.dataset.group && members ? ` · ${members} member${members === 1 ? '' : 's'}` : '');
        }
        // The same group button in the header (phones, tablets) and in the details panel (desktop).
        const onEach = (selector, type, handler) => root.querySelectorAll(selector).forEach((button) => button.addEventListener(type, handler));

        // Header buttons for the open conversation: voice and video call (one person you can message);
        // add people and leave (a group chat, not General).
        function syncThreadActions() {
            const canCall = Boolean(current) && current.dataset.canCall === '1';
            const isGroup = Boolean(current) && current.dataset.group === 'group';
            callButtons.forEach((button) => { button.hidden = !canCall; });
            root.querySelectorAll('.chat-header-group-button').forEach((button) => { button.hidden = !isGroup; });
            root.querySelector('[data-info-group-actions]').hidden = !isGroup;
            threadActions.hidden = !canCall && !isGroup;
        }

        const setText = (selector, text) => {
            const element = root.querySelector(selector);
            if (element) {
                element.textContent = text;
            }
        };

        const updateTotalUnread = () => {
            const total = items.reduce((sum, item) => sum + Number(item.dataset.unread || 0), 0);
            totalUnread.textContent = `${total} unread`;
            totalUnread.hidden = total === 0;
        };

        // Opening a conversation marks it read (saved on the server, so badges clear everywhere).
        const markRead = (item) => {
            if (Number(item.dataset.unread || 0) === 0) {
                return;
            }
            const form = new FormData();
            form.append('action', 'mark_read');
            form.append('conversation', item.dataset.conversation);
            form.append('message_action_token', root.dataset.token);
            fetch(window.location.pathname, { method: 'POST', body: form, credentials: 'same-origin' })
                .then((response) => response.ok ? response.json() : { ok: false })
                .then((result) => {
                    if (!result.ok) {
                        return;
                    }
                    item.dataset.unread = '0';
                    item.classList.remove('unread');
                    item.querySelector('.chat-unread-count')?.remove();
                    updateTotalUnread();
                })
                .catch(() => {});
        };

        // ---------- Attachments, emoji and GIFs ----------
        const IS_ADMIN = <?= $isAdmin ? 'true' : 'false' ?>; // HR admins' @everyone in a group notifies all its members
        const MAX_FILES = <?= MENTORA_MESSAGE_MAX_FILES ?>;
        const MAX_BYTES = <?= MENTORA_MESSAGE_MAX_BYTES ?>;
        const ALLOWED_EXTENSIONS = <?= json_encode(array_keys(MENTORA_MESSAGE_FILE_RULES)) ?>;
        const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
        const GIPHY_KEY = <?= json_encode($giphyKey) ?>;
        const fileInput = root.querySelector('[data-attach-input]');
        const attachButton = root.querySelector('[data-attach]');
        const pickerToggle = root.querySelector('[data-picker-toggle]');
        const picker = root.querySelector('[data-picker]');
        const chips = root.querySelector('[data-attachments]');
        const note = root.querySelector('[data-composer-note]');
        const gifFields = Object.fromEntries([...root.querySelectorAll('[data-gif-field]')].map((field) => [field.dataset.gifField, field]));
        let pendingFiles = [];
        let pendingGif = null;
        let previewUrls = [];

        const make = (tag, className, text) => {
            const element = document.createElement(tag);
            if (className) {
                element.className = className;
            }
            if (text !== undefined) {
                element.textContent = text;
            }
            return element;
        };
        const sizeText = (bytes) => bytes >= 1048576 ? `${(bytes / 1048576).toFixed(1)} MB` : bytes >= 1024 ? `${Math.round(bytes / 1024)} KB` : `${bytes} B`;
        const extensionOf = (name) => (name.includes('.') ? name.split('.').pop() : '').toLowerCase();
        const showNote = (text) => {
            note.textContent = text;
            note.hidden = !text;
        };

        const syncComposer = () => {
            const canReply = Boolean(current) && current.dataset.canReply === '1';
            composerInput.disabled = !canReply;
            attachButton.disabled = !canReply;
            pickerToggle.disabled = !canReply;
            if (!canReply) {
                closePicker();
            }
            composerInput.placeholder = !current
                ? 'Choose a conversation or start a new one'
                : !canReply ? "This conversation can't be replied to"
                    : current.dataset.group ? `Message ${current.dataset.name} · type @ to mention someone`
                        : `Message ${current.dataset.name}`;
            const hasContent = composerInput.value.trim() !== '' || pendingFiles.length > 0 || pendingGif !== null;
            composerButton.disabled = !canReply || !hasContent;
            if (canReply) {
                recipientInput.value = current.dataset.recipient;
                subjectInput.value = current.dataset.replySubject;
            }
        };

        // Chips above the message box for everything waiting to be sent.
        function renderChips() {
            previewUrls.forEach((url) => URL.revokeObjectURL(url));
            previewUrls = [];
            chips.replaceChildren();
            const addChip = (visual, title, detail, onRemove) => {
                const chip = make('span', 'chat-chip');
                const copy = make('span', 'chat-chip-copy');
                copy.append(make('strong', '', title), make('small', '', detail));
                const remove = make('button', 'chat-chip-remove', '×');
                remove.type = 'button';
                remove.setAttribute('aria-label', `Remove ${title}`);
                remove.addEventListener('click', onRemove);
                chip.append(visual, copy, remove);
                chips.append(chip);
            };
            pendingFiles.forEach((file, index) => {
                let visual;
                if (IMAGE_EXTENSIONS.includes(extensionOf(file.name))) {
                    const url = URL.createObjectURL(file);
                    previewUrls.push(url);
                    visual = make('span', 'chat-chip-thumb');
                    visual.style.backgroundImage = `url("${url}")`;
                } else {
                    visual = make('span', 'chat-chip-type', (extensionOf(file.name) || 'file').toUpperCase().slice(0, 4));
                }
                addChip(visual, file.name, sizeText(file.size), () => {
                    pendingFiles.splice(index, 1);
                    syncFiles();
                });
            });
            if (pendingGif) {
                const visual = make('span', 'chat-chip-thumb');
                visual.style.backgroundImage = `url("${pendingGif.preview}")`;
                addChip(visual, pendingGif.title || 'GIF', 'GIF', clearGif);
            }
            chips.hidden = chips.children.length === 0;
        }

        // Keep the real file field in step with the list (so the form uploads exactly these files).
        function syncFiles() {
            const transfer = new DataTransfer();
            pendingFiles.forEach((file) => transfer.items.add(file));
            fileInput.files = transfer.files;
            renderChips();
            syncComposer();
        }

        function addFiles(list) {
            const problems = [];
            for (const file of list) {
                if (pendingFiles.length >= MAX_FILES) {
                    problems.push(`You can attach up to ${MAX_FILES} files at a time.`);
                    break;
                }
                if (!ALLOWED_EXTENSIONS.includes(extensionOf(file.name))) {
                    problems.push(`"${file.name}" isn't a supported file type. Try a photo, PDF, Word, Excel, PowerPoint, TXT, CSV or ZIP file.`);
                    continue;
                }
                if (file.size > MAX_BYTES) {
                    problems.push(`"${file.name}" is larger than ${sizeText(MAX_BYTES)}.`);
                    continue;
                }
                pendingFiles.push(file);
            }
            showNote(problems[0] || '');
            syncFiles();
        }

        function setGif(gif) {
            pendingGif = gif;
            gifFields.url.value = gif.url;
            gifFields.title.value = gif.title || 'GIF';
            gifFields.width.value = gif.width || '';
            gifFields.height.value = gif.height || '';
            renderChips();
            syncComposer();
        }

        function clearGif() {
            pendingGif = null;
            Object.values(gifFields).forEach((field) => { field.value = ''; });
            renderChips();
            syncComposer();
        }

        attachButton.addEventListener('click', () => fileInput.click());
        fileInput.addEventListener('change', () => addFiles([...fileInput.files]));

        // Drop files onto the message box, or paste a screenshot into it.
        composer.addEventListener('dragover', (event) => {
            if (!attachButton.disabled && event.dataTransfer && [...event.dataTransfer.types].includes('Files')) {
                event.preventDefault();
                composer.classList.add('is-dragging');
            }
        });
        composer.addEventListener('dragleave', (event) => {
            if (!composer.contains(event.relatedTarget)) {
                composer.classList.remove('is-dragging');
            }
        });
        composer.addEventListener('drop', (event) => {
            composer.classList.remove('is-dragging');
            if (!attachButton.disabled && event.dataTransfer && event.dataTransfer.files.length) {
                event.preventDefault();
                addFiles([...event.dataTransfer.files]);
            }
        });
        composerInput.addEventListener('paste', (event) => {
            const files = event.clipboardData ? [...event.clipboardData.files] : [];
            if (files.length) {
                event.preventDefault();
                addFiles(files.map((file, index) => (
                    file.name && file.name !== 'image.png'
                        ? file
                        : new File([file], `pasted-image-${Date.now()}${index ? '-' + index : ''}.${extensionOf(file.type.replace('/', '.')) || 'png'}`, { type: file.type })
                )));
            }
        });

        // ---- Emoji picker ----
        const EMOJI = [
            ['Smileys', '😀', '😀 😃 😄 😁 😆 😅 🤣 😂 🙂 🙃 😉 😊 😇 🥰 😍 🤩 😘 😗 😚 😋 😛 😜 🤪 😝 🤑 🤗 🤭 🤫 🤔 🤐 🤨 😐 😑 😶 😏 😒 🙄 😬 😌 😔 😪 🤤 😴 😷 🤒 🤕 🤢 🥵 🥶 🥴 😵 🤯 🤠 🥳 😎 🤓 🧐 😕 😟 🙁 😮 😯 😲 😳 🥺 😦 😧 😨 😰 😥 😢 😭 😱 😖 😣 😞 😓 😩 😫 🥱 😤 😡 😠'],
            ['Gestures', '👍', '👋 🤚 🖐️ ✋ 🖖 👌 🤌 🤏 ✌️ 🤞 🤟 🤘 🤙 👈 👉 👆 👇 ☝️ 👍 👎 ✊ 👊 🤛 🤜 👏 🙌 👐 🤲 🤝 🙏 ✍️ 💪 🧠 👀'],
            ['Hearts', '❤️', '❤️ 🧡 💛 💚 💙 💜 🖤 🤍 🤎 💔 ❣️ 💕 💞 💓 💗 💖 💘 💝 💟'],
            ['Celebrate', '🎉', '🎉 🎊 🎈 🎁 🎂 🏆 🥇 🥈 🥉 🏅 🎖️ ⭐ 🌟 ✨ 💫 🔥 💯 🙌 🥂 🍾'],
            ['Nature', '🌱', '☀️ 🌤️ ⛅ 🌧️ ⛈️ ❄️ 🌈 🌊 🌱 🌿 🍀 🌸 🌼 🌻 🌺 🌷 🐶 🐱 🐼 🦊 🐻 🐨 🦁 🐯 🐸 🐵 🐧 🐦 🦋 🐝'],
            ['Food', '☕', '☕ 🍵 🧋 🥤 🍕 🍔 🍟 🌭 🥪 🌮 🍜 🍝 🍣 🍱 🍚 🍩 🍪 🍰 🍫 🍿 🍎 🍌 🍇 🍓 🥭 🍉'],
            ['Work', '💼', '💼 📁 📂 📄 📝 📌 📎 ✏️ 🖊️ 📅 📆 ⏰ ⌛ 💻 🖥️ ⌨️ 📱 ☎️ 📧 ✉️ 📢 💡 🔍 🔒 🔑 🛠️ ⚙️ 📊 📈 📉 🎓 📚'],
            ['Travel', '✈️', '🚗 🚕 🚌 🚇 🚲 ✈️ 🚀 🏠 🏢 🏫 🏥 🗺️ 🧭 🏖️ ⛰️ 🌏'],
            ['Symbols', '✅', '✅ ☑️ ✔️ ❌ ❎ ⚠️ ❓ ❗ ‼️ ⁉️ ➕ ➖ ➡️ ⬅️ ⬆️ ⬇️ 🔄 🆗 🆕 🆓 🔴 🟠 🟡 🟢 🔵 🟣']
        ].map(([name, icon, list]) => ({ name, icon, emoji: list.split(' ') }));
        const RECENT_KEY = 'mentora-recent-emoji';
        const emojiCategories = root.querySelector('[data-emoji-categories]');
        const emojiGrid = root.querySelector('[data-emoji-grid]');
        let emojiReady = false;

        const readRecent = () => {
            try {
                const list = JSON.parse(window.localStorage.getItem(RECENT_KEY) || '[]');
                return Array.isArray(list) ? list.filter((item) => typeof item === 'string').slice(0, 24) : [];
            } catch (error) {
                return [];
            }
        };

        function insertEmoji(emoji) {
            const start = composerInput.selectionStart ?? composerInput.value.length;
            const end = composerInput.selectionEnd ?? composerInput.value.length;
            composerInput.setRangeText(emoji, start, end, 'end');
            composerInput.dispatchEvent(new Event('input', { bubbles: true }));
            composerInput.focus();
            try {
                window.localStorage.setItem(RECENT_KEY, JSON.stringify([emoji, ...readRecent().filter((item) => item !== emoji)].slice(0, 24)));
            } catch (error) {
                // Recent emoji are a convenience; ignore storage problems.
            }
        }

        function showEmojiCategory(name) {
            const recent = readRecent();
            const category = name === 'Recent' ? { name: 'Recently used', emoji: recent } : EMOJI.find((item) => item.name === name);
            emojiCategories.querySelectorAll('button').forEach((button) => button.setAttribute('aria-pressed', String(button.dataset.category === name)));
            emojiGrid.replaceChildren(make('p', '', category.name));
            category.emoji.forEach((emoji) => {
                const button = make('button', '', emoji);
                button.type = 'button';
                button.setAttribute('aria-label', emoji);
                button.addEventListener('click', () => insertEmoji(emoji));
                emojiGrid.append(button);
            });
            emojiGrid.scrollTop = 0;
        }

        function buildEmojiPicker() {
            const tabs = (readRecent().length ? [{ name: 'Recent', icon: '🕘' }] : []).concat(EMOJI);
            emojiCategories.replaceChildren(...tabs.map((category) => {
                const button = make('button', '', category.icon);
                button.type = 'button';
                button.dataset.category = category.name;
                button.title = category.name;
                button.setAttribute('aria-label', category.name);
                button.addEventListener('click', () => showEmojiCategory(category.name));
                return button;
            }));
            showEmojiCategory(tabs[0].name);
            emojiReady = true;
        }

        // ---- GIF search (only when a GIPHY key is configured) ----
        const gifGrid = root.querySelector('[data-gif-grid]');
        const gifSearch = root.querySelector('[data-gif-search]');
        let gifLoaded = false;
        let gifTimer;

        async function loadGifs(query) {
            if (!GIPHY_KEY || !gifGrid) {
                return;
            }
            gifGrid.replaceChildren(make('p', '', 'Loading GIFs…'));
            const endpoint = query ? 'search' : 'trending';
            const url = `https://api.giphy.com/v1/gifs/${endpoint}?api_key=${encodeURIComponent(GIPHY_KEY)}&limit=24&rating=g`
                + (query ? `&q=${encodeURIComponent(query)}` : '');
            try {
                const response = await fetch(url, { referrerPolicy: 'no-referrer' });
                const results = response.ok ? (await response.json()).data || [] : null;
                if (!results) {
                    throw new Error('GIPHY request failed');
                }
                if (!results.length) {
                    gifGrid.replaceChildren(make('p', '', 'No GIFs found. Try another word.'));
                    return;
                }
                gifGrid.replaceChildren(...results.map((gif) => {
                    const preview = gif.images.fixed_width_small || gif.images.fixed_width;
                    const send = gif.images.downsized || gif.images.fixed_height || gif.images.original;
                    const button = make('button');
                    button.type = 'button';
                    button.title = gif.title || 'GIF';
                    const image = make('img');
                    image.src = preview.url;
                    image.alt = gif.title || 'GIF';
                    image.loading = 'lazy';
                    image.referrerPolicy = 'no-referrer';
                    button.append(image);
                    button.addEventListener('click', () => {
                        setGif({ url: send.url, title: gif.title || 'GIF', width: send.width, height: send.height, preview: preview.url });
                        closePicker();
                        composerInput.focus();
                    });
                    return button;
                }));
            } catch (error) {
                gifGrid.replaceChildren(make('p', '', "GIFs couldn't load. Check your connection and try again."));
            }
        }

        if (gifSearch) {
            gifSearch.addEventListener('input', () => {
                clearTimeout(gifTimer);
                gifTimer = setTimeout(() => loadGifs(gifSearch.value.trim()), 400);
            });
        }

        function showPickerTab(name) {
            picker.querySelectorAll('[data-picker-tab]').forEach((tab) => tab.setAttribute('aria-selected', String(tab.dataset.pickerTab === name)));
            picker.querySelectorAll('[data-picker-panel]').forEach((panel) => { panel.hidden = panel.dataset.pickerPanel !== name; });
            if (name === 'gif' && !gifLoaded) {
                gifLoaded = true;
                loadGifs('');
            }
            if (name === 'gif' && gifSearch) {
                gifSearch.focus();
            }
        }

        function openPicker() {
            if (!emojiReady) {
                buildEmojiPicker();
            }
            picker.style.bottom = `${composer.offsetHeight + 8}px`;
            picker.hidden = false;
            pickerToggle.setAttribute('aria-expanded', 'true');
        }

        function closePicker() {
            if (!picker || picker.hidden) {
                return;
            }
            picker.hidden = true;
            pickerToggle.setAttribute('aria-expanded', 'false');
        }

        pickerToggle.addEventListener('click', () => (picker.hidden ? openPicker() : closePicker()));
        picker.querySelectorAll('[data-picker-tab]').forEach((tab) => tab.addEventListener('click', () => showPickerTab(tab.dataset.pickerTab)));
        picker.querySelector('[data-picker-close]').addEventListener('click', () => {
            closePicker();
            composerInput.focus();
        });
        document.addEventListener('click', (event) => {
            if (!picker.hidden && !picker.contains(event.target) && !pickerToggle.contains(event.target)) {
                closePicker();
            }
        });
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && !picker.hidden) {
                closePicker();
                pickerToggle.focus();
            }
        });

        const growComposer = () => {
            composerInput.style.height = 'auto';
            composerInput.style.height = `${Math.min(composerInput.scrollHeight, 160)}px`;
        };

        const open = (item, { focus = false } = {}) => {
            current = item;
            const key = item.dataset.conversation;
            items.forEach((other) => {
                const selected = other === item;
                other.classList.toggle('selected', selected);
                other.setAttribute('aria-current', selected ? 'true' : 'false');
            });
            threads.forEach((thread) => { thread.hidden = thread.dataset.thread !== key; });
            emptyState.hidden = true;

            // Header and details panel.
            const avatar = item.querySelector('.avatar');
            root.querySelector('[data-thread-avatar]').replaceChildren(...(avatar ? [...avatar.cloneNode(true).childNodes] : []));
            root.querySelector('[data-info-avatar]').replaceChildren(avatar ? avatar.cloneNode(true) : '');
            setText('[data-thread-name]', item.dataset.name);
            setText('[data-thread-role]', item.dataset.role + (item.dataset.email ? ` · ${item.dataset.email}` : ''));
            setText('[data-info-name]', item.dataset.name);
            setText('[data-info-role]', item.dataset.role);
            const email = root.querySelector('[data-info-email]');
            email.hidden = !item.dataset.email;
            email.textContent = item.dataset.email;
            email.href = item.dataset.email ? `mailto:${item.dataset.email}` : '';
            const count = Number(item.dataset.count || 0);
            setText('[data-info-stats]', infoStats(item));
            // Shared photos and files for this conversation.
            root.querySelectorAll('[data-shared]').forEach((group) => { group.hidden = group.dataset.shared !== key; });
            root.querySelectorAll('[data-shared-count]').forEach((badge) => {
                const group = root.querySelector(`.chat-shared-group[data-shared-type="${badge.dataset.sharedCount}"]:not([hidden])`);
                const total = Number(group?.dataset.count || 0);
                badge.textContent = total ? String(total) : '';
            });

            workspace.classList.add('is-thread-open');
            syncComposer();
            syncThreadActions();
            body.scrollTop = body.scrollHeight; // newest at the bottom
            markRead(item);

            const url = new URL(window.location.href);
            url.searchParams.set('c', key);
            history.replaceState(null, '', url);
            if (focus && !composerInput.disabled) {
                composerInput.focus();
            }
        };

        const backToList = () => {
            workspace.classList.remove('is-thread-open');
            if (current) {
                current.focus();
            }
        };

        items.forEach((item) => item.addEventListener('click', () => open(item)));

        // ---------- New message: pick a person, then write ----------
        const newDialog = document.getElementById('chatNewDialog');
        const contactSearch = newDialog.querySelector('[data-contact-search]');
        const contactTopic = newDialog.querySelector('[data-contact-topic]');
        const contactButtons = [...newDialog.querySelectorAll('.chat-contact')];
        const contactEmpty = newDialog.querySelector('[data-contact-empty]');
        const conversationList = root.querySelector('.chat-conversation-list');

        contactSearch.addEventListener('input', () => {
            const query = contactSearch.value.trim().toLowerCase();
            let shown = 0;
            contactButtons.forEach((contact) => {
                const match = !query || contact.dataset.search.includes(query);
                contact.hidden = !match;
                shown += match ? 1 : 0;
            });
            // Hide a group heading when none of its people match.
            newDialog.querySelectorAll('.chat-contact-group').forEach((label) => {
                let next = label.nextElementSibling;
                let any = false;
                while (next && next.classList.contains('chat-contact')) {
                    any = any || !next.hidden;
                    next = next.nextElementSibling;
                }
                label.hidden = !any;
            });
            if (contactButtons.length) {
                contactEmpty.hidden = shown > 0;
            }
        });

        // Fresh dialog every time it opens.
        root.querySelectorAll('[data-bs-target="#chatNewDialog"]').forEach((opener) => opener.addEventListener('click', () => {
            contactSearch.value = '';
            contactTopic.value = '';
            contactSearch.dispatchEvent(new Event('input'));
            setTimeout(() => contactSearch.focus(), 60);
        }));

        const startConversation = (contact) => {
            const key = contact.dataset.contact;
            const topic = contactTopic.value.trim();
            let item = items.find((candidate) => candidate.dataset.conversation === key);

            if (!item) {
                // A conversation that exists only on this page until the first message is sent.
                item = make('button', 'chat-conversation is-draft');
                item.type = 'button';
                item.setAttribute('aria-controls', 'chat-thread-panel');
                Object.assign(item.dataset, {
                    conversation: key,
                    name: contact.dataset.name,
                    role: contact.dataset.role,
                    email: contact.dataset.email,
                    count: '0',
                    unread: '0',
                    canReply: '1',
                    recipient: contact.dataset.recipient,
                    replySubject: topic || 'Message',
                    support: '0',
                    archived: '0',
                    canCall: key.startsWith('u') ? '1' : '0',
                    search: contact.dataset.search
                });
                const top = make('span', 'chat-conversation-top');
                top.append(make('strong', '', contact.dataset.name), make('time', '', 'New'));
                const copy = make('span', 'chat-conversation-copy');
                copy.append(top, make('span', '', topic || 'New conversation'), make('small', '', 'Write your first message'));
                item.append(contact.querySelector('.avatar').cloneNode(true), copy);
                item.addEventListener('click', () => open(item));
                // Same row as saved conversations, without Archive / Delete until the first message is sent.
                const row = make('div', 'chat-conversation-row is-draft');
                row.dataset.conversationRow = '';
                row.append(item);
                conversationList.prepend(row);
                items.unshift(item);
                conversationList.querySelector('[data-empty-list]')?.remove();

                const thread = make('div', 'chat-thread-messages');
                thread.dataset.thread = key;
                thread.hidden = true;
                thread.append(
                    make('p', 'chat-day', 'New conversation'),
                    make('p', 'chat-new-hint', key === 'all'
                        ? 'Your announcement will go to everyone in the workspace.'
                        : `Say hello to ${contact.dataset.name} 👋`)
                );
                body.append(thread);
                threads.push(thread);
            } else if (topic) {
                item.dataset.replySubject = topic; // next message starts this new topic
            }

            // The dialog hands focus back to its opener when it finishes closing; then move it to the message box.
            newDialog.addEventListener('close', () => composerInput.focus(), { once: true });
            newDialog.querySelector('[data-bs-dismiss="dialog"]').click();
            open(item, { focus: true });
        };
        contactButtons.filter((contact) => !contact.hasAttribute('data-new-group'))
            .forEach((contact) => contact.addEventListener('click', () => startConversation(contact)));

        // ---------- New group: name + people (checkboxes); the form creates it and opens it ----------
        const groupDialog = document.getElementById('chatGroupDialog');
        const groupName = groupDialog.querySelector('[data-group-name]');
        const groupSearch = groupDialog.querySelector('[data-group-search]');
        const groupPicked = groupDialog.querySelector('[data-group-picked]');
        const groupCreate = groupDialog.querySelector('[data-group-create]');
        const groupPeople = [...groupDialog.querySelectorAll('.chat-group-person')];
        const groupEmpty = groupDialog.querySelector('[data-group-empty]');

        const syncGroupForm = () => {
            const chosen = groupPeople.map((person) => person.querySelector('input')).filter((box) => box.checked).map((box) => box.dataset.name);
            groupPicked.textContent = chosen.length
                ? `${chosen.length} chosen: ${chosen.slice(0, 4).join(', ')}${chosen.length > 4 ? ` and ${chosen.length - 4} more` : ''}`
                : 'Choose who’s in the group.';
            groupCreate.disabled = !chosen.length || groupName.value.trim() === '';
        };
        groupDialog.addEventListener('change', syncGroupForm);
        groupName.addEventListener('input', syncGroupForm);
        groupSearch.addEventListener('input', () => {
            const query = groupSearch.value.trim().toLowerCase();
            let shown = 0;
            groupPeople.forEach((person) => {
                person.hidden = Boolean(query) && !person.dataset.search.includes(query);
                shown += person.hidden ? 0 : 1;
            });
            groupDialog.querySelectorAll('[data-group-heading]').forEach((heading) => {
                let next = heading.nextElementSibling;
                let any = false;
                while (next && next.classList.contains('chat-group-person')) {
                    any = any || !next.hidden;
                    next = next.nextElementSibling;
                }
                heading.hidden = !any;
            });
            if (groupPeople.length) {
                groupEmpty.hidden = shown > 0;
            }
        });
        groupDialog.querySelector('[data-group-form]').addEventListener('submit', () => {
            groupCreate.disabled = true; // no double groups
        });
        newDialog.querySelector('[data-new-group]').addEventListener('click', () => {
            newDialog.querySelector('[data-bs-dismiss="dialog"]').click();
            groupDialog.querySelector('[data-group-form]').reset();
            groupSearch.dispatchEvent(new Event('input'));
            syncGroupForm();
            setTimeout(() => {
                groupDialog.showModal();
                groupName.focus();
            }, 200); // after the New message dialog has slid away
        });

        // messages.php?to=12 (e.g. "Message your agent" links): open that person's conversation, or start one.
        const wantedContact = (new URLSearchParams(location.search).get('to') || '').replace(/\D/g, '');
        if (wantedContact) {
            const contact = contactButtons.find((button) => button.dataset.contact === 'u' + wantedContact);
            if (contact) {
                setTimeout(() => startConversation(contact), 0);
            }
        }

        // Search + tabs
        const filterItems = () => {
            const search = (searchInput.value || '').trim().toLowerCase();
            const filter = (filterButtons.find((button) => button.classList.contains('active')) || {}).dataset?.filter || 'all';
            let shown = 0;
            items.forEach((item) => {
                const matchesSearch = !search || item.dataset.search.includes(search);
                // Archived conversations only show under Archived (a new message brings one back to All).
                const archived = item.dataset.archived === '1';
                const matchesFilter = filter === 'archived'
                    ? archived
                    : !archived && (filter === 'all'
                        || (filter === 'unread' && Number(item.dataset.unread) > 0)
                        || (filter === 'support' && item.dataset.support === '1'));
                const visible = matchesSearch && matchesFilter;
                rowOf(item).hidden = !visible;
                shown += visible ? 1 : 0;
            });
            if (noResults) {
                noResults.textContent = filter === 'archived' && !search ? 'No archived conversations.' : 'No conversations match.';
                noResults.hidden = shown > 0 || items.length === 0;
            }
        };
        const setFilter = (name) => {
            filterButtons.forEach((tab) => {
                tab.classList.toggle('active', tab.dataset.filter === name);
                tab.setAttribute('aria-pressed', String(tab.dataset.filter === name));
            });
            filterItems();
        };
        searchInput.addEventListener('input', filterItems);
        filterButtons.forEach((button) => button.addEventListener('click', () => setFilter(button.dataset.filter)));

        // ---------- @mentions in group chats: type "@" for a list of people (and @everyone) ----------
        // Picked people show as "@Ana Cruz" while you type; on sending they become "@[Ana Cruz](12)", which the
        // server checks (only people in the chat) and turns into a highlight and a bell notification for them.
        const ME = <?= $userId ?>;
        const MENTION_PEOPLE = <?= json_encode($mentionPeople, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_FORCE_OBJECT) ?: '{}' ?>;
        const MENTION_CONTACTS = <?= json_encode(array_map('intval', array_keys($contacts))) ?>;
        const MENTION_TOKEN = /@\[([^\]\[\n]{1,80})\]\((\d{1,10})\)/g;
        const picked = new Map(); // "Ana Cruz" => 12
        let mentionList = null;
        let mentionOptions = [];
        let mentionActive = 0;
        let mentionRange = null;

        // Who can be mentioned in the open chat. @everyone: in your groups for anyone, in General for HR admins.
        const mentionCandidates = () => {
            if (!current || !current.dataset.group) {
                return [];
            }
            const ids = current.dataset.group === 'general'
                ? MENTION_CONTACTS
                : (current.dataset.memberIds || '').split(',').filter(Boolean).map(Number);
            const people = [...new Set(ids)].filter((id) => id !== ME && MENTION_PEOPLE[id])
                .map((id) => ({ id, name: MENTION_PEOPLE[id].name, role: MENTION_PEOPLE[id].role }))
                .sort((a, b) => a.name.localeCompare(b.name));
            if (current.dataset.group === 'group' || IS_ADMIN) {
                people.unshift({ id: 'everyone', name: 'everyone', role: 'Notify everyone in this chat' });
            }
            return people;
        };

        const closeMentions = () => {
            if (mentionList) {
                mentionList.hidden = true;
            }
            composerInput.removeAttribute('aria-activedescendant');
            composerInput.setAttribute('aria-expanded', 'false');
            mentionOptions = [];
            mentionRange = null;
        };

        const highlightMention = (index) => {
            mentionActive = (index + mentionOptions.length) % mentionOptions.length;
            [...mentionList.children].forEach((option, position) => {
                option.setAttribute('aria-selected', String(position === mentionActive));
                if (position === mentionActive) {
                    option.scrollIntoView({ block: 'nearest' });
                    composerInput.setAttribute('aria-activedescendant', option.id);
                }
            });
        };

        const chooseMention = (person) => {
            if (!mentionRange) {
                return;
            }
            const text = person.id === 'everyone' ? '@everyone ' : `@${person.name} `;
            if (person.id !== 'everyone') {
                picked.set(person.name, person.id);
            }
            composerInput.setRangeText(text, mentionRange.start, mentionRange.end, 'end');
            closeMentions();
            composerInput.dispatchEvent(new Event('input', { bubbles: true }));
            composerInput.focus();
        };

        const updateMentions = () => {
            const caret = composerInput.selectionStart;
            const typed = caret === composerInput.selectionEnd ? composerInput.value.slice(0, caret).match(/(?:^|\s)@([^\s@]{0,30})$/) : null;
            const candidates = typed ? mentionCandidates() : [];
            if (!typed || !candidates.length) {
                closeMentions();
                return;
            }
            const query = typed[1].toLowerCase();
            mentionOptions = candidates.filter((person) => !query
                || person.name.toLowerCase().split(/\s+/).some((word) => word.startsWith(query))
                || person.name.toLowerCase().startsWith(query)).slice(0, 8);
            if (!mentionOptions.length) {
                closeMentions();
                return;
            }
            mentionRange = { start: caret - typed[1].length - 1, end: caret };
            if (!mentionList) {
                mentionList = make('div', 'chat-mention-list');
                mentionList.id = 'chatMentionList';
                mentionList.setAttribute('role', 'listbox');
                mentionList.setAttribute('aria-label', 'People to mention');
                composer.closest('.chat-thread').append(mentionList);
                composerInput.setAttribute('aria-controls', 'chatMentionList');
                composerInput.setAttribute('aria-autocomplete', 'list');
            }
            mentionList.replaceChildren(...mentionOptions.map((person, index) => {
                const option = make('button', 'chat-mention-option');
                option.type = 'button';
                option.id = `chatMention${index}`;
                option.tabIndex = -1;
                option.setAttribute('role', 'option');
                const badge = make('span', 'chat-mention-badge', person.id === 'everyone'
                    ? '@'
                    : person.name.split(/\s+/).slice(0, 2).map((part) => part[0] || '').join('').toUpperCase());
                const copy = make('span', 'chat-mention-copy');
                copy.append(make('strong', '', person.id === 'everyone' ? '@everyone' : person.name), make('small', '', person.role));
                option.append(badge, copy);
                option.addEventListener('mousedown', (event) => event.preventDefault()); // keep the cursor in the message box
                option.addEventListener('click', () => chooseMention(person));
                return option;
            }));
            mentionList.style.bottom = `${composer.offsetHeight + 8}px`;
            mentionList.hidden = false;
            composerInput.setAttribute('aria-expanded', 'true');
            highlightMention(0);
        };

        composerInput.setAttribute('aria-expanded', 'false');
        composerInput.addEventListener('input', updateMentions);
        composerInput.addEventListener('click', updateMentions);
        composerInput.addEventListener('keyup', (event) => {
            if (event.key === 'ArrowLeft' || event.key === 'ArrowRight' || event.key === 'Home' || event.key === 'End') {
                updateMentions();
            }
        });
        composerInput.addEventListener('blur', () => setTimeout(closeMentions, 150));
        // Runs before the message box's own keys (Enter sends), so Enter / Tab pick a person while the list is open.
        composer.addEventListener('keydown', (event) => {
            if (!mentionOptions.length || event.target !== composerInput) {
                return;
            }
            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                highlightMention(mentionActive + (event.key === 'ArrowDown' ? 1 : -1));
            } else if (event.key === 'Enter' || event.key === 'Tab') {
                chooseMention(mentionOptions[mentionActive]);
            } else if (event.key === 'Escape') {
                closeMentions();
            } else {
                return;
            }
            event.preventDefault();
            event.stopImmediatePropagation();
        }, true);

        // Sending: "@Ana Cruz" (picked from the list) becomes "@[Ana Cruz](12)". Longest names first, so
        // "@Ana Cruz" isn't taken for "@Ana".
        composer.addEventListener('submit', () => {
            closeMentions();
            let text = composerInput.value;
            [...picked.entries()].sort((a, b) => b[0].length - a[0].length).forEach(([name, id]) => {
                const escaped = name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
                text = text.replace(new RegExp(`@${escaped}(?![\\w\\]])`, 'g'), `@[${name}](${id})`);
            });
            composerInput.value = text;
        });

        // After a failed send the draft comes back with "@[Ana Cruz](12)": show it as "@Ana Cruz" again.
        if (MENTION_TOKEN.test(composerInput.value)) {
            composerInput.value = composerInput.value.replace(MENTION_TOKEN, (match, name, id) => {
                picked.set(name, Number(id));
                return `@${name}`;
            });
        }

        // ---------- "Ana is typing…" in group chats and General (account/typing.php) ----------
        // While you type, the server hears about it at most every 3 seconds; sending (or clearing the box) stops it.
        // The open group chat is checked every 2 seconds for others typing (only while this tab is visible).
        const typingBox = root.querySelector('[data-typing]');
        const typingText = root.querySelector('[data-typing-text]');
        let typingSentAt = 0;
        let typingGroup = null;
        let typingTimer = null;
        const groupIdOf = (item) => (item && item.dataset.group ? item.dataset.conversation.slice(1) : null);

        const sendTyping = (groupId, action) => {
            const form = new FormData();
            form.append('action', action);
            form.append('group_id', groupId);
            form.append('message_action_token', root.dataset.token);
            fetch('typing.php', { method: 'POST', body: form, credentials: 'same-origin', keepalive: action === 'stop' }).catch(() => {});
        };
        const stopTyping = () => {
            if (typingSentAt && typingGroup) {
                sendTyping(typingGroup, 'stop');
            }
            typingSentAt = 0;
            typingGroup = null;
        };

        composerInput.addEventListener('input', () => {
            const groupId = groupIdOf(current);
            if (!groupId || composerInput.value.trim() === '') {
                stopTyping();
                return;
            }
            if (typingGroup !== groupId) {
                stopTyping(); // moved to another group mid-sentence
            }
            if (Date.now() - typingSentAt > 3000) {
                typingSentAt = Date.now();
                typingGroup = groupId;
                sendTyping(groupId, 'typing');
            }
        });
        composer.addEventListener('submit', stopTyping);

        const showTyping = (names) => {
            const first = names.map((name) => name.split(/\s+/)[0]);
            typingText.textContent = first.length === 1 ? `${first[0]} is typing…`
                : first.length === 2 ? `${first[0]} and ${first[1]} are typing…`
                    : 'Several people are typing…';
            typingBox.hidden = names.length === 0;
        };

        const checkTyping = async () => {
            clearTimeout(typingTimer);
            const groupId = groupIdOf(current);
            if (groupId && !document.hidden) {
                try {
                    const response = await fetch(`typing.php?group_id=${encodeURIComponent(groupId)}`, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
                    const result = await response.json();
                    if (groupIdOf(current) === groupId) {
                        showTyping(result.ok ? result.names : []);
                    }
                } catch (error) {
                    showTyping([]);
                }
            } else {
                showTyping([]);
            }
            typingTimer = setTimeout(checkTyping, 2000);
        };
        // Another chat opened: forget the old one's indicator straight away and check the new one.
        conversationList.addEventListener('click', () => setTimeout(() => {
            showTyping([]);
            checkTyping();
        }, 0));
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden) {
                checkTyping();
            }
        });
        setTimeout(checkTyping, 0); // after the first conversation has opened

        // Composer: Enter sends, Shift+Enter adds a line; Send is off while empty.
        composerInput.addEventListener('input', () => {
            growComposer();
            syncComposer();
        });
        composerInput.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
                event.preventDefault();
                if (!composerButton.disabled) {
                    composer.requestSubmit(composerButton);
                }
            }
        });
        composer.addEventListener('submit', () => {
            composerButton.disabled = true; // no double sends
        });

        // Buttons
        root.querySelector('[data-action="back"]').addEventListener('click', backToList);

        root.querySelectorAll('.chat-collapse-toggle').forEach((toggle) => {
            toggle.addEventListener('click', () => {
                const content = toggle.nextElementSibling;
                content.hidden = !content.hidden;
                toggle.classList.toggle('is-collapsed', content.hidden);
                toggle.setAttribute('aria-expanded', String(!content.hidden));
            });
            toggle.addEventListener('keydown', (event) => {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    toggle.click();
                }
            });
        });

        // ---------- Archive, delete ----------
        let toastTimer;
        const showToast = (text) => {
            let toast = root.querySelector('[data-chat-toast]');
            if (!toast) {
                toast = make('p', 'chat-toast');
                toast.dataset.chatToast = '';
                toast.setAttribute('role', 'status');
                root.append(toast);
            }
            toast.textContent = text;
            toast.hidden = false;
            clearTimeout(toastTimer);
            toastTimer = setTimeout(() => { toast.hidden = true; }, 3200);
        };
        const tryAgain = "That didn't work. Please reload the page and try again.";

        // Background actions on this page (JSON, see the top of messages.php).
        const postAction = async (fields) => {
            const form = new FormData();
            Object.entries({ ...fields, message_action_token: root.dataset.token }).forEach(([name, value]) => form.append(name, value));
            try {
                const response = await fetch(window.location.pathname, { method: 'POST', body: form, credentials: 'same-origin' });
                const result = await response.json();
                return response.ok && result.ok === true;
            } catch (error) {
                return false;
            }
        };

        // Shared photos / files badges in the details panel, for the open conversation.
        const updateSharedBadges = () => {
            root.querySelectorAll('[data-shared-count]').forEach((badge) => {
                const group = root.querySelector(`.chat-shared-group[data-shared-type="${badge.dataset.sharedCount}"]:not([hidden])`);
                const total = Number(group?.dataset.count || 0);
                badge.textContent = total ? String(total) : '';
            });
        };

        // Back to "Select a conversation" (after deleting the open one).
        const closeConversation = () => {
            current = null;
            items.forEach((item) => {
                item.classList.remove('selected');
                item.setAttribute('aria-current', 'false');
            });
            threads.forEach((thread) => { thread.hidden = true; });
            emptyState.hidden = false;
            root.querySelector('[data-thread-avatar]').replaceChildren();
            root.querySelector('[data-info-avatar]').replaceChildren();
            setText('[data-thread-name]', 'Messages');
            setText('[data-thread-role]', 'Choose a conversation');
            setText('[data-info-name]', 'Messages');
            setText('[data-info-role]', 'Mentora');
            setText('[data-info-stats]', '');
            root.querySelector('[data-info-email]').hidden = true;
            root.querySelectorAll('[data-shared]').forEach((group) => { group.hidden = group.dataset.shared !== ''; });
            updateSharedBadges();
            workspace.classList.remove('is-thread-open');
            syncComposer();
            syncThreadActions();
            const url = new URL(window.location.href);
            url.searchParams.delete('c');
            history.replaceState(null, '', url);
        };

        // Archive (for you only): it moves to the Archived tab until a new message arrives. The same action moves it back.
        const archiveLabel = (item) => (item.dataset.archived === '1' ? 'Move back to Chats' : 'Archive');
        const archiveConversation = async (item) => {
            const archive = item.dataset.archived !== '1';
            if (!await postAction({ action: 'archive', conversation: item.dataset.conversation, archived: archive ? '1' : '0' })) {
                showToast(tryAgain);
                return;
            }
            item.dataset.archived = archive ? '1' : '0';
            const swipeArchive = rowOf(item).querySelector('[data-row-archive]');
            if (swipeArchive) {
                swipeArchive.setAttribute('aria-label', archive ? 'Move back to Chats' : 'Archive conversation');
                swipeArchive.title = archiveLabel(item);
            }
            if (archive) {
                item.dataset.unread = '0'; // archiving also marks it read
                item.classList.remove('unread');
                item.querySelector('.chat-unread-count')?.remove();
                updateTotalUnread();
            }
            filterItems();
            showToast(archive ? 'Conversation archived. You’ll find it under Archived.' : 'Conversation moved back to Chats.');
        };

        // Delete conversation (for you only): its messages so far disappear from your Messages; the other person keeps theirs.
        const deleteConversation = async (item) => {
            const isGroup = Boolean(item.dataset.group);
            const confirmed = await window.MentoraConfirm({
                title: isGroup ? 'Clear this group chat?' : 'Delete this conversation?',
                message: isGroup
                    ? `The messages in ${item.dataset.name} so far will be removed from your Messages. You stay in the group and will see new messages. Everyone else keeps theirs. This can’t be undone.`
                    : `Your conversation with ${item.dataset.name} will be removed from your Messages. `
                        + 'It’s only removed for you: the other person keeps their copy. This can’t be undone.',
                confirmLabel: isGroup ? 'Clear' : 'Delete',
                tone: 'danger'
            });
            if (!confirmed) {
                return;
            }
            const key = item.dataset.conversation;
            if (!await postAction({ action: 'delete_conversation', conversation: key })) {
                showToast(tryAgain);
                return;
            }
            if (isGroup) {
                window.location.assign(`${window.location.pathname}?c=${encodeURIComponent(key)}`); // the group stays, now empty
                return;
            }
            const thread = threads.find((candidate) => candidate.dataset.thread === key);
            if (thread) {
                thread.remove();
                threads.splice(threads.indexOf(thread), 1);
            }
            root.querySelectorAll('.chat-shared-group[data-shared]').forEach((group) => {
                if (group.dataset.shared === key) {
                    group.remove();
                }
            });
            rowOf(item).remove();
            items.splice(items.indexOf(item), 1);
            if (item === current) {
                closeConversation();
            }
            updateTotalUnread();
            filterItems();
            showToast('Conversation deleted.');
        };

        // ---- Every device: slide a conversation left (finger, mouse or trackpad) to show Archive and Delete ----
        const SWIPE_OPEN = 112; // px: the two round buttons (CSS .chat-row-actions)
        const swipeRows = () => [...conversationList.querySelectorAll('[data-conversation-row]:not(.is-draft):not(.is-empty)')];
        const setRowOpen = (row, open) => {
            row.classList.toggle('is-open', open);
            const item = row.querySelector('.chat-conversation');
            item.style.transform = '';
            row.querySelectorAll('[data-row-actions] button').forEach((button) => { button.tabIndex = open ? 0 : -1; });
        };
        const closeRows = (keep) => swipeRows().forEach((row) => row !== keep && row.classList.contains('is-open') && setRowOpen(row, false));

        conversationList.addEventListener('pointerdown', (event) => {
            const item = event.target.closest('.chat-conversation');
            const row = item ? item.closest('[data-conversation-row]') : null;
            if (!row || row.classList.contains('is-draft') || row.classList.contains('is-empty') || event.button !== 0) {
                return;
            }
            const startX = event.clientX;
            const startY = event.clientY;
            const base = row.classList.contains('is-open') ? -SWIPE_OPEN : 0;
            let offset = base;
            let dragging = false;

            const move = (moveEvent) => {
                const dx = moveEvent.clientX - startX;
                const dy = moveEvent.clientY - startY;
                if (!dragging) {
                    if (Math.abs(dy) > 8 && Math.abs(dy) > Math.abs(dx)) {
                        stop(); // scrolling the list, not sliding
                        return;
                    }
                    if (Math.abs(dx) < 8) {
                        return;
                    }
                    dragging = true;
                    closeRows(row);
                    row.classList.add('is-dragging');
                }
                offset = Math.min(0, Math.max(-SWIPE_OPEN * 1.25, base + dx));
                item.style.transform = `translateX(${offset}px)`;
            };
            const finish = () => {
                stop();
                if (!dragging) {
                    return;
                }
                row.classList.remove('is-dragging');
                setRowOpen(row, offset < -SWIPE_OPEN / 2);
                row.dataset.swipedAt = String(Date.now()); // a slide is not a tap (see the click check below)
            };
            const stop = () => {
                window.removeEventListener('pointermove', move);
                window.removeEventListener('pointerup', finish);
                window.removeEventListener('pointercancel', finish);
            };
            window.addEventListener('pointermove', move);
            window.addEventListener('pointerup', finish);
            window.addEventListener('pointercancel', finish);
        });

        // The click that ends a slide doesn't open the conversation; while a row is open, tapping it just closes it.
        conversationList.addEventListener('click', (event) => {
            const row = event.target.closest('[data-conversation-row]');
            if (!row || !event.target.closest('.chat-conversation')) {
                return;
            }
            const justSlid = Date.now() - Number(row.dataset.swipedAt || 0) < 400;
            if (justSlid || row.classList.contains('is-open')) {
                event.preventDefault();
                event.stopImmediatePropagation();
                if (!justSlid) {
                    setRowOpen(row, false);
                }
            }
        }, true);
        document.addEventListener('pointerdown', (event) => {
            if (!event.target.closest('[data-conversation-row].is-open') && !event.target.closest('dialog')) {
                closeRows(null);
            }
        });

        conversationList.addEventListener('click', (event) => {
            const action = event.target.closest('[data-row-archive], [data-row-delete]');
            if (!action) {
                return;
            }
            const row = action.closest('[data-conversation-row]');
            const item = row.querySelector('.chat-conversation');
            setRowOpen(row, false);
            if (action.matches('[data-row-archive]')) {
                archiveConversation(item);
            } else {
                deleteConversation(item);
            }
        });

        // ---- Every device: ⋯ on a conversation opens a small menu with Archive and Delete ----
        const rowMenu = root.querySelector('[data-row-menu]');
        const menuArchive = rowMenu.querySelector('[data-menu-archive]');
        let menuItem = null;
        let menuToggle = null;

        const closeRowMenu = ({ focus = false } = {}) => {
            if (rowMenu.hidden) {
                return;
            }
            rowMenu.hidden = true;
            if (menuToggle) {
                menuToggle.setAttribute('aria-expanded', 'false');
                menuToggle.closest('[data-conversation-row]')?.classList.remove('menu-open');
                if (focus) {
                    menuToggle.focus();
                }
            }
            menuItem = null;
            menuToggle = null;
        };

        const openRowMenu = (toggle) => {
            closeRowMenu();
            menuToggle = toggle;
            menuItem = toggle.closest('[data-conversation-row]').querySelector('.chat-conversation');
            menuArchive.querySelector('span').textContent = archiveLabel(menuItem);
            rowMenu.querySelector('[data-menu-delete] span').textContent = menuItem.dataset.group ? 'Clear chat' : 'Delete';
            toggle.setAttribute('aria-expanded', 'true');
            toggle.closest('[data-conversation-row]').classList.add('menu-open');
            rowMenu.hidden = false;
            // Fixed position next to the ⋯ (the list scrolls, so the menu isn't cut off by it).
            const box = toggle.getBoundingClientRect();
            const menuHeight = rowMenu.offsetHeight;
            const below = box.bottom + 4 + menuHeight <= window.innerHeight - 8;
            rowMenu.style.top = `${below ? box.bottom + 4 : box.top - 4 - menuHeight}px`;
            rowMenu.style.left = `${Math.max(8, box.right - rowMenu.offsetWidth)}px`;
            menuArchive.focus();
        };

        conversationList.addEventListener('click', (event) => {
            const toggle = event.target.closest('[data-row-more]');
            if (!toggle) {
                return;
            }
            event.stopPropagation();
            if (menuToggle === toggle) {
                closeRowMenu();
            } else {
                openRowMenu(toggle);
            }
        });
        rowMenu.addEventListener('click', (event) => {
            const choice = event.target.closest('[data-menu-archive], [data-menu-delete]');
            if (!choice || !menuItem) {
                return;
            }
            const item = menuItem;
            closeRowMenu();
            if (choice.matches('[data-menu-archive]')) {
                archiveConversation(item);
            } else {
                deleteConversation(item);
            }
        });
        rowMenu.addEventListener('keydown', (event) => {
            const choices = [...rowMenu.querySelectorAll('[role="menuitem"]')];
            const index = choices.indexOf(document.activeElement);
            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault();
                choices[(index + (event.key === 'ArrowDown' ? 1 : choices.length - 1)) % choices.length].focus();
            } else if (event.key === 'Escape') {
                event.preventDefault();
                closeRowMenu({ focus: true });
            } else if (event.key === 'Tab') {
                closeRowMenu();
            }
        });
        document.addEventListener('click', (event) => {
            if (!rowMenu.hidden && !rowMenu.contains(event.target)) {
                closeRowMenu();
            }
        });
        conversationList.addEventListener('scroll', () => closeRowMenu(), { passive: true });
        window.addEventListener('resize', () => closeRowMenu());

        // Delete a message: "for me" (any message: gone from your chat only, everyone else keeps it) or, on your own
        // messages, "for everyone" (with its photos and files).
        const deleteDialog = document.getElementById('chatDeleteDialog');
        const deleteEveryone = deleteDialog.querySelector('[data-delete-choice="everyone"]');
        let resolveDelete = null;
        const chooseDelete = (mine) => new Promise((resolve) => {
            resolveDelete = resolve;
            deleteEveryone.hidden = !mine;
            deleteDialog.querySelector('[data-delete-text]').textContent = mine
                ? '“Delete for me” removes it from your chat only. “Delete for everyone” removes it for everyone in this conversation, with any photos or files in it. This can’t be undone.'
                : 'It will be removed from your chat only. Everyone else in this conversation still sees it. This can’t be undone.';
            deleteDialog.showModal();
            deleteDialog.querySelector('[data-delete-cancel]').focus(); // start on the safe choice
        });
        deleteDialog.querySelectorAll('[data-delete-choice]').forEach((choice) => choice.addEventListener('click', () => {
            const resolve = resolveDelete;
            resolveDelete = null;
            deleteDialog.querySelector('[data-bs-dismiss="dialog"]').click(); // close with the usual slide
            resolve?.(choice.dataset.deleteChoice);
        }));
        deleteDialog.addEventListener('close', () => {
            const resolve = resolveDelete;
            resolveDelete = null;
            resolve?.(null); // Cancel, ×, Esc or clicking outside
        });

        body.addEventListener('click', async (event) => {
            const button = event.target.closest('[data-delete-message]');
            if (!button) {
                return;
            }
            const mine = Boolean(button.closest('.chat-bubble-mine'));
            const choice = await chooseDelete(mine);
            if (!choice) {
                return;
            }
            const messageId = button.dataset.deleteMessage;
            button.disabled = true;
            const done = await postAction(choice === 'everyone' && mine
                ? { action: 'delete_message', message_id: messageId }
                : { action: 'hide_message', message_id: messageId });
            if (!done) {
                button.disabled = false;
                showToast(tryAgain);
                return;
            }
            const bubble = button.closest('.chat-bubble');
            const thread = bubble.closest('[data-thread]');
            const day = bubble.previousElementSibling;
            bubble.remove();
            // A day label with no messages left under it goes too.
            if (day && day.classList.contains('chat-day') && (!day.nextElementSibling || day.nextElementSibling.classList.contains('chat-day'))) {
                day.remove();
            }
            // Its photos and files leave the details panel.
            root.querySelectorAll(`[data-shared-message="${CSS.escape(messageId)}"]`).forEach((shared) => {
                const group = shared.closest('.chat-shared-group');
                shared.remove();
                if (group) {
                    group.dataset.count = String(Math.max(0, Number(group.dataset.count || 0) - 1));
                }
            });
            updateSharedBadges();

            // The conversation's count and preview follow the newest message that's left.
            const item = items.find((candidate) => candidate.dataset.conversation === thread.dataset.thread);
            if (item) {
                const count = Math.max(0, Number(item.dataset.count || 0) - 1);
                item.dataset.count = String(count);
                const last = [...thread.querySelectorAll('.chat-bubble')].pop();
                const preview = item.querySelector('.chat-conversation-copy small');
                if (preview) {
                    preview.textContent = last
                        ? (last.classList.contains('chat-bubble-mine') ? 'You: ' : '') + (last.querySelector('.chat-message-text')?.textContent || '📎 Attachment')
                        : 'No messages';
                }
                if (item === current) {
                    setText('[data-info-stats]', infoStats(item));
                    syncThreadActions();
                }
            }
            showToast(choice === 'everyone' && mine ? 'Message deleted for everyone.' : 'Message deleted for you.');
        });

        // ---------- Group chats: add people, leave ----------
        const addDialog = document.getElementById('chatAddPeopleDialog');
        const addPeople = [...addDialog.querySelectorAll('[data-add-person]')];
        const addSearch = addDialog.querySelector('[data-add-search]');
        const addPicked = addDialog.querySelector('[data-add-picked]');
        const addSubmit = addDialog.querySelector('[data-add-submit]');
        const addEmpty = addDialog.querySelector('[data-add-empty]');
        let alreadyIn = new Set();

        // Who's shown: not in the group yet, and matching the search.
        const filterAddPeople = () => {
            const query = addSearch.value.trim().toLowerCase();
            let shown = 0;
            addPeople.forEach((person) => {
                const box = person.querySelector('input');
                const member = alreadyIn.has(box.value);
                box.disabled = member;
                if (member) {
                    box.checked = false;
                }
                person.hidden = member || (Boolean(query) && !person.dataset.search.includes(query));
                shown += person.hidden ? 0 : 1;
            });
            addDialog.querySelectorAll('[data-add-heading]').forEach((heading) => {
                let next = heading.nextElementSibling;
                let any = false;
                while (next && next.matches('[data-add-person]')) {
                    any = any || !next.hidden;
                    next = next.nextElementSibling;
                }
                heading.hidden = !any;
            });
            addEmpty.textContent = query ? 'No one matches your search.' : 'Everyone you can message is already in this group.';
            addEmpty.hidden = shown > 0;
        };
        const syncAddForm = () => {
            const chosen = addPeople.map((person) => person.querySelector('input')).filter((box) => box.checked).map((box) => box.dataset.name);
            addPicked.textContent = chosen.length
                ? `${chosen.length} chosen: ${chosen.slice(0, 4).join(', ')}${chosen.length > 4 ? ` and ${chosen.length - 4} more` : ''}`
                : 'Choose who to add.';
            addSubmit.disabled = chosen.length === 0;
            addSubmit.textContent = chosen.length > 1 ? `Add ${chosen.length} people` : 'Add';
        };
        addSearch.addEventListener('input', filterAddPeople);
        addDialog.addEventListener('change', syncAddForm);
        addDialog.querySelector('[data-add-form]').addEventListener('submit', () => {
            addSubmit.disabled = true;
        });

        onEach('[data-group-add]', 'click', () => {
            if (!current || current.dataset.group !== 'group') {
                return;
            }
            addDialog.querySelector('[data-add-form]').reset();
            addDialog.querySelector('[data-add-group-id]').value = current.dataset.conversation.slice(1);
            addDialog.querySelector('[data-add-title]').textContent = `Add people to ${current.dataset.name}`;
            alreadyIn = new Set((current.dataset.memberIds || '').split(',').filter(Boolean));
            filterAddPeople();
            syncAddForm();
            addDialog.showModal();
            addSearch.focus();
        });

        // Members: everyone in the group, the owner first. The owner can remove anyone else.
        const membersDialog = document.getElementById('chatMembersDialog');
        const membersList = membersDialog.querySelector('[data-members-list]');
        const renameForm = membersDialog.querySelector('[data-rename-form]');
        const renameInput = membersDialog.querySelector('[data-rename-input]');
        const renameSave = membersDialog.querySelector('[data-rename-save]');
        const renameError = membersDialog.querySelector('[data-rename-error]');

        renameInput.addEventListener('input', () => {
            const name = renameInput.value.trim();
            renameSave.disabled = name === '' || name === (current?.dataset.name || '');
            renameError.hidden = true;
        });
        renameForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            const name = renameInput.value.trim().replace(/\s+/g, ' ');
            if (!name) {
                return;
            }
            renameSave.disabled = true;
            const form = new FormData();
            form.append('action', 'rename_group');
            form.append('group_id', renameForm.dataset.groupId);
            form.append('name', name);
            form.append('message_action_token', root.dataset.token);
            try {
                const response = await fetch(window.location.pathname, { method: 'POST', body: form, credentials: 'same-origin' });
                const result = await response.json();
                if (!response.ok || !result.ok) {
                    throw new Error(result.error || tryAgain);
                }
                window.location.assign(`${window.location.pathname}?c=${encodeURIComponent(renameForm.dataset.conversation)}`);
            } catch (error) {
                renameError.textContent = error.message && !error.message.startsWith('Unexpected') ? error.message : tryAgain;
                renameError.hidden = false;
                renameSave.disabled = false;
            }
        });
        onEach('[data-group-members]', 'click', () => {
            const item = current;
            if (!item || item.dataset.group !== 'group') {
                return;
            }
            const ownerId = Number(item.dataset.ownerId || 0);
            const iAmOwner = ownerId === ME;
            const ids = (item.dataset.memberIds || '').split(',').filter(Boolean).map(Number);
            membersDialog.querySelector('[data-members-title]').textContent = `${item.dataset.name} · ${ids.length} member${ids.length === 1 ? '' : 's'}`;
            membersDialog.querySelector('[data-members-note]').textContent = iAmOwner
                ? 'You own this group, so you can rename it and remove people from it.'
                : 'Only the group’s owner can rename the group or remove people.';
            // Rename (owner only): Save turns on once the name has changed.
            renameForm.hidden = !iAmOwner;
            renameForm.dataset.groupId = item.dataset.conversation.slice(1);
            renameForm.dataset.conversation = item.dataset.conversation;
            renameInput.value = item.dataset.name;
            renameSave.disabled = true;
            renameError.hidden = true;

            membersList.replaceChildren(...ids.map((id) => {
                const person = id === ME ? { name: 'You', role: '' } : (MENTION_PEOPLE[id] || { name: 'Member', role: '' });
                const row = make('li', 'chat-member');
                const badge = make('span', 'chat-mention-badge', person.name.split(/\s+/).slice(0, 2).map((part) => part[0] || '').join('').toUpperCase());
                const copy = make('span', 'chat-mention-copy');
                copy.append(make('strong', '', person.name), make('small', '', [id === ownerId ? 'Owner' : '', person.role].filter(Boolean).join(' · ')));
                row.append(badge, copy);
                if (iAmOwner && id !== ME) {
                    const remove = make('button', 'chat-member-remove', 'Remove');
                    remove.type = 'button';
                    remove.setAttribute('aria-label', `Remove ${person.name} from the group`);
                    remove.addEventListener('click', async () => {
                        const confirmed = await window.MentoraConfirm({
                            title: `Remove ${person.name}?`,
                            message: `${person.name} will leave ${item.dataset.name} and won’t see its new messages. You can add them again later.`,
                            confirmLabel: 'Remove',
                            tone: 'danger'
                        });
                        if (!confirmed) {
                            return;
                        }
                        remove.disabled = true;
                        if (!await postAction({ action: 'remove_member', group_id: item.dataset.conversation.slice(1), user_id: String(id) })) {
                            remove.disabled = false;
                            showToast(tryAgain);
                            return;
                        }
                        window.location.assign(`${window.location.pathname}?c=${encodeURIComponent(item.dataset.conversation)}`);
                    });
                    row.append(remove);
                }
                return row;
            }));
            membersDialog.showModal();
        });

        onEach('[data-group-leave]', 'click', async () => {
            const item = current;
            if (!item || item.dataset.group !== 'group') {
                return;
            }
            const confirmed = await window.MentoraConfirm({
                title: `Leave ${item.dataset.name}?`,
                message: 'You won’t see this group or its new messages any more, and someone has to add you again to rejoin. '
                    + 'The others will see that you left.',
                confirmLabel: 'Leave group',
                tone: 'danger'
            });
            if (!confirmed) {
                return;
            }
            if (!await postAction({ action: 'leave_group', group_id: item.dataset.conversation.slice(1) })) {
                showToast(tryAgain);
                return;
            }
            window.location.assign(window.location.pathname); // back to your chats, without the group
        });

        // ---------- A message that arrives while the page is open (the video call line, from assets/js/video-call.js) ----------
        // message: { id, conversation: 'u12', mine, body, createdAt, time: '4:05 PM', unread }
        const TRASH_ICON = '<svg class="chat-svg" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4 7h16"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M6 7l1 12a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-12"/><path d="M9 7V4h6v3"/></svg>';
        window.MentoraChat = {
            addMessage(message) {
                const thread = threads.find((candidate) => candidate.dataset.thread === message.conversation);
                const item = items.find((candidate) => candidate.dataset.conversation === message.conversation);
                if (!thread || !item || thread.querySelector(`[data-message-id="${Number(message.id)}"]`)) {
                    return false; // not on this page (e.g. a deleted conversation) or already shown: it appears on the next load
                }

                // The bubble, under a "Today" label like the ones the page starts with.
                thread.querySelector('.chat-new-hint')?.remove();
                const days = thread.querySelectorAll('.chat-day');
                if (!days.length || days[days.length - 1].textContent !== 'Today') {
                    thread.append(make('p', 'chat-day', 'Today'));
                }
                const bubble = make('article', `chat-bubble ${message.mine ? 'chat-bubble-mine' : 'chat-bubble-theirs'}`);
                bubble.dataset.messageId = String(message.id);
                {
                    const remove = make('button', 'chat-bubble-delete'); // for me, or (yours) for everyone
                    remove.type = 'button';
                    remove.dataset.deleteMessage = String(message.id);
                    remove.setAttribute('aria-label', 'Delete this message');
                    remove.title = 'Delete message';
                    remove.innerHTML = TRASH_ICON;
                    bubble.append(remove);
                }
                const time = make('time', '', message.time);
                time.setAttribute('datetime', message.createdAt);
                bubble.append(make('p', 'chat-message-text', message.body), time);
                thread.append(bubble);

                // Its conversation: newest preview, top of the list, back from the archive (like after a reload).
                item.dataset.count = String(Number(item.dataset.count || 0) + 1);
                item.dataset.archived = '0';
                item.dataset.search = `${item.dataset.search} ${message.body.toLowerCase()}`;
                const preview = item.querySelector('.chat-conversation-copy small');
                if (preview) {
                    preview.textContent = (message.mine ? 'You: ' : '') + message.body;
                }
                const listTime = item.querySelector('.chat-conversation-top time');
                if (listTime) {
                    listTime.textContent = message.time;
                    listTime.setAttribute('datetime', message.createdAt);
                }
                conversationList.prepend(rowOf(item));
                if (message.unread) {
                    const unread = Number(item.dataset.unread || 0) + 1;
                    item.dataset.unread = String(unread);
                    item.classList.add('unread');
                    let badge = item.querySelector('.chat-unread-count');
                    if (!badge) {
                        badge = make('span', 'chat-unread-count');
                        item.append(badge);
                    }
                    badge.textContent = String(unread);
                    badge.setAttribute('aria-label', `${unread} unread`);
                }
                filterItems();

                if (item === current) {
                    setText('[data-info-stats]', infoStats(item));
                    body.scrollTop = body.scrollHeight;
                    markRead(item); // you're looking at it
                }
                updateTotalUnread();
                return true;
            }
        };

        // Start: the conversation from the address (?c=...), else the newest one (not archived) on wider screens.
        // Phones start on the list.
        filterItems();
        const requested = items.find((item) => item.dataset.conversation === root.dataset.selected);
        const newest = items.find((item) => item.dataset.archived !== '1');
        if (requested) {
            if (requested.dataset.archived === '1') {
                setFilter('archived');
            }
            open(requested);
        } else if (newest && !phone.matches) {
            open(newest);
        } else {
            syncComposer();
            syncThreadActions();
        }
        growComposer();
        updateTotalUnread();
    })();
</script>
<script src="../assets/js/video-call.js?v=4"></script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
