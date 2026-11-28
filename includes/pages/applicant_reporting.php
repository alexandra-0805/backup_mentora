<?php

/**
 * End-of-day report (applicant/intern/reporting.php, applicant/employee/reporting.php; $pathway set there).
 *   - Hours: automatic, while they click, type or scroll in Mentora (includes/intern_hours.php, account/activity.php);
 *     their first and last activity become the day's time in / time out (attendance, admin Monitoring), and fill in
 *     the report's times for today.
 *   - End-of-day report (includes/eod_report.php): date, day, time in, time out, hours worked, tasks completed,
 *     notes -> work_reports "submitted"; the supervising agent (interns) or HR (employees) is notified, approves or
 *     returns it (agent Reporting overview / admin Monitoring), and approved hours count as before.
 *   - A returned report can be corrected and sent again.
 *   - Their reports, oldest first, with a total after every 5 days (days and hours worked).
 */

declare(strict_types=1);

require_once __DIR__ . '/../workflow.php';
require_once __DIR__ . '/../eod_report.php';

$folder = mentora_pathway_folder($pathway ?? 'intern'); // $pathway is set by applicant/{intern,employee}/reporting.php
$self = 'applicant/' . $folder . '/reporting.php';

if (mentora_is_post()) {
    $me = mentora_post_user(['applicant'], $self);
    $userId = (int) $me['id'];
    $today = date('Y-m-d');
    $action = (string) ($_POST['action'] ?? '');

    if (in_array($action, ['time_in', 'time_out'], true)) {
        // Hours are counted automatically (no Time in / Time out buttons); an old form from a cached page does nothing.
        mentora_flash('Your hours are counted automatically while you click, type or scroll in Mentora.');
    } elseif ($action === 'submit_report') {
        [$eod, $error] = mentora_eod_from_post($_POST); // includes/eod_report.php
        $date = $eod['date'];
        if ($error === null) {
            $existing = $pdo->prepare('SELECT id, status FROM work_reports WHERE user_id = ? AND report_date = ? ORDER BY id DESC LIMIT 1');
            $existing->execute([$userId, $date]);
            $report = $existing->fetch();
            $fields = [$eod['day'], $eod['time_in'], $eod['time_out'], $eod['hours'], $eod['tasks'], $eod['notes']];
            if ($report && $report['status'] !== 'returned') {
                $error = 'You already sent an end-of-day report for ' . mentora_eod_date($date) . '.';
            } elseif ($report) {
                $pdo->prepare("UPDATE work_reports
                    SET day_name = ?, time_in = ?, time_out = ?, hours = ?, summary = ?, notes = ?,
                        status = 'submitted', reviewer_id = NULL, review_note = NULL, reviewed_at = NULL, created_at = NOW()
                    WHERE id = ?")
                    ->execute([...$fields, $report['id']]);
            } else {
                $pdo->prepare('INSERT INTO work_reports (user_id, report_date, day_name, time_in, time_out, hours, summary, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$userId, $date, ...$fields]);
            }
        }
        if ($error !== null) {
            mentora_flash($error, 'error');
            $_SESSION['report_draft'] = $eod;
        } else {
            $person = mentora_person($pdo, $userId);
            mentora_notify_reviewers(
                $pdo,
                $person,
                'End-of-day report to review: ' . $me['name'],
                mentora_eod_date($date) . ' (' . $eod['day'] . ') · ' . mentora_eod_time($eod['time_in']) . '–' . mentora_eod_time($eod['time_out'])
                    . ' · ' . mentora_hours($eod['hours']) . ' hrs · ' . mb_substr($eod['tasks'], 0, 120)
            );
            mentora_flash('End-of-day report sent. You’ll be notified when it’s reviewed.');
        }
    }
    mentora_redirect($self);
}

// ?format=pdf: download your end-of-day reports (with their review status and the 5-day totals).
if (($_GET['format'] ?? '') === 'pdf') {
    $me = mentora_require_login(['applicant']);
    $query = $pdo->prepare('SELECT r.*, r.summary AS tasks FROM work_reports r WHERE r.user_id = ? ORDER BY r.report_date, r.id');
    $query->execute([(int) $me['id']]);
    $labels = ['submitted' => 'In review', 'approved' => 'Approved', 'returned' => 'Returned'];
    mentora_eod_pdf('End-of-day reports', (string) $me['name'], $query->fetchAll(), [
        'Status' => static fn(array $r): string => $labels[$r['status']] ?? $r['status'],
    ]);
}

$pageTitle = 'End-of-day report';
$activePage = 'reporting.php';
require __DIR__ . '/../applicant_layout.php';

$userId = (int) $currentUser['id'];
$signedIn = $userId > 0;
$person = $signedIn ? mentora_person($pdo, $userId) : null;
$isIntern = $folder === 'intern';
$requiredHours = mentora_required_hours($person);

$reports = [];
$attendance = null;
$totals = ['approved' => 0.0, 'review' => 0.0, 'week' => 0.0];
if ($signedIn) {
    $query = $pdo->prepare('SELECT r.*, r.summary AS tasks, CONCAT(v.first_name, " ", v.last_name) AS reviewer_name
        FROM work_reports r
        LEFT JOIN users v ON v.id = r.reviewer_id
        WHERE r.user_id = ?
        ORDER BY r.report_date DESC, r.id DESC');
    $query->execute([$userId]);
    $reports = $query->fetchAll();
    $weekStart = date('Y-m-d', strtotime('monday this week'));
    foreach ($reports as $report) {
        if ($report['status'] === 'approved') {
            $totals['approved'] += (float) $report['hours'];
            if ($report['report_date'] >= $weekStart) {
                $totals['week'] += (float) $report['hours'];
            }
        } elseif ($report['status'] === 'submitted') {
            $totals['review'] += (float) $report['hours'];
        }
    }
    $query = $pdo->prepare('SELECT * FROM attendance WHERE user_id = ? AND work_date = ?');
    $query->execute([$userId, date('Y-m-d')]);
    $attendance = $query->fetch() ?: null;
}
[$attendanceLabel, $attendanceBadge] = mentora_attendance_state($attendance, date('Y-m-d'));

$draft = $_SESSION['report_draft'] ?? [];
unset($_SESSION['report_draft']);
$returned = array_values(array_filter($reports, static fn($r) => $r['status'] === 'returned'));
if (!$draft && $returned && isset($_GET['fix'])) {
    $fix = $returned[0];
    $draft = ['date' => $fix['report_date'], 'day' => $fix['day_name'], 'time_in' => $fix['time_in'], 'time_out' => $fix['time_out'],
        'hours' => $fix['hours'], 'tasks' => $fix['summary'], 'notes' => $fix['notes']];
}
// A new report for today starts with today's first and last activity (tracked automatically) as time in / time out.
if (!$draft) {
    $draft = ['date' => date('Y-m-d'),
        'time_in' => !empty($attendance['time_in']) ? date('H:i', strtotime($attendance['time_in'])) : '',
        'time_out' => !empty($attendance['time_out']) ? date('H:i', strtotime($attendance['time_out'])) : ''];
}
$approver = $person && !empty($person['agent_name'])
    ? $person['agent_name']
    : ($isIntern ? 'Your supervising agent' : 'HR (Mentora people team)');
$statusBadge = ['submitted' => ['info', 'In review'], 'approved' => ['', 'Approved'], 'returned' => ['warn', 'Returned']];
?>
<div class="admin-heading">
    <div>
        <h1>End-of-day report</h1>
        <p>Before you finish for the day: your date, day, time in and out, hours worked and the tasks you completed.
            <?= mentora_h($isIntern ? 'Your supervising agent' : 'HR') ?> approves it.</p>
    </div>
</div>
<?= mentora_flash_html() ?>
<?php if ($returned): ?>
<div class="alert">Your end-of-day report for <?= mentora_h(mentora_eod_date($returned[0]['report_date'])) ?> was returned<?= $returned[0]['review_note']
    ? ': “' . mentora_h($returned[0]['review_note']) . '”'
    : '' ?>. <a href="reporting.php?fix=1#report-form">Fix and resend it</a>
</div>
<?php endif; ?>
<?php if (!$signedIn): ?>
<div class="notice">You’re viewing a preview. <a href="../../auth/signin.php">Sign in</a> to time in and send reports.</div>
<?php endif; ?>
<div class="admin-grid">
    <section class="pathway-card applicant-form" id="report-form">
        <h3>End-of-day report</h3>
        <form method="post">
            <?= mentora_csrf_field() ?><input type="hidden" name="action" value="submit_report">
            <?= mentora_eod_fields($draft) ?>
            <button class="admin-btn primary"
                type="submit"<?= $signedIn ? '' : ' disabled' ?>
                data-confirm-title="Send this end-of-day report?"
                data-confirm="Your report and hours will be sent to <?= mentora_h($approver) ?> for approval.">Send end-of-day report</button>
        </form>
    </section>
    <section class="pathway-card">
        <?php
        require_once __DIR__ . '/../intern_hours.php';
        $myHours = $signedIn ? (mentora_intern_hours($pdo, $userId, 'all')[0] ?? null) : null;
        $trackedHours = $myHours ? floor((int) $myHours['rendered_seconds'] / 360) / 10 : 0.0;
        ?>
        <?php if ($isIntern): ?>
            <h2 class="kpi-large"><?= mentora_hours($trackedHours) ?> / <?= $requiredHours ?> hrs</h2>
            <p>Internship hours · tracked automatically</p>
            <div class="bar"><i style="width:<?= min(100, round($trackedHours / $requiredHours * 100, 1)) ?>%"></i></div>
        <?php else: ?>
            <h2 class="kpi-large"><?= $myHours ? mentora_duration((int) $myHours['week_seconds']) : '0h 00m' ?></h2>
            <p>Work hours this week · tracked automatically</p>
        <?php endif; ?>
        <div class="notice-row"><span>Approved reports this week</span><strong><?= mentora_hours($totals['week']) ?> hrs</strong></div>
        <div class="notice-row"><span>Reports in review</span><strong><?= mentora_hours($totals['review']) ?> hours</strong></div>
        <h3>Today’s hours <small class="badge info">Automatic</small></h3>
        <p class="panel-subtitle">Your hours count automatically while you click, type or scroll in Mentora<?= $isIntern ? ': writing reports, reading lessons and answering modules' : '' ?>. After <?= MENTORA_ACTIVITY_IDLE_MINUTES ?> minutes with no activity, counting pauses until you’re active again. It also pauses while your status is Offline and when you sign out, and resumes when you sign in and use Mentora. Only the number of clicks and keystrokes is recorded, never what you type.</p>
        <div class="notice-row"><span>Active today</span><strong><?= $myHours ? mentora_duration((int) $myHours['today_seconds']) : '—' ?></strong></div>
        <div class="notice-row">
            <span>First activity</span><strong><?= !empty($attendance['time_in']) ? date('g:i A', strtotime($attendance['time_in'])) : '—' ?></strong>
        </div>
        <div class="notice-row">
            <span>Last activity</span><strong><?= !empty($attendance['time_out']) ? date('g:i A', strtotime($attendance['time_out'])) : '—' ?></strong>
        </div>
        <div class="notice-row"><span><?= $isIntern ? 'Total rendered' : 'Total work hours' ?></span><strong><?= $myHours
            ? mentora_duration((int) $myHours['rendered_seconds']) . ($isIntern ? ' / ' . $myHours['required'] . 'h' : '')
            : '—' ?></strong></div>
        <h3>Your report approver</h3>
        <p><?= mentora_h($approver) ?> reviews your reports.</p>
    </section>
</div>
<section class="pathway-card data-panel eod-reports-card">
    <div class="eod-table-head">
        <div>
            <h3>Your end-of-day reports</h3>
            <p class="panel-subtitle">Oldest first, with your total days and hours worked after every 5 days.</p>
        </div>
        <?php if ($reports): ?><a class="admin-btn" href="reporting.php?format=pdf"><?= mentora_icon('download') ?>Download PDF</a><?php endif; ?>
    </div>
    <?php mentora_eod_table($reports, 'my-reports', [
        'Status' => static function (array $report) use ($statusBadge): string {
            [$badgeClass, $badgeText] = $statusBadge[$report['status']];
            return '<span class="badge ' . $badgeClass . '">' . $badgeText . '</span>';
        },
        'Review' => static fn(array $report): string => $report['reviewed_at']
            ? mentora_h(($report['reviewer_name'] ?: 'Reviewer') . ($report['review_note'] ? ': ' . $report['review_note'] : ''))
            : '—',
    ], 'No end-of-day reports yet. Your reports will appear here with their review status.'); ?>
</section>
<?php require __DIR__ . '/../applicant_footer.php'; ?>
