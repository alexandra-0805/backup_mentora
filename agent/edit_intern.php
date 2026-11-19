<?php

/**
 * Edit an intern (agents and HR): name, school details, team (department), supervising agent,
 * finished training modules, hold status and notes. The intern is notified when their team or agent changes.
 *   edit_intern.php           pick an intern
 *   edit_intern.php?id=12     edit one
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/workflow.php';

$findIntern = static function (PDO $pdo, int $id): ?array {
    $person = $id > 0 ? mentora_person($pdo, $id) : null;
    return $person && $person['role'] === 'applicant' && $person['status'] === 'active' && $person['pathway'] === 'internship' ? $person : null;
};

if (mentora_is_post()) {
    $id = (int) ($_POST['id'] ?? 0);
    $me = mentora_post_user(['agent', 'admin'], 'agent/edit_intern.php?id=' . $id);
    $intern = $findIntern($pdo, $id);
    if (!$intern) {
        mentora_flash('That intern couldn’t be found.', 'error');
        mentora_redirect('agent/intern_directory.php');
    }
    $first = mb_substr(trim((string) ($_POST['first_name'] ?? '')), 0, 80);
    $last = mb_substr(trim((string) ($_POST['last_name'] ?? '')), 0, 80);
    $hours = (int) ($_POST['required_hours'] ?? 0);
    $departmentId = (int) ($_POST['department_id'] ?? 0) ?: null;
    $agentId = (int) ($_POST['agent_id'] ?? 0) ?: null;
    $validDepartment = $departmentId === null || in_array($departmentId, array_map('intval', array_column(mentora_departments($pdo), 'id')), true);
    $validAgent = $agentId === null || in_array($agentId, array_map('intval', array_column(mentora_active_agents($pdo), 'id')), true);
    $error = match (true) {
        $first === '' || $last === '' => 'First and last name are required.',
        $hours < 1 || $hours > 5000 => 'Required hours must be between 1 and 5000.',
        !$validDepartment || !$validAgent => 'Please choose a team and agent from the list.',
        default => null,
    };
    if ($error !== null) {
        mentora_flash($error, 'error');
        mentora_redirect('agent/edit_intern.php?id=' . $id);
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE users SET first_name = ?, last_name = ?, department_id = ?, agent_id = ? WHERE id = ?')
            ->execute([$first, $last, $departmentId, $agentId, $id]);
        $school = mb_substr(trim((string) ($_POST['school'] ?? '')), 0, 190);
        $course = mb_substr(trim((string) ($_POST['course'] ?? '')), 0, 190);
        $latest = $pdo->prepare('SELECT MAX(id) FROM applications WHERE user_id = ?');
        $latest->execute([$id]);
        $applicationId = (int) $latest->fetchColumn();
        if ($applicationId > 0) {
            $pdo->prepare('UPDATE applications SET school = ?, course = ?, required_hours = ? WHERE id = ?')->execute([$school ?: null, $course ?: null, $hours, $applicationId]);
        } else {
            $pdo->prepare('INSERT INTO applications (user_id, school, course, required_hours) VALUES (?, ?, ?, ?)')->execute([$id, $school ?: null, $course ?: null, $hours]);
        }
        // Finished modules = the boxes ticked.
        $modules = mentora_training_modules('internship');
        $ticked = array_values(array_intersect(array_keys($modules), (array) ($_POST['modules'] ?? [])));
        $pdo->prepare('DELETE FROM training_progress WHERE user_id = ?')->execute([$id]);
        $insert = $pdo->prepare('INSERT INTO training_progress (user_id, module_key) VALUES (?, ?)');
        foreach ($ticked as $key) {
            $insert->execute([$id, $key]);
        }
        $onHold = ($_POST['training_status'] ?? '') === 'on_hold';
        $notes = mb_substr(trim((string) ($_POST['notes'] ?? '')), 0, 2000);
        $pdo->prepare("INSERT INTO training_records (user_id, status, notes) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE status = VALUES(status), notes = VALUES(notes)")
            ->execute([$id, $onHold ? 'on_hold' : 'in_training', $notes !== '' ? $notes : null]);
        mentora_sync_training($pdo, $id, 'internship');

        if ((int) $intern['department_id'] !== (int) $departmentId || (int) $intern['agent_id'] !== (int) $agentId) {
            mentora_notify($pdo, $id, 'Your team assignment was updated', 'See your team and supervising agent in your workspace.', 'applicant/intern/team-agent.php');
        }
        if ($onHold && ($intern['training_status'] ?? '') !== 'on_hold') {
            mentora_notify($pdo, $id, 'Your training is on hold', $notes !== '' ? $notes : 'Please contact your supervising agent.', 'applicant/intern/training.php');
        }
        if ($agentId !== null && $agentId !== (int) $intern['agent_id'] && $agentId !== (int) $me['id']) {
            mentora_notify($pdo, $agentId, 'New intern assigned: ' . $first . ' ' . $last, 'You are now their supervising agent.', 'agent/edit_intern.php?id=' . $id);
        }
        $pdo->commit();
        mentora_flash($first . ' ' . $last . '’s details were saved.');
    } catch (Throwable $exception) {
        $pdo->rollBack();
        error_log('Mentora edit intern failed: ' . $exception->getMessage());
        mentora_flash('The changes couldn’t be saved. Please try again.', 'error');
    }
    mentora_redirect('agent/edit_intern.php?id=' . $id);
}

$pageTitle = 'Edit intern';
$activePage = 'edit_intern.php';
require __DIR__ . '/../includes/agent_layout.php';

$id = (int) ($_GET['id'] ?? 0);
if ($id === 0 && isset($_GET['email'])) { // old links used ?email=
    $query = $pdo->prepare('SELECT id FROM users WHERE email = ?');
    $query->execute([(string) $_GET['email']]);
    $id = (int) $query->fetchColumn();
}
$intern = $findIntern($pdo, $id);
?>
<div class="admin-heading"><div><h1>Edit intern</h1><p>Update intern information, training progress and the team assignment.</p></div><a class="admin-btn" href="intern_directory.php">← Back to interns</a></div>
<?= mentora_flash_html() ?>
<?php if (!$intern):
    $interns = mentora_workspace_people($pdo); ?>
    <section class="admin-panel data-panel">
        <h2>Choose an intern</h2>
        <p class="panel-subtitle"><?= $id ? 'That intern couldn’t be found. ' : '' ?>Select who you want to update.</p>
        <div class="table-toolbar" data-table-filter="pick-intern"><input type="search" data-filter="search" placeholder="Search name or email" aria-label="Search interns"></div>
        <table class="admin-table" id="pick-intern"><thead><tr><th>Intern</th><th>Team</th><th>Training</th><th>Action</th></tr></thead><tbody>
            <?php foreach ($interns as $row): ?>
                <tr><td><?= mentora_h($row['name']) ?><br><small><?= mentora_h($row['email']) ?></small></td><td><?= mentora_h($row['department_name'] ?? '') ?: '—' ?></td><td><?= $row['completed'] ?>/<?= $row['total'] ?></td><td><a class="admin-btn primary" href="edit_intern.php?id=<?= (int) $row['id'] ?>">Edit</a></td></tr>
            <?php endforeach; ?>
        </tbody></table>
        <?php if (!$interns): ?><p class="table-note">No active interns yet. Approved applicants appear here once they create their account.</p><?php endif; ?>
    </section>
<?php else:
    $doneQuery = $pdo->prepare('SELECT module_key FROM training_progress WHERE user_id = ?');
    $doneQuery->execute([$intern['id']]);
    $doneModules = $doneQuery->fetchAll(PDO::FETCH_COLUMN);
    $record = $pdo->prepare('SELECT status, notes, hours_logged FROM training_records WHERE user_id = ?');
    $record->execute([$intern['id']]);
    $training = $record->fetch() ?: ['status' => 'in_training', 'notes' => '', 'hours_logged' => 0];
    $name = trim($intern['first_name'] . ' ' . $intern['last_name']);
?>
    <form method="post">
        <?= mentora_csrf_field() ?><input type="hidden" name="id" value="<?= (int) $intern['id'] ?>">
        <section class="admin-panel">
            <h2>Personal information</h2>
            <p class="panel-subtitle"><?= mentora_h($intern['user_code']) ?> · Fields marked with * are required.</p>
            <div class="form-grid">
                <div class="field"><label for="first_name">First name *</label><input id="first_name" name="first_name" required maxlength="80" value="<?= mentora_h($intern['first_name']) ?>"></div>
                <div class="field"><label for="last_name">Last name *</label><input id="last_name" name="last_name" required maxlength="80" value="<?= mentora_h($intern['last_name']) ?>"></div>
                <div class="field full"><label>Email address</label><input value="<?= mentora_h($intern['email']) ?>" disabled><small>Email changes are handled by HR in Users &amp; access.</small></div>
                <div class="field"><label for="school">School / university</label><input id="school" name="school" maxlength="190" value="<?= mentora_h($intern['school'] ?? '') ?>"></div>
                <div class="field"><label for="course">Degree / program</label><input id="course" name="course" maxlength="190" value="<?= mentora_h($intern['course'] ?? '') ?>"></div>
                <div class="field"><label for="required_hours">Required hours *</label><input id="required_hours" name="required_hours" type="number" min="1" max="5000" required value="<?= mentora_required_hours($intern) ?>"></div>
                <div class="field"><label>Approved hours</label><input value="<?= mentora_hours($training['hours_logged']) ?> hrs (from approved reports)" disabled></div>
            </div>
        </section>
        <div class="admin-grid" style="margin-top:16px">
            <section class="admin-panel">
                <h2>Team assignment</h2>
                <div class="form-grid">
                    <div class="field"><label for="department_id">Team / department</label><select id="department_id" name="department_id"><option value="">Not assigned</option><?php foreach (mentora_departments($pdo) as $department): ?><option value="<?= (int) $department['id'] ?>"<?= (int) $intern['department_id'] === (int) $department['id'] ? ' selected' : '' ?>><?= mentora_h($department['name']) ?></option><?php endforeach; ?></select></div>
                    <div class="field"><label for="agent_id">Supervising agent</label><select id="agent_id" name="agent_id"><option value="">Not assigned</option><?php foreach (mentora_active_agents($pdo) as $agent): ?><option value="<?= (int) $agent['id'] ?>"<?= (int) $intern['agent_id'] === (int) $agent['id'] ? ' selected' : '' ?>><?= mentora_h($agent['first_name'] . ' ' . $agent['last_name']) ?></option><?php endforeach; ?></select></div>
                    <div class="field"><label for="training_status">Training status *</label><select id="training_status" name="training_status"><option value="in_training">Active (completes when all modules are done)</option><option value="on_hold"<?= $training['status'] === 'on_hold' ? ' selected' : '' ?>>On hold</option></select></div>
                    <div class="field full"><label for="notes">Intern notes</label><textarea id="notes" name="notes" rows="4" maxlength="2000" placeholder="Shown to the intern if their training is on hold"><?= mentora_h($training['notes'] ?? '') ?></textarea></div>
                </div>
            </section>
            <section class="admin-panel">
                <h2>Training modules</h2>
                <p class="panel-subtitle">Tick the modules <?= mentora_h($intern['first_name']) ?> has finished.</p>
                <?php foreach (mentora_training_modules('internship') as $key => [$title, $duration]): ?>
                    <label class="check-row"><input type="checkbox" name="modules[]" value="<?= mentora_h($key) ?>"<?= in_array($key, $doneModules, true) ? ' checked' : '' ?>> <span><?= mentora_h($title) ?> <small>· <?= $duration ?> hr</small></span></label>
                <?php endforeach; ?>
            </section>
        </div>
        <div class="form-actions">
            <a class="admin-btn" href="intern_directory.php">Cancel</a>
            <a class="admin-btn" href="../account/messages.php?to=<?= (int) $intern['id'] ?>">Message intern</a>
            <a class="admin-btn" href="evaluation_list.php?intern=<?= (int) $intern['id'] ?>#create">Create evaluation</a>
            <button class="admin-btn primary" type="submit" data-confirm-title="Save changes to <?= mentora_h($name) ?>?" data-confirm="The intern's details, team, training and status will be updated.">Save changes</button>
        </div>
    </form>
<?php endif; ?>
<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
