<?php
// Departments (HR): add, rename / describe, delete (people in it become unassigned), and see who's in each.
declare(strict_types=1);
require_once __DIR__ . '/../includes/workflow.php';

if (mentora_is_post()) {
    mentora_post_user(['admin'], 'admin/department.php');
    $action = (string) ($_POST['action'] ?? 'add');
    $name = mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 120);
    $description = mb_substr(trim((string) ($_POST['description'] ?? '')), 0, 255);
    $id = (int) ($_POST['id'] ?? 0);
    $taken = static function (PDO $pdo, string $name, int $exceptId): bool {
        $query = $pdo->prepare('SELECT COUNT(*) FROM departments WHERE name = ? AND id <> ?');
        $query->execute([$name, $exceptId]);
        return (int) $query->fetchColumn() > 0;
    };
    if (in_array($action, ['add', 'update'], true) && $name === '') {
        mentora_flash('Please enter a department name.', 'error');
    } elseif (in_array($action, ['add', 'update'], true) && $taken($pdo, $name, $action === 'update' ? $id : 0)) {
        mentora_flash('A department called “' . $name . '” already exists.', 'error');
    } elseif ($action === 'add') {
        $pdo->prepare('INSERT INTO departments (name, description) VALUES (?, ?)')->execute([$name, $description]);
        mentora_flash('“' . $name . '” was added.');
    } elseif ($action === 'update') {
        $pdo->prepare('UPDATE departments SET name = ?, description = ? WHERE id = ?')->execute([$name, $description, $id]);
        mentora_flash('Department updated.');
    } elseif ($action === 'delete') {
        $pdo->prepare('UPDATE users SET department_id = NULL WHERE department_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM departments WHERE id = ?')->execute([$id]);
        mentora_flash('Department deleted. Its people are now unassigned.');
    }
    mentora_redirect('admin/department.php');
}

$pageTitle = 'Departments';
$activePage = 'department.php';
require __DIR__ . '/../includes/admin_layout.php';

$departments = $pdo->query("SELECT d.id, d.name, d.description, COUNT(u.id) AS people, SUM(u.role = 'agent') AS agents, SUM(u.role = 'applicant' AND u.pathway = 'internship') AS interns, SUM(u.role = 'applicant' AND u.pathway = 'employee_va') AS employees FROM departments d LEFT JOIN users u ON u.department_id = d.id AND u.status = 'active' GROUP BY d.id ORDER BY d.name")->fetchAll();
$unassigned = $pdo->query("SELECT id, first_name, last_name, role, pathway FROM users WHERE department_id IS NULL AND status = 'active' AND role <> 'admin' ORDER BY first_name")->fetchAll();
$editId = (int) ($_GET['edit'] ?? 0);
?>
<div class="admin-heading"><div><h1>Departments</h1><p>Organize your workforce, assign people to teams, and keep staffing balanced.</p></div><div class="admin-actions"><a class="admin-btn primary" href="#add-department">+ Add department</a></div></div>
<?= mentora_flash_html() ?>
<div class="metric-grid"><div class="metric-card"><small>Departments</small><strong><?= count($departments) ?></strong><span>Active workspace teams</span></div><div class="metric-card"><small>Total people</small><strong><?= array_sum(array_column($departments, 'people')) ?></strong><span>Employees, interns and agents</span></div><div class="metric-card"><small>Teams with people</small><strong><?= count(array_filter($departments, static fn($d) => (int) $d['people'] > 0)) ?></strong><span>Of <?= count($departments) ?> departments</span></div><div class="metric-card"><small>Unassigned people</small><strong><?= count($unassigned) ?></strong><span><?= $unassigned ? 'Assign them below' : 'Every person has a team' ?></span></div></div>
<section class="admin-panel data-panel"><table class="admin-table"><thead><tr><th>Department</th><th>Description</th><th>People assigned</th><th>Actions</th></tr></thead><tbody>
    <?php foreach ($departments as $department): ?>
        <?php if ($editId === (int) $department['id']): ?>
            <tr><td colspan="4"><form method="post" class="table-toolbar"><?= mentora_csrf_field() ?><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="<?= (int) $department['id'] ?>"><input name="name" required maxlength="120" value="<?= mentora_h($department['name']) ?>" aria-label="Department name"><input name="description" maxlength="255" value="<?= mentora_h($department['description']) ?>" aria-label="Description"><button class="admin-btn primary" type="submit">Save</button><a class="admin-btn" href="department.php">Cancel</a></form></td></tr>
        <?php else: ?>
            <tr><td><?= mentora_h($department['name']) ?></td><td><?= mentora_h($department['description']) ?></td>
                <td><?= (int) $department['people'] ?><br><small><?= (int) $department['employees'] ?> employees · <?= (int) $department['interns'] ?> interns · <?= (int) $department['agents'] ?> agents</small></td>
                <td class="row-actions"><a class="admin-btn" href="department.php?edit=<?= (int) $department['id'] ?>">Edit</a>
                    <form method="post"><?= mentora_csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $department['id'] ?>"><button class="admin-btn" type="submit" data-confirm-title="Delete <?= mentora_h($department['name']) ?>?" data-confirm="<?= (int) $department['people'] ?> people will become unassigned. You can assign them to another department." data-confirm-tone="danger" data-confirm-action="Delete">Delete</button></form></td></tr>
        <?php endif; ?>
    <?php endforeach; ?>
</tbody></table></section>
<div class="admin-grid" style="margin-top:16px">
    <section id="add-department" class="admin-panel"><h2>Add a department</h2><form method="post" class="table-toolbar"><?= mentora_csrf_field() ?><input type="hidden" name="action" value="add"><input name="name" required maxlength="120" placeholder="Department name" aria-label="Department name"><input name="description" maxlength="255" placeholder="Short description" aria-label="Description"><button class="admin-btn primary" type="submit" data-confirm-title="Add this department?" data-confirm="The new department will be added to your workspace and appear in the list above.">Save department</button></form></section>
    <section class="admin-panel"><h2>Unassigned people</h2><p class="panel-subtitle">Open someone to choose their department.</p>
        <?php if (!$unassigned): ?><p class="panel-subtitle">Everyone has a department.</p><?php endif; ?>
        <?php foreach (array_slice($unassigned, 0, 8) as $person): ?>
            <div class="list-row"><span><?= mentora_h($person['first_name'] . ' ' . $person['last_name']) ?><br><small><?= $person['role'] === 'agent' ? 'Agent' : mentora_pathway_label($person['pathway']) ?></small></span><a class="admin-btn" href="user_edit.php?id=<?= (int) $person['id'] ?>">Assign</a></div>
        <?php endforeach; ?>
    </section>
</div>
<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
