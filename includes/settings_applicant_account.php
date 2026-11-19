<?php
// Account section of the Settings page for employee and intern workspaces.
// Uses $currentUser and $pathway from includes/applicant_layout.php.
$applicantName = trim(($currentUser['first_name'] ?? '') . ' ' . ($currentUser['last_name'] ?? ''));
$applicantCode = (string) ($currentUser['user_code'] ?? '');

$settingsAccount = [
    [
        'key' => 'profile',
        'label' => 'Profile',
        'i18n' => 'profile',
        'icon' => 'user',
        'summary' => $applicantName . ($applicantCode !== '' ? ' · ' . $applicantCode : ''),
        'avatar' => ['url' => $avatarUrl, 'initials' => $initials, 'name' => $applicantName, 'canEdit' => (int) ($currentUser['id'] ?? 0) > 0],
        'rows' => [
            ['fullName', 'Full name', $applicantName !== '' ? $applicantName : '—'],
            ['email', 'Email', ($currentUser['email'] ?? '') !== '' ? $currentUser['email'] : '—'],
            ['pathway', 'Pathway', $pathway === 'intern' ? 'Internship' : 'Employee VA'],
            ['userId', 'User ID', $applicantCode !== '' ? $applicantCode : '—'],
        ],
        'link' => ['openProfile', 'Open my profile', 'profile.php'],
        'note' => ['accountNoteApplicant', 'Name and email changes are handled by HR.'],
    ],
];
