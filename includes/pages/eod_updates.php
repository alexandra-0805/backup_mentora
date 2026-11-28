<?php

/**
 * End-of-day reports (HR admins: admin/eod_updates.php), two tabs (format: includes/eod_report.php):
 *   Team reports (?tab=team, default)  everyone's reports in one place:
 *       interns and employees  work_reports (sent from their End-of-day report page; approved in Monitoring /
 *                              the agent's Reporting overview, so their status shows here)
 *       agents and HR admins   eod_updates (agents from their Reports page, HR from the other tab); no approval
 *     one day (?date=Y-m-d, today by default) with who hasn't sent a report, or one person (?person=ID) with a
 *     total after every 5 days (days and hours worked).
 *   Your end-of-day reports (?tab=mine)  the HR admin's own report, one per day (sending again replaces it), and
 *     their reports with the 5-day totals.
 */

declare(strict_types=1);

require_once __DIR__ . '/../client_work.php';
require_once __DIR__ . '/../eod_report.php';

if (mentora_is_post()) {
    $me = mentora_post_user(['admin'], 'admin/eod_updates.php?tab=mine');
    [$eod, $error] = mentora_eod_from_post($_POST);
    if ($error !== null) {
        mentora_flash($error, 'error');
        $_SESSION['admin_eod_draft'] = $eod;
    } else {
        $pdo->prepare('INSERT INTO eod_updates (user_id, work_date, day_name, time_in, time_out, hours_worked, completed_work, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE day_name = VALUES(day_name), time_in = VALUES(time_in), time_out = VALUES(time_out),
                hours_worked = VALUES(hours_worked), completed_work = VALUES(completed_work), notes = VALUES(notes)')
            ->execute([(int) $me['id'], $eod['date'], $eod['day'], $eod['time_in'], $eod['time_out'], $eod['hours'], $eod['tasks'], $eod['notes']]);
        mentora_flash('Your end-of-day report for ' . mentora_eod_date($eod['date']) . ' was saved.');
    }
    mentora_redirect('admin/eod_updates.php?tab=mine');
}

$pageTitle = 'End-of-day reports';
$activePage = 'eod_updates.php';
mentora_require_login(['admin']); // checked before the page (and the PDF downloads below) is built

$today = date('Y-m-d');
$day = mentora_clean_date((string) ($_GET['date'] ?? '')) ?? $today;
$day = $day > $today ? $today : $day;
$personId = (int) ($_GET['person'] ?? 0);
$statusBadge = ['submitted' => ['info', 'In review'], 'approved' => ['', 'Approved'], 'returned' => ['warn', 'Returned']];
$roleLabel = static fn(array $p): string => match ($p['role']) { 'agent' => 'Agent', 'admin' => 'HR', default => mentora_pathway_label((string) $p['pathway']) };
$tab = ($_GET['tab'] ?? '') === 'mine' ? 'mine' : 'team';
$meId = (int) ($_SESSION['user']['id'] ?? 0);

// Everyone who sends end-of-day reports: interns, employees, agents and HR admins.
$people = [];
foreach ($pdo->query("SELECT id, first_name, last_name, role, pathway FROM users
    WHERE status = 'active' AND role IN ('applicant', 'agent', 'admin') ORDER BY FIELD(role, 'applicant', 'agent', 'admin'), first_name, last_name") as $row) {
    $people[(int) $row['id']] = $row + ['name' => trim($row['first_name'] . ' ' . $row['last_name'])];
}
$person = $people[$personId] ?? null;

/** One person's reports (any source), as rows for mentora_eod_table(). */
$reportsOf = static function (PDO $pdo, array $person): array {
    if ($person['role'] !== 'applicant') { // agents and HR admins: no approval
        $query = $pdo->prepare('SELECT id, work_date AS report_date, day_name, time_in, time_out, hours_worked AS hours,
            completed_work AS tasks, notes, NULL AS status FROM eod_updates WHERE user_id = ? ORDER BY work_date');
    } else {
        $query = $pdo->prepare('SELECT id, report_date, day_name, time_in, time_out, hours, summary AS tasks, notes, status
            FROM work_reports WHERE user_id = ? ORDER BY report_date, id');
    }
    $query->execute([(int) $person['id']]);
    return $query->fetchAll();
};

// The day view: interns' and employees' reports and agents' reports for $day.
$dayRows = [];
if ($person === null) {
    $query = $pdo->prepare("SELECT r.user_id, r.report_date, r.day_name, r.time_in, r.time_out, r.hours, r.summary AS tasks, r.notes, r.status
        FROM work_reports r JOIN users u ON u.id = r.user_id WHERE r.report_date = ? AND u.status = 'active'
        UNION ALL
        SELECT e.user_id, e.work_date, e.day_name, e.time_in, e.time_out, e.hours_worked, e.completed_work, e.notes, NULL
        FROM eod_updates e JOIN users u ON u.id = e.user_id WHERE e.work_date = ? AND u.role IN ('agent', 'admin') AND u.status = 'active'");
    $query->execute([$day, $day]);
    foreach ($query->fetchAll() as $row) {
        if (isset($people[(int) $row['user_id']])) {
            $dayRows[] = $row + ['person' => $people[(int) $row['user_id']]];
        }
    }
    usort($dayRows, static fn(array $a, array $b): int => strcmp($a['person']['name'], $b['person']['name']));
}
$sentIds = array_flip(array_map(static fn(array $r): int => (int) $r['user_id'], $dayRows));
$missing = array_filter($people, static fn(array $p): bool => !isset($sentIds[(int) $p['id']]));
$statusHtml = static function (?string $status) use ($statusBadge): string {
    if ($status === null) {
        return '<span class="panel-subtitle">No approval</span>';
    }
    [$class, $text] = $statusBadge[$status] ?? ['', ucfirst($status)];
    return '<span class="badge ' . $class . '">' . $text . '</span>';
};

// Your end-of-day reports tab: your own reports, and the form (today's report to change, or a new one with
// today's first and last activity as time in / time out).
$myReports = isset($people[$meId]) ? $reportsOf($pdo, $people[$meId]) : [];
$myDraft = $_SESSION['admin_eod_draft'] ?? null;
unset($_SESSION['admin_eod_draft']);
$todays = array_values(array_filter($myReports, static fn(array $r): bool => $r['report_date'] === $today))[0] ?? null;
if ($myDraft === null) {
    $attendance = $pdo->prepare('SELECT time_in, time_out FROM attendance WHERE user_id = ? AND work_date = CURDATE()');
    $attendance->execute([$meId]);
    $activity = $attendance->fetch() ?: [];
    $myDraft = $todays
        ? ['date' => $todays['report_date'], 'day' => $todays['day_name'], 'time_in' => $todays['time_in'], 'time_out' => $todays['time_out'],
            'hours' => $todays['hours'], 'tasks' => $todays['tasks'], 'notes' => $todays['notes']]
        : ['date' => $today,
            'time_in' => !empty($activity['time_in']) ? date('H:i', strtotime($activity['time_in'])) : '',
            'time_out' => !empty($activity['time_out']) ? date('H:i', strtotime($activity['time_out'])) : ''];
}

// ?format=pdf downloads: your own (&tab=mine), one person's (&person=ID), or everyone's for one day (&date=).
if (($_GET['format'] ?? '') === 'pdf') {
    $statusText = ['submitted' => 'In review', 'approved' => 'Approved', 'returned' => 'Returned'];
    if ($tab === 'mine' && isset($people[$meId])) {
        mentora_eod_pdf('End-of-day reports', $people[$meId]['name'], $myReports);
    }
    if ($person !== null) {
        mentora_eod_pdf('End-of-day reports', $person['name'] . ' (' . $roleLabel($person) . ')', $reportsOf($pdo, $person),
            $person['role'] === 'applicant' ? ['Status' => static fn(array $r): string => $statusText[$r['status']] ?? (string) $r['status']] : []);
    }
    require_once __DIR__ . '/../pdf_export.php';
    $lines = array_map(static fn(array $r): array => [
        $r['person']['name'], $roleLabel($r['person']), mentora_eod_date($r['report_date']),
        $r['day_name'] ?: date('l', strtotime($r['report_date'])), mentora_eod_time($r['time_in']), mentora_eod_time($r['time_out']),
        mentora_hours($r['hours']), (string) $r['tasks'], (string) ($r['notes'] ?? ''),
        $r['status'] === null ? 'No approval' : ($statusText[$r['status']] ?? (string) $r['status']),
    ], $dayRows);
    if ($dayRows) {
        $lines[] = ['TOTAL', count($dayRows) . ' report' . (count($dayRows) === 1 ? '' : 's'), '', '', '', '',
            mentora_hours(array_sum(array_map('floatval', array_column($dayRows, 'hours')))) . ' hrs', '', '', ''];
    }
    $notSent = array_map(static fn(array $p): string => $p['name'], $missing);
    if ($notSent) {
        $lines[] = ['NOT SENT', count($notSent) . ' people', '', '', '', '', '', implode(', ', $notSent), '', ''];
    }
    mentora_pdf('End-of-day reports for ' . mentora_eod_date($day) . ' (' . date('l', strtotime($day)) . ')',
        'end-of-day-reports-' . $day . '.pdf',
        ['Person', 'Role', 'Date', 'Day', 'Time in', 'Time out', 'Hours worked', 'Tasks completed', 'Notes', 'Status'],
        $lines ?: [['No end-of-day reports for this day.', '', '', '', '', '', '', '', '', '']]);
}

require __DIR__ . '/../admin_layout.php';
?>
<div class="admin-heading">
    <div>
        <h1>End-of-day reports</h1>
        <p>Your own end-of-day reports, and everyone’s: interns, employees, agents and HR. Interns’ and employees’ reports are approved in Monitoring.</p>
    </div>
    <div class="admin-actions"><a class="admin-btn" href="monitoring.php">Monitoring</a></div>
</div>
<?= mentora_flash_html() ?>
<nav class="report-tabs" role="tablist" aria-label="End-of-day reports">
    <a href="?tab=team" role="tab" data-report-tab="team" aria-selected="<?= $tab === 'team' ? 'true' : 'false' ?>">Team reports</a>
    <a href="?tab=mine" role="tab" data-report-tab="mine" aria-selected="<?= $tab === 'mine' ? 'true' : 'false' ?>">Your end-of-day reports</a>
</nav>

<div class="report-tab-panel" data-report-panel="mine" role="tabpanel"<?= $tab === 'mine' ? '' : ' hidden' ?>>
    <section class="admin-panel" id="eod-form">
        <h2><?= $todays ? 'Today’s end-of-day report (sent — you can still change it)' : 'End-of-day report' ?></h2>
        <p class="panel-subtitle">One report per day. Choosing a day you already sent and saving replaces that day’s report.</p>
        <form method="post" action="eod_updates.php?tab=mine">
            <?= mentora_csrf_field() ?>
            <?= mentora_eod_fields($myDraft) ?>
            <div class="form-actions"><button class="admin-btn primary" type="submit">Save end-of-day report</button></div>
        </form>
    </section>
    <section class="admin-panel data-panel">
        <div class="eod-table-head">
            <div>
                <h2 class="report-section-title">Your end-of-day reports</h2>
                <p class="panel-subtitle">Oldest first, with your total days and hours worked after every 5 days.</p>
            </div>
            <?php if ($myReports): ?><a class="admin-btn" href="eod_updates.php?tab=mine&amp;format=pdf"><?= mentora_icon('download') ?>Download PDF</a><?php endif; ?>
        </div>
        <?php mentora_eod_table($myReports, 'my-eod-table', [], 'No end-of-day reports yet. Your first one will show here.'); ?>
    </section>
</div>

<div class="report-tab-panel" data-report-panel="team" role="tabpanel"<?= $tab === 'team' ? '' : ' hidden' ?>>
<section class="admin-panel eod-view-picker">
    <form method="get" class="eod-picker-form">
        <div class="field"><label for="eod-view-date">Day <small>(mm/dd/yyyy)</small></label>
            <input id="eod-view-date" type="date" name="date" max="<?= $today ?>" value="<?= mentora_h($day) ?>"></div>
        <div class="field"><label for="eod-view-person">Person</label>
            <select id="eod-view-person" name="person"><?= mentora_select_options(array_map(static fn(array $p): string => $p['name'] . ' · ' . $roleLabel($p), $people), $personId ?: null, 'Everyone (one day)') ?></select></div>
        <button class="admin-btn primary" type="submit">Show</button>
        <?php if ($person): ?><a class="admin-btn" href="eod_updates.php?date=<?= mentora_h($day) ?>">Back to everyone</a><?php endif; ?>
    </form>
</section>

<?php if ($person): $rows = $reportsOf($pdo, $person); ?>
    <section class="admin-panel data-panel">
        <div class="eod-table-head">
            <div>
                <h2 class="report-section-title"><?= mentora_h($person['name']) ?> <small class="panel-subtitle"><?= mentora_h($roleLabel($person)) ?> · <?= count($rows) ?> report<?= count($rows) === 1 ? '' : 's' ?></small></h2>
                <p class="panel-subtitle">Oldest first, with the total days and hours worked after every 5 days.</p>
            </div>
            <?php if ($rows): ?><a class="admin-btn" href="eod_updates.php?person=<?= (int) $person['id'] ?>&amp;format=pdf"><?= mentora_icon('download') ?>Download PDF</a><?php endif; ?>
        </div>
        <?php mentora_eod_table($rows, 'person-eod-table', $person['role'] !== 'applicant' ? [] : ['Status' => static fn(array $r): string => $statusHtml($r['status'])],
            $person['first_name'] . ' hasn’t sent an end-of-day report yet.'); ?>
    </section>
<?php else: ?>
    <div class="metric-grid">
        <div class="metric-card"><small>Reports for <?= mentora_eod_date($day) ?></small><strong><?= count($dayRows) ?></strong><span>Of <?= count($people) ?> people · <?= mentora_h(date('l', strtotime($day))) ?></span></div>
        <div class="metric-card"><small>Hours worked</small><strong><?= mentora_hours(array_sum(array_map('floatval', array_column($dayRows, 'hours')))) ?></strong><span>All reports that day</span></div>
        <div class="metric-card"><small>In review</small><strong><?= count(array_filter($dayRows, static fn(array $r): bool => $r['status'] === 'submitted')) ?></strong><span>Waiting for approval</span></div>
        <div class="metric-card"><small>Not sent</small><strong><?= count($missing) ?></strong><span><?= $missing ? 'See the list below' : 'Everyone sent one' ?></span></div>
    </div>
    <section class="admin-panel data-panel">
        <div class="eod-table-head">
            <h2 class="report-section-title">Reports for <?= mentora_eod_date($day) ?> <small class="panel-subtitle"><?= mentora_h(date('l', strtotime($day))) ?></small></h2>
            <a class="admin-btn" href="eod_updates.php?date=<?= mentora_h($day) ?>&amp;format=pdf"><?= mentora_icon('download') ?>Download PDF</a>
        </div>
        <div class="table-scroll">
        <table class="admin-table eod-report-table" id="eod-day-table" data-no-pager>
            <thead><tr><th>Person</th><th>Date</th><th>Day</th><th>Time in</th><th>Time out</th><th>Hours worked</th><th>Tasks completed</th><th>Notes</th><th>Status</th></tr></thead>
            <tbody>
            <?php if (!$dayRows): ?>
                <tr><td colspan="9" class="panel-subtitle">No end-of-day reports for <?= mentora_eod_date($day) ?> yet.</td></tr>
            <?php endif; ?>
            <?php foreach ($dayRows as $row): ?>
                <tr>
                    <td><a href="eod_updates.php?person=<?= (int) $row['user_id'] ?>&amp;date=<?= mentora_h($day) ?>"><strong><?= mentora_h($row['person']['name']) ?></strong></a>
                        <br><small><?= mentora_h($roleLabel($row['person'])) ?></small></td>
                    <td><?= mentora_eod_date($row['report_date']) ?></td>
                    <td><?= mentora_h($row['day_name'] ?: date('l', strtotime($row['report_date']))) ?></td>
                    <td><?= mentora_eod_time($row['time_in']) ?></td>
                    <td><?= mentora_eod_time($row['time_out']) ?></td>
                    <td><?= mentora_hours($row['hours']) ?></td>
                    <td class="eod-text"><?= nl2br(mentora_h((string) $row['tasks'])) ?></td>
                    <td class="eod-text"><?= ($row['notes'] ?? '') !== '' ? nl2br(mentora_h((string) $row['notes'])) : '<span class="panel-subtitle">—</span>' ?></td>
                    <td><?= $statusHtml($row['status']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </section>
    <section class="admin-panel">
        <h2>Not sent yet</h2>
        <p class="panel-subtitle"><?= $missing ? count($missing) . ' of the team have no end-of-day report for ' . mentora_eod_date($day) . '. Open someone to see all their reports.' : 'Everyone sent an end-of-day report for this day.' ?></p>
        <?php foreach ($missing as $absent): ?>
            <div class="list-row"><span><?= mentora_h($absent['name']) ?><br><small><?= mentora_h($roleLabel($absent)) ?></small></span>
                <a class="admin-btn" href="eod_updates.php?person=<?= (int) $absent['id'] ?>&amp;date=<?= mentora_h($day) ?>">Reports</a></div>
        <?php endforeach; ?>
    </section>
<?php endif; ?>
</div>
<script>
    // The tabs switch without reloading (the address keeps ?tab= so a reload stays on the same tab).
    document.querySelectorAll('[data-report-tab]').forEach((tab) => tab.addEventListener('click', (event) => {
        event.preventDefault();
        document.querySelectorAll('[data-report-panel]').forEach((panel) => { panel.hidden = panel.dataset.reportPanel !== tab.dataset.reportTab; });
        document.querySelectorAll('[data-report-tab]').forEach((other) => other.setAttribute('aria-selected', String(other === tab)));
        history.replaceState(null, '', '?tab=' + tab.dataset.reportTab);
    }));
</script>
<?php require __DIR__ . '/../admin_footer.php'; ?>
