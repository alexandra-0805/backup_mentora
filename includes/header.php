<?php
$pageTitle = $pageTitle ?? 'Mentora';
$bodyClass = $bodyClass ?? '';
$inAuth = str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/auth/');
$inAccount = str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/account/');
$root = ($inAuth || $inAccount) ? '../' : '';
$headerStyle = $headerStyle ?? $bodyClass;
// Public pages have no light/dark button and always follow the device's light or dark setting.
// Signed-in pages that use this header (Messages) set $themeFollowsDevice = false to use the
// person's own Light / Dark / Auto choice from Settings instead.
$themeFollowsDevice = $themeFollowsDevice ?? true;
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="icon" type="image/png" href="<?= $root ?>assets/images/mentora-logo.png?v=1">
    <link rel="stylesheet" href="<?= $root ?>assets/css/style.css?v=36">
    <link rel="stylesheet" href="<?= $root ?>assets/css/theme.css?v=8">
    <link rel="stylesheet" href="<?= $root ?>assets/css/dialog.css?v=1">
    <?php require __DIR__ . '/theme_boot.php'; ?>
</head>

<body class="<?= htmlspecialchars($bodyClass, ENT_QUOTES, 'UTF-8') ?>">
    <?php if (empty($hideTopbar)): // a page can set $hideTopbar = true (Messages does) to go without the top header ?>
    <header class="topbar<?= $headerStyle ? ' ' . htmlspecialchars($headerStyle, ENT_QUOTES, 'UTF-8') . '-topbar' : '' ?>">
        <a class="brand" href="<?= $root ?>index.php" aria-label="Mentora home">
            <img src="<?= $root ?>assets/images/mentora-logo.png" alt="Mentora">
            <span>Mentora</span>
        </a>
        <div class="topbar-tools">
            <?php if ($headerStyle !== 'signin-page'): ?>
                <nav>
                    <a href="<?= $root ?>auth/signin.php"><strong>Sign in</strong></a>
                    <a class="button button-small" href="<?= $root ?>auth/signup.php">Apply now</a>
                </nav>
            <?php endif; ?>
        </div>
    </header>
    <?php endif; ?>
    <div class="cursor-spotlight" aria-hidden="true"></div>
    <script>
        (() => {
            const spotlight = document.querySelector('.cursor-spotlight');
            const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

            if (!spotlight || reducedMotion) {
                return;
            }

            let frame;
            let pointerX = window.innerWidth / 2;
            let pointerY = window.innerHeight / 2;

            const updateSpotlight = () => {
                document.body.style.setProperty('--cursor-x', `${pointerX}px`);
                document.body.style.setProperty('--cursor-y', `${pointerY}px`);
                frame = null;
            };

            document.addEventListener('pointermove', (event) => {
                pointerX = event.clientX;
                pointerY = event.clientY;
                spotlight.classList.add('is-visible');
                if (!frame) {
                    frame = window.requestAnimationFrame(updateSpotlight);
                }
            });

            document.addEventListener('pointerleave', () => {
                spotlight.classList.remove('is-visible');
            });
        })();
    </script>