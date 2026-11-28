<?php

/**
 * Reports (agents; HR can open it too), two tabs:
 *   End-of-day reports  ?tab=eod (default): the agent's own end-of-day report, one per day (eod_updates; format in
 *                       includes/eod_report.php), and their reports with a total after every 5 days.
 *   Intern reports      ?tab=intern (links ending #new / #rep… open it): employability reports about their interns,
 *                       summarized by the AI for HR (admin/intern_reports.php), unchanged.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/intern_reports.php';
require_once __DIR__ . '/../includes/eod_report.php';

if (mentora_is_post()) {
    $me = mentora_post_user(['agent', 'admin'], 'agent/agent_reports.php');
    $action = (string) ($_POST['action'] ?? 'create');
    if ($action === 'save_eod') {
        // Your own end-of-day report: one per day; sending again for the same day replaces it.
        [$eod, $error] = mentora_eod_from_post($_POST);
        if ($error !== null) {
            mentora_flash($error, 'error');
            $_SESSION['agent_eod_draft'] = $eod;
        } else {
            $pdo->prepare('INSERT INTO eod_updates (user_id, work_date, day_name, time_in, time_out, hours_worked, completed_work, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE day_name = VALUES(day_name), time_in = VALUES(time_in), time_out = VALUES(time_out),
                    hours_worked = VALUES(hours_worked), completed_work = VALUES(completed_work), notes = VALUES(notes)')
                ->execute([(int) $me['id'], $eod['date'], $eod['day'], $eod['time_in'], $eod['time_out'], $eod['hours'], $eod['tasks'], $eod['notes']]);
            mentora_flash('Your end-of-day report for ' . mentora_eod_date($eod['date']) . ' was saved.');
        }
        mentora_redirect('agent/agent_reports.php?tab=eod');
    }
    if ($action === 'resummarize') {
        $id = (int) ($_POST['id'] ?? 0);
        $owns = $pdo->prepare('SELECT COUNT(*) FROM agent_reports WHERE id = ? AND (agent_id = ? OR ? = 1)');
        $owns->execute([$id, $me['id'], $me['role'] === 'admin' ? 1 : 0]);
        if ((int) $owns->fetchColumn() === 1) {
            mentora_ai_summarize_agent_report($pdo, $id, false);
            mentora_flash('The summary was updated with the latest assessments.');
        }
        mentora_redirect('agent/agent_reports.php?tab=intern#rep' . $id);
    }
    $title = mb_substr(trim((string) ($_POST['title'] ?? '')), 0, 190);
    $body = mb_substr(trim((string) ($_POST['body'] ?? '')), 0, 10000);
    $internIds = array_map('intval', array_column(mentora_workspace_people($pdo), 'id'));
    $recommended = array_values(array_intersect($internIds, array_map('intval', (array) ($_POST['recommended'] ?? []))));
    if ($title === '' || mb_strlen($body) < 40) {
        mentora_flash('Please add a title and a report of at least 40 characters about your interns’ performance.', 'error');
        $_SESSION['agent_report_draft'] = ['title' => $title, 'body' => $body, 'recommended' => $recommended];
        mentora_redirect('agent/agent_reports.php?tab=intern#new');
    }
    $pdo->prepare('INSERT INTO agent_reports (agent_id, title, body, recommended) VALUES (?, ?, ?, ?)')
        ->execute([$me['id'], $title, $body, mentora_ai_encode($recommended)]);
    $id = (int) $pdo->lastInsertId();
    try {
        mentora_ai_summarize_agent_report($pdo, $id);
    } catch (Throwable $exception) {
        error_log('Mentora agent report summary failed: ' . $exception->getMessage());
    }
    mentora_flash('Report sent to HR with its summary.');
    mentora_redirect('agent/agent_reports.php?tab=intern#rep' . $id);
}

// ?format=pdf: download your end-of-day reports (with the 5-day totals).
if (($_GET['format'] ?? '') === 'pdf') {
    $me = mentora_require_login(['agent', 'admin']);
    $query = $pdo->prepare('SELECT id, work_date AS report_date, day_name, time_in, time_out, hours_worked AS hours, completed_work AS tasks, notes
        FROM eod_updates WHERE user_id = ? ORDER BY work_date');
    $query->execute([(int) $me['id']]);
    mentora_eod_pdf('End-of-day reports', (string) $me['name'], $query->fetchAll());
}

$pageTitle = 'Reports';
$activePage = 'agent_reports.php';
require __DIR__ . '/../includes/agent_layout.php';

$me = $_SESSION['user'];
$tab = ($_GET['tab'] ?? '') === 'intern' ? 'intern' : 'eod';

// End-of-day reports tab: your own reports, and the form (today, or a day you choose to correct).
$eodDraft = $_SESSION['agent_eod_draft'] ?? null;
unset($_SESSION['agent_eod_draft']);
$myEod = $pdo->prepare('SELECT id, work_date AS report_date, day_name, time_in, time_out, hours_worked AS hours,
        completed_work AS tasks, notes, updated_at FROM eod_updates WHERE user_id = ? ORDER BY work_date');
$myEod->execute([(int) $me['id']]);
$myEodReports = $myEod->fetchAll();
if ($eodDraft === null) {
    $todays = array_values(array_filter($myEodReports, static fn(array $r): bool => $r['report_date'] === date('Y-m-d')))[0] ?? null;
    $attendance = $pdo->prepare('SELECT time_in, time_out FROM attendance WHERE user_id = ? AND work_date = CURDATE()');
    $attendance->execute([(int) $me['id']]);
    $today = $attendance->fetch() ?: [];
    $eodDraft = $todays
        ? ['date' => $todays['report_date'], 'day' => $todays['day_name'], 'time_in' => $todays['time_in'], 'time_out' => $todays['time_out'],
            'hours' => $todays['hours'], 'tasks' => $todays['tasks'], 'notes' => $todays['notes']]
        : ['date' => date('Y-m-d'),
            'time_in' => !empty($today['time_in']) ? date('H:i', strtotime($today['time_in'])) : '',
            'time_out' => !empty($today['time_out']) ? date('H:i', strtotime($today['time_out'])) : ''];
}
$sentToday = (bool) array_filter($myEodReports, static fn(array $r): bool => $r['report_date'] === date('Y-m-d'));
$draft = $_SESSION['agent_report_draft'] ?? ['title' => '', 'body' => '', 'recommended' => []];
unset($_SESSION['agent_report_draft']);
$mine = mentora_intern_rankings($pdo, (int) $me['id']);
$interns = $mine ?: mentora_intern_rankings($pdo);
$reports = $pdo->prepare(
    "SELECT r.*, CONCAT(v.first_name, ' ', v.last_name) AS reviewer_name, CONCAT(a.first_name, ' ', a.last_name) AS agent_name
     FROM agent_reports r LEFT JOIN users v ON v.id = r.reviewed_by LEFT JOIN users a ON a.id = r.agent_id
     WHERE r.agent_id = ? OR ? = 1 ORDER BY r.created_at DESC"
);
$reports->execute([$me['id'], $me['role'] === 'admin' ? 1 : 0]);
$reports = $reports->fetchAll();
?>
<div class="admin-heading">
    <div>
        <h1>Reports</h1>
        <p>Your end-of-day reports, and your reports to HR about the interns you handle.</p>
    </div>
    <div class="admin-actions"><a class="admin-btn" href="intern_assessments.php">View ranking</a></div>
</div>
<?= mentora_flash_html() ?>
<nav class="report-tabs" role="tablist" aria-label="Reports">
    <a href="?tab=eod" role="tab" data-report-tab="eod" aria-selected="<?= $tab === 'eod' ? 'true' : 'false' ?>">End-of-day reports</a>
    <a href="?tab=intern" role="tab" data-report-tab="intern" aria-selected="<?= $tab === 'intern' ? 'true' : 'false' ?>">Intern reports</a>
</nav>

<div class="report-tab-panel" data-report-panel="eod" role="tabpanel"<?= $tab === 'eod' ? '' : ' hidden' ?>>
    <section class="admin-panel" id="eod-form">
        <h2><?= $sentToday ? 'Today’s end-of-day report (sent — you can still change it)' : 'End-of-day report' ?></h2>
        <p class="panel-subtitle">One report per day. Choosing a day you already sent and saving replaces that day’s report.</p>
        <form method="post">
            <?= mentora_csrf_field() ?><input type="hidden" name="action" value="save_eod">
            <?= mentora_eod_fields($eodDraft) ?>
            <div class="form-actions"><button class="admin-btn primary" type="submit">Save end-of-day report</button></div>
        </form>
    </section>
    <section class="admin-panel data-panel">
        <div class="eod-table-head">
            <div>
                <h2 class="report-section-title">Your end-of-day reports</h2>
                <p class="panel-subtitle">Oldest first, with your total days and hours worked after every 5 days.</p>
            </div>
            <?php if ($myEodReports): ?><a class="admin-btn" href="agent_reports.php?format=pdf"><?= mentora_icon('download') ?>Download PDF</a><?php endif; ?>
        </div>
        <?php mentora_eod_table($myEodReports, 'agent-eod-table', [], 'No end-of-day reports yet. Your first one will show here.'); ?>
    </section>
</div>

<div class="report-tab-panel" data-report-panel="intern" role="tabpanel"<?= $tab === 'intern' ? '' : ' hidden' ?>>
<section class="admin-panel" id="new">
    <h2>New report</h2>
    <p class="panel-subtitle">Tick the interns you recommend for employment and describe their performance: work quality, reliability, communication, growth.</p>
    <form method="post">
        <?= mentora_csrf_field() ?><input type="hidden" name="action" value="create">
        <div class="form-grid">
            <div class="field full"><label for="title">Title *</label><input id="title" name="title" required maxlength="190" value="<?= mentora_h($draft['title']) ?>"></div>
            <div class="field full">
                <label>Recommend for employment<?= $mine ? '' : ' (no interns are assigned to you yet; showing all)' ?></label>
                <?php if (!$interns): ?><small class="panel-subtitle">No active interns yet.</small><?php endif; ?>
                <div class="recommend-list">
                    <?php foreach ($interns as $intern): $ai = $intern['ai']; ?>
                        <label class="check-row">
                            <input type="checkbox" name="recommended[]" value="<?= (int) $intern['id'] ?>"<?= in_array((int) $intern['id'], $draft['recommended'], true) ? ' checked' : '' ?>>
                            <span><?= mentora_h($intern['name']) ?>
                                <small>· <?= $ai ? mentora_h($ai['tier']) . ' ' . (int) $ai['score'] . '/100' . ((int) $ai['ready'] === 1 ? ' · ready' : '') : 'not assessed · training ' . $intern['percent'] . '%' ?></small></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="field full"><label for="body">Report *</label><textarea id="body" name="body" rows="7" required minlength="40" maxlength="10000" placeholder="How each intern performed during and after training, who is ready to be employed and why, and any concerns."><?= mentora_h($draft['body']) ?></textarea></div>
        </div>
        <div class="form-actions"><button class="admin-btn primary" type="submit" data-confirm-title="Send this report to HR?" data-confirm="It is summarized automatically and HR is notified.">Send report</button></div>
    </form>
</section>
<h2 class="report-section-title">Sent reports</h2>
<?php if (!$reports): ?><section class="admin-panel"><p class="panel-subtitle">No reports yet.</p></section><?php endif; ?>
<?php foreach ($reports as $report): ?>
    <?php mentora_render_agent_report($report, false); ?>
<?php endforeach; ?>
</div>
<script>
    // Links to an intern report (…#new, …#rep12) open the Intern reports tab; the tabs switch without reloading.
    (() => {
        const show = (name) => {
            document.querySelectorAll('[data-report-panel]').forEach((panel) => { panel.hidden = panel.dataset.reportPanel !== name; });
            document.querySelectorAll('[data-report-tab]').forEach((tab) => tab.setAttribute('aria-selected', String(tab.dataset.reportTab === name)));
        };
        if (/^#(new|rep\d+)$/.test(location.hash)) {
            show('intern');
            document.querySelector(location.hash)?.scrollIntoView();
        }
        document.querySelectorAll('[data-report-tab]').forEach((tab) => tab.addEventListener('click', (event) => {
            event.preventDefault();
            show(tab.dataset.reportTab);
            history.replaceState(null, '', '?tab=' + tab.dataset.reportTab);
        }));
    })();
</script>
<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
