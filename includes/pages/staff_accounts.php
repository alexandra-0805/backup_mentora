<?php

/**
 * Staff accounts: create agent and HR administrator accounts (HR admins and agents only).
 * Shared by admin/staff_accounts.php and agent/staff_accounts.php, which set $workspace ('admin' or 'agent')
 * so the page opens inside that workspace's sidebar.
 *
 *   HR admins can create agent and HR administrator accounts, and see every staff account.
 *   Agents can create agent accounts only (never admins, so an agent can't give themselves HR access),
 *   and see agent accounts.
 *
 * Creating an account uses the same invite flow as admin/user_edit.php?new=1: the account is created as
 * "approved" with an unknown password, and a one-time link (plus a backup activation code) lets the person
 * choose their username and password (auth/activate.php). HR admins are notified when an agent invites someone.
 */

declare(strict_types=1);

require_once __DIR__ . '/../workflow.php';
require_once __DIR__ . '/../account_invites.php';
require_once __DIR__ . '/../invite_panel.php';

$workspace = ($workspace ?? 'agent') === 'admin' ? 'admin' : 'agent';
$selfPath = $workspace . '/staff_accounts.php';
$staffRoleLabels = ['agent' => 'Agent', 'admin' => 'HR administrator'];

/** Which account types someone may create: HR admins any staff role, agents only agents. */
$creatableRoles = static fn(array $person): array => ($person['role'] ?? '') === 'admin' ? ['agent', 'admin'] : ['agent'];

if (mentora_is_post()) {
    // admin/staff_accounts.php only takes HR admins' forms; agent/staff_accounts.php takes agents' and HR admins'.
    $me = mentora_post_user($workspace === 'admin' ? ['admin'] : ['admin', 'agent'], $selfPath);
    $allowed = $creatableRoles($me);
    $action = (string) ($_POST['action'] ?? '');

    /** Create a fresh one-time link for $person, email it, and keep the result for the panel shown next. */
    $sendInvite = static function (array $person, bool $resent) use ($pdo, $me): void {
        $link = mentora_create_invite($pdo, (int) $person['id'], (int) $me['id']);
        $fullName = trim($person['first_name'] . ' ' . $person['last_name']);
        $mail = mentora_mail_enabled()
            ? mentora_send_invite_email($person['email'], $fullName, $person['first_name'], $person['pathway'], $link, $me['name'], $me['email'], $person['role'])
            : ['ok' => false, 'error' => '', 'not_configured' => true];
        $_SESSION['staff_invite'] = [
            'user_id' => (int) $person['id'], 'name' => $person['first_name'], 'full_name' => $fullName, 'email' => $person['email'],
            'pathway' => $person['pathway'], 'role' => $person['role'], 'link' => $link, 'resent' => $resent, 'emailed' => $mail['ok'],
            'mail_error' => $mail['error'], 'mail_not_configured' => !empty($mail['not_configured']),
        ];
    };

    if ($action === 'create') {
        $first = trim(mentora_post_string('first_name'));
        $last = trim(mentora_post_string('last_name'));
        $email = mb_strtolower(trim(mentora_post_string('email')));
        $role = mentora_post_string('role');
        $departmentId = (int) mentora_post_string('department_id') ?: null;
        $validDepartment = $departmentId === null
            || in_array($departmentId, array_map('intval', array_column(mentora_departments($pdo), 'id')), true);

        $error = ($first === '' || $last === '' ? 'First and last name are required.' : null)
            ?? mentora_name_error($first, 'first name')
            ?? mentora_name_error($last, 'last name')
            ?? mentora_email_error($email)
            ?? match (true) {
                !in_array($role, $allowed, true) => $me['role'] === 'admin'
                    ? 'Please choose Agent or HR administrator.'
                    : 'Agents can only create agent accounts.',
                !$validDepartment => 'Please choose a department from the list.',
                mentora_find_account_by_email($pdo, $email) !== null => 'Another account already uses that email. Each email can only have one Mentora account.',
                default => null,
            };
        if ($error !== null) {
            // Keep what was typed so it doesn't have to be entered again.
            $_SESSION['staff_form'] = ['first_name' => $first, 'last_name' => $last, 'email' => $email, 'role' => $role, 'department_id' => $departmentId];
            mentora_flash($error, 'error');
            mentora_redirect($selfPath);
        }

        // Nobody knows this password: the new staff member sets their own from the link.
        $pdo->prepare("INSERT INTO users (first_name, last_name, email, pathway, password_hash, role, status, department_id)
            VALUES (?, ?, ?, 'employee_va', ?, ?, 'approved', ?)")
            ->execute([$first, $last, $email, password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT), $role, $departmentId]);
        $newId = (int) $pdo->lastInsertId();
        $sendInvite(mentora_person($pdo, $newId), false);

        if ($me['role'] !== 'admin') {
            mentora_notify_roles(
                $pdo,
                ['admin'],
                'New ' . strtolower($staffRoleLabels[$role]) . ' invited: ' . $first . ' ' . $last,
                $me['name'] . ' created an account for ' . $email . '.',
                'admin/user_edit.php?id=' . $newId,
                (int) $me['id']
            );
        }
    } elseif ($action === 'resend') {
        $person = mentora_person($pdo, (int) mentora_post_string('user_id'));
        if ($person && in_array($person['role'], $allowed, true) && $person['status'] === 'approved') {
            $sendInvite($person, true);
        } else {
            mentora_flash('A new link can’t be sent for that account.', 'error');
        }
    }

    mentora_redirect($selfPath);
}

$pageTitle = 'Staff accounts';
$activePage = 'staff_accounts.php';
require __DIR__ . '/../' . $workspace . '_layout.php';

$me = $_SESSION['user'];
$allowed = $creatableRoles($me);
$isAdmin = $me['role'] === 'admin';
$senderName = (string) $me['name'];

$invite = $_SESSION['staff_invite'] ?? null;
$form = $_SESSION['staff_form'] ?? [];
unset($_SESSION['staff_invite'], $_SESSION['staff_form']);
$form += ['first_name' => '', 'last_name' => '', 'email' => '', 'role' => 'agent', 'department_id' => null];

$marks = implode(',', array_fill(0, count($allowed), '?'));
$staffQuery = $pdo->prepare("SELECT u.id, u.user_code, u.username, u.first_name, u.last_name, u.email, u.role, u.status, u.created_at,
        d.name AS department_name,
        (SELECT CONCAT(c.first_name, ' ', c.last_name) FROM account_invites i JOIN users c ON c.id = i.created_by
         WHERE i.user_id = u.id ORDER BY i.id DESC LIMIT 1) AS invited_by
    FROM users u
    LEFT JOIN departments d ON d.id = u.department_id
    WHERE u.role IN ($marks)
    ORDER BY u.created_at DESC");
$staffQuery->execute($allowed);
$staff = $staffQuery->fetchAll();

$statusBadge = [
    'active' => ['', 'Active'],
    'approved' => ['info', 'Link sent'],
    'pending' => ['warn', 'Waiting'],
    'disabled' => ['warn', 'Turned off'],
    'rejected' => ['warn', 'Declined'],
];
$countWhere = static fn(string $key, string $value): int => count(array_filter($staff, static fn(array $s): bool => $s[$key] === $value));
?>
<div class="admin-heading keep-title">
    <div>
        <h1>Staff accounts</h1>
        <p><?= $isAdmin
            ? 'Create accounts for new agents and HR administrators. They get a one-time link to choose their username and password.'
            : 'Create accounts for new agents. They get a one-time link to choose their username and password, and HR is told.' ?></p>
    </div>
    <?php if ($isAdmin): ?>
        <a class="admin-btn" href="<?= $workspace === 'admin' ? '' : '../admin/' ?>user&amp;access_management.php">Users &amp; access</a>
    <?php endif; ?>
</div>
<?= mentora_flash_html() ?>
<?php if ($invite) { mentora_render_invite_panel($invite, $senderName); } ?>

<div class="metric-grid">
    <div class="metric-card"><small>Agents</small><strong><?= $countWhere('role', 'agent') ?></strong><span>Intern supervision</span></div>
    <?php if ($isAdmin): ?>
        <div class="metric-card"><small>HR administrators</small><strong><?= $countWhere('role', 'admin') ?></strong><span>Full access</span></div>
    <?php endif; ?>
    <div class="metric-card"><small>Active</small><strong><?= $countWhere('status', 'active') ?></strong><span>Can sign in</span></div>
    <div class="metric-card"><small>Link sent</small><strong><?= $countWhere('status', 'approved') ?></strong><span>Haven’t set a password yet</span></div>
</div>

    <section class="admin-panel">
        <h2>Create a staff account</h2>
        <p class="panel-subtitle">The link works once and expires in <?= MENTORA_INVITE_DAYS ?> days. It’s emailed automatically when email is set up; otherwise you can copy it and send it yourself.</p>
        <form method="post" class="form-grid">
            <?= mentora_csrf_field() ?>
            <input type="hidden" name="action" value="create">
            <div class="field">
                <label for="first_name">First name *</label>
                <input id="first_name" name="first_name" required maxlength="80" autocomplete="off" value="<?= mentora_h($form['first_name']) ?>">
            </div>
            <div class="field">
                <label for="last_name">Last name *</label>
                <input id="last_name" name="last_name" required maxlength="80" autocomplete="off" value="<?= mentora_h($form['last_name']) ?>">
            </div>
            <div class="field full">
                <label for="email">Work email *</label>
                <input id="email" name="email" type="email" required maxlength="190" autocomplete="off" value="<?= mentora_h($form['email']) ?>">
            </div>
            <div class="field">
                <label for="role">Account type *</label>
                <?php if (count($allowed) > 1): ?>
                    <select id="role" name="role">
                        <?php foreach ($allowed as $key): ?>
                            <option value="<?= $key ?>"<?= $form['role'] === $key ? ' selected' : '' ?>><?= $staffRoleLabels[$key] ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php else: ?>
                    <input id="role" value="Agent" disabled>
                    <input type="hidden" name="role" value="agent">
                    <small>Only HR administrators can create HR administrator accounts.</small>
                <?php endif; ?>
            </div>
            <div class="field">
                <label for="department_id">Department</label>
                <select id="department_id" name="department_id">
                    <option value="">Not assigned</option>
                    <?php foreach (mentora_departments($pdo) as $department): ?>
                        <option value="<?= (int) $department['id'] ?>"<?= (int) $form['department_id'] === (int) $department['id'] ? ' selected' : '' ?>><?= mentora_h($department['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field full form-actions">
                <button class="admin-btn primary" type="submit"
                    data-confirm-title="Create this account?"
                    data-confirm="The account is created and a one-time link to set a username and password is sent.">Create &amp; send invite</button>
            </div>
        </form>
    </section>

<section class="admin-panel data-panel">
    <h2>Staff</h2>
    <div class="table-toolbar" data-table-filter="staff-table">
        <input type="search" data-filter="search" placeholder="Search name, email, username or ID" aria-label="Search staff accounts">
        <?php if ($isAdmin): ?>
            <select data-filter="role" aria-label="Account type">
                <option value="all">All account types</option>
                <option value="agent">Agents</option>
                <option value="admin">HR administrators</option>
            </select>
        <?php endif; ?>
        <select data-filter="status" aria-label="Status">
            <option value="all">All statuses</option>
            <?php foreach ($statusBadge as $key => [, $label]): ?>
                <option value="<?= $key ?>"><?= $label ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <table class="admin-table" id="staff-table">
        <thead>
            <tr>
                <th>User ID</th>
                <th>Account</th>
                <th>Type</th>
                <th>Department</th>
                <th>Status</th>
                <th>Created</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($staff as $person): [$badgeClass, $badgeText] = $statusBadge[$person['status']] ?? ['', ucfirst((string) $person['status'])]; ?>
            <tr data-role="<?= mentora_h($person['role']) ?>" data-status="<?= mentora_h($person['status']) ?>">
                <td><?= mentora_h($person['user_code'] ?? '—') ?></td>
                <td>
                    <?= mentora_h($person['first_name'] . ' ' . $person['last_name']) ?><br>
                    <small><?= mentora_h($person['email']) ?><?= $person['username'] ? ' · @' . mentora_h($person['username']) : '' ?></small>
                </td>
                <td><?= mentora_h($staffRoleLabels[$person['role']] ?? $person['role']) ?></td>
                <td><?= mentora_h($person['department_name'] ?? '—') ?></td>
                <td><span class="badge <?= $badgeClass ?>"><?= mentora_h($badgeText) ?></span></td>
                <td>
                    <?= mentora_h(date('d M Y', strtotime($person['created_at']))) ?>
                    <?php if ($person['invited_by']): ?><br><small>Link by <?= mentora_h($person['invited_by']) ?></small><?php endif; ?>
                </td>
                <td>
                    <?php if ($person['status'] === 'approved'): ?>
                        <form method="post" style="display:inline">
                            <?= mentora_csrf_field() ?>
                            <input type="hidden" name="action" value="resend">
                            <input type="hidden" name="user_id" value="<?= (int) $person['id'] ?>">
                            <button class="admin-btn" type="submit"
                                data-confirm-title="Send a new link?"
                                data-confirm="A new link replaces the old one, which stops working.">Send new link</button>
                        </form>
                    <?php elseif ($person['status'] === 'active' && (int) $person['id'] !== (int) $me['id']): ?>
                        <a class="admin-btn" href="../account/messages.php?to=<?= (int) $person['id'] ?>">Message</a>
                    <?php endif; ?>
                    <?php if ($isAdmin): ?>
                        <a class="admin-btn" href="<?= $workspace === 'admin' ? '' : '../admin/' ?>user_edit.php?id=<?= (int) $person['id'] ?>">Manage</a>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <div class="table-note">Showing <span data-filter-count="staff-table"><?= count($staff) ?></span> account(s).</div>
</section>
<?php require __DIR__ . '/../admin_footer.php'; ?>
