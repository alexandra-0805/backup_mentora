<?php
// Agent dashboard: your interns, training, evaluations due and reports waiting, from the database.
declare(strict_types=1);
require_once __DIR__ . '/../includes/workflow.php';
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
$hour = (int) date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
?>
<div class="admin-heading">
    <div>
        <h1>Dashboard</h1>
        <p><?= $greeting ?>. Keep your team's learning and work on track.</p>
    </div>
    <div class="admin-actions">
        <a class="admin-btn" href="reporting_overview.php">View reporting</a><a class="admin-btn primary" href="intern_directory.php<?= $myInterns ? '?scope=mine' : '' ?>">Open team</a>
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
        <h2>Intern training progress</h2>
        <p class="panel-subtitle"><?= $myInterns ? 'Your interns' : 'Active learners in the current cohort' ?></p>
        <?php if (!$cohort): ?><p class="panel-subtitle">No active interns yet.</p><?php else: ?>
        <table class="admin-table"><thead><tr><th>Intern / team</th><th>Module progress</th><th>Status</th></tr></thead><tbody>
            <?php foreach (array_slice($cohort, 0, 6) as $intern): [$badgeClass, $badgeText] = MENTORA_TRAINING_STATUS[$intern['training_status']] ?? ['info', $intern['training_status']]; ?>
                <tr>
                    <td><a href="edit_intern.php?id=<?= (int) $intern['id'] ?>"><?= mentora_h($intern['name']) ?></a><br><small><?= mentora_h($intern['department_name'] ?? 'No team yet') ?></small>
                    </td>
                    <td>
                        <span class="progress"><i style="width:<?= $intern['percent'] ?>%"></i></span> <?= $intern['completed'] ?>/<?= $intern['total'] ?></td>
                    <td><span class="badge <?= $badgeClass ?>"><?= $badgeText ?></span>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody></table>
        <?php endif; ?>
    </section>
    <section class="admin-panel">
        <h2>Needs your attention</h2>
        <p class="panel-subtitle"><?= date('l, d F') ?> · PHT</p>
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
        <?php if (!$waiting && !$dueEvaluations): ?><p class="panel-subtitle">Nothing waiting. Nice work!</p><?php endif; ?>
    </section>
</div>
<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
