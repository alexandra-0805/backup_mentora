<?php

/**
 * Tasks & deliverables: one row per piece of work, for one client, with one owner.
 *   admin/tasks.php, agent/tasks.php ($workspace 'admin' / 'agent'): add, assign, edit, delete; filter by client,
 *     owner and status (?client=ID opens filtered, e.g. from Clients). The new owner gets a notification.
 *   applicant/{intern,employee}/tasks.php ($workspace 'applicant', $pathway): "My tasks", the person's own, where
 *     they update the status and the deliverable link.
 * A task set to Done gets its completion time; setting it back clears it. See includes/client_work.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/../client_work.php';
require_once __DIR__ . '/../work_rules.php';

$requestedWorkspace = (string) ($workspace ?? ''); // set by the wrapper page (admin/, agent/ or applicant/…/tasks.php)
$workspace = in_array($requestedWorkspace, ['admin', 'agent', 'applicant'], true) ? $requestedWorkspace : 'agent';
$isStaff = $workspace !== 'applicant';
$folder = mentora_pathway_folder($pathway ?? 'employee');
$self = $isStaff ? $workspace . '/tasks.php' : 'applicant/' . $folder . '/tasks.php';

/** Done gets a completion time (kept if it already had one); anything else clears it. */
$completedSql = "completed_at = CASE WHEN status = 'done' THEN COALESCE(completed_at, NOW()) ELSE NULL END";

if (mentora_is_post()) {
    $me = mentora_post_user($isStaff ? ['admin', 'agent'] : ['applicant'], $self);
    $userId = (int) $me['id'];
    $action = (string) ($_POST['action'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);
    $status = array_key_exists((string) ($_POST['status'] ?? ''), MENTORA_TASK_STATUSES) ? (string) $_POST['status'] : 'to_do';
    $error = null;
    $deliverable = mentora_clean_url((string) ($_POST['deliverable_url'] ?? ''), $error);

    if (!$isStaff) {
        // My tasks: only the status and the deliverable link of your own task.
        $mine = $pdo->prepare('SELECT 1 FROM client_tasks WHERE id = ? AND owner_id = ?');
        $mine->execute([$id, $userId]);
        if ($error !== null) {
            mentora_flash($error, 'error');
        } elseif ($action !== 'update_mine' || !$mine->fetchColumn()) {
            mentora_flash('That task isn’t one of yours.', 'error');
        } else {
            $pdo->prepare('UPDATE client_tasks SET status = ?, deliverable_url = ? WHERE id = ? AND owner_id = ?')->execute([$status, $deliverable, $id, $userId]);
            $pdo->prepare("UPDATE client_tasks SET $completedSql WHERE id = ?")->execute([$id]);
            mentora_flash('Task updated.');
        }
        mentora_redirect($self);
    }

    if ($action === 'delete') {
        $pdo->prepare('DELETE FROM client_tasks WHERE id = ?')->execute([$id]);
        mentora_flash('Task deleted.');
        mentora_redirect($self);
    }

    // add / update
    $clientId = (int) ($_POST['client_id'] ?? 0);
    $ownerId = (int) ($_POST['owner_id'] ?? 0) ?: null;
    $title = mb_substr(trim((string) ($_POST['title'] ?? '')), 0, 190);
    $priority = array_key_exists((string) ($_POST['priority'] ?? ''), MENTORA_TASK_PRIORITIES) ? (string) $_POST['priority'] : 'normal';
    $dueText = trim((string) ($_POST['due_date'] ?? ''));
    $due = $dueText === '' ? null : mentora_clean_date($dueText);
    $clients = mentora_client_options($pdo);
    $people = mentora_task_people($pdo);
    $error = $error ?? match (true) {
        !isset($clients[$clientId]) => 'Please choose the client.',
        $title === '' => 'Please enter what the task is.',
        $ownerId !== null && !isset($people[$ownerId]) => 'Choose an active person as the owner.',
        $dueText !== '' && $due === null => 'Please choose a valid due date.',
        default => null,
    };
    if ($error !== null) {
        mentora_flash($error, 'error');
        mentora_redirect($self);
    }
    $values = [$clientId, $ownerId, $title, mb_substr(trim((string) ($_POST['description'] ?? '')), 0, 5000) ?: null,
        $deliverable, $status, $priority, $due];
    if ($action === 'update') {
        $previous = $pdo->prepare('SELECT owner_id FROM client_tasks WHERE id = ?');
        $previous->execute([$id]);
        $previousOwner = $previous->fetchColumn();
        $pdo->prepare('UPDATE client_tasks SET client_id = ?, owner_id = ?, title = ?, description = ?, deliverable_url = ?, status = ?,
            priority = ?, due_date = ? WHERE id = ?')->execute([...$values, $id]);
        $pdo->prepare("UPDATE client_tasks SET $completedSql WHERE id = ?")->execute([$id]);
        if ($ownerId !== null && (int) $previousOwner !== $ownerId) {
            mentora_notify_task_owner($pdo, $ownerId, $userId, $title, $clients[$clientId]);
        }
        mentora_flash('Task updated.');
    } else {
        $pdo->prepare('INSERT INTO client_tasks (client_id, owner_id, title, description, deliverable_url, status, priority, due_date, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([...$values, $userId]);
        $pdo->prepare("UPDATE client_tasks SET $completedSql WHERE id = ?")->execute([(int) $pdo->lastInsertId()]);
        if ($ownerId !== null) {
            mentora_notify_task_owner($pdo, $ownerId, $userId, $title, $clients[$clientId]);
        }
        mentora_flash('Task added' . ($ownerId !== null && $ownerId !== $userId ? ' and ' . $people[$ownerId]['name'] . ' was notified.' : '.'));
    }
    mentora_redirect($self);
}

if ($isStaff) {
    $pageTitle = 'Tasks & deliverables';
    // Agents open it from the Training tracker button (it isn't in their sidebar), so that stays highlighted.
    $activePage = $workspace === 'agent' ? 'training_progress.php' : 'tasks.php';
    require __DIR__ . '/../' . $workspace . '_layout.php';
    $assetBase = '../';
} else {
    mentora_require_login(['applicant']);
    $pageTitle = 'My tasks';
    $activePage = 'tasks.php';
    require __DIR__ . '/../applicant_layout.php';
    $assetBase = '../../';
}

$meId = (int) ($_SESSION['user']['id'] ?? 0);
$query = $pdo->prepare("SELECT t.*, c.name AS client_name, c.shared_folder_url, CONCAT(o.first_name, ' ', o.last_name) AS owner_name
    FROM client_tasks t
    JOIN clients c ON c.id = t.client_id
    LEFT JOIN users o ON o.id = t.owner_id
    " . ($isStaff ? '' : 'WHERE t.owner_id = ?') . "
    ORDER BY t.status = 'done', t.due_date IS NULL, t.due_date, FIELD(t.priority, 'urgent', 'high', 'normal', 'low'), t.id DESC");
$query->execute($isStaff ? [] : [$meId]);
$tasks = $query->fetchAll();
$today = date('Y-m-d');
$weekEnd = date('Y-m-d', strtotime('+7 days'));
$open = array_filter($tasks, static fn(array $t): bool => $t['status'] !== 'done');
$overdue = array_filter($open, static fn(array $t): bool => $t['due_date'] !== null && $t['due_date'] < $today);
$dueSoon = array_filter($open, static fn(array $t): bool => $t['due_date'] !== null && $t['due_date'] >= $today && $t['due_date'] <= $weekEnd);
$doneWeek = array_filter($tasks, static fn(array $t): bool => $t['completed_at'] !== null && $t['completed_at'] >= date('Y-m-d', strtotime('-7 days')));
$dueText = static function (array $t) use ($today): string {
    if ($t['due_date'] === null) {
        return '<span class="panel-subtitle">—</span>';
    }
    $text = mentora_h(date('d M Y', strtotime($t['due_date'])));
    return $t['status'] !== 'done' && $t['due_date'] < $today ? $text . '<br><span class="badge danger">Overdue</span>' : $text;
};
$clients = $isStaff ? mentora_client_options($pdo) : [];
$people = $isStaff ? mentora_task_people($pdo) : [];
$ownerOptions = [];
foreach ($people as $personId => $person) {
    $ownerOptions[$personId] = $person['name'] . ' · ' . $person['label'];
}
$filterClient = (int) ($_GET['client'] ?? 0);
?>
<div class="admin-heading">
    <div>
        <h1><?= $isStaff ? 'Tasks &amp; deliverables' : 'My tasks' ?></h1>
        <p><?= $isStaff
            ? 'Every piece of client work, who owns it, when it’s due and where the deliverable is.'
            : 'The client work given to you. Update the status as you go and add the link to your deliverable.' ?></p>
    </div>
    <?php if ($isStaff): ?>
        <div class="admin-actions">
            <a class="admin-btn" href="clients.php">Clients</a>
            <button class="admin-btn primary" type="button" data-record-open="#task-dialog"<?= $clients ? '' : ' disabled title="Add a client first"' ?>>+ Add task</button>
        </div>
    <?php endif; ?>
</div>
<?= mentora_flash_html() ?>
<?= mentora_work_rules($isStaff) // Starting rules (includes/work_rules.php) ?>
<div class="metric-grid">
    <div class="metric-card"><small>Open</small><strong><?= count($open) ?></strong><span>Not done yet</span></div>
    <div class="metric-card"><small>Due in 7 days</small><strong><?= count($dueSoon) ?></strong><span>Coming up</span></div>
    <div class="metric-card"><small>Overdue</small><strong><?= count($overdue) ?></strong><span><?= $overdue ? 'Past their due date' : 'Nothing late' ?></span></div>
    <div class="metric-card"><small>Done this week</small><strong><?= count($doneWeek) ?></strong><span>Last 7 days</span></div>
</div>

<section class="admin-panel data-panel">
    <div class="table-toolbar" data-table-filter="tasks-table">
        <input type="search" data-filter="search" placeholder="Search tasks" aria-label="Search tasks">
        <?php if ($isStaff): ?>
            <select data-filter="client" aria-label="Client"><?= mentora_select_options($clients, $filterClient ?: null, 'All clients') ?></select>
            <select data-filter="owner" aria-label="Owner"><?= mentora_select_options(['me' => 'My tasks', 'none' => 'No owner'] + array_map(static fn(array $p): string => $p['name'], $people), null, 'Everyone') ?></select>
        <?php endif; ?>
        <select data-filter="status" aria-label="Status"><?= mentora_select_options(MENTORA_TASK_STATUSES, null, 'All statuses') ?></select>
        <span class="panel-subtitle" data-filter-count="tasks-table"></span>
    </div>
    <table class="admin-table" id="tasks-table">
        <thead>
            <tr><th>Task</th><th>Client</th><?php if ($isStaff): ?><th>Owner</th><?php endif; ?><th>Status</th><th>Priority</th><th>Due</th><th>Deliverable</th><th><?= $isStaff ? 'Actions' : 'Update' ?></th></tr>
        </thead>
        <tbody>
        <?php if (!$tasks): ?>
            <tr><td colspan="<?= $isStaff ? 8 : 7 ?>" class="panel-subtitle"><?= $isStaff ? 'No tasks yet. Add the first one.' : 'No tasks have been given to you yet.' ?></td></tr>
        <?php endif; ?>
        <?php foreach ($tasks as $task):
            $ownerKey = $task['owner_id'] === null ? 'none' : ((int) $task['owner_id'] === $meId ? 'me' : (string) (int) $task['owner_id']);
            $record = array_intersect_key($task, array_flip(['id', 'client_id', 'owner_id', 'title', 'description', 'deliverable_url', 'status', 'priority', 'due_date']));
        ?>
            <tr data-status="<?= mentora_h($task['status']) ?>" data-client="<?= (int) $task['client_id'] ?>" data-owner="<?= mentora_h($ownerKey) ?>">
                <td><strong><?= mentora_h($task['title']) ?></strong>
                    <?php if ($task['description']): ?><br><small><?= mentora_h(mb_strimwidth((string) $task['description'], 0, 160, '…')) ?></small><?php endif; ?></td>
                <td><?= mentora_h($task['client_name']) ?>
                    <?php if ($task['shared_folder_url']): ?><br><small><?= mentora_external_link($task['shared_folder_url'], 'Shared folder') ?></small><?php endif; ?></td>
                <?php if ($isStaff): ?>
                    <td><?= $task['owner_name'] ? mentora_h($task['owner_name']) : '<span class="badge warn">No owner</span>' ?></td>
                <?php endif; ?>
                <td><?= mentora_work_badge($task['status']) ?>
                    <?php if ($task['completed_at']): ?><br><small>Done <?= mentora_h(date('d M', strtotime($task['completed_at']))) ?></small><?php endif; ?></td>
                <td><?= mentora_work_badge($task['priority']) ?></td>
                <td><?= $dueText($task) ?></td>
                <td><?= mentora_external_link($task['deliverable_url'], 'Open') ?></td>
                <td class="row-actions">
                    <?php if ($isStaff): ?>
                        <button class="admin-btn" type="button" data-record-open="#task-dialog"
                            data-record="<?= mentora_h(json_encode($record)) ?>" data-record-title="Edit task">Edit</button>
                        <form method="post"><?= mentora_csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $task['id'] ?>">
                            <button class="admin-btn" type="submit" data-confirm-title="Delete this task?" data-confirm="“<?= mentora_h($task['title']) ?>” will be removed. This can’t be undone." data-confirm-tone="danger" data-confirm-action="Delete">Delete</button></form>
                    <?php else: ?>
                        <form method="post" class="task-update-form">
                            <?= mentora_csrf_field() ?><input type="hidden" name="action" value="update_mine"><input type="hidden" name="id" value="<?= (int) $task['id'] ?>">
                            <select name="status" aria-label="Status of <?= mentora_h($task['title']) ?>"><?= mentora_select_options(MENTORA_TASK_STATUSES, $task['status']) ?></select>
                            <input name="deliverable_url" maxlength="500" inputmode="url" placeholder="Deliverable link" value="<?= mentora_h($task['deliverable_url'] ?? '') ?>" aria-label="Deliverable link">
                            <button class="admin-btn primary" type="submit">Save</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>

<?php if ($isStaff): ?>
<dialog class="dialog dialog-slide-down work-dialog" id="task-dialog" aria-labelledby="task-dialog-title"
    data-add-title="Add a task" data-add-save="Add task" data-edit-save="Save changes">
    <form method="post">
        <?= mentora_csrf_field() ?>
        <input type="hidden" name="action" value="add">
        <input type="hidden" name="id" value="" data-record-clear>
        <div class="dialog-header">
            <h1 class="dialog-title" id="task-dialog-title" data-record-heading>Add a task</h1>
            <button type="button" class="btn-close" data-bs-dismiss="dialog" aria-label="Close"></button>
        </div>
        <div class="dialog-body">
            <div class="form-grid">
                <div class="field full"><label for="task-title">Task *</label><input id="task-title" name="title" required maxlength="190" placeholder="e.g. March social media calendar"></div>
                <div class="field"><label for="task-client">Client *</label><select id="task-client" name="client_id" required><?= mentora_select_options($clients, $filterClient ?: null, 'Choose a client') ?></select></div>
                <div class="field"><label for="task-owner">Owner</label><select id="task-owner" name="owner_id"><?= mentora_select_options($ownerOptions, null, 'No owner yet') ?></select></div>
                <div class="field"><label for="task-status">Status</label><select id="task-status" name="status"><?= mentora_select_options(MENTORA_TASK_STATUSES, 'to_do') ?></select></div>
                <div class="field"><label for="task-priority">Priority</label><select id="task-priority" name="priority"><?= mentora_select_options(MENTORA_TASK_PRIORITIES, 'normal') ?></select></div>
                <div class="field"><label for="task-due">Due date</label><input id="task-due" name="due_date" type="date"></div>
                <div class="field"><label for="task-deliverable">Deliverable link</label><input id="task-deliverable" name="deliverable_url" maxlength="500" inputmode="url" placeholder="https://"></div>
                <div class="field full"><label for="task-description">Details</label><textarea id="task-description" name="description" rows="3" maxlength="5000" placeholder="What needs to be done, and what “done” looks like"></textarea></div>
            </div>
            <p class="panel-subtitle" data-record-only="add">The owner gets a notification with a link to their tasks.</p>
        </div>
        <div class="dialog-footer">
            <button type="button" class="btn-solid theme-secondary" data-bs-dismiss="dialog">Cancel</button>
            <button type="submit" class="btn-solid theme-primary" data-record-save>Add task</button>
        </div>
    </form>
</dialog>
<script>
    document.getElementById('task-dialog').querySelector('form').addEventListener('submit', (event) => {
        const form = event.currentTarget;
        form.elements.namedItem('action').value = form.elements.namedItem('id').value ? 'update' : 'add';
    });
    // Opened for one client (?client=ID, e.g. from Clients): apply that filter once the table filter is ready.
    window.addEventListener('load', () => {
        const client = document.querySelector('[data-filter="client"]');
        if (client && client.value) {
            client.dispatchEvent(new Event('change', { bubbles: true }));
            client.dispatchEvent(new Event('input', { bubbles: true }));
        }
    });
</script>
<script src="<?= $assetBase ?>assets/js/record-dialog.js?v=1"></script>
<?php endif; ?>
<?php require __DIR__ . '/../' . ($isStaff ? 'admin_footer.php' : 'applicant_footer.php'); ?>
