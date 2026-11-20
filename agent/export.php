<?php
// CSV downloads for the agent workspace: ?type=interns | training | evaluations | reports | social (agents and HR).
declare(strict_types=1);
require_once __DIR__ . '/../includes/workflow.php';
require_once __DIR__ . '/../includes/social_profiles.php';
mentora_require_login(['agent', 'admin']);

$type = (string) ($_GET['type'] ?? '');
$stamp = date('Y-m-d');
switch ($type) {
    case 'interns':
    case 'training':
        $rows = array_map(static fn($i) => [
            $i['user_code'], $i['name'], $i['email'], $i['department_name'] ?? '', $i['agent_name'] ?? '',
            $i['school'] ?? '', $i['completed'] . '/' . $i['total'], $i['percent'] . '%',
            mentora_hours($i['hours_logged']), mentora_required_hours($i), MENTORA_TRAINING_STATUS[$i['training_status']][1] ?? $i['training_status'],
            date('Y-m-d', strtotime($i['created_at'])),
        ], mentora_workspace_people($pdo));
        mentora_csv(
            "mentora-{$type}-{$stamp}.csv",
            ['User ID', 'Name', 'Email', 'Team', 'Agent', 'School', 'Modules', 'Completion', 'Approved hours', 'Required hours', 'Training status', 'Joined'],
            $rows
        );
    case 'evaluations':
        $rows = $pdo->query("SELECT u.user_code,
                CONCAT(u.first_name, ' ', u.last_name),
                u.email,
                IF(u.pathway = 'internship', 'Intern', 'Employee VA'),
                e.review_type,
                e.status,
                e.score,
                e.due_date,
                e.published_at,
                CONCAT(v.first_name, ' ', v.last_name),
                e.next_steps
            FROM evaluations e
            JOIN users u ON u.id = e.user_id
            LEFT JOIN users v ON v.id = e.evaluator_id
            ORDER BY e.updated_at DESC")
            ->fetchAll(PDO::FETCH_NUM);
        mentora_csv(
            "mentora-evaluations-{$stamp}.csv",
            ['User ID', 'Name', 'Email', 'Pathway', 'Review type', 'Status', 'Score (of 5)', 'Due date', 'Published', 'Evaluator', 'Next steps'],
            $rows
        );
    case 'reports':
        $rows = $pdo->query("SELECT u.user_code,
                CONCAT(u.first_name, ' ', u.last_name),
                IF(u.pathway = 'internship', 'Intern', 'Employee VA'),
                r.report_date,
                r.hours,
                r.summary,
                r.notes,
                r.status,
                CONCAT(v.first_name, ' ', v.last_name),
                r.review_note,
                r.reviewed_at
            FROM work_reports r
            JOIN users u ON u.id = r.user_id
            LEFT JOIN users v ON v.id = r.reviewer_id
            ORDER BY r.report_date DESC, r.id DESC")
            ->fetchAll(PDO::FETCH_NUM);
        mentora_csv(
            "mentora-reports-{$stamp}.csv",
            ['User ID', 'Name', 'Pathway', 'Date', 'Hours', 'Work completed', 'Notes / blockers', 'Status', 'Reviewer', 'Review note', 'Reviewed at'],
            $rows
        );
    case 'social':
        // Social profile monitoring for every active intern (Student VA) and employee (Professional VA).
        mentora_csv("mentora-social-profiles-{$stamp}.csv", mentora_social_export_header(), mentora_social_export_rows($pdo));
    default:
        http_response_code(404);
        exit('Unknown export.');
}
