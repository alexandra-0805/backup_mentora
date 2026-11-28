<?php

declare(strict_types=1);

date_default_timezone_set('Asia/Manila');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/auth_check.php';

// Original open flow: intern and employee pages are viewable without signing in, and either
// pathway can be browsed (demo profile shown to guests). This only starts the session and
// refreshes the signed-in user, if any; it does not restrict access.
mentora_current_user();

require __DIR__ . '/header_counts.php';

/* Fallback HTML escaper */
if (!isset($e) || !is_callable($e)) {
    $e = static fn($value): string =>
    htmlspecialchars(
        (string)$value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

$pathway   = $pathway ?? 'employee';
$pageTitle = $pageTitle ?? (
    $pathway === 'intern'
    ? 'Internship workspace'
    : 'Employee workspace'
);

$activePage = $activePage ?? '';

$user  = $_SESSION['user'] ?? null;
$email = is_array($user) ? ($user['email'] ?? '') : '';

/* =========================================================
   CURRENT USER
========================================================= */

$query = $pdo->prepare(
    'SELECT id, user_code, first_name, last_name, email, pathway, department_id, avatar_path, presence, last_seen_at, created_at
     FROM users
     WHERE email = ?
     LIMIT 1'
);

$query->execute([$email]);

$currentUser = $query->fetch() ?: [
    'id'            => 0,
    'first_name'    => $pathway === 'intern' ? 'Alyssa' : 'Miguel',
    'last_name'     => $pathway === 'intern' ? 'Reyes' : 'Santos',
    'email'         => $email,
    'pathway'       => $pathway === 'intern'
        ? 'internship'
        : 'employee_va',
    'department_id' => null,
    'avatar_path'   => null,
    'presence'      => 'active'
];

// Active / Busy / Offline, chosen on the Profile page ($chosenPresence); Offline automatically when they're away
// or signed out ($presence, what others see; see mentora_effective_presence()).
$presenceLabels = ['active' => 'Active', 'busy' => 'Busy', 'offline' => 'Offline'];
$chosenPresence = isset($presenceLabels[$currentUser['presence'] ?? '']) ? $currentUser['presence'] : 'active';
$presence = (int) $currentUser['id'] > 0 ? mentora_effective_presence($chosenPresence, $currentUser['last_seen_at'] ?? null) : $chosenPresence;

/* =========================================================
   NAVIGATION
========================================================= */

$nav = $pathway === 'intern'
    ? [
        'overview.php'    => ['Overview'],
        'evaluation.php'  => ['Evaluation'],
        'profile.php'     => ['Profile'],
        'training.php'    => ['Training'],
        'team-agent.php'  => ['Assigned team & agent'],
        'reporting.php'   => ['End-of-day report'], // includes/pages/applicant_reporting.php
        'tasks.php'       => ['My tasks'], // client work: includes/client_work.php
        'settings.php'    => ['Settings']
    ]
    : [
        'overview.php'    => ['Overview'],
        'evaluation.php'  => ['Evaluation'],
        'profile.php'     => ['Profile'],
        'training.php'    => ['Training'],
        'department.php'  => ['Assigned department'],
        'reporting.php'   => ['End-of-day report'],
        'tasks.php'       => ['My tasks'],
        'settings.php'    => ['Settings']
    ];

$base = $pathway === 'intern'
    ? 'intern'
    : 'employee';

/* =========================================================
   PROFILE / INITIALS
========================================================= */

$firstName = trim((string)($currentUser['first_name'] ?? ''));
$lastName  = trim((string)($currentUser['last_name'] ?? ''));

$fullName = trim($firstName . ' ' . $lastName);

$initials = strtoupper(
    substr($firstName, 0, 1) .
        substr($lastName, 0, 1)
);

$initials = $initials !== ''
    ? $initials
    : 'U';

$avatarUrl = mentora_avatar_url($currentUser['avatar_path'] ?? null, '../../');

/* =========================================================
   BREADCRUMB
========================================================= */

$crumbTitle = trim(explode('|', $pageTitle)[0]);

$crumbRole = $pathway === 'intern'
    ? 'Internship'
    : 'Employee';

/* =========================================================
   DATE
========================================================= */

$now = new DateTimeImmutable(
    'now',
    new DateTimeZone('Asia/Manila')
);

?>

<!doctype html>
<html lang="en">

<head>

    <meta charset="utf-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1">

    <title>
        <?= $e($pageTitle) ?> | Mentora
    </title>

    <link
        rel="icon"
        type="image/png"
        href="../../assets/images/mentora-logo.png?v=1">

    <link
        rel="stylesheet"
        href="../../assets/css/style.css?v=44">

    <link
        rel="stylesheet"
        href="../../assets/css/admin.css?v=17">

    <link
        rel="stylesheet"
        href="../../assets/css/applicant.css?v=12">

    <link
        rel="stylesheet"
        href="../../assets/css/settings.css?v=44">

    <link
        rel="stylesheet"
        href="../../assets/css/theme.css?v=10">

    <link
        rel="stylesheet"
        href="../../assets/css/dialog.css?v=2">

    <?php require __DIR__ . '/theme_boot.php'; ?>

</head>


<?php // admin-workspace: hides the big page title + subtitle (the top bar already names the page); see admin.css. ?>
<body class="applicant-body admin-workspace">

    <?php require __DIR__ . '/workspace_theme_runtime.php'; ?>


    <!-- =======================================================
         SIDEBAR
    ======================================================= -->

    <aside class="applicant-sidebar">

        <a
            class="admin-brand"
            href="<?= $e(basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'overview.php'))) ?>"
            aria-label="Mentora, <?= $e($crumbTitle) ?>"
            aria-current="page"><?php // The Mentora logo keeps you on the page you are on (e.g. Training -> Training). ?>

            <img
                src="../../assets/images/mentora-logo.png"
                alt="Mentora">

            <span>
                Mentora
            </span>

        </a>

        <?php require __DIR__ . '/sidebar_toggle.php'; ?>


        <small class="nav-label">

            <?= $pathway === 'intern'
                ? 'INTERNSHIP'
                : 'EMPLOYEE'
            ?>

        </small>


        <nav
            class="admin-nav"
            id="workspace-nav"
            aria-label="<?= $e($crumbRole) ?> navigation">

            <?php foreach ($nav as $file => [$label]): ?>

                <a
                    class="<?= $activePage === $file ? 'active' : '' ?>"
                    href="<?= $e($file) ?>"
                    <?= $activePage === $file
                        ? 'aria-current="page"'
                        : '' ?>>

                    <?= $e($label) ?>

                </a>

            <?php endforeach; ?>

        </nav>


        <div class="sidebar-bottom">

            <a class="sidebar-signout" href="../../auth/signout.php"><svg class="signout-icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                <path d="M9 4H6.5A1.5 1.5 0 0 0 5 5.5v13A1.5 1.5 0 0 0 6.5 20H9"/>
                <path d="M10 12h10"/>
                <path d="m16 8 4 4-4 4"/>
            </svg><span>Sign out</span></a>


            <a
                class="profile-chip"
                href="profile.php"
                aria-label="Open profile (<?= $e($presenceLabels[$presence]) ?>)">

                <span class="presence-wrap"><?= mentora_avatar($avatarUrl, $initials) ?><i class="presence-dot is-<?= $e($presence) ?>" title="<?= $e($presenceLabels[$presence]) ?>"></i></span>


                <div>

                    <strong>
                        <?= $e($fullName) ?>
                    </strong>

                    <small>

                        <?= $pathway === 'intern'
                            ? 'Internship pathway'
                            : 'Employee pathway'
                        ?><?= !empty($currentUser['user_code']) ? ' · ' . $e($currentUser['user_code']) : '' ?>

                    </small>

                </div>

            </a>

        </div>

    </aside>


    <!-- =======================================================
         MAIN AREA
    ======================================================= -->

    <div class="admin-main">


        <!-- ===================================================
             TOPBAR
        =================================================== -->

        <header class="admin-topbar">


            <!-- LEFT: Breadcrumb -->

            <nav
                class="crumbs"
                aria-label="Breadcrumb">

                <ol>

                    <li>

                        <a href="overview.php">
                            <?= $e($crumbRole) ?>
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
                    href="profile.php"
                    aria-label="Profile"
                    title="Profile">

                    <?= $avatarUrl ? '<img class="avatar-img" src="' . $e($avatarUrl) . '" alt="">' : $e($initials) ?>

                </a>


                <!-- Internship hours tracker (signed-in interns) and live date and time (Philippine time) -->

                <?php
                $hoursTracker = null;
                if ((int) $currentUser['id'] > 0) { // interns and employees
                    require_once __DIR__ . '/intern_hours.php';
                    $hoursTracker = mentora_intern_hours($pdo, (int) $currentUser['id'], 'all')[0] ?? null;
                    if ($hoursTracker) {
                        mentora_save_intern_hours($pdo, $hoursTracker); // keeps their internship hours up to date
                    }
                }
                require __DIR__ . '/topbar_clock.php';
                ?>

            </span>

        </header>


        <main
            class="admin-content"
            id="main-content"
            tabindex="-1">

<?php if (!empty($_SESSION['flash_notice']) && is_string($_SESSION['flash_notice'])): ?>
            <div class="applicant-banner" role="status"><strong><?= $e($_SESSION['flash_notice']) ?></strong></div>
<?php unset($_SESSION['flash_notice']); endif; ?>