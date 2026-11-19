<?php
$pathway = 'intern';
$pageTitle = 'Settings';
$activePage = 'settings.php';
require __DIR__ . '/../../includes/applicant_layout.php';

$settingsRole = 'intern';
require __DIR__ . '/../../includes/settings_applicant_account.php';
?>
<div class="admin-heading">
    <div>
        <h1 data-i18n="settingsTitle">Settings</h1>
        <p data-i18n="settingsSubtitle">Make Mentora feel like yours.</p>
    </div>
</div>
<?php require __DIR__ . '/../../includes/settings_panel.php'; ?>
<?php require __DIR__ . '/../../includes/applicant_footer.php'; ?>
