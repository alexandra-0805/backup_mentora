<?php

/**
 * Clients (HR admins and agents; admin/clients.php and agent/clients.php set $workspace).
 * Each client: details, service scope, shared folder, Mentora owner, status, its client department, and how
 * much work is open for it (Tasks & deliverables). HR admins and agents add and edit; only HR deletes, and a
 * client that still has tasks can't be deleted (its work history stays). See includes/client_work.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/../client_work.php';
require_once __DIR__ . '/../work_rules.php';

$workspace = ($workspace ?? 'agent') === 'admin' ? 'admin' : 'agent';
$self = $workspace . '/clients.php';

if (mentora_is_post()) {
    $me = mentora_post_user(['admin', 'agent'], $self);
    $action = (string) ($_POST['action'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);

    if ($action === 'delete') {
        $tasks = $pdo->prepare('SELECT COUNT(*) FROM client_tasks WHERE client_id = ?');
        $tasks->execute([$id]);
        if ($me['role'] !== 'admin') {
            mentora_flash('Only HR can delete a client.', 'error');
        } elseif ((int) $tasks->fetchColumn() > 0) {
            mentora_flash('This client still has tasks. Delete or move its tasks first, or set the client to Ended.', 'error');
        } else {
            $pdo->prepare('DELETE FROM clients WHERE id = ?')->execute([$id]);
            mentora_flash('Client deleted.');
        }
        mentora_redirect($self);
    }

    // add / update
    $error = null;
    $name = mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 160);
    $email = trim((string) ($_POST['contact_email'] ?? ''));
    $departmentId = (int) ($_POST['department_id'] ?? 0) ?: null;
    $ownerId = (int) ($_POST['owner_id'] ?? 0) ?: null;
    $status = array_key_exists((string) ($_POST['status'] ?? ''), MENTORA_CLIENT_STATUSES) ? (string) $_POST['status'] : 'active';
    $website = mentora_clean_url((string) ($_POST['website'] ?? ''), $error);
    $folder = mentora_clean_url((string) ($_POST['shared_folder_url'] ?? ''), $error);
    $staff = mentora_staff_people($pdo);
    if ($name === '') {
        $error = 'Please enter the client’s name.';
    } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid contact email.';
    } elseif ($ownerId !== null && !isset($staff[$ownerId])) {
        $error = 'Choose the owner from HR or the agents.';
    } elseif ($departmentId !== null) {
        $taken = $pdo->prepare('SELECT name FROM clients WHERE department_id = ? AND id <> ?');
        $taken->execute([$departmentId, $action === 'update' ? $id : 0]);
        $takenBy = $taken->fetchColumn();
        if ($takenBy !== false) {
            $error = 'That department is already linked to ' . $takenBy . '.';
        }
    }
    if ($error !== null) {
        mentora_flash($error, 'error');
        mentora_redirect($self);
    }
    $values = [
        $name, $departmentId,
        mb_substr(trim((string) ($_POST['contact_name'] ?? '')), 0, 120) ?: null,
        $email !== '' ? mb_substr($email, 0, 190) : null,
        mb_substr(trim((string) ($_POST['contact_phone'] ?? '')), 0, 40) ?: null,
        $website,
        mb_substr(trim((string) ($_POST['service_scope'] ?? '')), 0, 5000) ?: null,
        $folder, $ownerId, $status,
        mb_substr(trim((string) ($_POST['notes'] ?? '')), 0, 5000) ?: null,
    ];
    if ($action === 'update') {
        $pdo->prepare('UPDATE clients SET name = ?, department_id = ?, contact_name = ?, contact_email = ?, contact_phone = ?, website = ?,
            service_scope = ?, shared_folder_url = ?, owner_id = ?, status = ?, notes = ? WHERE id = ?')->execute([...$values, $id]);
        mentora_flash('“' . $name . '” was updated.');
    } else {
        $pdo->prepare('INSERT INTO clients (name, department_id, contact_name, contact_email, contact_phone, website,
            service_scope, shared_folder_url, owner_id, status, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute($values);
        mentora_flash('“' . $name . '” was added.');
    }
    mentora_redirect($self);
}

$pageTitle = 'Clients';
// Agents open Clients from the Department candidates button (it isn't in their sidebar), so that stays highlighted.
$activePage = $workspace === 'agent' ? 'candidates.php' : 'clients.php';
require __DIR__ . '/../' . $workspace . '_layout.php';

$isAdmin = ($_SESSION['user']['role'] ?? '') === 'admin';
$clients = $pdo->query("SELECT c.*, d.name AS department_name, CONCAT(o.first_name, ' ', o.last_name) AS owner_name,
        SUM(t.id IS NOT NULL AND t.status <> 'done') AS open_tasks,
        SUM(t.id IS NOT NULL AND t.status <> 'done' AND t.due_date < CURDATE()) AS overdue_tasks
    FROM clients c
    LEFT JOIN departments d ON d.id = c.department_id
    LEFT JOIN users o ON o.id = c.owner_id
    LEFT JOIN client_tasks t ON t.client_id = c.id
    GROUP BY c.id
    ORDER BY c.status = 'ended', c.name")->fetchAll();
$staff = mentora_staff_people($pdo);
$departments = array_column($pdo->query('SELECT id, name FROM departments ORDER BY name')->fetchAll(), 'name', 'id');
$active = count(array_filter($clients, static fn(array $c): bool => $c['status'] === 'active'));
$noOwner = count(array_filter($clients, static fn(array $c): bool => $c['owner_id'] === null && $c['status'] !== 'ended'));
$assetBase = '../';
?>
<div class="admin-heading">
    <div>
        <h1>Clients</h1>
        <p>Who Mentora works for: details, service scope, shared folder and the Mentora owner of each client.</p>
    </div>
    <div class="admin-actions">
        <a class="admin-btn" href="tasks.php">Tasks &amp; deliverables</a>
        <button class="admin-btn primary" type="button" data-record-open="#client-dialog">+ Add client</button>
    </div>
</div>
<?= mentora_flash_html() ?>
<?= mentora_work_rules(true) // Starting rules (includes/work_rules.php) ?>
<div class="metric-grid">
    <div class="metric-card"><small>Active clients</small><strong><?= $active ?></strong><span>Of <?= count($clients) ?> clients</span></div>
    <div class="metric-card"><small>Open tasks</small><strong><?= array_sum(array_map('intval', array_column($clients, 'open_tasks'))) ?></strong><span>Not done yet</span></div>
    <div class="metric-card"><small>Overdue tasks</small><strong><?= array_sum(array_map('intval', array_column($clients, 'overdue_tasks'))) ?></strong><span>Past their due date</span></div>
    <div class="metric-card"><small>No owner yet</small><strong><?= $noOwner ?></strong><span><?= $noOwner ? 'Choose a Mentora owner' : 'Every client has an owner' ?></span></div>
</div>

<section class="admin-panel data-panel">
    <div class="table-toolbar" data-table-filter="clients-table">
        <input type="search" data-filter="search" placeholder="Search clients" aria-label="Search clients">
        <select data-filter="status" aria-label="Status"><?= mentora_select_options(MENTORA_CLIENT_STATUSES, null, 'All statuses') ?></select>
        <span class="panel-subtitle" data-filter-count="clients-table"></span>
    </div>
    <table class="admin-table" id="clients-table">
        <thead>
            <tr><th>Client</th><th>Mentora owner</th><th>Contact</th><th>Service scope</th><th>Shared folder</th><th>Work</th><th>Status</th><th>Actions</th></tr>
        </thead>
        <tbody>
        <?php if (!$clients): ?>
            <tr><td colspan="8" class="panel-subtitle">No clients yet. Add the first one.</td></tr>
        <?php endif; ?>
        <?php foreach ($clients as $client):
            $record = array_intersect_key($client, array_flip(['id', 'name', 'department_id', 'contact_name', 'contact_email', 'contact_phone',
                'website', 'service_scope', 'shared_folder_url', 'owner_id', 'status', 'notes']));
        ?>
            <tr data-status="<?= mentora_h($client['status']) ?>">
                <td><strong><?= mentora_h($client['name']) ?></strong>
                    <br><small><?= $client['department_name'] ? 'Department: ' . mentora_h($client['department_name']) : 'No department' ?></small>
                    <?php if ($client['website']): ?><br><small><?= mentora_external_link($client['website'], 'Website') ?></small><?php endif; ?></td>
                <td><?= $client['owner_name'] ? mentora_h($client['owner_name']) : '<span class="badge warn">No owner</span>' ?></td>
                <td><?= mentora_h($client['contact_name'] ?? '') ?>
                    <?php if ($client['contact_email']): ?><br><small><a href="mailto:<?= mentora_h($client['contact_email']) ?>"><?= mentora_h($client['contact_email']) ?></a></small><?php endif; ?>
                    <?php if ($client['contact_phone']): ?><br><small><?= mentora_h($client['contact_phone']) ?></small><?php endif; ?>
                    <?php if (!$client['contact_name'] && !$client['contact_email'] && !$client['contact_phone']): ?><span class="panel-subtitle">—</span><?php endif; ?></td>
                <td><small><?= mentora_h(mb_strimwidth((string) ($client['service_scope'] ?? ''), 0, 140, '…')) ?: '—' ?></small></td>
                <td><?= mentora_external_link($client['shared_folder_url'], 'Open folder') ?></td>
                <td><a href="tasks.php?client=<?= (int) $client['id'] ?>"><?= (int) $client['open_tasks'] ?> open task<?= (int) $client['open_tasks'] === 1 ? '' : 's' ?></a>
                    <?php if ((int) $client['overdue_tasks'] > 0): ?><br><span class="badge danger"><?= (int) $client['overdue_tasks'] ?> overdue</span><?php endif; ?></td>
                <td><?= mentora_work_badge($client['status']) ?></td>
                <td class="row-actions">
                    <button class="admin-btn" type="button" data-record-open="#client-dialog"
                        data-record="<?= mentora_h(json_encode($record)) ?>" data-record-title="Edit <?= mentora_h($client['name']) ?>">Edit</button>
                    <?php if ($isAdmin): ?>
                        <form method="post"><?= mentora_csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $client['id'] ?>">
                            <button class="admin-btn" type="submit" data-confirm-title="Delete <?= mentora_h($client['name']) ?>?"
                                data-confirm="A client that still has tasks can't be deleted. This can't be undone." data-confirm-tone="danger" data-confirm-action="Delete">Delete</button></form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>

<dialog class="dialog dialog-slide-down work-dialog" id="client-dialog" aria-labelledby="client-dialog-title"
    data-add-title="Add a client" data-add-save="Add client" data-edit-save="Save changes">
    <form method="post">
        <?= mentora_csrf_field() ?>
        <input type="hidden" name="action" value="add">
        <input type="hidden" name="id" value="" data-record-clear>
        <div class="dialog-header">
            <h1 class="dialog-title" id="client-dialog-title" data-record-heading>Add a client</h1>
            <button type="button" class="btn-close" data-bs-dismiss="dialog" aria-label="Close"></button>
        </div>
        <div class="dialog-body">
            <div class="form-grid">
                <div class="field full"><label for="client-name">Client name *</label><input id="client-name" name="name" required maxlength="160"></div>
                <div class="field"><label for="client-owner">Mentora owner</label><select id="client-owner" name="owner_id"><?= mentora_select_options($staff, null, 'No owner yet') ?></select></div>
                <div class="field"><label for="client-status">Status</label><select id="client-status" name="status"><?= mentora_select_options(MENTORA_CLIENT_STATUSES, 'active') ?></select></div>
                <div class="field"><label for="client-department">Client department</label><select id="client-department" name="department_id"><?= mentora_select_options($departments, null, 'None') ?></select></div>
                <div class="field"><label for="client-website">Website</label><input id="client-website" name="website" maxlength="500" inputmode="url" placeholder="https://"></div>
                <div class="field"><label for="client-contact">Contact person</label><input id="client-contact" name="contact_name" maxlength="120"></div>
                <div class="field"><label for="client-email">Contact email</label><input id="client-email" name="contact_email" type="email" maxlength="190"></div>
                <div class="field"><label for="client-phone">Contact phone</label><input id="client-phone" name="contact_phone" maxlength="40" inputmode="tel"></div>
                <div class="field"><label for="client-folder">Shared folder</label><input id="client-folder" name="shared_folder_url" maxlength="500" inputmode="url" placeholder="https://drive.google.com/…"></div>
                <div class="field full"><label for="client-scope">Service scope</label><textarea id="client-scope" name="service_scope" rows="3" maxlength="5000" placeholder="What Mentora does for this client"></textarea></div>
                <div class="field full"><label for="client-notes">Notes</label><textarea id="client-notes" name="notes" rows="2" maxlength="5000" placeholder="Optional"></textarea></div>
            </div>
        </div>
        <div class="dialog-footer">
            <button type="button" class="btn-solid theme-secondary" data-bs-dismiss="dialog">Cancel</button>
            <button type="submit" class="btn-solid theme-primary" data-record-save>Add client</button>
        </div>
    </form>
</dialog>
<script>
    // Editing sends "update"; adding sends "add".
    document.getElementById('client-dialog').querySelector('form').addEventListener('submit', (event) => {
        const form = event.currentTarget;
        form.elements.namedItem('action').value = form.elements.namedItem('id').value ? 'update' : 'add';
    });
</script>
<script src="<?= $assetBase ?>assets/js/record-dialog.js?v=1"></script>
<?php require __DIR__ . '/../admin_footer.php'; ?>
