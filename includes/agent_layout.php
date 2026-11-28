<?php

declare(strict_types=1);

date_default_timezone_set('Asia/Manila');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/auth_check.php';

// Agent pages show and change interns' records, so they need an agent (or HR admin) account.
mentora_require_login(['agent', 'admin']);

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

/* The agent's department (set by HR), shown under their name */
$agentDepartmentQuery = $pdo->prepare('SELECT d.name FROM users u JOIN departments d ON d.id = u.department_id WHERE u.id = ?');
$agentDepartmentQuery->execute([(int) ($agentUser['id'] ?? 0)]);
$agentDepartment = (string) ($agentDepartmentQuery->fetchColumn() ?: '');

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

$avatarUrl = mentora_avatar_url($agentUser['avatar_path'] ?? null, '../');

$now = new DateTimeImmutable(
    'now',
    new DateTimeZone('Asia/Manila')
);

$agentNav = [
    'dashboard.php'             => 'Dashboard', // intern_assessments.php opens from the Dashboard button, so it isn't listed
    'candidates.php'            => 'Department candidates', // recruitment.php and clients.php open from here (Recruitment links / Clients buttons), so they aren't listed
    'intern_directory.php'      => 'Intern directory', // edit_intern.php (Edit buttons) and intern_hours.php open from here, so they aren't listed
    'training_progress.php'     => 'Training tracker', // not training_tracker.php: InfinityFree blocks any URL ending in "tracker.php" (403); tasks.php opens from here (Tasks & deliverables button), so it isn't listed
    'evaluation_list.php'       => 'Evaluation list', // individual_evaluation.php opens from here (button, Review/View), so it isn't listed
    'agent_reports.php'         => 'Reports', // two tabs: End-of-day reports (yours) and Intern reports (to HR)
    'reporting_overview.php'    => 'Reporting overview',
    'documents.php'             => 'Documents',
    'settings.php'              => 'Settings',
];

/* Suitable candidates the AI matched to this agent's department, not decided by HR yet (sidebar badge) */
$candidateCountQuery = $pdo->prepare(
    "SELECT COUNT(*) FROM ai_reviews r
     JOIN (SELECT user_id, MAX(id) AS id FROM ai_reviews GROUP BY user_id) l ON l.id = r.id
     JOIN users u ON u.id = r.user_id AND u.status = 'active'
     JOIN applications a ON a.id = (SELECT MAX(id) FROM applications WHERE user_id = u.id)
     WHERE r.category = 'suitable' AND a.status = 'pending' AND r.dept_verdict IS NULL
       AND r.department_id = (SELECT department_id FROM users WHERE id = ?)"
);
$candidateCountQuery->execute([(int) ($agentUser['id'] ?? 0)]);
$navCounts = ['candidates.php' => (int) $candidateCountQuery->fetchColumn()];

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
        href="../assets/css/style.css?v=44">

    <link
        rel="stylesheet"
        href="../assets/css/admin.css?v=17">

    <link
        rel="stylesheet"
        href="../assets/css/settings.css?v=45">

    <link
        rel="stylesheet"
        href="../assets/css/theme.css?v=10">

    <link
        rel="stylesheet"
        href="../assets/css/dialog.css?v=2">

    <?php require __DIR__ . '/theme_boot.php'; ?>
</head>

<?php // admin-workspace: hides the big page title + subtitle (the top bar already names the page); see admin.css. ?>
<body class="admin-body admin-workspace">

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
            href="<?= $e(basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'dashboard.php'))) ?>"
            aria-label="Mentora, <?= $e($crumbTitle) ?>"
            aria-current="page"><?php // The Mentora logo keeps you on the page you are on. ?>
            <img
                src="../assets/images/mentora-logo.png"
                alt="Mentora">

            <span>Mentora</span>
        </a>

        <?php require __DIR__ . '/sidebar_toggle.php'; ?>

        <nav
            class="admin-nav"
            id="workspace-nav"
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

            <a
                class="profile-chip"
                href="settings.php"
                aria-label="Open profile settings">

                <?= mentora_avatar($avatarUrl, $initials) ?>

                <div>

                    <strong>
                        <?= $e($agentName) ?>
                    </strong>

                    <small>
                        Agent<?= $agentDepartment !== '' ? ' · ' . $e($agentDepartment) : '' ?><?= $agentCode !== '' ? ' · ' . $e($agentCode) : '' ?>
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

                <?php $themeToggleClass = 'header-icon-link'; require __DIR__ . '/theme_toggle.php'; ?>

                <?php require __DIR__ . '/message_panel.php'; ?>

                <?php require __DIR__ . '/notification_panel.php'; ?>


                <!-- Profile -->
                <a
                    class="header-avatar-link<?= $avatarUrl ? ' has-photo' : '' ?>"
                    href="settings.php#profile"
                    aria-label="Profile"
                    title="Profile">
                    <?= $avatarUrl ? '<img class="avatar-img" src="' . $e($avatarUrl) . '" alt="">' : $e($initials) ?>
                </a>


                <!-- Date and time (live, Philippine time) -->
                <?php require __DIR__ . '/topbar_clock.php'; ?>

            </span>

        </header>


        <!-- =========================
         PAGE CONTENT
    ========================= -->

        <main
            class="admin-content"
            id="main-content"
            tabindex="-1">