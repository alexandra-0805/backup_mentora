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
    // Where to go next: back to the list, or the form again (after "Save & add another" or an error).
    $formAgain = $action === 'update' ? 'admin/department.php?edit=' . $id : 'admin/department.php?add=1';
    $next = 'admin/department.php';
    if (in_array($action, ['add', 'update'], true) && $name === '') {
        mentora_flash('Please enter a department name.', 'error');
        $next = $formAgain;
    } elseif (in_array($action, ['add', 'update'], true) && $taken($pdo, $name, $action === 'update' ? $id : 0)) {
        mentora_flash('A department called “' . $name . '” already exists.', 'error');
        $next = $formAgain;
    } elseif ($action === 'add') {
        $pdo->prepare('INSERT INTO departments (name, description) VALUES (?, ?)')->execute([$name, $description]);
        $another = ($_POST['then'] ?? '') === 'another';
        mentora_flash('“' . $name . '” was added.' . ($another ? ' Add the next one.' : ''));
        $next = $another ? 'admin/department.php?add=1' : 'admin/department.php';
    } elseif ($action === 'update') {
        $pdo->prepare('UPDATE departments SET name = ?, description = ? WHERE id = ?')->execute([$name, $description, $id]);
        mentora_flash('“' . $name . '” was updated.');
    } elseif ($action === 'delete') {
        $pdo->prepare('UPDATE users SET department_id = NULL WHERE department_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM departments WHERE id = ?')->execute([$id]);
        mentora_flash('Department deleted. Its people are now unassigned.');
    }
    mentora_redirect($next);
}

$pageTitle = 'Departments';
$activePage = 'department.php';
require __DIR__ . '/../includes/admin_layout.php';

$departments = $pdo->query("SELECT d.id,
        d.name,
        d.description,
        COUNT(u.id) AS people,
        SUM(u.role = 'agent') AS agents,
        SUM(u.role = 'applicant' AND u.pathway = 'internship') AS interns,
        SUM(u.role = 'applicant' AND u.pathway = 'employee_va') AS employees
    FROM departments d
    LEFT JOIN users u ON u.department_id = d.id AND u.status = 'active'
    GROUP BY d.id
    ORDER BY d.name")
    ->fetchAll();
$unassigned = $pdo->query("SELECT id, first_name, last_name, role, pathway
    FROM users
    WHERE department_id IS NULL AND status = 'active' AND role <> 'admin'
    ORDER BY first_name")
    ->fetchAll();
// Reopen the form after "Save & add another" (?add=1) or after an error (?add=1 / ?edit=ID).
$reopen = isset($_GET['add']) ? 'add' : ((int) ($_GET['edit'] ?? 0) > 0 ? 'edit-' . (int) $_GET['edit'] : '');
?>
<div class="admin-heading">
    <div>
        <h1>Departments</h1>
        <p>Organize your workforce, assign people to teams, and keep staffing balanced.</p>
    </div>
    <div class="admin-actions">
        <button class="admin-btn primary"
            type="button"
            data-bs-toggle="dialog"
            data-bs-target="#department-dialog"
            data-dept-open="add">+ Add department</button>
    </div>
</div>
<?= mentora_flash_html() ?>
<div class="metric-grid">
    <div class="metric-card">
        <small>Departments</small><strong><?= count($departments) ?></strong><span>Active workspace teams</span>
    </div>
    <div class="metric-card">
        <small>Total people</small><strong><?= array_sum(array_column($departments, 'people')) ?></strong><span>Employees, interns and agents</span>
    </div>
    <div class="metric-card">
        <small>Teams with people</small><strong><?= count(array_filter($departments, static fn($d) => (int) $d['people'] > 0)) ?></strong><span>Of <?= count($departments) ?> departments</span>
    </div>
    <div class="metric-card">
        <small>Unassigned people</small><strong><?= count($unassigned) ?></strong><span><?= $unassigned ? 'Assign them below' : 'Every person has a team' ?></span>
    </div>
</div>
<section class="admin-panel data-panel">
    <table class="admin-table">
        <thead>
            <tr>
                <th>Department</th>
                <th>Description</th>
                <th>People assigned</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
    <?php foreach ($departments as $department): ?>
        <tr><td><?= mentora_h($department['name']) ?></td><td><?= mentora_h($department['description']) ?></td>
            <td><?= (int) $department['people'] ?><br><small><?= (int) $department['employees'] ?> employees · <?= (int) $department['interns'] ?> interns · <?= (int) $department['agents'] ?> agents</small>
            </td>
            <td class="row-actions">
                <button class="admin-btn"
                    type="button"
                    data-bs-toggle="dialog"
                    data-bs-target="#department-dialog"
                    data-dept-open="edit-<?= (int) $department['id'] ?>"
                    data-dept-id="<?= (int) $department['id'] ?>"
                    data-dept-name="<?= mentora_h($department['name']) ?>"
                    data-dept-description="<?= mentora_h($department['description']) ?>">Edit</button>
                <form method="post"><?= mentora_csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $department['id'] ?>"><button class="admin-btn" type="submit" data-confirm-title="Delete <?= mentora_h($department['name']) ?>?" data-confirm="<?= (int) $department['people'] ?> people will become unassigned. You can assign them to another department." data-confirm-tone="danger" data-confirm-action="Delete">Delete</button>
                </form>
            </td></tr>
    <?php endforeach; ?>
</tbody></table>
<div class="table-note">
    <button class="admin-btn" type="button" data-bs-toggle="dialog" data-bs-target="#department-dialog" data-dept-open="add">+ Add another department</button>
</div>
</section>
<section class="admin-panel" style="margin-top:16px"><h2>Unassigned people</h2><p class="panel-subtitle">Open someone to choose their department.</p>
    <?php if (!$unassigned): ?><p class="panel-subtitle">Everyone has a department.</p><?php endif; ?>
    <?php foreach (array_slice($unassigned, 0, 8) as $person): ?>
        <div class="list-row">
            <span><?= mentora_h($person['first_name'] . ' ' . $person['last_name']) ?><br><small><?= $person['role'] === 'agent' ? 'Agent' : mentora_pathway_label($person['pathway']) ?></small></span><a class="admin-btn" href="user_edit.php?id=<?= (int) $person['id'] ?>">Assign</a>
        </div>
    <?php endforeach; ?>
</section>

<dialog class="dialog dialog-slide-down department-dialog" id="department-dialog" aria-labelledby="department-dialog-title">
    <form method="post">
        <?= mentora_csrf_field() ?>
        <input type="hidden" name="action" value="add" data-dept-field="action">
        <input type="hidden" name="id" value="" data-dept-field="id">
        <div class="dialog-header">
            <h1 class="dialog-title" id="department-dialog-title">Add a department</h1>
            <button type="button" class="btn-close" data-bs-dismiss="dialog" aria-label="Close"></button>
        </div>
        <div class="dialog-body">
            <div class="field">
                <label for="dept-name">Department name *</label><input id="dept-name" name="name" required maxlength="120" placeholder="e.g. Customer Support" data-dept-field="name">
            </div>
            <div class="field">
                <label for="dept-description">Description</label><textarea id="dept-description" name="description" rows="3" maxlength="255" placeholder="What this department does for clients" data-dept-field="description"></textarea>
            </div>
        </div>
        <div class="dialog-footer">
            <button type="button" class="btn-solid theme-secondary" data-bs-dismiss="dialog">Cancel</button>
            <button type="submit" class="btn-solid theme-secondary" name="then" value="another" data-dept-another>Save &amp; add another</button>
            <button type="submit" class="btn-solid theme-primary" data-dept-save>Add department</button>
        </div>
    </form>
</dialog>
<script>
    // One form for adding and editing: the button that opens the dialog fills it in.
    (() => {
        const dialog = document.getElementById('department-dialog');
        const field = (name) => dialog.querySelector('[data-dept-field="' + name + '"]');
        const title = dialog.querySelector('#department-dialog-title');
        const save = dialog.querySelector('[data-dept-save]');
        const another = dialog.querySelector('[data-dept-another]');
        const openers = document.querySelectorAll('[data-dept-open]');
        openers.forEach((button) => button.addEventListener('click', () => {
            const editing = button.dataset.deptId !== undefined;
            field('action').value = editing ? 'update' : 'add';
            field('id').value = editing ? button.dataset.deptId : '';
            field('name').value = editing ? button.dataset.deptName : '';
            field('description').value = editing ? button.dataset.deptDescription : '';
            title.textContent = editing ? 'Edit ' + button.dataset.deptName : 'Add a department';
            save.textContent = editing ? 'Save changes' : 'Add department';
            another.hidden = editing;
            setTimeout(() => field('name').focus(), 80);
        }));
        // After "Save & add another" (or an error), open the form again.
        const reopen = <?= json_encode($reopen) ?>;
        if (reopen) {
            const opener = document.querySelector('[data-dept-open="' + reopen + '"]');
            if (opener) {
                window.addEventListener('load', () => opener.click());
            }
        }
    })();
</script>
<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
