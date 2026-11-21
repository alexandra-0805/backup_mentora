<?php

/**
 * Skills: what an applicant can do (detected in their form or resume) and what each department
 * still needs.
 *
 *   Required skills  departments.required_skills, set by HR (admin/department.php)
 *   Coverage         how many active people in the department have each required skill
 *   Lacking skills   required skills fewer than MENTORA_SKILL_TARGET people in the team have
 *
 * An applicant who has a department's lacking skills is the "suitable" candidate HR and that
 * department are notified about (ai/resume_review.php).
 */

declare(strict_types=1);

/** People per required skill a department should have; below this the skill is "lacking". */
const MENTORA_SKILL_TARGET = 2;

/** Common VA skills and the words that show them in a resume: canonical name => phrases (lowercase). */
function mentora_skill_dictionary(): array
{
    return [
        'HTML' => ['html', 'html5'],
        'CSS' => ['css', 'css3', 'tailwind', 'bootstrap', 'sass'],
        'JavaScript' => ['javascript', 'typescript', 'react', 'vue', 'angular', 'node.js', 'nodejs', 'jquery'],
        'PHP' => ['php', 'laravel', 'codeigniter'],
        'WordPress' => ['wordpress', 'elementor', 'woocommerce'],
        'Responsive design' => ['responsive design', 'responsive web', 'mobile-friendly', 'mobile first'],
        'Git' => ['git', 'github', 'gitlab', 'version control'],
        'SEO' => ['seo', 'search engine optimization', 'keyword research'],
        'Social media management' => ['social media', 'facebook page', 'instagram', 'tiktok', 'community management'],
        'Content writing' => ['content writing', 'blog writing', 'blog posts', 'article writing', 'content creation'],
        'Copywriting' => ['copywriting', 'copywriter', 'ad copy', 'sales copy'],
        'Canva' => ['canva'],
        'Graphic design' => ['graphic design', 'photoshop', 'illustrator', 'figma'],
        'Email marketing' => ['email marketing', 'mailchimp', 'newsletter', 'klaviyo', 'email campaigns'],
        'Facebook ads' => ['facebook ads', 'meta ads', 'paid ads', 'google ads', 'online advertising'],
        'Bookkeeping' => ['bookkeeping', 'bookkeeper', 'accounts payable', 'accounts receivable', 'bank reconciliation'],
        'QuickBooks' => ['quickbooks'],
        'Xero' => ['xero'],
        'Microsoft Excel' => ['excel', 'spreadsheet', 'spreadsheets', 'google sheets', 'vlookup', 'pivot table'],
        'Invoicing' => ['invoicing', 'invoice', 'invoices', 'billing'],
        'Payroll' => ['payroll'],
        'Financial reporting' => ['financial report', 'financial reports', 'financial statements', 'financial reporting', 'balance sheet', 'profit and loss'],
        'Client intake' => ['client intake', 'patient intake', 'intake forms', 'intake'],
        'Calendar management' => ['calendar management', 'scheduling', 'appointment setting', 'appointments', 'google calendar', 'calendly'],
        'Email management' => ['email management', 'inbox management', 'gmail', 'outlook', 'email handling'],
        'Customer service' => ['customer service', 'customer support', 'client support', 'chat support', 'helpdesk', 'call center'],
        'Data privacy' => ['data privacy', 'confidentiality', 'confidential', 'hipaa', 'gdpr'],
        'Medical records' => ['medical records', 'ehr', 'emr', 'health records', 'medical billing'],
        'Data entry' => ['data entry', 'encoding', 'typing speed'],
        'Project management' => ['project management', 'trello', 'asana', 'clickup', 'notion'],
        'Communication' => ['communication skills', 'english', 'written communication', 'verbal communication'],
        'Research' => ['research', 'lead generation', 'market research'],
        'Google Workspace' => ['google workspace', 'google docs', 'google drive', 'g suite'],
        'Microsoft Office' => ['microsoft office', 'ms office', 'microsoft word', 'ms word', 'powerpoint'],
        'Video editing' => ['video editing', 'premiere pro', 'capcut', 'final cut'],
    ];
}

/** "a, b; c" or one per line -> ['a', 'b', 'c'] (trimmed, no duplicates ignoring case, at most 30). */
function mentora_parse_skill_list(?string $value): array
{
    $skills = [];
    foreach (preg_split('/[,;\n]+/', (string) $value) ?: [] as $skill) {
        $skill = mb_substr(trim(preg_replace('/\s+/u', ' ', $skill) ?? ''), 0, 60);
        if ($skill !== '' && !isset($skills[mb_strtolower($skill)])) {
            $skills[mb_strtolower($skill)] = $skill;
        }
    }

    return array_slice(array_values($skills), 0, 30);
}

/** Every skill Mentora looks for: the dictionary plus each department's own required skills. */
function mentora_skill_vocabulary(PDO $pdo): array
{
    static $vocabulary = null;
    if ($vocabulary !== null) {
        return $vocabulary;
    }
    $vocabulary = mentora_skill_dictionary();
    $byLower = [];
    foreach (array_keys($vocabulary) as $name) {
        $byLower[mb_strtolower($name)] = $name;
    }
    foreach ($pdo->query('SELECT required_skills FROM departments')->fetchAll(PDO::FETCH_COLUMN) as $list) {
        foreach (mentora_parse_skill_list($list) as $skill) {
            $lower = mb_strtolower($skill);
            if (!isset($byLower[$lower])) {
                $vocabulary[$skill] = [$lower];
                $byLower[$lower] = $skill;
            }
        }
    }

    return $vocabulary;
}

/** The canonical name a department skill matches in the vocabulary (so "excel" and "Microsoft Excel" agree). */
function mentora_canonical_skill(string $skill, array $vocabulary): string
{
    $lower = mb_strtolower(trim($skill));
    foreach ($vocabulary as $name => $phrases) {
        if (mb_strtolower($name) === $lower || in_array($lower, $phrases, true)) {
            return $name;
        }
    }

    return trim($skill);
}

/** Skills (canonical names) mentioned in a piece of text. */
function mentora_detect_skills(string $text, array $vocabulary): array
{
    $text = mb_strtolower($text);
    if (trim($text) === '') {
        return [];
    }
    $found = [];
    foreach ($vocabulary as $name => $phrases) {
        foreach (array_merge([mb_strtolower($name)], $phrases) as $phrase) {
            if (preg_match('/(?<![\p{L}\p{N}])' . preg_quote($phrase, '/') . '(?![\p{L}\p{N}])/u', $text)) {
                $found[] = $name;
                break;
            }
        }
    }

    return $found;
}

/** Case-insensitive intersection of two skill lists, keeping the spelling of $a. */
function mentora_skill_intersect(array $a, array $b): array
{
    $lowerB = array_map('mb_strtolower', $b);

    return array_values(array_filter($a, static fn(string $skill): bool => in_array(mb_strtolower($skill), $lowerB, true)));
}

/**
 * Each department with its required skills, how many of its active people have each, and the
 * lacking ones: [id => ['id', 'name', 'required' => [...], 'coverage' => [skill => n], 'lacking' => [...], 'people' => n]].
 */
function mentora_department_skill_map(PDO $pdo, bool $fresh = false): array
{
    static $map = null;
    if ($map !== null && !$fresh) {
        return $map;
    }
    $vocabulary = mentora_skill_vocabulary($pdo);
    $map = [];
    foreach ($pdo->query('SELECT id, name, required_skills FROM departments ORDER BY name')->fetchAll() as $department) {
        $required = array_values(array_unique(array_map(
            static fn(string $skill): string => mentora_canonical_skill($skill, $vocabulary),
            mentora_parse_skill_list($department['required_skills'])
        )));
        $map[(int) $department['id']] = [
            'id' => (int) $department['id'],
            'name' => $department['name'],
            'required' => $required,
            'coverage' => array_fill_keys($required, 0),
            'lacking' => [],
            'people' => 0,
        ];
    }

    // What each active intern / employee in a department can do: their application plus their latest AI review.
    $members = $pdo->query(
        "SELECT u.department_id, CONCAT_WS(' ', a.skills, a.desired_role, a.course, a.interest) AS profile,
                (SELECT r.skills FROM ai_reviews r WHERE r.user_id = u.id ORDER BY r.id DESC LIMIT 1) AS reviewed_skills
         FROM users u
         LEFT JOIN applications a ON a.id = (SELECT MAX(id) FROM applications WHERE user_id = u.id)
         WHERE u.role = 'applicant' AND u.status = 'active' AND u.department_id IS NOT NULL"
    )->fetchAll();
    foreach ($members as $member) {
        $departmentId = (int) $member['department_id'];
        if (!isset($map[$departmentId])) {
            continue;
        }
        $map[$departmentId]['people']++;
        $skills = array_unique(array_merge(
            mentora_detect_skills((string) $member['profile'], $vocabulary),
            array_filter(mentora_ai_decode($member['reviewed_skills']), 'is_string')
        ));
        foreach (mentora_skill_intersect($map[$departmentId]['required'], $skills) as $skill) {
            $map[$departmentId]['coverage'][$skill]++;
        }
    }
    foreach ($map as &$department) {
        $department['lacking'] = array_keys(array_filter($department['coverage'], static fn(int $count): bool => $count < MENTORA_SKILL_TARGET));
    }
    unset($department);

    return $map;
}
