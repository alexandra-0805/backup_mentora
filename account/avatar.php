<?php

/**
 * Upload or remove the signed-in user's profile photo (Settings > Profile).
 * POST action=upload (file field "avatar") or action=remove, with the CSRF token.
 * Redirects back to the user's own Settings page with ?avatar=<status>#profile.
 *
 * The browser already crops/resizes the photo (includes/settings_panel.php); this file still
 * checks everything itself: real JPEG/PNG/WebP content, at most 2 MB, sensible dimensions.
 * Files get random names in uploads/avatars/ (scripts can't run there: see its .htaccess).
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth_check.php';

const MENTORA_AVATAR_MAX_BYTES = 2 * 1024 * 1024;
const MENTORA_AVATAR_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

$user = mentora_require_login();

$settingsPath = match ($user['role']) {
    'admin' => 'admin/settings.php',
    'agent' => 'agent/settings.php',
    default => $user['pathway'] === 'internship' ? 'applicant/intern/settings.php' : 'applicant/employee/settings.php',
};
$back = static fn(string $status): never => mentora_redirect($settingsPath . '?avatar=' . $status . '#profile');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $back('error');
}
if (!mentora_csrf_valid($_POST['csrf_token'] ?? null)) {
    // Also the case when the upload was bigger than PHP's post_max_size (PHP drops the whole form).
    $back(empty($_POST) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0 ? 'too-big' : 'error');
}

$avatarDir = MENTORA_APP_ROOT . '/uploads/avatars';
$userId = (int) $user['id'];

$currentQuery = $pdo->prepare('SELECT avatar_path FROM users WHERE id = ?');
$currentQuery->execute([$userId]);
$previousPath = $currentQuery->fetchColumn() ?: null;

// Only ever delete files this feature created.
$deleteStoredFile = static function (?string $path) use ($avatarDir): void {
    if (is_string($path) && preg_match('#^uploads/avatars/(u\d+-[a-f0-9]{16}\.(?:jpg|png|webp))$#', $path, $match)) {
        $file = $avatarDir . '/' . $match[1];
        if (is_file($file)) {
            @unlink($file);
        }
    }
};

$action = mentora_post_string('action');

if ($action === 'remove') {
    $pdo->prepare('UPDATE users SET avatar_path = NULL WHERE id = ?')->execute([$userId]);
    $deleteStoredFile($previousPath);
    $back('removed');
}

if ($action !== 'upload') {
    $back('error');
}

$file = $_FILES['avatar'] ?? null;
if (!is_array($file) || !is_int($file['error'] ?? null) || !is_string($file['tmp_name'] ?? null)) {
    $back('error'); // missing, or several files sent as an array
}
if (in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
    $back('too-big');
}
if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
    $back('error');
}
if ((int) $file['size'] > MENTORA_AVATAR_MAX_BYTES) {
    $back('too-big');
}

// Trust the file's contents, not its name or the browser's claimed type.
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
$extension = MENTORA_AVATAR_TYPES[$mime] ?? null;
if ($extension === null) {
    $back('bad-type');
}
$size = @getimagesize($file['tmp_name']);
if (
    $size === false
    || image_type_to_mime_type($size[2]) !== $mime
    || $size[0] < 32 || $size[1] < 32
    || $size[0] > 6000 || $size[1] > 6000
) {
    $back('unreadable');
}

if (!is_dir($avatarDir) && !mkdir($avatarDir, 0755, true) && !is_dir($avatarDir)) {
    error_log('Mentora avatar: could not create ' . $avatarDir);
    $back('error');
}

$fileName = sprintf('u%d-%s.%s', $userId, bin2hex(random_bytes(8)), $extension);
if (!move_uploaded_file($file['tmp_name'], $avatarDir . '/' . $fileName)) {
    error_log('Mentora avatar: could not store upload for user ' . $userId);
    $back('error');
}

$pdo->prepare('UPDATE users SET avatar_path = ? WHERE id = ?')->execute(['uploads/avatars/' . $fileName, $userId]);
$deleteStoredFile($previousPath);
$back('saved');
