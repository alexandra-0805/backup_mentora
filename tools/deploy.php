<?php
/*
 * Upload changed files to the live site over FTP.
 *
 *   E:\xampp_db\php\php.exe tools\deploy.php            upload files changed since the last deploy
 *   E:\xampp_db\php\php.exe tools\deploy.php --dry-run  only list what would be uploaded
 *   E:\xampp_db\php\php.exe tools\deploy.php --all      upload every file again
 *
 * Login details: config/deploy.local.php (not in git, never uploaded).
 * What was uploaded last time: logs/deploy_manifest.json (not in git). With no manifest, every file is uploaded.
 *
 * Only files git knows about (committed, changed, or new and not ignored) are uploaded, so secrets in
 * *.local.php, user uploads, logs and AI models are never sent. Files deleted locally are only listed,
 * never deleted from the live site.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
chdir($root);

$args = array_slice($argv, 1);
$dryRun = in_array('--dry-run', $args, true);
$uploadAll = in_array('--all', $args, true);

$configFile = $root . '/config/deploy.local.php';
$config = is_file($configFile) ? require $configFile : null;
if (!is_array($config) || ($config['password'] ?? '') === '') {
    fwrite(STDERR, "Fill in config/deploy.local.php first (FTP password is empty).\n");
    exit(1);
}
$remoteDir = rtrim((string) ($config['remote_dir'] ?? '/htdocs'), '/');

// Files to consider: tracked + new-but-not-ignored, minus things the live site doesn't need.
exec('git ls-files --cached --others --exclude-standard -z 2>&1', $out, $code);
if ($code !== 0) {
    fwrite(STDERR, "git failed: " . implode("\n", $out) . "\n");
    exit(1);
}
$skip = '#^(\.git|\.claude/|tools/)|^\.gitignore$|\.(sql|md)$|\.example\.php$|\.local\.php$#i';
$files = [];
foreach (array_filter(explode("\0", implode("\n", $out))) as $path) {
    if (!preg_match($skip, $path) && is_file($root . '/' . $path)) {
        $files[$path] = sha1_file($root . '/' . $path);
    }
}
ksort($files);

$manifestFile = $root . '/logs/deploy_manifest.json';
$manifest = (!$uploadAll && is_file($manifestFile)) ? (json_decode((string) file_get_contents($manifestFile), true) ?: []) : [];
$toUpload = array_keys(array_filter($files, static fn($hash, $path) => ($manifest[$path] ?? null) !== $hash, ARRAY_FILTER_USE_BOTH));
$deleted = array_keys(array_diff_key($manifest, $files));

echo $manifest ? "Changed since last deploy:\n" : "No previous deploy recorded: uploading all files.\n";
foreach ($toUpload as $path) {
    echo "  $path\n";
}
foreach ($deleted as $path) {
    echo "  (deleted locally, still on live site) $path\n";
}
echo count($toUpload) . " file(s) to upload to {$config['host']}{$remoteDir}\n";
if ($dryRun || !$toUpload) {
    exit(0);
}

// Connect: FTPS when the host supports it, plain FTP otherwise.
$ftp = null;
foreach (['ftp_ssl_connect', 'ftp_connect'] as $connect) {
    if (!function_exists($connect)) {
        continue;
    }
    $conn = @$connect((string) $config['host'], (int) ($config['port'] ?? 21), 30);
    if ($conn && @ftp_login($conn, (string) $config['user'], (string) $config['password'])) {
        $ftp = $conn;
        break;
    }
    if ($conn) {
        @ftp_close($conn);
    }
}
if (!$ftp) {
    fwrite(STDERR, "Could not log in to {$config['host']} as {$config['user']}. Check config/deploy.local.php.\n");
    exit(1);
}
ftp_pasv($ftp, true);

// Safety check: the target folder must already hold the site.
if (@ftp_size($ftp, $remoteDir . '/index.php') < 0) {
    fwrite(STDERR, "No index.php in $remoteDir on the server. Check remote_dir in config/deploy.local.php.\n");
    exit(1);
}

$madeDirs = [];
$failed = [];
foreach ($toUpload as $i => $path) {
    $dir = dirname($path);
    $built = $remoteDir;
    foreach ($dir === '.' ? [] : explode('/', $dir) as $part) {
        $built .= '/' . $part;
        if (!isset($madeDirs[$built])) {
            @ftp_mkdir($ftp, $built);
            $madeDirs[$built] = true;
        }
    }
    $started = time();
    $ok = @ftp_put($ftp, $remoteDir . '/' . $path, $root . '/' . $path, FTP_BINARY);
    if (!$ok) {
        // The host sometimes answers an image upload with a speed report instead of "transfer complete"
        // (it re-compresses images, so sizes differ). Count it as uploaded if the file was just written.
        $ok = @ftp_size($ftp, $remoteDir . '/' . $path) > 0 && @ftp_mdtm($ftp, $remoteDir . '/' . $path) >= $started - 120;
    }
    printf("[%d/%d] %s %s\n", $i + 1, count($toUpload), $ok ? 'OK  ' : 'FAIL', $path);
    if ($ok) {
        $manifest[$path] = $files[$path];
    } else {
        $failed[] = $path;
    }
}
@ftp_close($ftp); // the host drops TLS on close; the uploads are already done

foreach ($deleted as $path) {
    unset($manifest[$path]);
}
ksort($manifest);
file_put_contents($manifestFile, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

echo $failed ? count($failed) . " file(s) failed; run again to retry them.\n" : "Done. Reload the live site with Ctrl + F5.\n";
exit($failed ? 1 : 0);
