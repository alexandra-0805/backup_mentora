<?php

/**
 * Shared people list for admin/employee_list.php, intern_list.php and agent_list.php ($listType set there:
 * 'employee', 'intern' or 'agent'). Active and turned-off accounts with department, progress and actions.
 */

if (basename((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === basename(__FILE__)) {
    header('Location: employee_list.php');
    exit;
}

require_once __DIR__ . '/../includes/workflow.php';

$listType = in_array($listType ?? '', ['employee', 'intern', 'agent'], true) ? $listType : 'employee';
$config = [
    'employee' => ['Employee list', 'employee_list.php', "u.role = 'applicant' AND u.pathway = 'employee_va'", 'employee', '../auth/signup.php', 'employees'],
    'intern' => [
        'Intern list',
        'intern_list.php',
        "u.role = 'applicant' AND u.pathway = 'internship'",
        'intern',
        '../auth/signup.php?pathway=internship',
        'interns'
    ],
    'agent' => ['Agent list', 'agent_list.php', "u.role = 'agent'", 'agent', 'user_edit.php?new=1&role=agent', 'agents'],
][$listType];
[$pageTitle, $activePage, $where, $noun, $addHref, $exportType] = $config;
require __DIR__ . '/../includes/admin_layout.php';

$users = $pdo->query(
    "SELECT u.id, u.user_code, u.first_name, u.last_name, u.email, u.status, u.created_at, u.department_id,
            d.name AS department_name, CONCAT(ag.first_name, ' ', ag.last_name) AS agent_name,
            COALESCE(t.modules_completed, 0) AS completed, COALESCE(t.hours_logged, 0) AS hours_logged,
            (SELECT COUNT(*) FROM evaluations e WHERE e.user_id = u.id AND e.status <> 'published') AS reviews_due,
            (SELECT COUNT(*) FROM work_reports r WHERE r.user_id = u.id AND r.status = 'submitted') AS reports_waiting,
            (SELECT COUNT(*) FROM users i WHERE i.agent_id = u.id AND i.status = 'active') AS assigned_interns,
            ((COALESCE(s.upwork_url, '') <> '') + (COALESCE(s.linkedin_url, '') <> '') + (COALESCE(s.quora_url, '') <> '') + (COALESCE(s.reddit_url, '') <> '') + (COALESCE(s.instagram_url, '') <> '') + (COALESCE(s.threads_url, '') <> '') + (COALESCE(s.substack_url, '') <> '')) AS social_added
     FROM users u
     LEFT JOIN departments d ON d.id = u.department_id
     LEFT JOIN users ag ON ag.id = u.agent_id
     LEFT JOIN training_records t ON t.user_id = u.id
     LEFT JOIN social_profiles s ON s.user_id = u.id
     WHERE $where AND u.status IN ('active', 'disabled')
     ORDER BY u.created_at DESC"
)->fetchAll();
$active = count(array_filter($users, static fn($u) => $u['status'] === 'active'));
$recent = count(array_filter($users, static fn($u) => strtotime($u['created_at']) >= strtotime('-30 days')));
$reviewsDue = array_sum(array_column($users, 'reviews_due')) + array_sum(array_column($users, 'reports_waiting'));
$modulesTotal = count(mentora_training_modules($listType === 'intern' ? 'internship' : 'employee_va'));
?>
<div class="admin-heading">
    <div>
        <h1><?= mentora_h($pageTitle) ?></h1>
        <p>Manage profiles, assignments, schedules, and workspace readiness.</p>
    </div>
    <div class="admin-actions"><a class="admin-btn"
        href="export.php?type=<?= $exportType ?>"><?= mentora_icon('download') ?>Export list</a><?php if ($listType !== 'agent'): ?><a class="admin-btn" href="export.php?type=social"><?= mentora_icon('download') ?>Export social profiles</a><?php endif; ?><a class="admin-btn primary" href="<?= mentora_h($addHref) ?>">+ Add <?= $noun ?></a>
    </div>
</div>
<?= mentora_flash_html() ?>
<div class="metric-grid">
    <div class="metric-card">
        <small>Total records</small><strong><?= count($users) ?></strong><span>Connected to backup_mentora_db</span>
    </div>
    <div class="metric-card">
        <small>Active</small><strong><?= $active ?></strong><span><?= count($users) - $active ?> turned off</span>
    </div>
    <div class="metric-card">
        <small>New this cycle</small><strong><?= $recent ?></strong><span>Joined in the last 30 days</span>
    </div>
    <div class="metric-card">
        <small><?= $listType === 'agent' ? 'Interns supervised' : 'Reviews due' ?></small><strong><?= $listType === 'agent'
            ? array_sum(array_column($users, 'assigned_interns'))
            : $reviewsDue ?></strong><span><?= $listType === 'agent' ? 'Assigned to these agents' : 'Evaluations and reports waiting' ?></span>
    </div>
</div>
<section class="admin-panel data-panel">
    <div class="table-toolbar" data-table-filter="people-table">
        <input type="search" data-filter="search" placeholder="Search name or email" aria-label="Search">
        <select data-filter="status" aria-label="Status">
            <option value="all">All statuses</option>
            <option value="active">Active</option>
            <option value="disabled">Turned off</option>
        </select>
        <select data-filter="department" aria-label="Department">
            <option value="all">All departments</option>
            <option value="none">Not assigned</option>
            <?php foreach (mentora_departments($pdo) as $department): ?>
            <option value="<?= (int) $department['id'] ?>"><?= mentora_h($department['name']) ?></option>
            <?php endforeach; ?></select>
    </div>
    <table class="admin-table" id="people-table">
        <thead>
            <tr>
                <th>User ID</th>
                <th>Person</th>
                <th>Department</th>
                <th><?= $listType === 'agent'
                    ? 'Interns'
                    : ($listType === 'intern' ? 'Training / hours' : 'Training') ?></th><?= $listType === 'agent' ? '' : '<th>Social profiles</th>' ?><th>Date joined</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($users as $user): ?>
            <tr data-status="<?= $user['status'] ?>" data-department="<?= $user['department_id'] ? (int) $user['department_id'] : 'none' ?>">
                <td><?= mentora_h($user['user_code']) ?></td>
                <td><?= mentora_h($user['first_name'] . ' ' . $user['last_name']) ?><br><small><?= mentora_h($user['email']) ?></small></td>
                <td><?= mentora_h($user['department_name'] ?? 'Not assigned') ?><?= $listType === 'intern'
                    ? '<br><small>' . mentora_h($user['agent_name'] ?? 'No agent yet') . '</small>'
                    : '' ?></td>
                <td>
                    <?php if ($listType === 'agent'): ?><?= (int) $user['assigned_interns'] ?> assigned<?php else: ?><?= (int) $user['completed'] ?>/<?= $modulesTotal ?> modules<?= $listType === 'intern'
                        ? '<br><small>' . mentora_hours($user['hours_logged']) . ' hrs approved</small>'
                        : '' ?><?php endif; ?>
                </td>
                <?php if ($listType !== 'agent'): $socialPercent = (int) round((int) $user['social_added'] / 7 * 100); ?>
                <td>
                    <span class="progress"><i style="width:<?= $socialPercent ?>%"></i></span> <?= (int) $user['social_added'] ?>/7<br><small><?= $socialPercent ?>% complete</small>
                </td>
                <?php endif; ?>
                <td><?= mentora_h(date('d M Y', strtotime($user['created_at']))) ?></td>
                <td><span class="badge <?= $user['status'] === 'active' ? '' : 'warn' ?>"><?= $user['status'] === 'active' ? 'Active' : 'Turned off' ?></span><?= ($user['reports_waiting'] ?? 0)
                    ? '<br><small>' . (int) $user['reports_waiting'] . ' report(s) waiting</small>'
                    : '' ?></td>
                <td class="row-actions"><a class="admin-btn"
                    href="user_edit.php?id=<?= (int) $user['id'] ?>"><?= mentora_icon('view') ?>View</a> <a class="admin-btn" href="user_edit.php?id=<?= (int) $user['id'] ?>#first_name">Edit</a>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody></table>
    <div class="table-note">Showing <span data-filter-count="people-table"><?= count($users) ?></span> records from backup_mentora_db.</div>
</section>
<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
