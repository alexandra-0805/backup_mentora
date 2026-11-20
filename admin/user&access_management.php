<?php
// Every account with its role and access (HR). Open one to edit it, turn access off/on, or resend a link.
declare(strict_types=1);
require_once __DIR__ . '/../includes/workflow.php';
$pageTitle = 'User & access management';
$activePage = 'user&access_management.php';
require __DIR__ . '/../includes/admin_layout.php';

$users = $pdo->query("SELECT u.id,
        u.user_code,
        u.username,
        u.first_name,
        u.last_name,
        u.email,
        u.role,
        u.pathway,
        u.status,
        u.created_at,
        d.name AS department_name
    FROM users u
    LEFT JOIN departments d ON d.id = u.department_id
    ORDER BY u.created_at DESC")
    ->fetchAll();
$statusBadge = [
    'active' => ['', 'Active'],
    'pending' => ['warn', 'Waiting for confirmation'],
    'approved' => ['info', 'Link sent'],
    'rejected' => ['warn', 'Declined'],
    'disabled' => ['warn', 'Turned off']
];
$roleLabel = static fn(array $u): string => match ($u['role']) { 'admin' => 'HR administrator', 'agent' => 'Agent', default => mentora_pathway_label($u['pathway']) };
$count = static fn(string $key, string $value): int => count(array_filter($users, static fn($u) => $u[$key] === $value));
?>
<div class="admin-heading">
    <div>
        <h1>User & access management</h1>
        <p>Manage workspace accounts, keep access proportional to each role, and review who can sign in.</p>
    </div>
    <div class="admin-actions">
        <a class="admin-btn" href="export.php?type=users"><?= mentora_icon('download') ?>Export access audit</a><a class="admin-btn primary" href="user_edit.php?new=1">+ Invite user</a>
    </div>
</div>
<?= mentora_flash_html() ?>
<div class="metric-grid">
    <div class="metric-card">
        <small>Accounts</small><strong><?= count($users) ?></strong><span><?= $count('status', 'active') ?> can sign in</span>
    </div>
    <div class="metric-card">
        <small>HR administrators</small><strong><?= $count('role', 'admin') ?></strong><span>Full access</span>
    </div>
    <div class="metric-card">
        <small>Agents</small><strong><?= $count('role', 'agent') ?></strong><span>Intern supervision</span>
    </div>
    <div class="metric-card">
        <small>Waiting / turned off</small><strong><?= $count('status', 'pending') + $count('status', 'approved') ?> / <?= $count('status', 'disabled') ?></strong><span>Need attention</span>
    </div>
</div>
<section class="admin-panel data-panel">
    <div class="table-toolbar" data-table-filter="access-table">
        <input type="search" data-filter="search" placeholder="Search name, email, username or ID" aria-label="Search accounts">
        <select data-filter="role" aria-label="Role">
            <option value="all">All roles</option>
            <option value="admin">HR administrators</option>
            <option value="agent">Agents</option>
            <option value="intern">Interns</option>
            <option value="employee">Employees</option>
        </select>
        <select data-filter="status" aria-label="Status">
            <option value="all">All statuses</option>
            <?php foreach ($statusBadge as $key => [, $label]): ?>
            <option value="<?= $key ?>"><?= $label ?></option>
            <?php endforeach; ?></select>
    </div>
    <table class="admin-table" id="access-table">
        <thead>
            <tr>
                <th>User ID</th>
                <th>Account</th>
                <th>Role</th>
                <th>Department</th>
                <th>Account status</th>
                <th>Created</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($users as $user): [$badgeClass, $badgeText] = $statusBadge[$user['status']] ?? ['', ucfirst((string) $user['status'])]; $roleKey = $user['role'] === 'applicant' ? mentora_pathway_folder($user['pathway']) : $user['role']; ?>
            <tr data-role="<?= $roleKey ?>" data-status="<?= $user['status'] ?>">
                <td><?= mentora_h($user['user_code']) ?></td>
                <td><?= mentora_h($user['first_name'] . ' ' . $user['last_name']) ?><br><small><?= mentora_h($user['email']) ?><?= $user['username'] ? ' · @' . mentora_h($user['username']) : '' ?></small>
                </td>
                <td><?= mentora_h($roleLabel($user)) ?></td>
                <td><?= mentora_h($user['department_name'] ?? '—') ?></td>
                <td><span class="badge <?= $badgeClass ?>"><?= mentora_h($badgeText) ?></span></td>
                <td><?= mentora_h(date('d M Y', strtotime($user['created_at']))) ?></td>
                <td><a class="admin-btn" href="user_edit.php?id=<?= (int) $user['id'] ?>">Manage</a></td>
            </tr>
        <?php endforeach; ?>
    </tbody></table>
    <div class="table-note">Showing <span data-filter-count="access-table"><?= count($users) ?></span> account(s).</div>
</section>
<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
