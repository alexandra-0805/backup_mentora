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
    'SELECT id, user_code, first_name, last_name, email, pathway, department_id, created_at
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
    'department_id' => null
];

/* =========================================================
   NAVIGATION
========================================================= */

$nav = $pathway === 'intern'
    ? [
        'form.php'        => ['Form'],
        'overview.php'    => ['Overview'],
        'evaluation.php'  => ['Evaluation'],
        'profile.php'     => ['Profile'],
        'training.php'    => ['Training'],
        'team-agent.php'  => ['Assigned team & agent'],
        'reporting.php'   => ['Reporting'],
        'settings.php'    => ['Settings']
    ]
    : [
        'form.php'        => ['Form'],
        'overview.php'    => ['Overview'],
        'evaluation.php'  => ['Evaluation'],
        'profile.php'     => ['Profile'],
        'training.php'    => ['Training'],
        'department.php'  => ['Assigned department'],
        'reporting.php'   => ['Reporting'],
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
        href="../../assets/css/style.css?v=36">

    <link
        rel="stylesheet"
        href="../../assets/css/admin.css?v=5">

    <link
        rel="stylesheet"
        href="../../assets/css/applicant.css?v=2">

</head>


<body class="applicant-body">

    <?php require __DIR__ . '/workspace_theme_runtime.php'; ?>


    <!-- =======================================================
         SIDEBAR
    ======================================================= -->

    <aside class="applicant-sidebar">

        <a
            class="admin-brand"
            href="overview.php"
            aria-label="Mentora workspace home">

            <img
                src="../../assets/images/mentora-logo.png"
                alt="Mentora">

            <span>
                Mentora
            </span>

        </a>


        <small class="nav-label">

            <?= $pathway === 'intern'
                ? 'INTERNSHIP'
                : 'EMPLOYEE'
            ?>

        </small>


        <nav
            class="admin-nav"
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

            <a href="../../auth/signout.php">
                ↪ Sign out
            </a>


            <a
                class="profile-chip"
                href="profile.php"
                aria-label="Open profile">

                <span>
                    <?= $e($initials) ?>
                </span>


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


                <?php require __DIR__ . '/message_panel.php'; ?>


                <?php require __DIR__ . '/notification_panel.php'; ?>


                <!-- Profile -->

                <a
                    class="header-avatar-link"
                    href="profile.php"
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


        <main
            class="admin-content"
            id="main-content"
            tabindex="-1">

<?php if (!empty($_SESSION['flash_notice']) && is_string($_SESSION['flash_notice'])): ?>
            <div class="applicant-banner" role="status"><strong><?= $e($_SESSION['flash_notice']) ?></strong></div>
<?php unset($_SESSION['flash_notice']); endif; ?>