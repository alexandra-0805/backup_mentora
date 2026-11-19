<?php

/**
 * Monitoring (HR): time in / time out for every intern and employee on a chosen day (attendance,
 * recorded on their Reporting page), and every daily report waiting for approval.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/workflow.php';

if (mentora_is_post()) {
    $admin = mentora_post_user(['admin'], 'admin/monitoring.php#reports');
    if (($_POST['action'] ?? '') === 'review_report') {
        [$ok, $message] = mentora_review_report($pdo, (int) ($_POST['report_id'] ?? 0), $admin, (string) ($_POST['decision'] ?? ''), (string) ($_POST['note'] ?? ''));
        mentora_flash($message, $ok ? 'success' : 'error');
    }
    mentora_redirect('admin/monitoring.php#reports');
}

$pageTitle = 'Monitoring';
$activePage = 'monitoring.php';
require __DIR__ . '/../includes/admin_layout.php';

$date = (string) ($_GET['date'] ?? date('Y-m-d'));
$parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
if (!$parsed || $parsed->format('Y-m-d') !== $date || $date > date('Y-m-d')) {
    $date = date('Y-m-d');
}
$isWeekend = (int) date('N', strtotime($date)) >= 6;
$query = $pdo->prepare(
    "SELECT u.id, u.user_code, u.first_name, u.last_name, u.email, u.pathway, d.name AS department_name, a.time_in, a.time_out
     FROM users u
     LEFT JOIN departments d ON d.id = u.department_id
     LEFT JOIN attendance a ON a.user_id = u.id AND a.work_date = ?
     WHERE u.role = 'applicant' AND u.status = 'active' AND DATE(u.created_at) <= ?
     ORDER BY a.time_in IS NULL, a.time_in, u.first_name"
);
$query->execute([$date, $date]);
$roster = $query->fetchAll();
$states = [];
foreach ($roster as $person) {
    $states[$person['id']] = mentora_attendance_state($person['time_in'] ? $person : null, $date);
}
$checkedIn = count(array_filter($roster, static fn($p) => $p['time_in'] !== null));
$late = count(array_filter($states, static fn($s) => $s[0] === 'Late'));
$absent = count(array_filter($states, static fn($s) => $s[0] === 'Absent'));
$scheduled = $isWeekend ? 0 : count($roster);
$waiting = mentora_reports_waiting($pdo);
$shift = date('g:i A', strtotime(MENTORA_SHIFT_START)) . '–' . date('g:i A', strtotime(MENTORA_SHIFT_END));
?>
<div class="admin-heading"><div><h1>Monitoring</h1><p>Review time-in and time-out records, worked hours, attendance logs, and daily reports.</p></div><div class="admin-actions"><a class="admin-btn" href="export.php?type=attendance&amp;date=<?= mentora_h($date) ?>">Export attendance</a><a class="admin-btn primary" href="monitoring.php?date=<?= mentora_h($date) ?>">Refresh records</a></div></div>
<?= mentora_flash_html() ?>
<div class="metric-grid"><div class="metric-card"><small>Scheduled <?= $date === date('Y-m-d') ? 'today' : date('d M', strtotime($date)) ?></small><strong><?= $scheduled ?></strong><span><?= $isWeekend ? 'Rest day' : 'Interns and employees' ?></span></div><div class="metric-card"><small>Checked in</small><strong><?= $checkedIn ?></strong><span><?= $checkedIn ? 'Time-in recorded' : 'No check-ins recorded' ?></span></div><div class="metric-card"><small>Attendance</small><strong><?= $scheduled ? round($checkedIn / $scheduled * 100) : 0 ?>%</strong><span>Based on current records</span></div><div class="metric-card"><small>Attendance exceptions</small><strong><?= $late + $absent ?></strong><span><?= $late ?> late · <?= $absent ?> absent</span></div></div>
<section class="admin-panel data-panel">
    <form class="table-toolbar" method="get"><label for="day" class="sr-only">Day</label><input id="day" type="date" name="date" max="<?= date('Y-m-d') ?>" value="<?= mentora_h($date) ?>" onchange="this.form.submit()"><noscript><button class="admin-btn" type="submit">Show</button></noscript></form>
    <div class="table-toolbar" data-table-filter="attendance-table"><input type="search" data-filter="search" placeholder="Search person or ID" aria-label="Search people"><select data-filter="state" aria-label="Attendance"><option value="all">All records</option><option value="present">Present</option><option value="checked in">Checked in</option><option value="late">Late</option><option value="absent">Absent</option><option value="not checked in">Not checked in</option></select></div>
    <table class="admin-table" id="attendance-table"><thead><tr><th>Person</th><th>Schedule</th><th>Time in</th><th>Time out</th><th>Worked hours</th><th>Status</th></tr></thead><tbody>
        <?php foreach ($roster as $person): [$label, $badge, $worked] = $states[$person['id']]; ?>
            <tr data-state="<?= mentora_h(mb_strtolower($label)) ?>">
                <td><?= mentora_h($person['first_name'] . ' ' . $person['last_name']) ?><br><small><?= mentora_h($person['user_code']) ?> · <?= mentora_pathway_label($person['pathway']) ?><?= $person['department_name'] ? ' · ' . mentora_h($person['department_name']) : '' ?></small></td>
                <td><?= $isWeekend ? 'Rest day' : $shift ?></td>
                <td><?= $person['time_in'] ? date('g:i A', strtotime($person['time_in'])) : '—' ?></td>
                <td><?= $person['time_out'] ? date('g:i A', strtotime($person['time_out'])) : '—' ?></td>
                <td><?= $worked !== null ? mentora_hours($worked) . ' hrs' : ($person['time_in'] ? 'In progress' : 'Not recorded') ?></td>
                <td><span class="badge <?= $badge ?>"><?= mentora_h($label) ?></span></td>
            </tr>
        <?php endforeach; ?>
    </tbody></table>
    <div class="table-note">Showing <span data-filter-count="attendance-table"><?= count($roster) ?></span> people · shift <?= $shift ?>, late after <?= MENTORA_LATE_GRACE_MINUTES ?> minutes. Interns and employees time in and out on their Reporting page.</div>
</section>
<section class="admin-panel data-panel" id="reports" style="margin-top:16px">
    <h2>Daily reports to approve <span class="badge <?= $waiting ? 'warn' : '' ?>"><?= count($waiting) ?></span></h2>
    <p class="panel-subtitle">Employees’ reports come to HR; interns’ reports also go to their agent. Either of you can approve.</p>
    <?php mentora_render_review_table($waiting, 'admin-review', 'All caught up. New reports appear here.'); ?>
</section>
<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
