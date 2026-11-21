<?php
// CSV downloads for HR: ?type=employees | interns | agents | users | social | screening | attendance&date= | summary&from=&to=&scope=
declare(strict_types=1);
require_once __DIR__ . '/../includes/workflow.php';
require_once __DIR__ . '/../includes/social_profiles.php';
require_once __DIR__ . '/../includes/report_summary.php';
require_once __DIR__ . '/../includes/screening.php';
mentora_require_login(['admin']);

$type = (string) ($_GET['type'] ?? '');
$stamp = date('Y-m-d');
$statusLabel = ['active' => 'Active', 'pending' => 'Waiting for confirmation', 'approved' => 'Link sent', 'rejected' => 'Declined', 'disabled' => 'Turned off'];

switch ($type) {
    case 'employees':
    case 'interns':
    case 'agents':
        $where = [
            'employees' => "u.role = 'applicant' AND u.pathway = 'employee_va'",
            'interns' => "u.role = 'applicant' AND u.pathway = 'internship'",
            'agents' => "u.role = 'agent'"
        ][$type];
        $rows = $pdo->query("SELECT u.user_code,
                u.first_name,
                u.last_name,
                u.email,
                u.username,
                d.name,
                CONCAT(ag.first_name, ' ', ag.last_name),
                COALESCE(t.modules_completed, 0),
                COALESCE(t.hours_logged, 0),
                u.status,
                DATE(u.created_at)
            FROM users u
            LEFT JOIN departments d ON d.id = u.department_id
            LEFT JOIN users ag ON ag.id = u.agent_id
            LEFT JOIN training_records t ON t.user_id = u.id
            WHERE $where AND u.status IN ('active', 'disabled') ORDER BY u.last_name, u.first_name")
            ->fetchAll(PDO::FETCH_NUM);
        $rows = array_map(static function (array $row) use ($statusLabel): array { $row[9] = $statusLabel[$row[9]] ?? $row[9]; return $row; }, $rows);
        mentora_csv(
            "mentora-{$type}-{$stamp}.csv",
            [
                'User ID',
                'First name',
                'Last name',
                'Email',
                'Username',
                'Department',
                'Supervising agent',
                'Modules done',
                'Approved hours',
                'Status',
                'Joined'
            ],
            $rows
        );
    case 'users':
        $rows = $pdo->query("SELECT u.user_code,
                CONCAT(u.first_name, ' ', u.last_name),
                u.email,
                u.username,
                CASE u.role WHEN 'admin' THEN 'HR administrator' WHEN 'agent' THEN 'Agent' ELSE IF(u.pathway = 'internship', 'Intern', 'Employee VA') END,
                d.name,
                u.status,
                DATE(u.created_at),
                (SELECT MAX(i.used_at) FROM account_invites i WHERE i.user_id = u.id)
            FROM users u
            LEFT JOIN departments d ON d.id = u.department_id
            ORDER BY u.created_at DESC")
            ->fetchAll(PDO::FETCH_NUM);
        $rows = array_map(static function (array $row) use ($statusLabel): array { $row[6] = $statusLabel[$row[6]] ?? $row[6]; return $row; }, $rows);
        mentora_csv(
            "mentora-access-audit-{$stamp}.csv",
            ['User ID', 'Name', 'Email', 'Username', 'Role', 'Department', 'Access', 'Created', 'Account confirmed'],
            $rows
        );
    case 'attendance':
        $date = (string) ($_GET['date'] ?? $stamp);
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $date = $parsed && $parsed->format('Y-m-d') === $date ? $date : $stamp;
        $query = $pdo->prepare("SELECT u.id,
                u.user_code,
                CONCAT(u.first_name, ' ', u.last_name) AS name,
                u.pathway,
                d.name AS department,
                a.time_in,
                a.time_out
            FROM users u
            LEFT JOIN departments d ON d.id = u.department_id
            LEFT JOIN attendance a ON a.user_id = u.id AND a.work_date = ?
            WHERE u.role = 'applicant' AND u.status = 'active' AND DATE(u.created_at) <= ?
            ORDER BY u.last_name");
        $query->execute([$date, $date]);
        $rows = array_map(static function (array $row) use ($date): array {
            [$label, , $worked] = mentora_attendance_state($row['time_in'] ? $row : null, $date);
            return [
                $date,
                $row['user_code'],
                $row['name'],
                mentora_pathway_label($row['pathway']),
                $row['department'] ?? '',
                $row['time_in'] ? date('H:i', strtotime($row['time_in'])) : '',
                $row['time_out'] ? date('H:i', strtotime($row['time_out'])) : '',
                $worked !== null ? mentora_hours($worked) : '',
                $label
            ];
        }, $query->fetchAll());
        mentora_csv(
            "mentora-attendance-{$date}.csv",
            ['Date', 'User ID', 'Name', 'Pathway', 'Department', 'Time in', 'Time out', 'Worked hours', 'Status'],
            $rows
        );
    case 'summary':
        [$from, $to] = mentora_report_range($_GET['from'] ?? null, $_GET['to'] ?? null);
        $scope = in_array($_GET['scope'] ?? '', ['all', 'internship', 'employee_va'], true) ? $_GET['scope'] : 'all';
        $s = mentora_report_summary($pdo, $from, $to, $scope);
        $rows = [
            ['Applications received', $s['applications']], ['Applications approved', $s['approved']], ['Applications declined', $s['declined']],
            ['Accounts created', $s['activated']], ['Active people', $s['active_people']], ['Daily reports', $s['reports']],
            ['Reports waiting for review', $s['reports_waiting']], ['Approved hours', mentora_hours($s['hours'])],
            ['Training modules completed', $s['modules']], ['People who finished training', $s['training_completed']],
            ['Evaluations published', $s['evaluations']], [
                'Average evaluation score (of 5)',
                $s['average_score'] !== null ? mentora_hours($s['average_score']) : ''
            ],
            ['Days present', $s['present']], ['Hours worked (time in / out)', mentora_hours($s['worked_hours'])],
            ['Employee VAs screened by AI', (int) $s['screening']['screened']], ['AI: suitable', (int) $s['screening']['suitable']],
            ['AI: for review', (int) $s['screening']['for_review']], ['AI: flagged unsuitable', (int) $s['screening']['unsuitable']],
            ['Interns assessed by AI', (int) $s['assessments']['assessed']], ['Interns ready to employ', (int) $s['assessments']['ready']],
            ['Agent employability reports', $s['agent_reports']],
        ];
        foreach (mentora_department_skill_map($pdo) as $gap) {
            $rows[] = ['Lacking skills: ' . $gap['name'], $gap['lacking'] ? implode(', ', $gap['lacking']) : 'None'];
        }
        foreach ($s['departments'] as $department) {
            $rows[] = [
                'Department: ' . $department['name'] . ' (people / hours / avg score)',
                $department['people'] . ' / ' . mentora_hours($department['hours']) . ' / ' . ($department['score'] !== null ? mentora_hours($department['score']) : '—')
            ];
        }
        mentora_csv("mentora-summary-{$from}-to-{$to}.csv", ["Mentora summary {$from} to {$to}", 'Value'], $rows);
    case 'social':
        // Social profile monitoring for every active intern (Student VA) and employee (Professional VA).
        mentora_csv("mentora-social-profiles-{$stamp}.csv", mentora_social_export_header(), mentora_social_export_rows($pdo));
    case 'screening':
        // AI screening of Employee VA applications: every candidate's latest review and HR's decision.
        $rows = [];
        foreach (array_keys(MENTORA_SCREENING_VIEWS) as $view) {
            if ($view === 'flagged') {
                continue; // already inside "review"
            }
            foreach (mentora_screening_rows($pdo, $view) as $row) {
                $rows[] = [
                    $row['user_code'], $row['name'], $row['email'], $row['desired_role'] ?? '',
                    MENTORA_EDUCATION_LEVELS[$row['education'] ?? ''] ?? '', $row['experience_years'] ?? '',
                    $row['submission_mode'] === 'resume' ? 'Resume' : 'Form', $row['score'], MENTORA_AI_CATEGORIES[$row['category']][0],
                    $row['department_name'] ?? '', implode(', ', $row['gap_skills']), implode(', ', $row['skills']), $row['summary'],
                    $row['dept_verdict'] ? ($row['dept_verdict'] === 'endorsed' ? 'Endorsed' : 'Not endorsed') : '',
                    ['pending' => 'Waiting', 'approved' => 'Accepted', 'rejected' => 'Declined'][$row['application_status']] ?? '',
                    $row['referrer_name'] ?? '', date('Y-m-d', strtotime($row['created_at'])),
                ];
            }
        }
        mentora_csv("mentora-ai-screening-{$stamp}.csv", [
            'User ID', 'Name', 'Email', 'Role applied for', 'Education', 'Years of experience', 'Applied with', 'AI score', 'AI category',
            'Best-fit department', 'Lacking skills they fill', 'Skills found', 'AI summary', 'Department assessment', 'HR decision', 'Referred by', 'Reviewed',
        ], $rows);
    default:
        http_response_code(404);
        exit('Unknown export.');
}
