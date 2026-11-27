<?php

/**
 * Social profile monitoring: the public profiles a Student VA (intern) or Professional VA (employee)
 * builds. Entered on the applicant Profile page; agents and HR download them all as PDF
 * (agent/export.php?type=social, admin/export.php?type=social).
 */

declare(strict_types=1);

require_once __DIR__ . '/workflow.php';

/** column => [label, allowed host (the domain or any subdomain of it), example]. */
const MENTORA_SOCIAL_PLATFORMS = [
    'upwork_url' => ['Upwork', ['upwork.com'], 'https://www.upwork.com/freelancers/~0123abcd'],
    'linkedin_url' => ['LinkedIn', ['linkedin.com'], 'https://www.linkedin.com/in/your-name'],
    'quora_url' => ['Quora', ['quora.com'], 'https://www.quora.com/profile/Your-Name'],
    'reddit_url' => ['Reddit', ['reddit.com'], 'https://www.reddit.com/user/your_name'],
    'instagram_url' => ['Instagram', ['instagram.com'], 'https://www.instagram.com/your.name'],
    'threads_url' => ['Threads', ['threads.net', 'threads.com'], 'https://www.threads.net/@your.name'],
    'substack_url' => ['Substack', ['substack.com'], 'https://yourname.substack.com'],
];

/** "Student VA" for interns, "Professional VA" for employees. */
function mentora_va_type(string $pathway): string
{
    return mentora_pathway_folder($pathway) === 'intern' ? 'Student VA' : 'Professional VA';
}

function mentora_social_profile(PDO $pdo, int $userId): array
{
    $query = $pdo->prepare('SELECT * FROM social_profiles WHERE user_id = ?');
    $query->execute([$userId]);

    return $query->fetch() ?: [];
}

/** [profiles added, % complete] for a social_profiles row. */
function mentora_social_completion(array $row): array
{
    $added = 0;
    foreach (array_keys(MENTORA_SOCIAL_PLATFORMS) as $column) {
        $added += trim((string) ($row[$column] ?? '')) !== '' ? 1 : 0;
    }

    return [$added, (int) round($added / count(MENTORA_SOCIAL_PLATFORMS) * 100)];
}

/**
 * Check a posted URL for one platform. Returns [clean URL or null when empty, error or null].
 * Adds https:// when it's missing; only http(s) links on that platform's domain are accepted.
 */
function mentora_social_clean_url(string $column, string $value): array
{
    [$label, $hosts] = MENTORA_SOCIAL_PLATFORMS[$column];
    $value = trim($value);
    if ($value === '') {
        return [null, null];
    }
    if (!preg_match('~^https?://~i', $value)) {
        $value = 'https://' . ltrim($value, '/');
    }
    $parts = parse_url($value);
    $host = strtolower((string) ($parts['host'] ?? ''));
    $onPlatform = false;
    foreach ($hosts as $allowed) {
        $onPlatform = $onPlatform || $host === $allowed || str_ends_with($host, '.' . $allowed);
    }
    if (!filter_var($value, FILTER_VALIDATE_URL) || mb_strlen($value) > 255 || !in_array(
        strtolower((string) ($parts['scheme'] ?? '')),
        ['http', 'https'],
        true
    )) {
        return [null, "The {$label} link doesn’t look like a web address."];
    }
    if (!$onPlatform) {
        return [null, "The {$label} link must be on " . implode(' or ', $hosts) . '.'];
    }

    return [$value, null];
}

/** Every active intern and employee with their social profiles, for the PDF export. */
function mentora_social_export_rows(PDO $pdo): array
{
    $columns = implode(', ', array_map(static fn($c) => 's.' . $c, array_keys(MENTORA_SOCIAL_PLATFORMS)));
    $rows = $pdo->query(
        "SELECT u.user_code, u.first_name, u.last_name, u.email, u.pathway, d.name AS department,
                CONCAT(ag.first_name, ' ', ag.last_name) AS agent, $columns, s.updated_at
         FROM users u
         LEFT JOIN social_profiles s ON s.user_id = u.id
         LEFT JOIN departments d ON d.id = u.department_id
         LEFT JOIN users ag ON ag.id = u.agent_id
         WHERE u.role = 'applicant' AND u.status = 'active'
         ORDER BY u.pathway, u.last_name, u.first_name"
    )->fetchAll();

    return array_map(static function (array $row): array {
        [$added, $percent] = mentora_social_completion($row);
        $line = [
            $row['user_code'],
            trim($row['first_name'] . ' ' . $row['last_name']),
            $row['email'],
            mentora_va_type($row['pathway']),
            $row['department'] ?? '',
            $row['agent'] ?? ''
        ];
        foreach (array_keys(MENTORA_SOCIAL_PLATFORMS) as $column) {
            $line[] = $row[$column] ?? '';
        }
        array_push($line, $added, $percent . '%', $row['updated_at'] ?? '');
        return $line;
    }, $rows);
}

function mentora_social_export_header(): array
{
    return [
        'User ID', 'Name', 'Email', 'VA type', 'Department', 'Supervising agent',
        ...array_map(static fn($p) => $p[0] . ' URL', array_values(MENTORA_SOCIAL_PLATFORMS)),
        'Number of profiles added', 'Percentage of completion', 'Last updated',
    ];
}
