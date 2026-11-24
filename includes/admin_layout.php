<?php

declare(strict_types=1);

date_default_timezone_set('Asia/Manila');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/auth_check.php';

mentora_require_login(['admin']);

require __DIR__ . '/header_counts.php';

// Fallback HTML escaper in case it isn't defined by the includes above
if (!isset($e) || !is_callable($e)) {
    $e = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$pageTitle  = $pageTitle ?? 'Admin | Mentora';
$activePage = $activePage ?? '';

$adminUser = (isset($_SESSION['user']) && is_array($_SESSION['user']))
    ? $_SESSION['user']
    : ['name' => 'HR Administrator'];
$adminName = trim((string)($adminUser['name'] ?? '')) ?: 'HR Administrator';
$adminCode = (string)($adminUser['user_code'] ?? '');

$crumbTitle = trim(explode('|', $pageTitle)[0]);

$initials = '';
foreach (array_slice(preg_split('/\s+/', $adminName) ?: [], 0, 2) as $part) {
    $initials .= mb_strtoupper(mb_substr($part, 0, 1));
}
$initials = $initials !== '' ? $initials : 'HR';

$avatarUrl = mentora_avatar_url($adminUser['avatar_path'] ?? null, '../');

$now = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));

// Waiting for HR: applications to confirm, suitable AI-screened candidates, agents' reports (sidebar badges).
$navCounts = [
    'applications.php' => (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'applicant' AND status = 'pending'")->fetchColumn(),
    'screening.php' => (int) $pdo->query(
        "SELECT COUNT(*) FROM ai_reviews r
         JOIN (SELECT user_id, MAX(id) AS id FROM ai_reviews GROUP BY user_id) l ON l.id = r.id
         JOIN users u ON u.id = r.user_id AND u.status = 'active'
         JOIN applications a ON a.id = (SELECT MAX(id) FROM applications WHERE user_id = u.id)
         WHERE r.category = 'suitable' AND a.status = 'pending'"
    )->fetchColumn(),
    'intern_reports.php' => (int) $pdo->query("SELECT COUNT(*) FROM agent_reports WHERE status = 'submitted'")->fetchColumn(),
];

$adminNav = [
    'dashboard.php'              => 'Dashboard',
    'applications.php'           => 'Applications',
    'screening.php'              => 'AI screening',
    'employee_list.php'          => 'Employee list',
    'intern_list.php'            => 'Intern list',
    'agent_list.php'             => 'Agent list',
    'department.php'             => 'Departments',
    'monitoring.php'             => 'Monitoring',
    'intern_hours.php'           => 'Internship hours',
    'employee_hours.php'         => 'Employee hours',
    'intern_reports.php'         => 'Intern reports',
    'consolidated_report.php'    => 'Reports',
    'user&access_management.php' => 'Users & access',
    'staff_accounts.php'         => 'Staff accounts',
    'settings.php'               => 'Settings',
];
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $e($pageTitle) ?></title>
    <link rel="icon" type="image/png" href="../assets/images/mentora-logo.png?v=1">
    <link rel="stylesheet" href="../assets/css/style.css?v=44">
    <link rel="stylesheet" href="../assets/css/admin.css?v=12">
    <link rel="stylesheet" href="../assets/css/settings.css?v=33">
    <link rel="stylesheet" href="../assets/css/theme.css?v=10">
    <link rel="stylesheet" href="../assets/css/dialog.css?v=2">
    <?php require __DIR__ . '/theme_boot.php'; ?>
</head>

<?php // admin-workspace: admin pages hide the big page title + subtitle (the top bar already names the page). ?>
<body class="admin-body admin-workspace">
    <?php require __DIR__ . '/workspace_theme_runtime.php'; ?>
    <a class="skip-link" href="#main-content">Skip to main content</a>
    <aside class="admin-sidebar">
        <?php // The Mentora logo keeps you on the page you are on (e.g. Monitoring -> Monitoring). ?>
        <a class="admin-brand"
            href="<?= $e(basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'dashboard.php'))) ?>"
            aria-label="Mentora, <?= $e($crumbTitle) ?>"
            aria-current="page">
            <img src="../assets/images/mentora-logo.png" alt="Mentora">
            <span>Mentora</span>
        </a>
        <?php require __DIR__ . '/sidebar_toggle.php'; ?>
        <nav class="admin-nav" id="workspace-nav" aria-label="Admin navigation">
            <small class="nav-label">ADMIN</small>
            <?php foreach ($adminNav as $file => $label): ?>
                <a class="<?= $activePage === $file ? 'active' : '' ?>"
                    href="<?= $e($file) ?>"
                    <?= $activePage === $file ? 'aria-current="page"' : '' ?>>
                    <span aria-hidden="true"></span><?= $e($label) ?>
                    <?php if (($navCounts[$file] ?? 0) > 0): ?>
                        <b class="nav-count" aria-label="<?= $navCounts[$file] ?> waiting"><?= $navCounts[$file] ?></b>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </nav>
        <div class="sidebar-bottom">
            <a class="sidebar-signout" href="../auth/signout.php"><svg class="signout-icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                <path d="M9 4H6.5A1.5 1.5 0 0 0 5 5.5v13A1.5 1.5 0 0 0 6.5 20H9"/>
                <path d="M10 12h10"/>
                <path d="m16 8 4 4-4 4"/>
            </svg><span>Sign out</span></a>
            <a class="profile-chip" href="settings.php" aria-label="Open profile settings">
                <?= mentora_avatar($avatarUrl, $initials) ?>
                <div>
                    <strong><?= $e($adminName) ?></strong>
                    <small>HR administrator<?= $adminCode !== '' ? ' · ' . $e($adminCode) : '' ?></small>
                </div>
            </a>
        </div>
    </aside>

    <div class="admin-main">
        <header class="admin-topbar">
            <nav class="crumbs" aria-label="Breadcrumb">
                <ol>
                    <li><a href="dashboard.php">Admin</a></li>
                    <li><span aria-current="page"><?= $e($crumbTitle) ?></span></li>
                </ol>
            </nav>

            <span class="topbar-actions">
                <?php $themeToggleClass = 'header-icon-link'; require __DIR__ . '/theme_toggle.php'; ?>
                <?php require __DIR__ . '/message_panel.php'; ?>
                <?php require __DIR__ . '/notification_panel.php'; ?>

                <a class="header-avatar-link<?= $avatarUrl ? ' has-photo' : '' ?>"
                    href="settings.php#profile"
                    aria-label="Profile"
                    title="Profile"><?= $avatarUrl ? '<img class="avatar-img" src="' . $e($avatarUrl) . '" alt="">' : $e($initials) ?></a>

                <?php require __DIR__ . '/topbar_clock.php'; ?>
            </span>
        </header>

        <main class="admin-content" id="main-content" tabindex="-1">