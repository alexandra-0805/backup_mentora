<?php
// Agent dashboard: your interns, training, AI assessments, department candidates, evaluations due and reports waiting.
declare(strict_types=1);
require_once __DIR__ . '/../includes/workflow.php';
require_once __DIR__ . '/../includes/screening.php';
$pageTitle = 'Dashboard';
$activePage = 'dashboard.php';
require __DIR__ . '/../includes/agent_layout.php';

$meId = (int) ($_SESSION['user']['id'] ?? 0);
$allInterns = mentora_workspace_people($pdo);
$myInterns = array_values(array_filter($allInterns, static fn($i) => (int) $i['agent_id'] === $meId));
$cohort = $myInterns ?: $allInterns;
$inTraining = count(array_filter($cohort, static fn($i) => in_array($i['training_status'], ['in_training', 'not_started'], true)));
$waiting = mentora_reports_waiting($pdo, 'internship', $meId);
$dueEvaluations = $pdo->query("SELECT e.id, e.review_type, e.due_date, e.status, u.first_name, u.last_name
    FROM evaluations e
    JOIN users u ON u.id = e.user_id AND u.status = 'active'
    WHERE e.status <> 'published'
    ORDER BY e.due_date IS NULL, e.due_date LIMIT 5")
    ->fetchAll();
$evaluationsOpen = (int) $pdo->query("SELECT COUNT(*)
    FROM evaluations e
    JOIN users u ON u.id = e.user_id AND u.status = 'active'
    WHERE e.status <> 'published'")
    ->fetchColumn();
$checkedIn = (int) $pdo->query("SELECT COUNT(*)
    FROM attendance a
    JOIN users u ON u.id = a.user_id
    WHERE a.work_date = CURDATE() AND a.time_in IS NOT NULL AND u.pathway = 'internship'")
    ->fetchColumn();
$myDepartmentId = (int) ($pdo->query('SELECT department_id FROM users WHERE id = ' . $meId)->fetchColumn() ?: 0);
$candidates = $myDepartmentId ? mentora_screening_rows($pdo, 'suitable', $myDepartmentId) : [];
// Intern ranking (ai/intern_assessment.php): best AI assessment first, not assessed yet last. "Recommended" = finished
// training and scored MENTORA_READY_SCORE (70) or more, the same "ready to employ" as the AI ranking page.
$ranking = mentora_intern_rankings($pdo, $myInterns ? $meId : null);
$assessed = array_filter($ranking, static fn($i) => $i['ai'] !== null);
$readyToReport = count(array_filter($assessed, static fn($i) => (int) $i['ai']['ready'] === 1));
$hour = (int) date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
?>
<div class="admin-heading">
    <div>
        <h1>Dashboard</h1>
        <p><?= $greeting ?>. Keep your team's learning and work on track.</p>
    </div>
    <div class="admin-actions">
        <a class="admin-btn" href="intern_assessments.php">Assessments</a><a class="admin-btn" href="reporting_overview.php">View reporting</a><a class="admin-btn primary" href="intern_directory.php<?= $myInterns ? '?scope=mine' : '' ?>">Open team</a>
    </div>
</div>
<?= mentora_flash_html() ?>
<div class="metric-grid">
    <div class="metric-card">
        <small><?= $myInterns ? 'My interns' : 'Intern cohort' ?></small><strong><?= count($cohort) ?></strong><span><?= $myInterns ? 'Assigned to you' : 'All active interns' ?></span>
    </div>
    <div class="metric-card"><small>In training</small><strong><?= $inTraining ?></strong><span><?= $checkedIn ?> checked in today</span></div>
    <div class="metric-card"><small>Evaluations to review</small><strong><?= $evaluationsOpen ?></strong><span>Pending or draft</span></div>
    <div class="metric-card">
        <small>Reports to review</small><strong><?= count($waiting) ?></strong><span><?= count($waiting) ? 'Waiting for approval' : 'All caught up' ?></span>
    </div>
</div>
<div class="admin-grid">
    <section class="admin-panel">
        <div class="panel-title-row">
            <div>
                <h2>Intern ranking</h2>
                <p class="panel-subtitle"><?= $myInterns ? 'Your interns' : 'All active interns' ?>, best first ·
                    <?= $readyToReport ?> recommended for employment</p>
            </div>
            <a class="admin-btn" href="intern_assessments.php">Full ranking</a>
        </div>
        <?php if (!$ranking): ?><p class="panel-subtitle">No active interns yet.</p><?php else: ?>
        <table class="admin-table intern-ranking-table" data-no-pager><thead><tr><th>Rank</th><th>Intern / team</th><th>Score</th><th>Recommendation</th></tr></thead><tbody>
            <?php foreach (array_slice($ranking, 0, 6) as $intern): $ai = $intern['ai']; $ready = $ai && (int) $ai['ready'] === 1; ?>
                <tr>
                    <td class="intern-rank"><?= $intern['rank'] ? '#' . (int) $intern['rank'] : '—' ?></td>
                    <td><a href="edit_intern.php?id=<?= (int) $intern['id'] ?>"><?= mentora_h($intern['name']) ?></a><br><small><?= mentora_h($intern['department_name'] ?? 'No team yet') ?></small></td>
                    <td><?php if ($ai): ?>
                            <strong><?= (int) $ai['score'] ?></strong>/100<br><small><?= mentora_h($ai['tier']) ?></small>
                        <?php else: ?>
                            <span class="progress"><i style="width:<?= (int) $intern['percent'] ?>%"></i></span> <small><?= (int) $intern['completed'] ?>/<?= (int) $intern['total'] ?> modules</small>
                        <?php endif; ?></td>
                    <td><?php if ($ready): ?>
                            <span class="badge">Recommended</span>
                        <?php elseif ($ai): ?>
                            <span class="badge warn">Not recommended yet</span><br><small>Needs <?= MENTORA_READY_SCORE ?>+<?= $intern['percent'] < 100 ? ' and finished training' : '' ?></small>
                        <?php else: ?>
                            <span class="badge info">Not assessed yet</span><br><small><?= $intern['percent'] >= 100 ? 'Training done · assess it' : 'Assessed after training' ?></small>
                        <?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody></table>
        <?php if (count($ranking) > 6): ?><p class="table-note">Top 6 of <?= count($ranking) ?> interns. <a href="intern_assessments.php">See the full ranking</a>.</p><?php endif; ?>
        <?php endif; ?>
    </section>
    <section class="admin-panel">
        <h2>Needs your attention</h2>
        <p class="panel-subtitle"><?= date('l, d F') ?> · PHT</p>
        <?php foreach (array_slice($candidates, 0, 3) as $candidate): ?>
            <div class="notice-row">
                <span><?= mentora_h($candidate['name']) ?><br><small>Suitable for <?= mentora_h($candidate['department_name']) ?> · Score <?= (int) $candidate['score'] ?>/100<?= $candidate['dept_verdict'] ? ' · assessed' : '' ?></small></span><a class="badge <?= $candidate['dept_verdict'] ? '' : 'warn' ?>" href="candidates.php#r<?= (int) $candidate['user_id'] ?>"><?= $candidate['dept_verdict'] ? 'View' : 'Assess' ?></a>
            </div>
        <?php endforeach; ?>
        <?php if ($readyToReport): ?>
            <div class="notice-row">
                <span><?= $readyToReport ?> intern<?= $readyToReport === 1 ? ' is' : 's are' ?> ready to employ<br><small>From the assessment after training</small></span><a class="badge info" href="agent_reports.php?tab=intern#new">Report to HR</a>
            </div>
        <?php endif; ?>
        <?php foreach (array_slice($waiting, 0, 4) as $report): ?>
            <div class="notice-row">
                <span><?= mentora_h($report['first_name'] . ' ' . $report['last_name']) ?><br><small>Report · <?= date('d M', strtotime($report['report_date'])) ?> · <?= mentora_hours($report['hours']) ?> hrs</small></span><a class="badge warn" href="reporting_overview.php#review">Review</a>
            </div>
        <?php endforeach; ?>
        <?php foreach ($dueEvaluations as $evaluation): $overdue = $evaluation['due_date'] && $evaluation['due_date'] < date('Y-m-d'); ?>
            <div class="notice-row">
                <span><?= mentora_h($evaluation['first_name'] . ' ' . $evaluation['last_name']) ?><br><small><?= mentora_h($evaluation['review_type']) ?><?= $evaluation['due_date']
                    ? ' · due ' . date('d M', strtotime($evaluation['due_date']))
                    : '' ?></small></span><a class="badge <?= $overdue ? 'warn' : 'info' ?>"
                        href="individual_evaluation.php?id=<?= (int) $evaluation['id'] ?>"><?= $overdue ? 'Overdue' : 'Evaluate' ?></a>
            </div>
        <?php endforeach; ?>
        <?php if (!$waiting && !$dueEvaluations && !$candidates && !$readyToReport): ?><p class="panel-subtitle">Nothing waiting. Nice work!</p><?php endif; ?>
    </section>
</div>
<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
