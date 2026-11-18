<?php
if (basename((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === basename(__FILE__)) {
    header('Location: employee_list.php');
    exit;
}

$users = is_array($users ?? null) ? $users : [];
$pageTitle = is_string($pageTitle ?? null) ? $pageTitle : 'People list';
?>
<div class="admin-heading"><div><h1><?= htmlspecialchars($pageTitle) ?></h1><p>Manage profiles, assignments, schedules, and workspace readiness.</p></div><div class="admin-actions"><a class="admin-btn" href="#">Export list</a><a class="admin-btn primary" href="../auth/signup.php">+ Add <?= strtolower(explode(' ', $pageTitle)[0]) ?></a></div></div>
<div class="metric-grid"><div class="metric-card"><small>Total records</small><strong><?= count($users) ?></strong><span>Connected to backup_mentora_db</span></div><div class="metric-card"><small>Active</small><strong><?= count($users) ?></strong><span>Ready for follow-up</span></div><div class="metric-card"><small>New this cycle</small><strong><?= count($users) ?></strong><span>Recently registered</span></div><div class="metric-card"><small>Reviews due</small><strong>0</strong><span>No pending reviews</span></div></div>
<section class="admin-panel data-panel"><div class="table-toolbar"><input placeholder="Search name or email"><select><option>All statuses</option></select><select><option>All departments</option></select></div><table class="admin-table"><thead><tr><th>User ID</th><th>Person</th><th>Email</th><th>Pathway</th><th>Date joined</th><th>Status</th><th>Actions</th></tr></thead><tbody><?php foreach($users as $user): ?><tr><td><?= htmlspecialchars((string) ($user['user_code'] ?? '')) ?></td><td><?= htmlspecialchars($user['first_name'].' '.$user['last_name']) ?></td><td><?= htmlspecialchars($user['email']) ?></td><td><?= htmlspecialchars($pageTitle === 'Intern list' ? 'Internship' : ($pageTitle === 'Agent list' ? 'Agent' : 'Employee VA')) ?></td><td><?= htmlspecialchars(date('d M Y',strtotime($user['created_at']))) ?></td><td><span class="badge">Active</span></td><td><a class="admin-btn" href="#">View</a> <a class="admin-btn" href="#">Edit</a></td></tr><?php endforeach; ?></tbody></table><div class="table-note">Showing <?= count($users) ?> records from backup_mentora_db.</div></section>
<?php require __DIR__.'/../includes/admin_footer.php'; ?>
