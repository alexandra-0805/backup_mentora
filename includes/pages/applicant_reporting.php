<?php

/**
 * Applicant Reporting (applicant/intern/reporting.php, applicant/employee/reporting.php; $pathway set there).
 *   - Time in / time out once a day                       -> attendance (admin Monitoring)
 *   - Daily report: date, hours, work done, blockers       -> work_reports "submitted"
 *     the supervising agent (interns) or HR (employees) is notified, approves or returns it
 *     (agent Reporting overview / admin Monitoring); approved hours count toward required hours.
 *   - A returned report can be corrected and sent again.
 */

declare(strict_types=1);

require_once __DIR__ . '/../workflow.php';

$folder = mentora_pathway_folder($pathway);
$self = 'applicant/' . $folder . '/reporting.php';

if (mentora_is_post()) {
    $me = mentora_post_user(['applicant'], $self);
    $userId = (int) $me['id'];
    $today = date('Y-m-d');
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'time_in') {
        $insert = $pdo->prepare('INSERT IGNORE INTO attendance (user_id, work_date, time_in) VALUES (?, ?, NOW())');
        $insert->execute([$userId, $today]);
        if ($insert->rowCount() === 0) {
            // A row without time_in (shouldn't happen) gets one; otherwise you already timed in.
            $update = $pdo->prepare('UPDATE attendance SET time_in = NOW() WHERE user_id = ? AND work_date = ? AND time_in IS NULL');
            $update->execute([$userId, $today]);
            $update->rowCount() > 0
                ? mentora_flash('Timed in at ' . date('g:i A') . '. Have a great day!')
                : mentora_flash('You already timed in today.', 'error');
        } else {
            mentora_flash('Timed in at ' . date('g:i A') . '. Have a great day!');
        }
    } elseif ($action === 'time_out') {
        $update = $pdo->prepare('UPDATE attendance SET time_out = NOW() WHERE user_id = ? AND work_date = ? AND time_in IS NOT NULL AND time_out IS NULL');
        $update->execute([$userId, $today]);
        $update->rowCount() > 0
            ? mentora_flash('Timed out at ' . date('g:i A') . '. Don’t forget your daily report.')
            : mentora_flash('Time in first. You can time out once per day.', 'error');
    } elseif ($action === 'submit_report') {
        $date = (string) ($_POST['report_date'] ?? '');
        $hours = round((float) ($_POST['hours'] ?? 0) * 2) / 2;
        $summary = trim((string) ($_POST['summary'] ?? ''));
        $notes = trim((string) ($_POST['notes'] ?? ''));
        $validDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $error = match (true) {
            !$validDate || $validDate->format('Y-m-d') !== $date => 'Please choose the report date.',
            $date > $today => 'You can’t report a future date.',
            $date < date('Y-m-d', strtotime('-30 days')) => 'Reports can be sent for the last 30 days only.',
            $hours < 0.5 || $hours > 16 => 'Hours worked must be between 0.5 and 16.',
            $summary === '' => 'Please describe the work you completed.',
            mb_strlen($summary) > 4000 || mb_strlen($notes) > 2000 => 'Your report is too long. Please shorten it.',
            default => null,
        };
        if ($error === null) {
            $existing = $pdo->prepare('SELECT id, status FROM work_reports WHERE user_id = ? AND report_date = ? ORDER BY id DESC LIMIT 1');
            $existing->execute([$userId, $date]);
            $report = $existing->fetch();
            if ($report && $report['status'] !== 'returned') {
                $error = 'You already sent a report for ' . date('d M Y', strtotime($date)) . '.';
            } elseif ($report) {
                $pdo->prepare("UPDATE work_reports SET hours = ?, summary = ?, notes = ?, status = 'submitted', reviewer_id = NULL, review_note = NULL, reviewed_at = NULL, created_at = NOW() WHERE id = ?")
                    ->execute([$hours, $summary, $notes !== '' ? $notes : null, $report['id']]);
            } else {
                $pdo->prepare('INSERT INTO work_reports (user_id, report_date, hours, summary, notes) VALUES (?, ?, ?, ?, ?)')
                    ->execute([$userId, $date, $hours, $summary, $notes !== '' ? $notes : null]);
            }
        }
        if ($error !== null) {
            mentora_flash($error, 'error');
            $_SESSION['report_draft'] = ['report_date' => $date, 'hours' => $hours, 'summary' => $summary, 'notes' => $notes];
        } else {
            $person = mentora_person($pdo, $userId);
            mentora_notify_reviewers(
                $pdo,
                $person,
                'Report to review: ' . $me['name'],
                mentora_hours($hours) . ' hrs on ' . date('d M Y', strtotime($date)) . ' · ' . mb_substr($summary, 0, 120)
            );
            mentora_flash('Report sent. You’ll be notified when it’s reviewed.');
        }
    }
    mentora_redirect($self);
}

$pageTitle = $folder === 'intern' ? 'Task reporting' : 'Employee reporting';
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
    $query = $pdo->prepare('SELECT r.*, CONCAT(v.first_name, " ", v.last_name) AS reviewer_name FROM work_reports r LEFT JOIN users v ON v.id = r.reviewer_id WHERE r.user_id = ? ORDER BY r.report_date DESC, r.id DESC');
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
    $draft = ['report_date' => $fix['report_date'], 'hours' => $fix['hours'], 'summary' => $fix['summary'], 'notes' => $fix['notes']];
}
$approver = $person && !empty($person['agent_name'])
    ? $person['agent_name']
    : ($isIntern ? 'Your supervising agent' : 'HR (Mentora people team)');
$statusBadge = ['submitted' => ['info', 'In review'], 'approved' => ['', 'Approved'], 'returned' => ['warn', 'Returned']];
?>
<div class="admin-heading"><div><h1><?= $isIntern ? 'Task reporting' : 'Employee reporting' ?></h1><p><?= $isIntern ? 'Track your assigned work, submit daily activity and keep your internship hours up to date.' : 'Record your daily work, share outcomes, and flag blockers for your manager.' ?></p></div><span class="badge info"><?= $isIntern ? 'Internship pathway' : 'Daily work report' ?></span></div>
<?= mentora_flash_html() ?>
<?php if ($returned): ?><div class="alert">A report for <?= mentora_h(date('d M Y', strtotime($returned[0]['report_date']))) ?> was returned<?= $returned[0]['review_note'] ? ': “' . mentora_h($returned[0]['review_note']) . '”' : '' ?>. <a href="reporting.php?fix=1#report-form">Fix and resend it</a></div><?php endif; ?>
<?php if (!$signedIn): ?><div class="notice">You’re viewing a preview. <a href="../../auth/signin.php">Sign in</a> to time in and send reports.</div><?php endif; ?>
<div class="admin-grid">
    <section class="pathway-card applicant-form" id="report-form">
        <h3><?= $isIntern ? 'Daily activity report' : 'Daily work report' ?></h3>
        <form method="post">
            <?= mentora_csrf_field() ?><input type="hidden" name="action" value="submit_report">
            <div class="form-grid">
                <div class="field"><label for="report_date">Report date</label><input id="report_date" type="date" name="report_date" required max="<?= date('Y-m-d') ?>" min="<?= date('Y-m-d', strtotime('-30 days')) ?>" value="<?= mentora_h($draft['report_date'] ?? date('Y-m-d')) ?>"></div>
                <div class="field"><label for="hours">Hours worked</label><input id="hours" type="number" name="hours" required step=".5" min=".5" max="16" value="<?= mentora_h(mentora_hours($draft['hours'] ?? ($isIntern ? 4 : 8))) ?>"></div>
                <div class="field full"><label for="summary"><?= $isIntern ? 'Activity / work completed *' : 'Work completed *' ?></label><textarea id="summary" name="summary" rows="4" required maxlength="4000" placeholder="<?= $isIntern ? 'Describe what you completed today' : 'Summarize your completed work' ?>"><?= mentora_h($draft['summary'] ?? '') ?></textarea></div>
                <div class="field full"><label for="notes"><?= $isIntern ? 'Outcomes, learnings or blockers' : 'Blockers / support needed' ?></label><textarea id="notes" name="notes" rows="3" maxlength="2000" placeholder="Optional"><?= mentora_h($draft['notes'] ?? '') ?></textarea></div>
            </div>
            <button class="admin-btn primary" type="submit"<?= $signedIn ? '' : ' disabled' ?> data-confirm-title="Submit this report?" data-confirm="Your report and hours will be sent to <?= mentora_h($approver) ?> for approval.">Submit report</button>
        </form>
    </section>
    <section class="pathway-card">
        <?php if ($isIntern): ?>
            <h2 class="kpi-large"><?= mentora_hours($totals['approved']) ?> / <?= $requiredHours ?> hrs</h2>
            <p>Approved internship hours</p>
            <div class="bar"><i style="width:<?= min(100, round($totals['approved'] / $requiredHours * 100, 1)) ?>%"></i></div>
        <?php else: ?>
            <h2 class="kpi-large"><?= mentora_hours($totals['week']) ?> hrs</h2>
            <p>Approved this week</p>
        <?php endif; ?>
        <div class="notice-row"><span>This week</span><strong><?= mentora_hours($totals['week']) ?> approved hrs</strong></div>
        <div class="notice-row"><span>In review</span><strong><?= mentora_hours($totals['review']) ?> hours</strong></div>
        <h3>Today’s attendance</h3>
        <div class="notice-row"><span>Time in</span><strong><?= !empty($attendance['time_in']) ? date('g:i A', strtotime($attendance['time_in'])) : '—' ?></strong></div>
        <div class="notice-row"><span>Time out</span><strong><?= !empty($attendance['time_out']) ? date('g:i A', strtotime($attendance['time_out'])) : '—' ?></strong></div>
        <div class="notice-row"><span>Status</span><span class="badge <?= $attendanceBadge ?>"><?= mentora_h($attendanceLabel) ?></span></div>
        <form method="post" class="attendance-actions">
            <?= mentora_csrf_field() ?>
            <?php if (empty($attendance['time_in'])): ?>
                <button class="admin-btn primary" type="submit" name="action" value="time_in"<?= $signedIn ? '' : ' disabled' ?>>Time in</button>
            <?php elseif (empty($attendance['time_out'])): ?>
                <button class="admin-btn" type="submit" name="action" value="time_out" data-confirm-title="Time out now?" data-confirm="Your time out is recorded once per day.">Time out</button>
            <?php else: ?>
                <span class="badge">Done for today</span>
            <?php endif; ?>
        </form>
        <h3>Your report approver</h3>
        <p><?= mentora_h($approver) ?> reviews your reports<?= $isIntern ? ' and approves your hours' : '' ?>.</p>
    </section>
</div>
<section class="pathway-card data-panel">
    <h3>Recent reports</h3>
    <?php if (!$reports): ?>
        <p>No reports submitted yet. Your submitted reports will appear here with their review status.</p>
    <?php else: ?>
        <div class="table-toolbar" data-table-filter="my-reports"><input type="search" data-filter="search" placeholder="Search reports" aria-label="Search reports"><select data-filter="status" aria-label="Status"><option value="all">All statuses</option><option value="submitted">In review</option><option value="approved">Approved</option><option value="returned">Returned</option></select></div>
        <table class="admin-table" id="my-reports"><thead><tr><th>Date</th><th>Hours</th><th>Work completed</th><th>Status</th><th>Review</th></tr></thead><tbody>
            <?php foreach ($reports as $report): [$badgeClass, $badgeText] = $statusBadge[$report['status']]; ?>
                <tr data-status="<?= $report['status'] ?>">
                    <td><?= date('d M Y', strtotime($report['report_date'])) ?></td>
                    <td><?= mentora_hours($report['hours']) ?></td>
                    <td><?= mentora_h(mb_strimwidth($report['summary'], 0, 140, '…')) ?></td>
                    <td><span class="badge <?= $badgeClass ?>"><?= $badgeText ?></span></td>
                    <td><?= $report['reviewed_at'] ? mentora_h(($report['reviewer_name'] ?: 'Reviewer') . ($report['review_note'] ? ': ' . $report['review_note'] : '')) : '—' ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody></table>
        <div class="table-note">Showing <span data-filter-count="my-reports"><?= count($reports) ?></span> report(s).</div>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/../applicant_footer.php'; ?>
