<?php

/**
 * Hours tracker, counted automatically, for interns and employees (VAs):
 *   - Interns: how many of their required hours (applications.required_hours, default 480) they've rendered.
 *   - Employees: open shift, no required total; today, this week and all their tracked work hours.
 * (The functions keep their "intern" names; $pathway / is_intern tell the two apart.)
 *
 *   - Hours count while the intern is clicking, typing or scrolling in Mentora (reports, lessons, modules): their
 *     open page reports it about every 30 seconds (includes/topbar_clock.php -> account/activity.php) and every
 *     such minute is stored once in intern_activity. After MENTORA_ACTIVITY_IDLE_MINUTES with no activity,
 *     counting pauses until they're active again. Opening or moving to a page counts as activity.
 *   - Paused straight away while their status is Offline (Profile page) or after they sign out; it resumes when
 *     they sign in, set themselves Active or Busy, and use or open a page.
 *   - Their first and last activity of the day become that day's time in / time out (attendance, admin Monitoring).
 *   - Older days recorded with the manual Time in / Time out buttons (no activity minutes) still count
 *     time_out - time_in; such a day without a time out counts 0 and is flagged as a missing time-out.
 *   - These tracked hours are saved as their hours (training_records.hours_logged, see mentora_save_intern_hours()):
 *     interns' internship hours, counted toward completing the required hours; employees' work hours.
 *   - Approved daily-report hours (work_reports) are shown next to them as the work record.
 *
 * Used by the topbar tracker (includes/topbar_clock.php), account/activity.php and the hours reports
 * (includes/pages/intern_hours_report.php: agent/intern_hours.php, admin/intern_hours.php, admin/employee_hours.php).
 */

declare(strict_types=1);

require_once __DIR__ . '/workflow.php';

/** Minutes without a click, keystroke or scroll that still count as working; after that counting pauses. */
const MENTORA_ACTIVITY_IDLE_MINUTES = 5;

/**
 * Active interns or employees with their hours, A–Z. $onlyUserId: just that person (their own tracker).
 * $pathway: 'internship' (interns), 'employee_va' (employees) or 'all'.
 * Each row: id, name, user_code, pathway, is_intern, school, department_name, agent_id, agent_name, required (hours;
 * 0 for employees), approved_hours, time_in_today / time_out_today (first / last activity today), completed_minutes,
 * today_minutes and week_minutes (active minutes before the current minute), last_active_minute, legacy_seconds,
 * days_present, missing_timeouts, plus the figures from mentora_intern_hours_figures().
 */
function mentora_intern_hours(PDO $pdo, ?int $onlyUserId = null, string $pathway = 'internship'): array
{
    $now = time();
    $minute = date('Y-m-d H:i:00', $now - $now % 60); // the current minute (PHP and MySQL both run on +08:00)
    $pathways = $pathway === 'all' ? ['internship', 'employee_va'] : [$pathway === 'employee_va' ? 'employee_va' : 'internship'];

    $sql = "SELECT u.id, u.user_code, u.first_name, u.last_name, u.pathway, u.agent_id, u.presence, u.last_seen_at,
                d.name AS department_name, CONCAT(ag.first_name, ' ', ag.last_name) AS agent_name,
                a.required_hours, a.school,
                (SELECT COALESCE(SUM(r.hours), 0) FROM work_reports r WHERE r.user_id = u.id AND r.status = 'approved') AS approved_hours,
                td.time_in AS time_in_today, td.time_out AS time_out_today,
                (SELECT COUNT(*) FROM intern_activity x WHERE x.user_id = u.id AND x.active_minute < ?) AS completed_minutes,
                (SELECT COUNT(*) FROM intern_activity x WHERE x.user_id = u.id AND x.active_minute >= CURDATE() AND x.active_minute < ?) AS today_minutes,
                (SELECT COUNT(*) FROM intern_activity x WHERE x.user_id = u.id
                  AND x.active_minute >= CURDATE() - INTERVAL WEEKDAY(CURDATE()) DAY AND x.active_minute < ?) AS week_minutes,
                (SELECT MAX(x.active_minute) FROM intern_activity x WHERE x.user_id = u.id) AS last_active_minute,
                (SELECT COUNT(DISTINCT DATE(x.active_minute)) FROM intern_activity x WHERE x.user_id = u.id) AS activity_days,
                (SELECT COALESCE(SUM(TIMESTAMPDIFF(SECOND, x.time_in, x.time_out)), 0) FROM attendance x
                  WHERE x.user_id = u.id AND x.time_in IS NOT NULL AND x.time_out > x.time_in
                    AND NOT EXISTS (SELECT 1 FROM intern_activity y WHERE y.user_id = x.user_id
                                    AND y.active_minute >= x.work_date AND y.active_minute < x.work_date + INTERVAL 1 DAY)) AS legacy_seconds,
                (SELECT COUNT(*) FROM attendance x
                  WHERE x.user_id = u.id AND x.time_in IS NOT NULL AND x.time_out > x.time_in
                    AND NOT EXISTS (SELECT 1 FROM intern_activity y WHERE y.user_id = x.user_id
                                    AND y.active_minute >= x.work_date AND y.active_minute < x.work_date + INTERVAL 1 DAY)) AS legacy_days,
                (SELECT COUNT(*) FROM attendance x
                  WHERE x.user_id = u.id AND x.work_date < CURDATE() AND x.time_in IS NOT NULL AND x.time_out IS NULL
                    AND NOT EXISTS (SELECT 1 FROM intern_activity y WHERE y.user_id = x.user_id
                                    AND y.active_minute >= x.work_date AND y.active_minute < x.work_date + INTERVAL 1 DAY)) AS missing_timeouts
            FROM users u
            LEFT JOIN departments d ON d.id = u.department_id
            LEFT JOIN users ag ON ag.id = u.agent_id
            LEFT JOIN training_records t ON t.user_id = u.id
            LEFT JOIN applications a ON a.id = (SELECT MAX(id) FROM applications WHERE user_id = u.id)
            LEFT JOIN attendance td ON td.user_id = u.id AND td.work_date = CURDATE()
            WHERE u.role = 'applicant' AND u.status = 'active' AND u.pathway IN (" . implode(',', array_fill(0, count($pathways), '?')) . ')'
        . ($onlyUserId !== null ? ' AND u.id = ?' : '')
        . ' ORDER BY u.first_name, u.last_name, u.id';
    $query = $pdo->prepare($sql);
    $query->execute([$minute, $minute, $minute, ...$pathways, ...($onlyUserId !== null ? [$onlyUserId] : [])]);

    $rows = [];
    foreach ($query->fetchAll() as $row) {
        $row['name'] = trim($row['first_name'] . ' ' . $row['last_name']);
        $row['is_intern'] = $row['pathway'] === 'internship';
        $row['required'] = $row['is_intern'] ? mentora_required_hours($row) : 0; // employees: open shift, no total
        $row['days_present'] = (int) $row['activity_days'] + (int) $row['legacy_days'];
        $rows[] = $row + mentora_intern_hours_figures($row, $now);
    }

    return $rows;
}

/**
 * Figures for one intern at time $now: active_now (active this minute or the last one), base (start of the current
 * minute, Unix), closed_seconds (everything before the current minute), rendered_seconds and today_seconds (with the
 * current minute so far while active), remaining_seconds, percent (0–100), state ('completed', 'working' = active
 * now, 'out' = active earlier today, 'absent' = not active today) and finish_estimate (Y-m-d from their average day,
 * counting Monday–Friday only; null before their first day).
 */
function mentora_intern_hours_figures(array $row, int $now): array
{
    $base = $now - $now % 60;
    // Paused right away when they set their status to Offline or sign out (signing out clears last_seen_at).
    $pausedBy = ($row['presence'] ?? '') === 'offline' ? 'offline' : (empty($row['last_seen_at']) ? 'signed_out' : null);
    $activeNow = $pausedBy === null && $row['last_active_minute'] !== null && (int) strtotime((string) $row['last_active_minute']) >= $base - 60;
    $running = $activeNow ? min(120, $now - $base) : 0;
    $closed = (int) $row['completed_minutes'] * 60 + (int) $row['legacy_seconds'];
    $rendered = $closed + $running;
    $isIntern = !empty($row['is_intern']);
    $required = (int) $row['required'] * 3600;
    $remaining = $isIntern ? max(0, $required - $rendered) : 0;
    $state = $isIntern && $remaining === 0 ? 'completed' : ($activeNow ? 'working' : ($row['time_in_today'] ? 'out' : 'absent'));

    $estimate = null;
    $days = (int) $row['days_present'];
    if ($isIntern && $remaining > 0 && $days > 0 && $rendered > 0) {
        $average = max(1800, $rendered / $days);   // seconds on an average day (at least half an hour)
        $workdays = (int) ceil($remaining / $average);
        $date = (new DateTimeImmutable('@' . $now))->setTimezone(new DateTimeZone('Asia/Manila'));
        while ($workdays > 0) {
            $date = $date->modify('+1 day');
            if ((int) $date->format('N') <= 5) {
                $workdays--;
            }
        }
        $estimate = $date->format('Y-m-d');
    }

    return [
        'active_now' => $activeNow,
        'paused_by' => $pausedBy,
        'base' => $base,
        'closed_seconds' => $closed,
        'rendered_seconds' => $rendered,
        'today_seconds' => (int) $row['today_minutes'] * 60 + $running,
        'week_seconds' => (int) $row['week_minutes'] * 60 + $running,
        'remaining_seconds' => $remaining,
        'percent' => $required > 0 ? min(100, round($rendered / $required * 100, 1)) : 0,
        'state' => $state,
        'finish_estimate' => $estimate,
    ];
}

/**
 * A person's tracked hours as saved in training_records.hours_logged (interns' internship hours, employees' work
 * hours): rounded down to 0.1 h, so nobody is marked complete before they've truly rendered their required hours.
 * Null when they aren't an active intern or employee (their saved hours are then left as they are).
 */
function mentora_intern_tracked_hours(PDO $pdo, int $userId): ?float
{
    $row = mentora_intern_hours($pdo, $userId, 'all')[0] ?? null;

    return $row ? min(99999.9, floor((int) $row['rendered_seconds'] / 360) / 10) : null;
}

/**
 * Save an intern's tracked hours (one row from mentora_intern_hours()) as their internship hours, so their
 * overview, progress steps, Training page and the agent / HR lists all count them. Called while they're active
 * (account/activity.php), when they open a page (includes/applicant_layout.php) and when staff open the
 * Internship hours report. The moment they reach their required hours, they, their supervising agent (every agent
 * when none is assigned) and HR are notified, once.
 */
function mentora_save_intern_hours(PDO $pdo, array $row): void
{
    $userId = (int) $row['id'];
    $hours = min(99999.9, floor((int) $row['rendered_seconds'] / 360) / 10);
    $required = (int) $row['required'];

    $current = $pdo->prepare('SELECT hours_logged FROM training_records WHERE user_id = ?');
    $current->execute([$userId]);
    $saved = $current->fetchColumn();
    if ($saved === false) {
        mentora_sync_training($pdo, $userId, (string) $row['pathway']); // creates their training record with these hours
        $saved = 0.0;
    } elseif (abs((float) $saved - $hours) < 0.05) {
        return; // nothing new to save
    }

    // Employees have no required total; interns below it, or already past it, just save.
    if (empty($row['is_intern']) || $hours < $required || (float) $saved >= $required) {
        $pdo->prepare('UPDATE training_records SET hours_logged = ? WHERE user_id = ?')->execute([$hours, $userId]);
        return;
    }
    // Crossing the required hours now: only the request that actually saves it (not one running at the same moment
    // in another tab) sends the notifications.
    $update = $pdo->prepare('UPDATE training_records SET hours_logged = ? WHERE user_id = ? AND hours_logged < ?');
    $update->execute([$hours, $userId, $required]);
    if ($update->rowCount() !== 1) {
        return;
    }
    $title = $row['name'] . ' completed their internship hours';
    $body = mentora_hours($hours) . ' of ' . $required . ' required hours rendered.';
    mentora_notify($pdo, $userId, 'You completed your required internship hours',
        'You’ve rendered ' . mentora_hours($hours) . ' of your ' . $required . ' required hours. Great work!', 'applicant/intern/overview.php');
    if ((int) $row['agent_id'] > 0) {
        mentora_notify($pdo, (int) $row['agent_id'], $title, $body, 'agent/intern_hours.php');
    } else {
        mentora_notify_roles($pdo, ['agent'], $title, $body, 'agent/intern_hours.php');
    }
    mentora_notify_roles($pdo, ['admin'], $title, $body, 'admin/intern_hours.php');
}

/** "132h 45m" (and seconds when $withSeconds), from a number of seconds. */
function mentora_duration(int $seconds, bool $withSeconds = false): string
{
    $seconds = max(0, $seconds);
    $text = intdiv($seconds, 3600) . 'h ' . str_pad((string) intdiv($seconds % 3600, 60), 2, '0', STR_PAD_LEFT) . 'm';

    return $withSeconds ? $text . ' ' . str_pad((string) ($seconds % 60), 2, '0', STR_PAD_LEFT) . 's' : $text;
}

/**
 * What the live trackers need for one intern (data-* attributes / JSON; times in Unix seconds). The pages show
 * closed + today and add the running minute while activeNow: rendered = closed + min(120, now - base).
 */
function mentora_intern_hours_live(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'isIntern' => !empty($row['is_intern']),
        'required' => (int) $row['required'],
        'closed' => (int) $row['closed_seconds'],
        'today' => (int) $row['today_minutes'] * 60,
        'week' => (int) $row['week_minutes'] * 60,
        'base' => (int) $row['base'],
        'activeNow' => (bool) $row['active_now'],
        'pausedBy' => $row['paused_by'],   // 'offline' (status set to Offline), 'signed_out' or null
        'firstActive' => $row['time_in_today'] ? date('g:i A', (int) strtotime((string) $row['time_in_today'])) : null,
        'lastActive' => $row['time_out_today'] ? date('g:i A', (int) strtotime((string) $row['time_out_today'])) : null,
        'approved' => (float) $row['approved_hours'],
        'missing' => (int) $row['missing_timeouts'],
        'finish' => $row['finish_estimate'] ? date('d M Y', (int) strtotime($row['finish_estimate'])) : null,
    ];
}
