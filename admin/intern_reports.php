<?php

/**
 * Intern reports (HR): agents' employability reports with their AI summaries, and the interns that
 * can be employed now (recommended by their agent and/or ready by their AI assessment).
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/intern_reports.php';

if (mentora_is_post()) {
    $admin = mentora_post_user(['admin'], 'admin/intern_reports.php');
    $id = (int) ($_POST['id'] ?? 0);
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'resummarize') {
        mentora_ai_summarize_agent_report($pdo, $id, false);
        mentora_flash('The AI summary was updated with the latest assessments.');
    } elseif ($action === 'review') {
        $note = mb_substr(trim((string) ($_POST['note'] ?? '')), 0, 500);
        $update = $pdo->prepare("UPDATE agent_reports SET status = 'reviewed', reviewed_by = ?, review_note = ?, reviewed_at = NOW() WHERE id = ? AND status = 'submitted'");
        $update->execute([$admin['id'], $note !== '' ? $note : null, $id]);
        if ($update->rowCount()) {
            $report = $pdo->prepare('SELECT agent_id, title FROM agent_reports WHERE id = ?');
            $report->execute([$id]);
            $report = $report->fetch();
            if ($report && $report['agent_id']) {
                mentora_notify($pdo, (int) $report['agent_id'], 'HR reviewed your report: ' . mb_substr($report['title'], 0, 120), $note !== '' ? $admin['name'] . ': “' . $note . '”' : 'Reviewed by ' . $admin['name'] . '.', 'agent/agent_reports.php#rep' . $id);
            }
            mentora_flash('Report marked as reviewed. The agent was notified.');
        }
    }
    mentora_redirect('admin/intern_reports.php#rep' . $id);
}

$pageTitle = 'Intern reports';
$activePage = 'intern_reports.php';
require __DIR__ . '/../includes/admin_layout.php';

$reports = mentora_agent_reports($pdo);
$waiting = count(array_filter($reports, static fn($r) => $r['status'] === 'submitted'));
$employable = mentora_employable_interns($pdo);
$confirmed = count(array_filter($employable, static fn($r) => $r['verdict'] === 'employable'));
$rankings = mentora_intern_rankings($pdo);
$assessed = count(array_filter($rankings, static fn($r) => $r['ai'] !== null));
$verdicts = MENTORA_REPORT_VERDICTS + ['ready_only' => ['Ready · no agent report yet', 'info']];
?>
<div class="admin-heading">
    <div>
        <h1>Intern reports</h1>
        <p>Agents’ reports on the interns they handle, summarized by AI, and the interns you can employ now.</p>
    </div>
    <div class="admin-actions">
        <a class="admin-btn" href="../agent/export.php?type=assessments"><?= mentora_icon('download') ?>Export AI ranking</a>
        <button class="admin-btn" type="button" onclick="window.print()">Print / save as PDF</button>
    </div>
</div>
<?= mentora_flash_html() ?>
<div class="metric-grid">
    <div class="metric-card"><small>Reports waiting</small><strong><?= $waiting ?></strong><span>Of <?= count($reports) ?> from agents</span></div>
    <div class="metric-card"><small>Employable</small><strong><?= $confirmed ?></strong><span>Agent recommends · AI ready</span></div>
    <div class="metric-card"><small>To check</small><strong><?= count($employable) - $confirmed ?></strong><span>Only one of the two agrees</span></div>
    <div class="metric-card"><small>Interns assessed</small><strong><?= $assessed ?></strong><span>Of <?= count($rankings) ?> active interns</span></div>
</div>
<section class="admin-panel data-panel">
    <h2>Interns you can employ</h2>
    <p class="panel-subtitle">Recommended by their agent and/or ready by their AI assessment (training done, score ≥ <?= MENTORA_READY_SCORE ?>).</p>
    <table class="admin-table">
        <thead><tr><th>Intern</th><th>Team / agent</th><th>AI assessment</th><th>Recommended by</th><th>Verdict</th><th>Actions</th></tr></thead>
        <tbody>
        <?php if (!$employable): ?><tr><td colspan="6" class="table-note">No intern is ready yet. They appear here after training and their agent’s report.</td></tr><?php endif; ?>
        <?php foreach ($employable as $intern): [$label, $class] = $verdicts[$intern['verdict']]; ?>
            <tr>
                <td><?= mentora_h($intern['name']) ?><br><small><?= mentora_h($intern['email']) ?></small></td>
                <td><?= mentora_h($intern['department_name'] ?? 'No team') ?><br><small><?= mentora_h($intern['agent_name'] ?? 'No agent') ?></small></td>
                <td><?= $intern['ai'] ? mentora_h($intern['ai']['tier']) . ' · ' . (int) $intern['ai']['score'] . '/100' : '—' ?><br><small>Training <?= $intern['percent'] ?>% · <?= mentora_hours($intern['hours_logged']) ?> hrs</small></td>
                <td><?= $intern['recommended_by'] ? mentora_h(implode(', ', $intern['recommended_by'])) : '—' ?></td>
                <td><span class="badge <?= $class ?>"><?= mentora_h($label) ?></span></td>
                <td class="row-actions"><a class="admin-btn" href="user_edit.php?id=<?= (int) $intern['id'] ?>">Profile</a><a class="admin-btn" href="../account/messages.php?to=<?= (int) $intern['id'] ?>">Message</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>
<h2 class="report-section-title">Agent reports</h2>
<?php if (!$reports): ?><section class="admin-panel"><p class="panel-subtitle">No agent has sent a report yet.</p></section><?php endif; ?>
<?php foreach ($reports as $report) { mentora_render_agent_report($report, true); } ?>
<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
