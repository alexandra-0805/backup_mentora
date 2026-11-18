<?php

declare(strict_types=1);

date_default_timezone_set('Asia/Manila');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/auth_check.php';

// Original open flow: agent pages are viewable without signing in (demo profile shown to guests).
// This only starts the session and refreshes the signed-in user, if any; it does not restrict access.
mentora_current_user();

require __DIR__ . '/header_counts.php';

/* Fallback HTML escaper */
if (!isset($e) || !is_callable($e)) {
    $e = static fn($value): string =>
    htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$pageTitle  = $pageTitle ?? 'Agent | Mentora';
$activePage = $activePage ?? '';

$agentUser = (isset($_SESSION['user']) && is_array($_SESSION['user']))
    ? $_SESSION['user']
    : ['name' => 'Camille Flores'];

$agentName = trim((string)($agentUser['name'] ?? '')) ?: 'Camille Flores';
$agentCode = (string)($agentUser['user_code'] ?? '');

/* Breadcrumb title */
$crumbTitle = trim(explode('|', $pageTitle)[0]);

/* Generate initials from logged-in user's name */
$initials = '';

foreach (
    array_slice(preg_split('/\s+/', $agentName) ?: [], 0, 2)
    as $part
) {
    $initials .= mb_strtoupper(mb_substr($part, 0, 1));
}

$initials = $initials !== '' ? $initials : 'CF';

$now = new DateTimeImmutable(
    'now',
    new DateTimeZone('Asia/Manila')
);

$agentNav = [
    'dashboard.php'             => 'Dashboard',
    'intern_directory.php'      => 'Intern directory',
    'edit_intern.php'           => 'Edit intern',
    'training_tracker.php'      => 'Training tracker',
    'individual_evaluation.php' => 'Individual evaluation',
    'evaluation_list.php'       => 'Evaluation list',
    'reporting_overview.php'    => 'Reporting overview',
    'documents.php'             => 'Documents',
    'settings.php'              => 'Settings',
];

?>

<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title><?= $e($pageTitle) ?></title>

    <link
        rel="icon"
        type="image/png"
        href="../assets/images/mentora-logo.png?v=1">

    <link
        rel="stylesheet"
        href="../assets/css/style.css?v=28">

    <link
        rel="stylesheet"
        href="../assets/css/admin.css?v=5">
</head>

<body class="admin-body">

    <?php require __DIR__ . '/workspace_theme_runtime.php'; ?>

    <a class="skip-link" href="#main-content">
        Skip to main content
    </a>

    <!-- =========================
     SIDEBAR
========================= -->

    <aside class="admin-sidebar">

        <a
            class="admin-brand"
            href="dashboard.php"
            aria-label="Mentora dashboard">
            <img
                src="../assets/images/mentora-logo.png"
                alt="Mentora">

            <span>Mentora</span>
        </a>

        <nav
            class="admin-nav"
            aria-label="Agent navigation">

            <small class="nav-label">
                AGENT
            </small>

            <?php foreach ($agentNav as $file => $label): ?>

                <a
                    class="<?= $activePage === $file ? 'active' : '' ?>"
                    href="<?= $e($file) ?>"
                    <?= $activePage === $file ? 'aria-current="page"' : '' ?>>
                    <span aria-hidden="true"></span>
                    <?= $e($label) ?>
                </a>

            <?php endforeach; ?>

        </nav>

        <div class="sidebar-bottom">

            <a href="../auth/signout.php">
                ↪ Sign out
            </a>

            <a
                class="profile-chip"
                href="settings.php"
                aria-label="Open profile settings">

                <span>
                    <?= $e($initials) ?>
                </span>

                <div>

                    <strong>
                        <?= $e($agentName) ?>
                    </strong>

                    <small>
                        Agent · Customer Success<?= $agentCode !== '' ? ' · ' . $e($agentCode) : '' ?>
                    </small>

                </div>

            </a>

        </div>

    </aside>


    <!-- =========================
     MAIN AREA
========================= -->

    <div class="admin-main">

        <!-- =========================
         TOPBAR
    ========================= -->

        <header class="admin-topbar">

            <!-- LEFT: Breadcrumb -->
            <nav
                class="crumbs"
                aria-label="Breadcrumb">

                <ol>

                    <li>
                        <a href="dashboard.php">
                            Agent
                        </a>
                    </li>

                    <li>
                        <span aria-current="page">
                            <?= $e($crumbTitle) ?>
                        </span>
                    </li>

                </ol>

            </nav>


            <!-- RIGHT: Actions -->
            <span class="topbar-actions">

                <?php require __DIR__ . '/message_panel.php'; ?>

                <?php require __DIR__ . '/notification_panel.php'; ?>


                <!-- Profile -->
                <a
                    class="header-avatar-link"
                    href="settings.php"
                    aria-label="Profile"
                    title="Profile">
                    <?= $e($initials) ?>
                </a>


                <!-- Date -->
                <span class="topbar-date">
                    <?= $e($now->format('D, d M Y')) ?> · PHT
                </span>

            </span>

        </header>


        <!-- =========================
         PAGE CONTENT
    ========================= -->

        <main
            class="admin-content"
            id="main-content"
            tabindex="-1">