<?php

/**
 * AI assessments (agents and HR): interns ranked by their AI assessment after training.
 * An assessment runs automatically when an intern finishes their last module; agents can run it again
 * any time (e.g. after new reports or an evaluation). Ready interns go into the agent's
 * employability report (agent/agent_reports.php).
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/workflow.php';
require_once __DIR__ . '/../ai/bootstrap.php';

if (mentora_is_post()) {
    mentora_post_user(['agent', 'admin'], 'agent/intern_assessments.php');
    $scope = ($_POST['scope'] ?? '') === 'mine' ? '?scope=mine' : '';
    if (($_POST['action'] ?? '') === 'assess_all') {
        $count = 0;
        foreach (mentora_workspace_people($pdo) as $intern) {
            if ($intern['percent'] >= 100) {
                $count += mentora_ai_assess_intern($pdo, (int) $intern['id']) ? 1 : 0;
            }
        }
        mentora_flash($count ? 'Assessed ' . $count . ' intern(s) who finished training.' : 'No intern has finished training yet.');
        mentora_redirect('agent/intern_assessments.php' . $scope);
    }
    $id = (int) ($_POST['user_id'] ?? 0);
    $result = mentora_ai_assess_intern($pdo, $id);
    mentora_flash($result ? 'Assessment updated: ' . $result['tier'] . ' (' . $result['score'] . '/100).' : 'That intern couldn’t be assessed.', $result ? 'success' : 'error');
    mentora_redirect('agent/intern_assessments.php' . $scope . '#i' . $id);
}

$pageTitle = 'Assessments';
$activePage = 'dashboard.php'; // not in the sidebar; highlight the Dashboard it's opened from
require __DIR__ . '/../includes/agent_layout.php';

$meId = (int) $_SESSION['user']['id'];
$mineCount = count(mentora_workspace_people($pdo, 'internship', $meId));
$mine = ($_GET['scope'] ?? ($mineCount ? 'mine' : 'all')) === 'mine';
$rows = mentora_intern_rankings($pdo, $mine ? $meId : null);
$assessed = array_values(array_filter($rows, static fn($r) => $r['ai'] !== null));
$ready = count(array_filter($assessed, static fn($r) => (int) $r['ai']['ready'] === 1));
$average = $assessed ? round(array_sum(array_map(static fn($r) => (int) $r['ai']['score'], $assessed)) / count($assessed)) : null;
?>
<div class="admin-heading">
    <div>
        <h1>Assessments</h1>
        <p>After training, each intern is ranked from their quiz scores, hours, reports, evaluations and attendance. Ready interns go into your employability report.</p>
    </div>
    <div class="admin-actions">
        <a class="admin-btn" href="export.php?type=assessments"><?= mentora_icon('download') ?>Export ranking</a>
        <a class="admin-btn primary" href="agent_reports.php?tab=intern#new">Write employability report</a>
    </div>
</div>
<?= mentora_flash_html() ?>
<div class="metric-grid">
    <div class="metric-card"><small>Interns</small><strong><?= count($rows) ?></strong><span><?= $mine ? 'Assigned to you' : 'All active interns' ?></span></div>
    <div class="metric-card"><small>Assessed</small><strong><?= count($assessed) ?></strong><span>Finished training or re-run</span></div>
    <div class="metric-card"><small>Ready to employ</small><strong><?= $ready ?></strong><span>Training done · score ≥ <?= MENTORA_READY_SCORE ?></span></div>
    <div class="metric-card"><small>Average score</small><strong><?= $average !== null ? $average : '—' ?></strong><span>Out of 100</span></div>
</div>
<nav class="application-tabs" aria-label="Interns">
    <a href="?scope=mine"<?= $mine ? ' aria-current="page"' : '' ?>>My interns<b><?= $mineCount ?></b></a>
    <a href="?scope=all"<?= $mine ? '' : ' aria-current="page"' ?>>All interns</a>
</nav>
<section class="admin-panel data-panel">
    <div class="table-toolbar" data-table-filter="ranking-table">
        <input type="search" data-filter="search" placeholder="Search intern" aria-label="Search interns">
        <select data-filter="tier" aria-label="Tier">
            <option value="all">All tiers</option>
            <?php foreach (array_keys(MENTORA_ASSESSMENT_TIERS) as $tier): ?><option value="<?= mentora_h(mb_strtolower($tier)) ?>"><?= $tier ?></option><?php endforeach; ?>
            <option value="none">Not assessed</option>
        </select>
        <form method="post" class="toolbar-action"><?= mentora_csrf_field() ?><input type="hidden" name="action" value="assess_all"><input type="hidden" name="scope" value="<?= $mine ? 'mine' : 'all' ?>"><button class="admin-btn" type="submit">Re-assess everyone who finished</button></form>
    </div>
    <table class="admin-table" id="ranking-table">
        <thead><tr><th>Rank</th><th>Intern</th><th>Training</th><th>Quiz avg.</th><th>Hours</th><th>Assessment</th><th>Action</th></tr></thead>
        <tbody>
        <?php if (!$rows): ?><tr><td colspan="7" class="table-note">No active interns<?= $mine ? ' assigned to you' : '' ?> yet.</td></tr><?php endif; ?>
        <?php foreach ($rows as $row): $ai = $row['ai']; $m = $ai['metrics'] ?? []; ?>
            <tr id="i<?= (int) $row['id'] ?>" data-tier="<?= $ai ? mentora_h(mb_strtolower($ai['tier'])) : 'none' ?>">
                <td><?= $row['rank'] ? '#' . $row['rank'] : '—' ?></td>
                <td><a href="edit_intern.php?id=<?= (int) $row['id'] ?>"><?= mentora_h($row['name']) ?></a><br><small><?= mentora_h($row['department_name'] ?? 'No team yet') ?> · <?= mentora_h($row['agent_name'] ?? 'No agent') ?></small></td>
                <td><span class="progress"><i style="width:<?= $row['percent'] ?>%"></i></span> <?= $row['completed'] ?>/<?= $row['total'] ?></td>
                <td><?= isset($m['quiz_average']) && $m['quiz_average'] !== null ? mentora_hours($m['quiz_average']) . '%' : '—' ?></td>
                <td><?= mentora_hours($row['hours_logged']) ?> / <?= mentora_required_hours($row) ?></td>
                <td>
                    <?php if ($ai): ?>
                        <span class="badge <?= MENTORA_ASSESSMENT_TIERS[$ai['tier']][1] ?? '' ?>"><?= mentora_h($ai['tier']) ?> · <?= (int) $ai['score'] ?></span>
                        <?= (int) $ai['ready'] === 1 ? '<span class="badge">Ready to employ</span>' : '' ?>
                        <details class="assessment-details"><summary>Summary</summary>
                            <p><?= mentora_h($ai['summary']) ?></p>
                            <?php foreach (['strengths' => 'Strengths', 'focus' => 'Focus areas'] as $key => $label): if (!empty($m[$key])): ?>
                                <strong><?= $label ?></strong><ul class="screening-list"><?php foreach ($m[$key] as $item): ?><li><?= mentora_h($item) ?></li><?php endforeach; ?></ul>
                            <?php endif; endforeach; ?>
                            <small><?= mentora_h($ai['engine']) ?> · <?= date('d M Y, g:i A', strtotime($ai['assessed_at'])) ?></small>
                        </details>
                    <?php else: ?>
                        <small><?= $row['percent'] >= 100 ? 'Not assessed yet' : 'After training (' . $row['percent'] . '%)' ?></small>
                    <?php endif; ?>
                </td>
                <td><form method="post"><?= mentora_csrf_field() ?><input type="hidden" name="user_id" value="<?= (int) $row['id'] ?>"><input type="hidden" name="scope" value="<?= $mine ? 'mine' : 'all' ?>"><button class="admin-btn<?= $ai ? '' : ' primary' ?>" type="submit"><?= $ai ? 'Re-assess' : 'Assess now' ?></button></form></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <div class="table-note">Score: quiz 35 · training 15 · hours 15 · report quality 15 · evaluations 15 · attendance 5. Tiers: Top performer ≥ 85, Strong ≥ 70, Developing ≥ 55. The ranking is the same for every intern; the writing model, when set up, writes the summary.</div>
</section>
<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
