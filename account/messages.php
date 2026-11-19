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
$contactRoles = in_array($me['role'], ['admin', 'agent'], true) ? ['admin', 'agent', 'applicant'] : ['admin', 'agent'];
$contactQuery = $pdo->prepare(
    'SELECT id, first_name, last_name, email, role, pathway, avatar_path FROM users
     WHERE id <> ? AND role IN (' . implode(',', array_fill(0, count($contactRoles), '?')) . ')
     ORDER BY FIELD(role, "admin", "agent", "applicant"), pathway, first_name, last_name'
);
$contactQuery->execute([$userId, ...$contactRoles]);
$contacts = [];
foreach ($contactQuery->fetchAll() as $contact) {
    $contacts[(int) $contact['id']] = $contact;
}

/** Can this person send a message to $recipientId? */
$canMessage = static function (int $recipientId) use ($pdo, $userId, $contacts): bool {
    if ($recipientId <= 0 || $recipientId === $userId) {
        return false;
    }
    if (isset($contacts[$recipientId])) {
        return true;
    }
    // Not normally a contact, but they wrote to you (directly or in an announcement): you can reply.
    $check = $pdo->prepare('SELECT COUNT(*) FROM messages WHERE sender_id = ? AND (recipient_id = ? OR recipient_id IS NULL)');
    $check->execute([$recipientId, $userId]);
    return (int) $check->fetchColumn() > 0;
};

// ---------- Mark a conversation as read (sent by the page when a conversation is opened) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_read') {
    header('Content-Type: application/json');
    if (!$tokenValid()) {
        http_response_code(403);
        exit(json_encode(['ok' => false]));
    }
    $conversation = (string) ($_POST['conversation'] ?? '');
    if (preg_match('/^u(\d+)$/', $conversation, $match)) {
        $otherId = (int) $match[1];
        $pdo->prepare('UPDATE messages SET read_at = NOW() WHERE recipient_id = ? AND sender_id = ? AND read_at IS NULL')
            ->execute([$userId, $otherId]);
        $pdo->prepare('INSERT IGNORE INTO message_reads (message_id, user_id) SELECT id, ? FROM messages WHERE recipient_id IS NULL AND sender_id = ?')
            ->execute([$userId, $otherId]);
    } elseif ($conversation === 'support') {
        $pdo->prepare('UPDATE messages SET read_at = NOW() WHERE recipient_id = ? AND sender_id IS NULL AND read_at IS NULL')
            ->execute([$userId]);
        $pdo->prepare('INSERT IGNORE INTO message_reads (message_id, user_id) SELECT id, ? FROM messages WHERE recipient_id IS NULL AND sender_id IS NULL')
            ->execute([$userId]);
    } else {
        http_response_code(400);
        exit(json_encode(['ok' => false]));
    }
    exit(json_encode(['ok' => true]));
}

// ---------- Send a reply ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_message'])) {
    $toEveryone = ($_POST['recipient_id'] ?? null) === 'all';
    $recipientId = !$toEveryone && is_string($_POST['recipient_id'] ?? null) ? (int) $_POST['recipient_id'] : 0;
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
    } elseif ((!$recipientId && !$toEveryone) || $subject === '') {
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
        // Your contacts, anyone who has messaged you, or (HR admins only) everyone.
        $allowed = $toEveryone ? $isAdmin : $canMessage($recipientId);

        $prepared = [];
        if (!$allowed) {
            $messageError = $toEveryone ? 'Only HR admins can send announcements to everyone.' : "You can't send messages to that person.";
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
                $pdo->beginTransaction();
                $pdo->prepare('INSERT INTO messages (sender_id, recipient_id, subject, body) VALUES (?, ?, ?, ?)')
                    ->execute([$userId, $toEveryone ? null : $recipientId, mb_substr($subject, 0, 190), $draftBody]);
                $messageId = (int) $pdo->lastInsertId();
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
                    $addAttachment->execute([$messageId, $file['kind'], $storedName, null, $file['name'], $file['mime'], $file['size'], $file['width'], $file['height']]);
                }
                if ($gif !== null) {
                    $addAttachment->execute([$messageId, 'gif', null, $gif['url'], $gif['title'], 'image/gif', 0, $gif['width'], $gif['height']]);
                }
                $pdo->commit();
                header('Location: messages.php?c=' . ($toEveryone ? 'all' : 'u' . $recipientId));
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

// ---------- Load every message to, from or broadcast to this person ----------
$query = $pdo->prepare(
    'SELECT m.id, m.sender_id, m.recipient_id, m.subject, m.body, m.created_at,
            CASE
                WHEN m.sender_id <=> ? THEN 0
                WHEN m.recipient_id IS NULL THEN r.user_id IS NULL
                ELSE m.read_at IS NULL
            END AS is_unread
     FROM messages m
     LEFT JOIN message_reads r ON r.message_id = m.id AND r.user_id = ?
     WHERE m.recipient_id = ? OR m.recipient_id IS NULL OR m.sender_id = ?
     ORDER BY m.created_at, m.id'
);
$query->execute([$userId, $userId, $userId, $userId]);
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

// People taking part (for names, roles and photos).
$personIds = [];
foreach ($rows as $row) {
    foreach (['sender_id', 'recipient_id'] as $column) {
        if ($row[$column] !== null && (int) $row[$column] !== $userId) {
            $personIds[(int) $row[$column]] = true;
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
$conversations = [];
foreach ($rows as $row) {
    $mine = (int) ($row['sender_id'] ?? 0) === $userId;
    $otherId = $mine ? $row['recipient_id'] : $row['sender_id'];
    $key = $otherId === null ? ($mine ? 'all' : 'support') : 'u' . (int) $otherId;

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
foreach ($conversations as &$conversation) {
    if ($conversation['replySubject'] === '' && $conversation['messages']) {
        $conversation['replySubject'] = (string) end($conversation['messages'])['subject'];
    }
}
unset($conversation);
// Most recent conversation first.
uasort($conversations, static fn(array $a, array $b): int => strcmp(end($b['messages'])['created_at'], end($a['messages'])['created_at']));

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

<main class="chat-page" data-chat data-selected="<?= $h($selectedKey) ?>" data-token="<?= $h($messageActionToken) ?>">
    <div class="chat-page-bar">
        <a class="chat-back-link" href="<?= $h($homeHref) ?>">
            <svg class="chat-svg" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m15 6-6 6 6 6"/></svg>
            Back to workspace
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
                    <button class="chat-icon-button" type="button" data-action="refresh" aria-label="Refresh messages" title="Refresh messages">
                        <svg class="chat-svg" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M20 11a8 8 0 1 0-2.3 5.7"/><path d="M20 5v6h-6"/></svg>
                    </button>
                    <button class="chat-icon-button chat-new-button" type="button" data-bs-toggle="dialog" data-bs-target="#chatNewDialog" aria-label="New message" title="New message">
                        <svg class="chat-svg" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
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
            </div>
            <div class="chat-conversation-list">
                <?php if (!$conversations): ?>
                    <div class="chat-empty-list" data-empty-list>
                        <p>No conversations yet.</p>
                        <button class="btn-solid theme-primary" type="button" data-bs-toggle="dialog" data-bs-target="#chatNewDialog">New message</button>
                    </div>
                <?php else: ?>
                    <?php foreach ($conversations as $conversation):
                        $last = end($conversation['messages']);
                        $searchText = mb_strtolower($conversation['name'] . ' ' . implode(' ', array_map(
                            static fn(array $message): string => $message['subject'] . ' ' . $message['body'] . ' '
                                . implode(' ', array_column($attachments[(int) $message['id']] ?? [], 'original_name')),
                            $conversation['messages']
                        )));
                        $lastPreview = trim((string) $last['body']) !== ''
                            ? (string) $last['body']
                            : $attachmentSummary($attachments[(int) $last['id']] ?? []);
                    ?>
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
                            data-search="<?= $h($searchText) ?>">
                            <?= $conversation['avatar'] ?>
                            <span class="chat-conversation-copy">
                                <span class="chat-conversation-top">
                                    <strong><?= $h($conversation['name']) ?></strong>
                                    <time datetime="<?= $h($last['created_at']) ?>"><?= $h($listTime($toDate($last['created_at']))) ?></time>
                                </span>
                                <span><?= $h($last['subject']) ?></span>
                                <small><?= $last['mine'] ? 'You: ' : '' ?><?= $h($lastPreview) ?></small>
                            </span>
                            <?php if ($conversation['unread']): ?>
                                <span class="chat-unread-count" aria-label="<?= (int) $conversation['unread'] ?> unread"><?= (int) $conversation['unread'] ?></span>
                            <?php endif; ?>
                        </button>
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
            </header>

            <div class="chat-thread-body" data-thread-body aria-live="polite">
                <div class="chat-thread-empty">
                    <span class="chat-thread-empty-icon" aria-hidden="true">
                        <svg class="chat-svg" viewBox="0 0 24 24" focusable="false"><path d="M20 11.5a7.5 7.5 0 0 1-8 7.5 8.8 8.8 0 0 1-3-.5L4 20l1.5-4A7.3 7.3 0 0 1 4.5 12 7.5 7.5 0 0 1 12 4.5a7.5 7.5 0 0 1 8 7Z"/></svg>
                    </span>
                    <strong><?= $conversations ? 'Select a conversation' : 'Start a conversation' ?></strong>
                    <span><?= $conversations ? 'Or start a new one with someone in your workspace.' : 'Message HR, agents' . (in_array('applicant', $contactRoles, true) ? ', interns and employees' : '') . ' in your workspace.' ?></span>
                    <button class="btn-solid theme-primary" type="button" data-bs-toggle="dialog" data-bs-target="#chatNewDialog">New message</button>
                </div>
                <?php foreach ($conversations as $conversation): ?>
                    <div class="chat-thread-messages" data-thread="<?= $h($conversation['key']) ?>" hidden>
                        <?php
                        $previousDay = '';
                        $previousSubject = '';
                        foreach ($conversation['messages'] as $message):
                            $date = $toDate($message['created_at']);
                            $day = $dayLabel($date);
                            $subject = trim((string) $message['subject']);
                        ?>
                            <?php if ($day !== $previousDay): $previousDay = $day; ?>
                                <p class="chat-day"><?= $h($day) ?></p>
                            <?php endif; ?>
                            <article class="chat-bubble <?= $message['mine'] ? 'chat-bubble-mine' : 'chat-bubble-theirs' ?>">
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
                                                        <?= $picture['width'] ? 'width="' . (int) $picture['width'] . '" height="' . (int) $picture['height'] . '"' : '' ?>>
                                                    <span class="chat-gif-badge">GIF</span>
                                                </span>
                                            <?php else: ?>
                                                <a class="chat-media-item" href="attachment.php?id=<?= (int) $picture['id'] ?>" target="_blank" rel="noopener" title="Open <?= $h($picture['original_name']) ?>">
                                                    <img src="attachment.php?id=<?= (int) $picture['id'] ?>" alt="<?= $h($picture['original_name']) ?>" loading="lazy"
                                                        <?= $picture['width'] ? 'width="' . (int) $picture['width'] . '" height="' . (int) $picture['height'] . '"' : '' ?>>
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
                                    <p class="chat-message-text"><?= $h($message['body']) ?></p>
                                <?php endif; ?>
                                <time datetime="<?= $h($message['created_at']) ?>"><?= $h($date->format('g:i A')) ?></time>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
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
                            <svg class="chat-svg" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="11" cy="11" r="6.5"/><path d="m16 16 4.5 4.5"/></svg>
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
                    <button class="chat-tool" type="button" data-attach aria-label="Attach files or photos" title="Attach files or photos (up to <?= MENTORA_MESSAGE_MAX_FILES ?>, <?= $h($formatBytes(MENTORA_MESSAGE_MAX_BYTES)) ?> each)" disabled>
                        <svg class="chat-svg" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m20 11.5-8.2 8.2a5 5 0 0 1-7.1-7.1l8.6-8.6a3.4 3.4 0 0 1 4.8 4.8l-8.6 8.6a1.7 1.7 0 0 1-2.4-2.4l8-8"/></svg>
                    </button>
                    <button class="chat-tool" type="button" data-picker-toggle aria-label="Emoji and GIFs" aria-expanded="false" title="Emoji and GIFs" disabled>
                        <svg class="chat-svg" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="9"/><path d="M8.5 14.5a4.5 4.5 0 0 0 7 0M9 9.5h.01M15 9.5h.01"/></svg>
                    </button>
                    <textarea class="chat-message-input" name="body" rows="1" maxlength="5000" placeholder="Choose a conversation or start a new one" aria-label="Write a message" disabled><?= $h($draftBody) ?></textarea>
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
            <h3 class="chat-collapse-toggle" tabindex="0" role="button" aria-expanded="true">Chat info <span aria-hidden="true">⌄</span></h3>
            <p class="chat-collapse-content">Messages are stored securely in your Mentora inbox.</p>
            <h3 class="chat-collapse-toggle" tabindex="0" role="button" aria-expanded="true">Replying <span aria-hidden="true">⌄</span></h3>
            <p class="chat-collapse-content">Press Enter to send and Shift + Enter for a new line. Mentora support announcements can't be replied to.</p>
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
                <input type="text" maxlength="120" placeholder="e.g. Weekly report, Training question" data-contact-topic>
            </label>
            <label class="chat-new-search">
                <svg class="chat-svg" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="11" cy="11" r="6.5"/><path d="m16 16 4.5 4.5"/></svg>
                <input type="search" placeholder="Search people" aria-label="Search people" data-contact-search autocomplete="off">
            </label>
            <div class="chat-contact-list" data-contact-list>
                <?php if ($isAdmin): ?>
                    <p class="chat-contact-group">Announcement</p>
                    <button class="chat-contact" type="button" data-contact="all" data-recipient="all" data-name="Everyone" data-role="Announcement" data-email="" data-search="everyone announcement all">
                        <span class="avatar avatar-initials chat-contact-everyone" aria-hidden="true">
                            <svg class="chat-svg" viewBox="0 0 24 24" focusable="false"><path d="M3 11v2a1 1 0 0 0 1 1h2l5 4V6L6 10H4a1 1 0 0 0-1 1Z"/><path d="M15.5 8.5a5 5 0 0 1 0 7M18.5 5.5a9 9 0 0 1 0 13"/></svg>
                        </span>
                        <span class="chat-contact-copy"><strong>Everyone</strong><small>Send an announcement to all users</small></span>
                    </button>
                <?php endif; ?>
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
                <p class="chat-contact-empty" data-contact-empty <?= $contacts || $isAdmin ? 'hidden' : '' ?>>
                    <?= $contacts || $isAdmin ? 'No one matches your search.' : "There's no one you can message yet." ?>
                </p>
            </div>
        </div>
    </dialog>
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
        let current = null;

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
                : canReply ? `Message ${current.dataset.name}` : "This conversation can't be replied to";
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
            setText('[data-info-stats]', `${count} message${count === 1 ? '' : 's'} in this conversation`);

            workspace.classList.add('is-thread-open');
            syncComposer();
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
                    search: contact.dataset.search
                });
                const top = make('span', 'chat-conversation-top');
                top.append(make('strong', '', contact.dataset.name), make('time', '', 'New'));
                const copy = make('span', 'chat-conversation-copy');
                copy.append(top, make('span', '', topic || 'New conversation'), make('small', '', 'Write your first message'));
                item.append(contact.querySelector('.avatar').cloneNode(true), copy);
                item.addEventListener('click', () => open(item));
                conversationList.prepend(item);
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
        contactButtons.forEach((contact) => contact.addEventListener('click', () => startConversation(contact)));

        // Search + tabs
        const filterItems = () => {
            const search = (searchInput.value || '').trim().toLowerCase();
            const filter = (filterButtons.find((button) => button.classList.contains('active')) || {}).dataset?.filter || 'all';
            let shown = 0;
            items.forEach((item) => {
                const matchesSearch = !search || item.dataset.search.includes(search);
                const matchesFilter = filter === 'all'
                    || (filter === 'unread' && Number(item.dataset.unread) > 0)
                    || (filter === 'support' && item.dataset.support === '1');
                item.hidden = !(matchesSearch && matchesFilter);
                shown += item.hidden ? 0 : 1;
            });
            if (noResults) {
                noResults.hidden = shown > 0 || items.length === 0;
            }
        };
        searchInput.addEventListener('input', filterItems);
        filterButtons.forEach((button) => button.addEventListener('click', () => {
            filterButtons.forEach((tab) => {
                tab.classList.toggle('active', tab === button);
                tab.setAttribute('aria-pressed', String(tab === button));
            });
            filterItems();
        }));

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
        root.querySelector('[data-action="refresh"]').addEventListener('click', () => window.location.reload());
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

        // Start: the conversation from the address (?c=...), else the newest one on wider screens.
        // Phones start on the list.
        const requested = items.find((item) => item.dataset.conversation === root.dataset.selected);
        if (requested) {
            open(requested);
        } else if (items.length && !phone.matches) {
            open(items[0]);
        } else {
            syncComposer();
        }
        growComposer();
        updateTotalUnread();
    })();
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
