<?php

/**
 * Agent reporting (agents and HR): approve or return interns' daily reports (and reports from anyone
 * assigned to you), with cohort training, hours and evaluation results.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/workflow.php';

if (mentora_is_post()) {
    $me = mentora_post_user(['agent', 'admin'], 'agent/reporting_overview.php');
    if (($_POST['action'] ?? '') === 'review_report') {
        [$ok, $message] = mentora_review_report(
            $pdo,
            (int) ($_POST['report_id'] ?? 0),
            $me,
            (string) ($_POST['decision'] ?? ''),
            (string) ($_POST['note'] ?? '')
        );
        mentora_flash($message, $ok ? 'success' : 'error');
    }
    mentora_redirect('agent/reporting_overview.php#review');
}

$pageTitle = 'Agent reporting & evaluation';
$activePage = 'reporting_overview.php';
require __DIR__ . '/../includes/agent_layout.php';

$meId = (int) ($_SESSION['user']['id'] ?? 0);
$interns = mentora_workspace_people($pdo);
$waiting = mentora_reports_waiting($pdo, 'internship', $meId);
$completed = array_sum(array_column($interns, 'completed'));
$total = array_sum(array_column($interns, 'total'));
$hours = array_sum(array_map('floatval', array_column($interns, 'hours_logged')));
$average = $pdo->query("SELECT AVG(e.score)
    FROM evaluations e
    JOIN users u ON u.id = e.user_id
    WHERE e.status = 'published' AND u.pathway = 'internship' AND u.status = 'active'")
    ->fetchColumn();
$recent = $pdo->query(
    "SELECT r.report_date, r.hours, r.status, r.review_note, r.reviewed_at, u.first_name, u.last_name, CONCAT(v.first_name, ' ', v.last_name) AS reviewer
     FROM work_reports r JOIN users u ON u.id = r.user_id LEFT JOIN users v ON v.id = r.reviewer_id
     WHERE r.status <> 'submitted' AND u.pathway = 'internship' ORDER BY r.reviewed_at DESC LIMIT 8"
)->fetchAll();
// Training completion per team.
$teams = [];
foreach ($interns as $intern) {
    $team = $intern['department_name'] ?? 'Not assigned';
    $teams[$team] ??= ['done' => 0, 'total' => 0, 'people' => 0];
    $teams[$team]['done'] += min($intern['completed'], $intern['total']);
    $teams[$team]['total'] += $intern['total'];
    $teams[$team]['people']++;
}
ksort($teams);
?>
<div class="admin-heading">
    <div>
        <h1>Agent reporting & evaluation</h1>
        <p>Review intern reports and connect onboarding progress with evaluation readiness.</p>
    </div>
    <div class="admin-actions">
        <button class="admin-btn"
            type="button"
            onclick="window.print()">Print</button><a class="admin-btn primary" href="export.php?type=reports"><?= mentora_icon('download') ?>Export PDF</a>
    </div>
</div>
<?= mentora_flash_html() ?>
<div class="metric-grid">
    <div class="metric-card">
        <small>Interns in cohort</small><strong><?= count($interns) ?></strong><span>Active interns</span>
    </div>
    <div class="metric-card">
        <small>Training completion</small><strong><?= $total ? round($completed / $total * 100) : 0 ?>%</strong><span>Based on recorded modules</span>
    </div>
    <div class="metric-card">
        <small>Internship hours</small><strong><?= mentora_hours($hours) ?></strong><span>All interns, tracked automatically</span>
    </div>
    <div class="metric-card">
        <small>Average evaluation score</small><strong><?= $average !== null && $average !== false ? mentora_hours($average) . ' / 5' : '—' ?></strong><span><?= $average ? 'Published evaluations' : 'No published scores yet' ?></span>
    </div>
</div>
<section class="admin-panel data-panel" id="review">
    <h2>Reports to review <span class="badge <?= $waiting ? 'warn' : '' ?>"><?= count($waiting) ?></span></h2>
    <p class="panel-subtitle">Approve to add the hours to the intern’s record, or return with a note so they can fix it.</p>
    <?php mentora_render_review_table($waiting, 'agent-review', 'All caught up. New reports from interns will appear here.'); ?>
</section>
<div class="admin-grid" style="margin-top:16px">
    <section class="admin-panel">
        <h2>Recent decisions</h2>
        <?php if (!$recent): ?><p class="panel-subtitle">No reports reviewed yet.</p><?php endif; ?>
        <?php foreach ($recent as $row): ?>
            <div class="list-row">
                <span><?= mentora_h($row['first_name'] . ' ' . $row['last_name']) ?> · <?= date('d M', strtotime($row['report_date'])) ?> · <?= mentora_hours($row['hours']) ?> hrs<br><small><?= mentora_h(($row['reviewer'] ?? '') . ($row['review_note'] ? ': ' . $row['review_note'] : '')) ?></small></span><span class="badge <?= $row['status'] === 'approved' ? '' : 'warn' ?>"><?= $row['status'] === 'approved' ? 'Approved' : 'Returned' ?></span>
            </div>
        <?php endforeach; ?>
    </section>
    <section class="admin-panel">
        <h2>Training progress by team</h2>
        <p class="panel-subtitle">Module completion per team</p>
        <?php if (!$teams): ?><p class="panel-subtitle">No interns yet.</p><?php endif; ?>
        <?php foreach ($teams as $team => $data): $percent = $data['total'] ? round($data['done'] / $data['total'] * 100) : 0; ?>
            <div class="bar-row">
                <span><?= mentora_h($team) ?></span>
                <div class="bar"><i style="width:<?= $percent ?>%"></i>
                </div>
                <small><?= $percent ?>% · <?= $data['people'] ?></small>
            </div>
        <?php endforeach; ?>
    </section>
</div>
<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
