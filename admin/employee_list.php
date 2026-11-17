<?php
$pageTitle = 'Employee list';
$activePage = 'employee_list.php';
require __DIR__ . '/../includes/admin_layout.php';

$users = $pdo->query("SELECT first_name, last_name, email, created_at FROM users WHERE pathway = 'employee_va' ORDER BY created_at DESC")->fetchAll();
$total = count($users);
$h = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
?>
<div class="admin-heading">
    <div>
        <h1><?= $h($pageTitle) ?></h1>
        <p>Manage profiles, assignments, schedules, and workspace readiness.</p>
    </div>
    <div class="admin-actions">
        <a class="admin-btn" href="#">Export list</a>
        <a class="admin-btn primary" href="../auth/signup.php">+ Add employee</a>
    </div>
</div>

<div class="metric-grid">
    <div class="metric-card"><small>Total records</small><strong><?= $total ?></strong><span>Connected to backup_mentora_db</span></div>
    <div class="metric-card"><small>Active</small><strong><?= $total ?></strong><span>Ready for follow-up</span></div>
    <div class="metric-card"><small>New this cycle</small><strong><?= $total ?></strong><span>Recently registered</span></div>
    <div class="metric-card"><small>Reviews due</small><strong>0</strong><span>No pending reviews</span></div>
</div>

<section class="admin-panel data-panel">
    <div class="table-toolbar">
        <input placeholder="Search name or email">
        <select>
            <option>All statuses</option>
        </select>
        <select>
            <option>All departments</option>
        </select>
    </div>
    <table class="admin-table">
        <thead>
            <tr>
                <th>Person</th>
                <th>Email</th>
                <th>Pathway</th>
                <th>Date joined</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($users as $u): ?>
                <tr>
                    <td><?= $h($u['first_name'] . ' ' . $u['last_name']) ?></td>
                    <td><?= $h($u['email']) ?></td>
                    <td>Employee VA</td>
                    <td><?= $h(date('d M Y', strtotime($u['created_at']))) ?></td>
                    <td><span class="badge">Active</span></td>
                    <td><a class="admin-btn" href="#">View</a> <a class="admin-btn" href="#">Edit</a></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <div class="table-note">Showing <?= $total ?> records from backup_mentora_db.</div>
</section>
<?php require __DIR__ . '/../includes/admin_footer.php'; ?>