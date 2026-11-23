<?php

/**
 * One account (HR admins), opened from the people lists and Users & access.
 *   user_edit.php?id=12          view the person's records and edit name, email, role, pathway,
 *                                department, supervising agent and access (active / turned off)
 *   user_edit.php?new=1&role=agent   invite someone: creates the account and a one-time link to set
 *                                their username and password (emailed when mail is set up)
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/workflow.php';
require_once __DIR__ . '/../includes/account_invites.php';
require_once __DIR__ . '/../includes/invite_panel.php';
require_once __DIR__ . '/../includes/training_certificate.php';

$roles = ['applicant' => 'Intern / employee', 'agent' => 'Agent', 'admin' => 'HR administrator'];
$pathways = ['internship' => 'Internship', 'employee_va' => 'Employee VA'];

/** Send a fresh invite link to $user and keep the result for the panel. */
$sendInvite = static function (PDO $pdo, array $user, array $admin, bool $resent): void {
    $link = mentora_create_invite($pdo, (int) $user['id'], (int) $admin['id'], $code);
    $fullName = trim($user['first_name'] . ' ' . $user['last_name']);
    $mail = mentora_mail_enabled()
        ? mentora_send_invite_email($user['email'], $fullName, $user['first_name'], $user['pathway'], $link, $admin['name'], $admin['email'], $user['role'], (string) $code)
        : ['ok' => false, 'error' => '', 'not_configured' => true];
    $_SESSION['user_invite'] = [
        'user_id' => (int) $user['id'], 'name' => $user['first_name'], 'full_name' => $fullName, 'email' => $user['email'], 'pathway' => $user['pathway'],
        'role' => $user['role'], 'link' => $link, 'resent' => $resent, 'emailed' => $mail['ok'],
        'mail_error' => $mail['error'], 'mail_not_configured' => !empty($mail['not_configured']),
    ];
};

if (mentora_is_post()) {
    $id = (int) ($_POST['id'] ?? 0);
    $back = $id > 0 ? 'admin/user_edit.php?id=' . $id : 'admin/user_edit.php?new=1';
    $admin = mentora_post_user(['admin'], $back);
    $action = (string) ($_POST['action'] ?? '');

    $first = mb_substr(trim((string) ($_POST['first_name'] ?? '')), 0, 80);
    $last = mb_substr(trim((string) ($_POST['last_name'] ?? '')), 0, 80);
    $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
    $role = (string) ($_POST['role'] ?? 'applicant');
    $pathway = (string) ($_POST['pathway'] ?? 'employee_va');
    $departmentId = (int) ($_POST['department_id'] ?? 0) ?: null;
    $agentId = (int) ($_POST['agent_id'] ?? 0) ?: null;
    $validDepartment = $departmentId === null || in_array($departmentId, array_map('intval', array_column(mentora_departments($pdo), 'id')), true);
    $validAgent = $agentId === null || in_array($agentId, array_map('intval', array_column(mentora_active_agents($pdo), 'id')), true);
    // One account per email (Gmail lookalikes such as dots or "+tags" count as the same email).
    $emailTaken = static fn(PDO $pdo, string $email, int $exceptId): bool => mentora_find_account_by_email($pdo, $email, $exceptId) !== null;
    $formError = match (true) {
        !in_array($action, ['save', 'invite'], true) => null,
        $first === '' || $last === '' => 'First and last name are required.',
        !filter_var($email, FILTER_VALIDATE_EMAIL) => 'Please enter a valid email address.',
        $emailTaken($pdo, $email, $id) => 'Another account already uses that email.',
        !isset($roles[$role]) || !isset($pathways[$pathway]) => 'Please choose a role and pathway.',
        !$validDepartment || !$validAgent => 'Please choose a department and agent from the list.',
        default => null,
    };
    if ($formError !== null) {
        mentora_flash($formError, 'error');
        mentora_redirect($back);
    }
    if ($role !== 'applicant' || $pathway !== 'internship') {
        $agentId = null; // only interns have a supervising agent
    }

    if ($action === 'invite') {
        $pdo->prepare("INSERT INTO users (first_name, last_name, email, pathway, password_hash, role, status, department_id, agent_id)
            VALUES (?, ?, ?, ?, ?, ?, 'approved', ?, ?)")
            ->execute([$first, $last, $email, $pathway, password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT), $role, $departmentId, $agentId]);
        $newId = (int) $pdo->lastInsertId();
        $sendInvite($pdo, mentora_person($pdo, $newId), $admin, false);
        mentora_redirect('admin/user_edit.php?id=' . $newId);
    }

    $user = $id > 0 ? mentora_person($pdo, $id) : null;
    if (!$user) {
        mentora_flash('That account couldn’t be found.', 'error');
        mentora_redirect('admin/user&access_management.php');
    }
    $isSelf = (int) $user['id'] === (int) $admin['id'];

    if ($action === 'save') {
        if ($isSelf && $role !== 'admin') {
            mentora_flash('You can’t remove your own HR admin role.', 'error');
            mentora_redirect($back);
        }
        $pdo->prepare('UPDATE users SET first_name = ?, last_name = ?, email = ?, role = ?, pathway = ?, department_id = ?, agent_id = ? WHERE id = ?')
            ->execute([$first, $last, $email, $role, $pathway, $departmentId, $agentId, $id]);
        if ($role === 'applicant' && $user['status'] === 'active' && ((int) $user['department_id'] !== (int) $departmentId || (int) $user['agent_id'] !== (int) $agentId)) {
            $folder = mentora_pathway_folder($pathway);
            mentora_notify(
                $pdo,
                $id,
                $folder === 'intern' ? 'Your team assignment was updated' : 'Your department was updated',
                'Open your workspace to see the details.',
                'applicant/' . $folder . ($folder === 'intern' ? '/team-agent.php' : '/department.php')
            );
        }
        if ($agentId !== null && $agentId !== (int) $user['agent_id']) {
            mentora_notify(
                $pdo,
                $agentId,
                'New intern assigned: ' . $first . ' ' . $last,
                'You are now their supervising agent.',
                'agent/edit_intern.php?id=' . $id
            );
        }
        mentora_flash('Changes saved.');
    } elseif ($action === 'disable' || $action === 'enable') {
        if ($isSelf) {
            mentora_flash('You can’t turn off your own account.', 'error');
        } elseif ($action === 'disable' && $user['status'] === 'active') {
            $pdo->prepare("UPDATE users SET status = 'disabled' WHERE id = ?")->execute([$id]);
            mentora_flash(trim($user['first_name'] . ' ' . $user['last_name']) . ' can no longer sign in.');
        } elseif ($action === 'enable' && $user['status'] === 'disabled') {
            $pdo->prepare("UPDATE users SET status = 'active' WHERE id = ?")->execute([$id]);
            mentora_flash(trim($user['first_name'] . ' ' . $user['last_name']) . ' can sign in again.');
        }
    } elseif ($action === 'resend' && in_array($user['status'], ['pending', 'approved'], true)) {
        $sendInvite($pdo, $user, $admin, $user['status'] === 'approved');
    } elseif (in_array($action, ['upload_certificate', 'remove_certificate'], true) && $user['role'] === 'applicant') {
        [$ok, $message] = mentora_handle_certificate_post($pdo, $admin, $user, $action);
        mentora_flash($message, $ok ? 'success' : 'error');
        mentora_redirect($back . '#certificate');
    }
    mentora_redirect($back);
}

$isNew = isset($_GET['new']);
$user = $isNew ? null : mentora_person($pdo, (int) ($_GET['id'] ?? 0));
$pageTitle = $isNew ? 'Invite user' : 'User details';
$activePage = 'user&access_management.php';
require __DIR__ . '/../includes/admin_layout.php';

$invite = $_SESSION['user_invite'] ?? null;
unset($_SESSION['user_invite']);
$defaults = $user ?? [
    'first_name' => '', 'last_name' => '', 'email' => '',
    'role' => isset($roles[$_GET['role'] ?? '']) ? $_GET['role'] : 'agent',
    'pathway' => isset($pathways[$_GET['pathway'] ?? '']) ? $_GET['pathway'] : 'employee_va',
    'department_id' => null, 'agent_id' => null, 'status' => 'new',
];
$statusBadge = [
    'active' => ['', 'Active'],
    'pending' => ['warn', 'Waiting for confirmation'],
    'approved' => ['info', 'Link sent'],
    'rejected' => ['warn', 'Declined'],
    'disabled' => ['warn', 'Turned off']
];
?>
<div class="admin-heading keep-title">
    <div>
        <h1><?= $isNew ? 'Invite user' : ($user ? mentora_h(trim($user['first_name'] . ' ' . $user['last_name'])) : 'User not found') ?></h1>
        <p><?= $isNew ? 'Create an account and send a one-time link to set a username and password.' : 'Account details, access and workspace records.' ?></p>
    </div>
    <a class="admin-btn" href="user&amp;access_management.php">← Users &amp; access</a>
</div>
<?= mentora_flash_html() ?>
<?php if ($invite) { mentora_render_invite_panel($invite, $adminName); } ?>
<?php if (!$isNew && !$user): ?>
    <section class="admin-panel"><p>That account doesn’t exist anymore.</p></section>
<?php else:
    [$badgeClass, $badgeText] = $statusBadge[$defaults['status']] ?? ['info', 'New'];
    $isSelf = $user && (int) $user['id'] === (int) ($_SESSION['user']['id'] ?? 0);
?>
    <div class="admin-grid">
        <section class="admin-panel">
            <h2><?= $isNew ? 'New account' : 'Account' ?> <?php if (!$isNew): ?><span class="badge <?= $badgeClass ?>"><?= $badgeText ?></span><?php endif; ?>
            </h2>
            <p class="panel-subtitle"><?= $user
                ? mentora_h($user['user_code'] . ($user['username'] ? ' · @' . $user['username'] : '') . ' · created ' . date('d M Y', strtotime($user['created_at'])))
                : 'They’ll get an email with a link (or you can send it yourself).' ?></p>
            <form method="post" class="form-grid" data-role-form>
                <?= mentora_csrf_field() ?><input type="hidden"
                    name="id"
                    value="<?= (int) ($user['id'] ?? 0) ?>"><input type="hidden" name="action" value="<?= $isNew ? 'invite' : 'save' ?>">
                <div class="field">
                    <label for="first_name">First name *</label><input id="first_name" name="first_name" required maxlength="80" value="<?= mentora_h($defaults['first_name']) ?>"></div>
                <div class="field">
                    <label for="last_name">Last name *</label><input id="last_name" name="last_name" required maxlength="80" value="<?= mentora_h($defaults['last_name']) ?>"></div>
                <div class="field full">
                    <label for="email">Email *</label><input id="email"
                        name="email"
                        type="email"
                        required
                        maxlength="190"
                        value="<?= mentora_h($defaults['email']) ?>"></div>
                <div class="field">
                    <label for="role">Role *</label><select id="role" name="role"<?= $isSelf ? ' disabled' : '' ?>><?php foreach ($roles as $key => $label): ?>
                        <option value="<?= $key ?>"<?= $defaults['role'] === $key ? ' selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?></select><?php if ($isSelf): ?><input type="hidden" name="role" value="admin"><small>You can’t change your own role.</small><?php endif; ?>
                </div>
                <div class="field" data-for-role="applicant">
                    <label for="pathway">Pathway *</label><select id="pathway" name="pathway"><?php foreach ($pathways as $key => $label): ?>
                        <option value="<?= $key ?>"<?= $defaults['pathway'] === $key ? ' selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?></select>
                </div>
                <div class="field">
                    <label for="department_id">Department</label><select id="department_id" name="department_id">
                        <option value="">Not assigned</option>
                        <?php foreach (mentora_departments($pdo) as $department): ?>
                        <option value="<?= (int) $department['id'] ?>"<?= (int) $defaults['department_id'] === (int) $department['id'] ? ' selected' : '' ?>><?= mentora_h($department['name']) ?></option>
                        <?php endforeach; ?></select>
                </div>
                <div class="field" data-for-role="intern">
                    <label for="agent_id">Supervising agent</label><select id="agent_id" name="agent_id">
                        <option value="">Not assigned</option>
                        <?php foreach (mentora_active_agents($pdo) as $agent): ?>
                        <option value="<?= (int) $agent['id'] ?>"<?= (int) $defaults['agent_id'] === (int) $agent['id'] ? ' selected' : '' ?>><?= mentora_h($agent['first_name'] . ' ' . $agent['last_name']) ?></option>
                        <?php endforeach; ?></select>
                </div>
                <div class="field full form-actions">
                    <button class="admin-btn primary"
                        type="submit"
                        data-confirm-title="<?= $isNew ? 'Send the invitation?' : 'Save these changes?' ?>"
                        data-confirm="<?= $isNew
                            ? 'The account is created and a one-time link to set a username and password is sent.'
                            : 'The account details will be updated.' ?>"><?= $isNew ? 'Create & send invite' : 'Save changes' ?></button>
                </div>
            </form>
        </section>
        <?php if ($user): ?>
            <section class="admin-panel">
                <h2>Access</h2>
                <?php if (in_array($user['status'], ['pending', 'approved'], true)): ?>
                    <p class="panel-subtitle"><?= $user['status'] === 'pending'
                        ? 'Waiting for an HR decision.'
                        : 'They haven’t created their username and password yet.' ?></p>
                    <?php if ($user['role'] === 'applicant' && $user['status'] === 'pending'): ?><a class="admin-btn primary" href="applications.php?view=pending">Review in Applications</a><?php else: ?>
                    <form method="post"><?= mentora_csrf_field() ?><input type="hidden" name="id" value="<?= (int) $user['id'] ?>"><button class="admin-btn primary" type="submit" name="action" value="resend" data-confirm-title="Send a new link?" data-confirm="A new link replaces the old one, which stops working.">Send a new link</button>
                    </form>
                    <?php endif; ?>
                <?php elseif ($user['status'] === 'active'): ?>
                    <p class="panel-subtitle">Can sign in<?= $user['username'] ? ' as @' . mentora_h($user['username']) . ' or' : ' with' ?> their email.</p>
                    <?php if (!$isSelf): ?>
                    <form method="post"><?= mentora_csrf_field() ?><input type="hidden" name="id" value="<?= (int) $user['id'] ?>"><button class="admin-btn danger" type="submit" name="action" value="disable" data-confirm-title="Turn off this account?" data-confirm="<?= mentora_h($user['first_name']) ?> will be signed out and can’t sign in until you turn it back on. Their records are kept." data-confirm-tone="danger" data-confirm-action="Turn off">Turn off access</button>
                    </form>
                    <?php endif; ?>
                <?php elseif ($user['status'] === 'disabled'): ?>
                    <p class="panel-subtitle">Turned off: they can’t sign in. Their records are kept.</p>
                    <form method="post"><?= mentora_csrf_field() ?><input type="hidden" name="id" value="<?= (int) $user['id'] ?>"><button class="admin-btn primary" type="submit" name="action" value="enable" data-confirm-title="Turn this account back on?" data-confirm="<?= mentora_h($user['first_name']) ?> will be able to sign in again.">Turn access back on</button>
                    </form>
                <?php else: ?>
                    <p class="panel-subtitle">The application was declined. Reopen it in Applications.</p>
                    <a class="admin-btn" href="applications.php?view=rejected">Open Applications</a>
                <?php endif; ?>
                <?php if ($user['status'] === 'active' && !$isSelf): ?>
                <p><a class="admin-btn" href="../account/messages.php?to=<?= (int) $user['id'] ?>">Message <?= mentora_h($user['first_name']) ?></a>
                </p>
                <?php endif; ?>
                <?php if ($user['role'] === 'applicant'):
                    $records = $pdo->prepare("SELECT
                        (SELECT COALESCE(SUM(hours), 0) FROM work_reports WHERE user_id = ? AND status = 'approved') AS hours,
                        (SELECT COUNT(*) FROM work_reports WHERE user_id = ? AND status = 'submitted') AS waiting,
                        (SELECT COUNT(*) FROM training_progress WHERE user_id = ?) AS modules,
                        (SELECT score FROM evaluations WHERE user_id = ? AND status = 'published' ORDER BY published_at DESC LIMIT 1) AS score,
                        (SELECT COUNT(*) FROM attendance WHERE user_id = ? AND work_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) AND time_in IS NOT NULL) AS days");
                    $records->execute(array_fill(0, 5, $user['id']));
                    $r = $records->fetch(); ?>
                    <h2 style="margin-top:18px">Workspace records</h2>
                    <div class="notice-row">
                        <span>Application</span><strong><?= $user['applied_at']
                            ? 'Submitted ' . date('d M Y', strtotime($user['applied_at']))
                            : 'Not submitted' ?></strong>
                    </div>
                    <?php if ($user['school']): ?>
                    <div class="notice-row">
                        <span>School</span><strong><?= mentora_h($user['school']) ?></strong>
                    </div>
                    <?php endif; ?>
                    <div class="notice-row">
                        <span>Training modules</span><strong><?= (int) $r['modules'] ?> / <?= count(mentora_training_modules($user['pathway'])) ?></strong>
                    </div>
                    <div class="notice-row">
                        <span>Approved hours</span><strong><?= mentora_hours($r['hours']) ?><?= $user['pathway'] === 'internship' ? ' / ' . mentora_required_hours($user) : '' ?></strong>
                    </div>
                    <div class="notice-row"><span>Reports waiting</span><strong><?= (int) $r['waiting'] ?></strong></div>
                    <div class="notice-row">
                        <span>Latest evaluation</span><strong><?= $r['score'] !== null ? mentora_hours($r['score']) . ' / 5' : '—' ?></strong>
                    </div>
                    <div class="notice-row"><span>Days present (30 days)</span><strong><?= (int) $r['days'] ?></strong></div>
                    <?php if ($user['pathway'] === 'internship'): ?>
                    <p><a class="admin-btn" href="../agent/edit_intern.php?id=<?= (int) $user['id'] ?>">Open training record</a>
                    </p>
                    <?php endif; ?>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </div>
    <?php if ($user && $user['role'] === 'applicant' && $user['status'] === 'active') { mentora_render_certificate_panel($pdo, $user, 'user_edit.php?id=' . (int) $user['id'], '../'); } ?>
    <script>
        // Pathway only matters for interns/employees, and the agent only for interns.
        (() => {
            const form = document.querySelector('[data-role-form]');
            const role = form.querySelector('#role');
            const pathway = form.querySelector('#pathway');
            const sync = () => {
                const isApplicant = role.value === 'applicant';
                form.querySelector('[data-for-role="applicant"]').hidden = !isApplicant;
                form.querySelector('[data-for-role="intern"]').hidden = !(isApplicant && pathway.value === 'internship');
            };
            role.addEventListener('change', sync);
            pathway.addEventListener('change', sync);
            sync();
        })();
    </script>
<?php endif; ?>
<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
