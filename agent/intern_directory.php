<?php
// Active interns with their team, agent and training (agents and HR). ?scope=mine shows only your interns.
declare(strict_types=1);
require_once __DIR__ . '/../includes/workflow.php';
$pageTitle = 'Team / Interns';
$activePage = 'intern_directory.php';
require __DIR__ . '/../includes/agent_layout.php';

$meId = (int) ($_SESSION['user']['id'] ?? 0);
$mine = ($_GET['scope'] ?? '') === 'mine';
$interns = mentora_workspace_people($pdo, 'internship', $mine ? $meId : null);
$count = static fn(string $status): int => count(array_filter($interns, static fn($i) => $i['training_status'] === $status));
?>
<div class="admin-heading">
    <div>
        <h1>Team / Interns</h1>
        <p>Manage your interns, follow their progress, and support every next step.</p>
    </div>
    <div class="admin-actions">
        <a class="admin-btn" href="intern_hours.php">Internship hours</a><a class="admin-btn" href="export.php?type=interns"><?= mentora_icon('download') ?>Export list</a><a class="admin-btn" href="export.php?type=social"><?= mentora_icon('download') ?>Export social profiles</a><a class="admin-btn primary" href="../auth/signup.php?pathway=internship">+ Add intern</a>
    </div>
</div>
<?= mentora_flash_html() ?>
<div class="application-tabs"><a href="intern_directory.php"<?= $mine ? '' : ' aria-current="page"' ?>>All interns</a><a href="intern_directory.php?scope=mine"<?= $mine ? ' aria-current="page"' : '' ?>>My interns</a>
</div>
<div class="metric-grid">
    <div class="metric-card">
        <small><?= $mine ? 'My interns' : 'Total interns' ?></small><strong><?= count($interns) ?></strong><span>Current cohort</span>
    </div>
    <div class="metric-card">
        <small>In training</small><strong><?= $count('in_training') + $count('not_started') ?></strong><span>Across learning tracks</span>
    </div>
    <div class="metric-card">
        <small>Completed</small><strong><?= $count('completed') ?></strong><span>Ready for next pathway</span>
    </div>
    <div class="metric-card">
        <small>On hold</small><strong><?= $count('on_hold') ?></strong><span>Follow-up needed</span>
    </div>
</div>
<section class="admin-panel data-panel">
    <div class="table-toolbar" data-table-filter="intern-table">
        <input type="search" data-filter="search" placeholder="Search name, email or intern ID" aria-label="Search interns">
        <select data-filter="status" aria-label="Training status">
            <option value="all">All statuses</option>
            <?php foreach (MENTORA_TRAINING_STATUS as $key => [, $label]): ?>
            <option value="<?= $key ?>"><?= $label ?></option>
            <?php endforeach; ?></select>
        <select data-filter="team" aria-label="Team">
            <option value="all">All teams</option>
            <option value="none">Not assigned</option>
            <?php foreach (mentora_departments($pdo) as $department): ?>
            <option value="<?= mentora_h(mb_strtolower($department['name'])) ?>"><?= mentora_h($department['name']) ?></option>
            <?php endforeach; ?></select>
    </div>
    <table class="admin-table" id="intern-table">
        <thead>
            <tr>
                <th>Intern</th>
                <th>Team / agent</th>
                <th>Training</th>
                <th>Social profiles</th>
                <th>Status</th>
                <th>Joined</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($interns as $intern): [$badgeClass, $badgeText] = MENTORA_TRAINING_STATUS[$intern['training_status']] ?? ['info', $intern['training_status']]; ?>
            <tr data-status="<?= $intern['training_status'] ?>" data-team="<?= mentora_h(mb_strtolower($intern['department_name'] ?? 'none')) ?>">
                <td><?= mentora_h($intern['name']) ?><br><small><?= mentora_h($intern['user_code'] . ' · ' . $intern['email']) ?></small></td>
                <td><?= mentora_h($intern['department_name'] ?? 'Not assigned') ?><br><small><?= mentora_h($intern['agent_name'] ?? 'No agent yet') ?></small>
                </td>
                <td><span class="progress"><i style="width:<?= $intern['percent'] ?>%"></i></span> <?= $intern['completed'] ?>/<?= $intern['total'] ?></td>
                <?php $socialPercent = (int) round((int) $intern['social_added'] / 7 * 100); ?>
                <td>
                    <span class="progress"><i style="width:<?= $socialPercent ?>%"></i></span> <?= (int) $intern['social_added'] ?>/7<br><small><?= $socialPercent ?>% complete</small>
                </td>
                <td><span class="badge <?= $badgeClass ?>"><?= $badgeText ?></span><?= $intern['reports_waiting']
                    ? '<br><small>' . (int) $intern['reports_waiting'] . ' report(s) to review</small>'
                    : '' ?></td>
                <td><?= date('d M Y', strtotime($intern['created_at'])) ?></td>
                <td class="row-actions"><a class="admin-btn"
                    href="edit_intern.php?id=<?= (int) $intern['id'] ?>">Edit</a> <a class="admin-btn" href="../account/messages.php?to=<?= (int) $intern['id'] ?>">Message</a>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody></table>
    <div class="table-note">Showing <span data-filter-count="intern-table"><?= count($interns) ?></span> intern(s).<?= $mine && !$interns
        ? ' No interns are assigned to you yet; HR or an agent can assign them in Edit intern.'
        : '' ?></div>
</section>
<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
