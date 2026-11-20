<?php
$pageTitle = 'Settings';
$activePage = 'settings.php';
require __DIR__ . '/../includes/admin_layout.php';

$settingsRole = 'admin';
$settingsAccount = [
    [
        'key' => 'profile',
        'label' => 'Profile',
        'i18n' => 'profile',
        'icon' => 'user',
        'summary' => $adminName . ($adminCode !== '' ? ' · ' . $adminCode : ''),
        'avatar' => ['url' => $avatarUrl, 'initials' => $initials, 'name' => $adminName, 'canEdit' => !empty($_SESSION['user']['id'])],
        'rows' => [
            ['fullName', 'Full name', $adminName],
            ['role', 'Role', 'HR administrator'],
            ['userId', 'User ID', $adminCode !== '' ? $adminCode : '—'],
            ['email', 'Email', (string) ($adminUser['email'] ?? '—')],
        ],
        'link' => ['openUsers', 'Users & access', 'user&access_management.php'],
        'note' => ['accountNoteAdmin', 'Manage accounts and roles in Users & access.'],
    ],
    [
        'key' => 'workspace',
        'label' => 'Workspace',
        'i18n' => 'workspace',
        'icon' => 'building',
        'summary' => 'Mentora PH · HR management',
        'rows' => [
            ['organization', 'Organization', 'Mentora PH'],
            ['workspaceName', 'Workspace name', 'HR management'],
            ['contactEmail', 'Contact email', 'hr@mentora.ph'],
            ['country', 'Country', 'Philippines'],
            ['workingDays', 'Working days', 'Any day'],
            ['defaultShift', 'Default shift', 'Open shift'],
        ],
    ],
];
?>
<div class="admin-heading">
    <div>
        <h1 data-i18n="settingsTitle">Settings</h1>
        <p data-i18n="settingsSubtitle">Make Mentora feel like yours.</p>
    </div>
</div>
<?php require __DIR__ . '/../includes/settings_panel.php'; ?>
<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
