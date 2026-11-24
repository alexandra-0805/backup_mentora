<?php

/**
 * Automatic hours for interns and employees (includes/topbar_clock.php calls this): while a signed-in intern or
 * employee is clicking, typing or scrolling in Mentora, their open page posts here about every 30 seconds with how many clicks and keystrokes happened
 * (never what was typed). Each call marks the current minute as active (intern_activity; one row per minute, so
 * several tabs never count twice) and sets today's first and last activity as their time in / time out (attendance,
 * shown on admin Monitoring). Answers with their up-to-date hours (mentora_intern_hours_live) so the tracker
 * stays exact. Anyone who isn't an active intern or employee gets 204 and nothing is recorded; while their status is
 * Offline nothing is recorded either (paused), and signed out the request is refused (401).
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/intern_hours.php';

header('Cache-Control: no-store');

$user = mentora_current_user();
if ($user === null) {
    http_response_code(401);
    exit;
}
// Only a POST from Mentora's own pages (a link or another site can't add hours).
$origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
$host = (string) ($_SERVER['HTTP_HOST'] ?? '');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || ($origin !== '' && parse_url($origin, PHP_URL_HOST) . (parse_url($origin, PHP_URL_PORT) ? ':' . parse_url($origin, PHP_URL_PORT) : '') !== $host)) {
    http_response_code(405);
    exit;
}
if ($user['role'] !== 'applicant' || !in_array($user['pathway'], ['internship', 'employee_va'], true)) {
    http_response_code(204);
    exit;
}

$clicks = max(0, min(5000, (int) ($_POST['clicks'] ?? 0)));
$keys = max(0, min(5000, (int) ($_POST['keys'] ?? 0)));
$userId = (int) $user['id'];

// Status set to Offline (Profile page): the time is paused, nothing is recorded until they're Active or Busy again.
$presence = $pdo->prepare('SELECT presence FROM users WHERE id = ?');
$presence->execute([$userId]);
if ($presence->fetchColumn() !== 'offline') {
    // The minute comes from the server's clock, never from the browser.
    $pdo->prepare("INSERT INTO intern_activity (user_id, active_minute, clicks, keystrokes)
            VALUES (?, DATE_FORMAT(NOW(), '%Y-%m-%d %H:%i:00'), ?, ?)
        ON DUPLICATE KEY UPDATE clicks = LEAST(65535, clicks + VALUES(clicks)), keystrokes = LEAST(65535, keystrokes + VALUES(keystrokes))")
        ->execute([$userId, $clicks, $keys]);
    $pdo->prepare('INSERT INTO attendance (user_id, work_date, time_in, time_out) VALUES (?, CURDATE(), NOW(), NOW())
        ON DUPLICATE KEY UPDATE time_in = COALESCE(time_in, VALUES(time_in)), time_out = NOW()')
        ->execute([$userId]);
}

$row = mentora_intern_hours($pdo, $userId, 'all')[0] ?? null;
if ($row) {
    mentora_save_intern_hours($pdo, $row); // interns: toward their required hours; employees: their work hours
}
header('Content-Type: application/json');
echo json_encode(['now' => time(), 'hours' => $row ? mentora_intern_hours_live($row) : null]);
