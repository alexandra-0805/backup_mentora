<?php

/**
 * Workspace summary for a date range (admin Reports page, its CSV download and the admin dashboard).
 * $scope: 'all', 'internship' or 'employee_va'.
 */

declare(strict_types=1);

require_once __DIR__ . '/workflow.php';

/** A valid [from, to] (Y-m-d), defaulting to this month so far; swapped if reversed, never in the future. */
function mentora_report_range(mixed $from, mixed $to): array
{
    $valid = static function (mixed $value): ?string {
        $date = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
        return $date && $date->format('Y-m-d') === $value ? min($value, date('Y-m-d')) : null;
    };
    $from = $valid($from) ?? date('Y-m-01');
    $to = $valid($to) ?? date('Y-m-d');

    return $from > $to ? [$to, $from] : [$from, $to];
}

function mentora_report_summary(PDO $pdo, string $from, string $to, string $scope = 'all'): array
{
    $pathwaySql = $scope === 'all' ? '' : ' AND u.pathway = ' . $pdo->quote($scope);
    $range = [$from . ' 00:00:00', $to . ' 23:59:59'];
    $one = static function (string $sql, array $params) use ($pdo): mixed {
        $query = $pdo->prepare($sql);
        $query->execute($params);
        return $query->fetchColumn();
    };

    $summary = [
        'applications' => (int) $one("SELECT COUNT(*) FROM users u WHERE u.role = 'applicant' AND u.created_at BETWEEN ? AND ?$pathwaySql", $range),
        'approved' => (int) $one("SELECT COUNT(*)
            FROM users u
            WHERE u.role = 'applicant' AND u.status IN ('approved', 'active') AND u.created_at BETWEEN ? AND ?$pathwaySql", $range),
        'declined' => (int) $one("SELECT COUNT(*)
            FROM users u
            WHERE u.role = 'applicant' AND u.status = 'rejected' AND u.created_at BETWEEN ? AND ?$pathwaySql", $range),
        'activated' => (int) $one("SELECT COUNT(*)
            FROM account_invites i
            JOIN users u ON u.id = i.user_id
            WHERE u.role = 'applicant' AND i.used_at BETWEEN ? AND ?$pathwaySql", $range),
        'active_people' => (int) $one("SELECT COUNT(*) FROM users u WHERE u.role = 'applicant' AND u.status = 'active'$pathwaySql", []),
        'hours' => (float) $one("SELECT COALESCE(SUM(r.hours), 0)
            FROM work_reports r
            JOIN users u ON u.id = r.user_id
            WHERE r.status = 'approved' AND r.report_date BETWEEN ? AND ?$pathwaySql", [$from, $to]),
        'reports' => (int) $one("SELECT COUNT(*)
            FROM work_reports r
            JOIN users u ON u.id = r.user_id
            WHERE r.report_date BETWEEN ? AND ?$pathwaySql", [$from, $to]),
        'reports_waiting' => (int) $one("SELECT COUNT(*)
            FROM work_reports r
            JOIN users u ON u.id = r.user_id
            WHERE r.status = 'submitted' AND r.report_date BETWEEN ? AND ?$pathwaySql", [$from, $to]),
        'modules' => (int) $one("SELECT COUNT(*)
            FROM training_progress p
            JOIN users u ON u.id = p.user_id
            WHERE p.completed_at BETWEEN ? AND ?$pathwaySql", $range),
        'training_completed' => (int) $one("SELECT COUNT(*)
            FROM training_records t
            JOIN users u ON u.id = t.user_id
            WHERE t.status = 'completed' AND t.updated_at BETWEEN ? AND ?$pathwaySql", $range),
        'evaluations' => (int) $one("SELECT COUNT(*)
            FROM evaluations e
            JOIN users u ON u.id = e.user_id
            WHERE e.status = 'published' AND e.published_at BETWEEN ? AND ?$pathwaySql", $range),
        'average_score' => $one("SELECT AVG(e.score)
            FROM evaluations e
            JOIN users u ON u.id = e.user_id
            WHERE e.status = 'published' AND e.published_at BETWEEN ? AND ?$pathwaySql", $range),
        'present' => (int) $one("SELECT COUNT(*)
            FROM attendance a
            JOIN users u ON u.id = a.user_id
            WHERE a.time_in IS NOT NULL AND a.work_date BETWEEN ? AND ?$pathwaySql", [$from, $to]),
        // Open shift: hours between time in and time out (no late check-ins).
        'worked_hours' => (float) $one("SELECT COALESCE(SUM(TIMESTAMPDIFF(MINUTE, a.time_in, a.time_out)), 0) / 60
            FROM attendance a
            JOIN users u ON u.id = a.user_id
            WHERE a.time_in IS NOT NULL AND a.time_out IS NOT NULL AND a.work_date BETWEEN ? AND ?$pathwaySql", [$from, $to]),
    ];
    $summary['average_score'] = $summary['average_score'] !== null && $summary['average_score'] !== false ? (float) $summary['average_score'] : null;

    $departments = $pdo->prepare(
        "SELECT COALESCE(d.name, 'Not assigned') AS name, COUNT(DISTINCT u.id) AS people,
                COALESCE((SELECT SUM(r.hours) FROM work_reports r JOIN users ru ON ru.id = r.user_id
                          WHERE r.status = 'approved' AND r.report_date BETWEEN ? AND ? AND ru.role = 'applicant' AND ru.status = 'active'
                            AND (ru.department_id <=> d.id)" . str_replace('u.pathway', 'ru.pathway', $pathwaySql) . "), 0) AS hours,
                (SELECT AVG(e.score) FROM evaluations e JOIN users eu ON eu.id = e.user_id
                  WHERE e.status = 'published' AND e.published_at BETWEEN ? AND ? AND eu.role = 'applicant' AND eu.status = 'active'
                    AND (eu.department_id <=> d.id)" . str_replace('u.pathway', 'eu.pathway', $pathwaySql) . ") AS score
         FROM users u LEFT JOIN departments d ON d.id = u.department_id
         WHERE u.role = 'applicant' AND u.status = 'active'$pathwaySql
         GROUP BY d.id, d.name ORDER BY d.name IS NULL, d.name"
    );
    $departments->execute([$from, $to, ...$range]);
    $summary['departments'] = $departments->fetchAll();

    return $summary;
}
