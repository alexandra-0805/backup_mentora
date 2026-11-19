<?php

/**
 * Downloads a document to someone allowed to see it (anyone else gets "not found"):
 *   - agents and HR admins: every document
 *   - the person who uploaded it
 *   - everyone signed in: published team documents (not other people's application documents)
 * Files live in uploads/documents/, which the web server never serves directly (see its .htaccess).
 *   document.php?id=5             PDFs and images open in the browser
 *   document.php?id=5&download=1  always downloads
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/workflow.php';

$me = mentora_require_login();

$notFound = static function (): never {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Document not found.');
};

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$id) {
    $notFound();
}
$query = $pdo->prepare('SELECT name, owner_id, category, status, stored_name, original_name FROM documents WHERE id = ?');
$query->execute([$id]);
$document = $query->fetch();
if (!$document) {
    $notFound();
}

$isStaff = in_array($me['role'], ['agent', 'admin'], true);
$isOwner = (int) ($document['owner_id'] ?? 0) === (int) $me['id'];
$isPublic = $document['status'] === 'published' && $document['category'] !== 'Application documents';
if (!$isStaff && !$isOwner && !$isPublic) {
    $notFound();
}

$types = [
    'pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'png' => 'image/png',
    'doc' => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'xls' => 'application/vnd.ms-excel',
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'ppt' => 'application/vnd.ms-powerpoint',
    'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'txt' => 'text/plain; charset=utf-8', 'csv' => 'text/csv; charset=utf-8',
];
if (!preg_match('/^d-[a-f0-9]{24}\.([a-z]+)$/', (string) $document['stored_name'], $match) || !isset($types[$match[1]])) {
    $notFound();
}
$path = MENTORA_APP_ROOT . '/uploads/documents/' . $document['stored_name'];
if (!is_file($path)) {
    $notFound();
}

$extension = $match[1];
$inline = in_array($extension, ['pdf', 'jpg', 'png'], true) && !isset($_GET['download']);
$fileName = (string) $document['original_name'] !== '' ? (string) $document['original_name'] : 'document.' . $extension;
$asciiName = preg_replace('/[^A-Za-z0-9._ -]/', '_', $fileName) ?: 'document.' . $extension;

header('Content-Type: ' . $types[$extension]);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $asciiName . '"; filename*=UTF-8\'\'' . rawurlencode($fileName));
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
readfile($path);
