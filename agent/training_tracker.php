<?php
// Training progress per intern: modules (training_progress) and approved hours (work_reports). Agents and HR.
declare(strict_types=1);
require_once __DIR__ . '/../includes/workflow.php';
$pageTitle = 'Training tracker';
$activePage = 'training_tracker.php';
require __DIR__ . '/../includes/agent_layout.php';

$rows = mentora_workspace_people($pdo);
$completed = array_sum(array_column($rows, 'completed'));
$total = array_sum(array_column($rows, 'total'));
$hours = array_sum(array_map('floatval', array_column($rows, 'hours_logged')));
$attention = count(array_filter(
    $rows,
    static fn($r) => $r['training_status'] === 'on_hold' || ($r['training_status'] === 'not_started' && strtotime($r['created_at']) < strtotime('-7 days'))
));
?>
<div class="admin-heading">
    <div>
        <h1>Training tracker</h1>
        <p>Monitor module completion, training hours, and upcoming learning milestones.</p>
    </div>
    <div class="admin-actions">
        <a class="admin-btn" href="export.php?type=training"><?= mentora_icon('download') ?>Export tracker</a><a class="admin-btn primary" href="documents.php#upload">+ Share training resource</a>
    </div>
</div>
<div class="metric-grid">
    <div class="metric-card">
        <small>Average completion</small><strong><?= $total ? round($completed / $total * 100) : 0 ?>%</strong><span>Across all interns</span>
    </div>
    <div class="metric-card">
        <small>Modules completed</small><strong><?= $completed ?> / <?= $total ?></strong><span>Tracked in database</span>
    </div>
    <div class="metric-card">
        <small>Approved hours</small><strong><?= mentora_hours($hours) ?></strong><span>From approved reports</span>
    </div>
    <div class="metric-card">
        <small>Needs attention</small><strong><?= $attention ?></strong><span>On hold or not started after 7 days</span>
    </div>
</div>
<section class="admin-panel data-panel">
    <div class="table-toolbar" data-table-filter="tracker-table">
        <input type="search" data-filter="search" placeholder="Search intern" aria-label="Search interns">
        <select data-filter="team" aria-label="Team">
            <option value="all">All teams</option>
            <option value="none">Not assigned</option>
            <?php foreach (mentora_departments($pdo) as $department): ?>
            <option value="<?= mentora_h(mb_strtolower($department['name'])) ?>"><?= mentora_h($department['name']) ?></option>
            <?php endforeach; ?></select>
        <select data-filter="status" aria-label="Status">
            <option value="all">All statuses</option>
            <?php foreach (MENTORA_TRAINING_STATUS as $key => [, $label]): ?>
            <option value="<?= $key ?>"><?= $label ?></option>
            <?php endforeach; ?></select>
    </div>
    <table class="admin-table" id="tracker-table">
        <thead>
            <tr>
                <th>Intern</th>
                <th>Team</th>
                <th>Completion</th>
                <th>Hours</th>
                <th>Status</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $row): [$badgeClass, $badgeText] = MENTORA_TRAINING_STATUS[$row['training_status']] ?? ['info', $row['training_status']]; $required = mentora_required_hours($row); ?>
            <tr data-status="<?= $row['training_status'] ?>" data-team="<?= mentora_h(mb_strtolower($row['department_name'] ?? 'none')) ?>">
                <td><?= mentora_h($row['name']) ?><br><small><?= mentora_h($row['email']) ?></small></td>
                <td><?= mentora_h($row['department_name'] ?? 'Not assigned') ?></td>
                <td>
                    <span class="progress"><i style="width:<?= $row['percent'] ?>%"></i></span> <?= $row['percent'] ?>% <small>(<?= $row['completed'] ?>/<?= $row['total'] ?>)</small>
                </td>
                <td><?= mentora_hours($row['hours_logged']) ?> / <?= $required ?> hrs</td>
                <td><span class="badge <?= $badgeClass ?>"><?= $badgeText ?></span></td>
                <td><a class="admin-btn" href="edit_intern.php?id=<?= (int) $row['id'] ?>">Update training</a></td>
            </tr>
        <?php endforeach; ?>
    </tbody></table>
    <div class="table-note">Showing <span data-filter-count="tracker-table"><?= count($rows) ?></span> intern(s). Interns mark modules as done in their Training page; you can adjust them in Update training.</div>
</section>
<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
