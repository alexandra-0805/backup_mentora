<?php
$pageTitle = 'Settings';
$activePage = 'settings.php';
require __DIR__ . '/../includes/agent_layout.php';

$settingsRole = 'agent';
$settingsAccount = [
    [
        'key' => 'profile',
        'label' => 'Profile',
        'i18n' => 'profile',
        'icon' => 'user',
        'summary' => $agentName . ($agentCode !== '' ? ' · ' . $agentCode : ''),
        'avatar' => ['url' => $avatarUrl, 'initials' => $initials, 'name' => $agentName, 'canEdit' => !empty($_SESSION['user']['id'])],
        'rows' => [
            ['fullName', 'Full name', $agentName],
            ['role', 'Role', 'Agent · Customer Success'],
            ['userId', 'User ID', $agentCode !== '' ? $agentCode : '—'],
            ['email', 'Email', (string) ($agentUser['email'] ?? '—')],
        ],
        'note' => ['accountNoteStaff', 'Name, email and role changes are handled by HR.'],
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
