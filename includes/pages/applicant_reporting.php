<?php

/**
 * Applicant Reporting (applicant/intern/reporting.php, applicant/employee/reporting.php; $pathway set there).
 *   - Hours: automatic, while they click, type or scroll in Mentora (includes/intern_hours.php, account/activity.php);
 *     their first and last activity become the day's time in / time out (attendance, admin Monitoring)
 *   - Daily report: date, hours, work done, blockers       -> work_reports "submitted"
 *     the supervising agent (interns) or HR (employees) is notified, approves or returns it
 *     (agent Reporting overview / admin Monitoring). Hours are the automatically tracked ones (interns: internship
 *     hours; employees: work hours); approved reports are the work record.
 *   - A returned report can be corrected and sent again.
 */

declare(strict_types=1);

require_once __DIR__ . '/../workflow.php';

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
                $pdo->prepare("UPDATE work_reports
                    SET hours = ?, summary = ?, notes = ?, status = 'submitted', reviewer_id = NULL, review_note = NULL, reviewed_at = NULL, created_at = NOW()
                    WHERE id = ?")
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
    $query = $pdo->prepare('SELECT r.*, CONCAT(v.first_name, " ", v.last_name) AS reviewer_name
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
    $draft = ['report_date' => $fix['report_date'], 'hours' => $fix['hours'], 'summary' => $fix['summary'], 'notes' => $fix['notes']];
}
$approver = $person && !empty($person['agent_name'])
    ? $person['agent_name']
    : ($isIntern ? 'Your supervising agent' : 'HR (Mentora people team)');
$statusBadge = ['submitted' => ['info', 'In review'], 'approved' => ['', 'Approved'], 'returned' => ['warn', 'Returned']];
?>
<div class="admin-heading">
    <div>
        <h1><?= $isIntern ? 'Task reporting' : 'Employee reporting' ?></h1>
        <p><?= $isIntern
            ? 'Track your assigned work, submit daily activity and keep your internship hours up to date.'
            : 'Record your daily work, share outcomes, and flag blockers for your manager.' ?></p>
    </div>
    <?php if (!$isIntern): ?><span class="badge info">Daily work report</span><?php endif; ?>
</div>
<?= mentora_flash_html() ?>
<?php if ($returned): ?>
<div class="alert">A report for <?= mentora_h(date('d M Y', strtotime($returned[0]['report_date']))) ?> was returned<?= $returned[0]['review_note']
    ? ': “' . mentora_h($returned[0]['review_note']) . '”'
    : '' ?>. <a href="reporting.php?fix=1#report-form">Fix and resend it</a>
</div>
<?php endif; ?>
<?php if (!$signedIn): ?>
<div class="notice">You’re viewing a preview. <a href="../../auth/signin.php">Sign in</a> to time in and send reports.</div>
<?php endif; ?>
<div class="admin-grid">
    <section class="pathway-card applicant-form" id="report-form">
        <h3><?= $isIntern ? 'Daily activity report' : 'Daily work report' ?></h3>
        <form method="post">
            <?= mentora_csrf_field() ?><input type="hidden" name="action" value="submit_report">
            <div class="form-grid">
                <div class="field">
                    <label for="report_date">Report date</label><input id="report_date" type="date" name="report_date" required max="<?= date('Y-m-d') ?>" min="<?= date('Y-m-d', strtotime('-30 days')) ?>" value="<?= mentora_h($draft['report_date'] ?? date('Y-m-d')) ?>"></div>
                <div class="field">
                    <label for="hours">Hours worked</label><input id="hours"
                        type="number"
                        name="hours"
                        required
                        step=".5"
                        min=".5"
                        max="16"
                        value="<?= mentora_h(mentora_hours($draft['hours'] ?? ($isIntern ? 4 : 8))) ?>"></div>
                <div class="field full">
                    <label for="summary"><?= $isIntern ? 'Activity / work completed *' : 'Work completed *' ?></label><textarea id="summary" name="summary" rows="4" required maxlength="4000" placeholder="<?= $isIntern ? 'Describe what you completed today' : 'Summarize your completed work' ?>"><?= mentora_h($draft['summary'] ?? '') ?></textarea>
                </div>
                <div class="field full">
                    <label for="notes"><?= $isIntern ? 'Outcomes, learnings or blockers' : 'Blockers / support needed' ?></label><textarea id="notes" name="notes" rows="3" maxlength="2000" placeholder="Optional"><?= mentora_h($draft['notes'] ?? '') ?></textarea>
                </div>
            </div>
            <button class="admin-btn primary"
                type="submit"<?= $signedIn ? '' : ' disabled' ?>
                data-confirm-title="Submit this report?"
                data-confirm="Your report and hours will be sent to <?= mentora_h($approver) ?> for approval.">Submit report</button>
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
<section class="pathway-card data-panel">
    <h3>Recent reports</h3>
    <?php if (!$reports): ?>
        <p>No reports submitted yet. Your submitted reports will appear here with their review status.</p>
    <?php else: ?>
        <div class="table-toolbar" data-table-filter="my-reports">
            <input type="search" data-filter="search" placeholder="Search reports" aria-label="Search reports"><select data-filter="status" aria-label="Status">
                <option value="all">All statuses</option>
                <option value="submitted">In review</option>
                <option value="approved">Approved</option>
                <option value="returned">Returned</option>
            </select>
        </div>
        <table class="admin-table" id="my-reports">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Hours</th>
                    <th>Work completed</th>
                    <th>Status</th>
                    <th>Review</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($reports as $report): [$badgeClass, $badgeText] = $statusBadge[$report['status']]; ?>
                <tr data-status="<?= $report['status'] ?>">
                    <td><?= date('d M Y', strtotime($report['report_date'])) ?></td>
                    <td><?= mentora_hours($report['hours']) ?></td>
                    <td><?= mentora_h(mb_strimwidth($report['summary'], 0, 140, '…')) ?></td>
                    <td><span class="badge <?= $badgeClass ?>"><?= $badgeText ?></span></td>
                    <td><?= $report['reviewed_at']
                        ? mentora_h(($report['reviewer_name'] ?: 'Reviewer') . ($report['review_note'] ? ': ' . $report['review_note'] : ''))
                        : '—' ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody></table>
        <div class="table-note">Showing <span data-filter-count="my-reports"><?= count($reports) ?></span> report(s).</div>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/../applicant_footer.php'; ?>
