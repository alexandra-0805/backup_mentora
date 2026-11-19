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

$adminNav = [
    'dashboard.php'              => 'Dashboard',
    'employee_list.php'          => 'Employee list',
    'intern_list.php'            => 'Intern list',
    'agent_list.php'             => 'Agent list',
    'department.php'             => 'Departments',
    'monitoring.php'             => 'Monitoring',
    'consolidated_report.php'    => 'Reports',
    'user&access_management.php' => 'Users & access',
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
    <link rel="stylesheet" href="../assets/css/style.css?v=36">
    <link rel="stylesheet" href="../assets/css/admin.css?v=5">
    <link rel="stylesheet" href="../assets/css/settings.css?v=5">
    <link rel="stylesheet" href="../assets/css/theme.css?v=6">
    <link rel="stylesheet" href="../assets/css/dialog.css?v=1">
    <?php require __DIR__ . '/theme_boot.php'; ?>
</head>

<body class="admin-body">
    <?php require __DIR__ . '/workspace_theme_runtime.php'; ?>
    <a class="skip-link" href="#main-content">Skip to main content</a>
    <aside class="admin-sidebar">
        <?php // The Mentora logo keeps you on the page you are on (e.g. Monitoring -> Monitoring). ?>
        <a class="admin-brand" href="<?= $e(basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'dashboard.php'))) ?>" aria-label="Mentora, <?= $e($crumbTitle) ?>" aria-current="page">
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
                </a>
            <?php endforeach; ?>
        </nav>
        <div class="sidebar-bottom">
            <a href="../auth/signout.php">↪ Sign out</a>
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

                <a class="header-avatar-link<?= $avatarUrl ? ' has-photo' : '' ?>" href="settings.php#profile" aria-label="Profile" title="Profile"><?= $avatarUrl ? '<img class="avatar-img" src="' . $e($avatarUrl) . '" alt="">' : $e($initials) ?></a>

                <span class="topbar-date"><?= $e($now->format('D, d M Y')) ?> · PHT</span>
            </span>
        </header>

        <main class="admin-content" id="main-content" tabindex="-1">