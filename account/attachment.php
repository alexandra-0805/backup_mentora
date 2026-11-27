<?php

/**
 * Sends a message attachment to someone allowed to see it: the sender, the recipient, anyone for an
 * announcement, or the members of a group chat (includes/messaging.php). Everyone else gets "not found",
 * so file ids can't be probed.
 *   attachment.php?id=12             images open in the browser
 *   attachment.php?id=12&download=1  always downloads (files always download)
 * Files live in uploads/messages/, which the web server never serves directly (see its .htaccess).
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/messaging.php';

$me = mentora_require_login();
$userId = (int) $me['id'];

// Content types we send, by file extension (never the uploader's claimed type).
const MENTORA_ATTACHMENT_TYPES = [
    'jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif',
    'pdf' => 'application/pdf',
    'doc' => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'xls' => 'application/vnd.ms-excel',
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'ppt' => 'application/vnd.ms-powerpoint',
    'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'txt' => 'text/plain; charset=utf-8', 'csv' => 'text/csv; charset=utf-8',
    'zip' => 'application/zip',
];

$notFound = static function (): never {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Attachment not found.');
};

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$id) {
    $notFound();
}

$query = $pdo->prepare(
    'SELECT a.kind, a.stored_name, a.original_name, a.size, m.sender_id, m.recipient_id, m.group_id
     FROM message_attachments a
     JOIN messages m ON m.id = a.message_id
     WHERE a.id = ?'
);
$query->execute([$id]);
$attachment = $query->fetch();

// The sender, the recipient, anyone for an announcement, or the members of a group chat.
$allowed = $attachment
    && ((int) ($attachment['sender_id'] ?? 0) === $userId
        || (int) ($attachment['recipient_id'] ?? 0) === $userId
        || ($attachment['recipient_id'] === null && ($attachment['group_id'] === null
            || mentora_in_group($pdo, (int) $attachment['group_id'], $userId))));
if (!$allowed || $attachment['kind'] === 'gif') {
    $notFound();
}

if (!preg_match('/^m\d+-[a-f0-9]{16}\.([a-z]+)$/', (string) $attachment['stored_name'], $match)
    || !isset(MENTORA_ATTACHMENT_TYPES[$match[1]])) {
    $notFound();
}
$extension = $match[1];
$path = MENTORA_APP_ROOT . '/uploads/messages/' . $attachment['stored_name'];
if (!is_file($path)) {
    $notFound();
}

$isImage = $attachment['kind'] === 'image';
$inline = $isImage && !isset($_GET['download']);
$fileName = (string) $attachment['original_name'] !== '' ? (string) $attachment['original_name'] : 'attachment.' . $extension;
$asciiName = preg_replace('/[^A-Za-z0-9._ -]/', '_', $fileName) ?: 'attachment.' . $extension;

header('Content-Type: ' . MENTORA_ATTACHMENT_TYPES[$extension]);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $asciiName . '"; filename*=UTF-8\'\'' . rawurlencode($fileName));
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; img-src 'self'; sandbox");
header('Cache-Control: private, max-age=86400');
readfile($path);
