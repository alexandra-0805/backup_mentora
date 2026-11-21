<?php

/**
 * Employability reports (agents): after handling interns through training, the agent reports on them
 * and ticks the ones they recommend for employment. The AI summarizes the report and checks each
 * recommendation against the intern's AI assessment (ai/agent_report_summary.php); HR sees it in
 * admin/intern_reports.php and is notified.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/intern_reports.php';

if (mentora_is_post()) {
    $me = mentora_post_user(['agent', 'admin'], 'agent/agent_reports.php');
    $action = (string) ($_POST['action'] ?? 'create');
    if ($action === 'resummarize') {
        $id = (int) ($_POST['id'] ?? 0);
        $owns = $pdo->prepare('SELECT COUNT(*) FROM agent_reports WHERE id = ? AND (agent_id = ? OR ? = 1)');
        $owns->execute([$id, $me['id'], $me['role'] === 'admin' ? 1 : 0]);
        if ((int) $owns->fetchColumn() === 1) {
            mentora_ai_summarize_agent_report($pdo, $id, false);
            mentora_flash('The AI summary was updated with the latest assessments.');
        }
        mentora_redirect('agent/agent_reports.php#rep' . $id);
    }
    $title = mb_substr(trim((string) ($_POST['title'] ?? '')), 0, 190);
    $body = mb_substr(trim((string) ($_POST['body'] ?? '')), 0, 10000);
    $internIds = array_map('intval', array_column(mentora_workspace_people($pdo), 'id'));
    $recommended = array_values(array_intersect($internIds, array_map('intval', (array) ($_POST['recommended'] ?? []))));
    if ($title === '' || mb_strlen($body) < 40) {
        mentora_flash('Please add a title and a report of at least 40 characters about your interns’ performance.', 'error');
        $_SESSION['agent_report_draft'] = ['title' => $title, 'body' => $body, 'recommended' => $recommended];
        mentora_redirect('agent/agent_reports.php#new');
    }
    $pdo->prepare('INSERT INTO agent_reports (agent_id, title, body, recommended) VALUES (?, ?, ?, ?)')
        ->execute([$me['id'], $title, $body, mentora_ai_encode($recommended)]);
    $id = (int) $pdo->lastInsertId();
    try {
        mentora_ai_summarize_agent_report($pdo, $id);
    } catch (Throwable $exception) {
        error_log('Mentora agent report summary failed: ' . $exception->getMessage());
    }
    mentora_flash('Report sent to HR with its AI summary.');
    mentora_redirect('agent/agent_reports.php#rep' . $id);
}

$pageTitle = 'Employability reports';
$activePage = 'agent_reports.php';
require __DIR__ . '/../includes/agent_layout.php';

$me = $_SESSION['user'];
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
        <h1>Employability reports</h1>
        <p>Report on the interns you handle and recommend the ones ready to employ. The AI summarizes it for HR.</p>
    </div>
    <div class="admin-actions"><a class="admin-btn" href="intern_assessments.php">View AI ranking</a></div>
</div>
<?= mentora_flash_html() ?>
<section class="admin-panel" id="new">
    <h2>New report</h2>
    <p class="panel-subtitle">Tick the interns you recommend for employment and describe their performance: work quality, reliability, communication, growth.</p>
    <form method="post">
        <?= mentora_csrf_field() ?><input type="hidden" name="action" value="create">
        <div class="form-grid">
            <div class="field full"><label for="title">Title *</label><input id="title" name="title" required maxlength="190" placeholder="e.g. November cohort: employment recommendations" value="<?= mentora_h($draft['title']) ?>"></div>
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
        <div class="form-actions"><button class="admin-btn primary" type="submit" data-confirm-title="Send this report to HR?" data-confirm="The AI summarizes it and HR is notified.">Send report</button></div>
    </form>
</section>
<h2 class="report-section-title">Sent reports</h2>
<?php if (!$reports): ?><section class="admin-panel"><p class="panel-subtitle">No reports yet.</p></section><?php endif; ?>
<?php foreach ($reports as $report): ?>
    <?php mentora_render_agent_report($report, false); ?>
<?php endforeach; ?>
<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
