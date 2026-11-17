<?php

declare(strict_types=1);

date_default_timezone_set('Asia/Manila');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../config/db.php';
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

$crumbTitle = trim(explode('|', $pageTitle)[0]);

$initials = '';
foreach (array_slice(preg_split('/\s+/', $adminName) ?: [], 0, 2) as $part) {
    $initials .= mb_strtoupper(mb_substr($part, 0, 1));
}
$initials = $initials !== '' ? $initials : 'HR';

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
    <link rel="stylesheet" href="../assets/css/style.css?v=14">
    <link rel="stylesheet" href="../assets/css/admin.css?v=4">
</head>

<body class="admin-body">
    <?php require __DIR__ . '/workspace_theme_runtime.php'; ?>
    <a class="skip-link" href="#main-content">Skip to main content</a>
    <aside class="admin-sidebar">
        <a class="admin-brand" href="dashboard.php" aria-label="Mentora dashboard">
            <img src="../assets/images/mentora-logo.png" alt="Mentora">
            <span>Mentora</span>
        </a>
        <nav class="admin-nav" aria-label="Admin navigation">
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
                <span><?= $e($initials) ?></span>
                <div>
                    <strong><?= $e($adminName) ?></strong>
                    <small>HR administrator</small>
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
                <?php require __DIR__ . '/message_panel.php'; ?>
                <?php require __DIR__ . '/notification_panel.php'; ?>

                <a class="header-avatar-link" href="settings.php" aria-label="Profile" title="Profile"><?= $e($initials) ?></a>

                <span class="topbar-date"><?= $e($now->format('D, d M Y')) ?> · PHT</span>
            </span>
        </header>

        <main class="admin-content" id="main-content" tabindex="-1">