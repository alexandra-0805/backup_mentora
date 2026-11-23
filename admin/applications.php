<?php

/**
 * Applications waiting for an HR decision.
 * Approve -> a one-time confirmation link is created; the admin sends it from their own Gmail
 * ("Open in Gmail" opens a ready-to-send email) or copies it. The applicant opens the link
 * (auth/activate.php), creates a username and password, and enters the applicant workspace.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/account_invites.php';
require_once __DIR__ . '/../includes/invite_panel.php';

$admin = mentora_require_login(['admin']);

// ---------- Actions (POST, then back to this page) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $view = in_array($_POST['view'] ?? '', ['pending', 'approved', 'active', 'rejected'], true) ? $_POST['view'] : 'pending';
    $applicantId = (int) ($_POST['user_id'] ?? 0);
    $action = (string) ($_POST['action'] ?? '');

    $applicant = null;
    if ($applicantId > 0) {
        $query = $pdo->prepare("SELECT id, first_name, last_name, email, pathway, status FROM users WHERE id = ? AND role = 'applicant'");
        $query->execute([$applicantId]);
        $applicant = $query->fetch() ?: null;
    }

    if (!mentora_csrf_valid($_POST['csrf_token'] ?? null)) {
        $_SESSION['applications_flash'] = ['error' => 'Your session expired. Please try again.'];
    } elseif ($applicant === null) {
        $_SESSION['applications_flash'] = ['error' => 'That application no longer exists.'];
    } elseif (in_array($action, ['approve', 'resend'], true) && in_array($applicant['status'], ['pending', 'approved'], true)) {
        try {
            $link = mentora_create_invite($pdo, (int) $applicant['id'], (int) $admin['id']);
            $fullName = trim($applicant['first_name'] . ' ' . $applicant['last_name']);
            // Email it automatically when a mail account is set up (config/mail.local.php).
            $mail = mentora_mail_enabled()
                ? mentora_send_invite_email(
                    $applicant['email'],
                    $fullName,
                    $applicant['first_name'],
                    $applicant['pathway'],
                    $link,
                    $admin['name'],
                    $admin['email']
                )
                : ['ok' => false, 'error' => '', 'not_configured' => true];
            // The link is also shown once, to this admin, as a backup (Gmail / copy).
            $_SESSION['applications_flash'] = [
                'invite' => [
                    'user_id' => (int) $applicant['id'],
                    'name' => $applicant['first_name'],
                    'full_name' => $fullName,
                    'email' => $applicant['email'],
                    'pathway' => $applicant['pathway'],
                    'link' => $link,
                    'resent' => $action === 'resend' || $applicant['status'] === 'approved',
                    'emailed' => $mail['ok'],
                    'mail_error' => $mail['error'],
                    'mail_not_configured' => !empty($mail['not_configured']),
                ],
            ];
            $view = 'approved';
        } catch (Throwable $exception) {
            error_log('Mentora approval failed: ' . $exception->getMessage());
            $_SESSION['applications_flash'] = ['error' => 'The application could not be approved right now. Please try again.'];
        }
    } elseif ($action === 'reject' && in_array($applicant['status'], ['pending', 'approved'], true)) {
        $pdo->prepare('DELETE FROM account_invites WHERE user_id = ? AND used_at IS NULL')->execute([$applicant['id']]);
        $pdo->prepare("UPDATE users SET status = 'rejected' WHERE id = ?")->execute([$applicant['id']]);
        $_SESSION['applications_flash'] = ['notice' => trim($applicant['first_name'] . ' ' . $applicant['last_name']) . '’s application was declined. Any link sent earlier no longer works.'];
    } elseif ($action === 'reopen' && $applicant['status'] === 'rejected') {
        $pdo->prepare("UPDATE users SET status = 'pending' WHERE id = ?")->execute([$applicant['id']]);
        $_SESSION['applications_flash'] = ['notice' => trim($applicant['first_name'] . ' ' . $applicant['last_name']) . '’s application is waiting for confirmation again.'];
        $view = 'pending';
    } else {
        $_SESSION['applications_flash'] = ['error' => 'That action isn’t available for this application anymore.'];
    }

    mentora_redirect('admin/applications.php?view=' . $view);
}

$flash = $_SESSION['applications_flash'] ?? [];
unset($_SESSION['applications_flash']);

// ---------- Lists ----------
$views = [
    'pending' => ['Waiting', 'Waiting for your confirmation'],
    'approved' => ['Link sent', 'Approved: waiting for the applicant to create their account'],
    'active' => ['Confirmed', 'Created their username and password'],
    'rejected' => ['Declined', 'Not approved'],
];
$view = is_string($_GET['view'] ?? null) && isset($views[$_GET['view']]) ? $_GET['view'] : 'pending';

$counts = array_fill_keys(array_keys($views), 0);
foreach ($pdo->query("SELECT status, COUNT(*) total FROM users WHERE role = 'applicant' GROUP BY status")->fetchAll() as $row) {
    $counts[$row['status']] = (int) $row['total'];
}

$listQuery = $pdo->prepare(
    "SELECT u.id, u.user_code, u.username, u.first_name, u.last_name, u.email, u.email_verified_at, u.pathway, u.status, u.created_at,
            (SELECT MAX(i.created_at) FROM account_invites i WHERE i.user_id = u.id) AS link_sent_at,
            (SELECT MAX(i.expires_at) FROM account_invites i WHERE i.user_id = u.id AND i.used_at IS NULL) AS link_expires_at
     FROM users u
     WHERE u.role = 'applicant' AND u.status = ?
     ORDER BY u.created_at DESC"
);
$listQuery->execute([$view]);
$applicants = $listQuery->fetchAll();

$pageTitle = 'Applications';
$activePage = 'applications.php';
require __DIR__ . '/../includes/admin_layout.php';

$h = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$date = static fn(?string $value): string => $value ? date('d M Y', strtotime($value)) : '—';
$pathwayLabel = static fn(string $pathway): string => $pathway === 'internship' ? 'Intern' : 'Employee VA';
?>
<div class="admin-heading">
    <div>
        <h1>Applications</h1>
        <p>Confirm new applicants. Approving creates a one-time link you send from your Gmail; the applicant uses it to create their username and password.</p>
    </div>
    <div class="admin-actions">
        <a class="admin-btn primary" href="../auth/signup.php">+ Add applicant</a>
    </div>
</div>

<?php if (!empty($flash['error'])): ?>
    <div class="alert"><?= $h($flash['error']) ?></div>
<?php elseif (!empty($flash['notice'])): ?>
    <div class="notice"><?= $h($flash['notice']) ?></div>
<?php endif; ?>

<?php if (!empty($flash['invite'])) { mentora_render_invite_panel($flash['invite'], $adminName); } ?>

<nav class="application-tabs" aria-label="Application status">
    <?php foreach ($views as $key => [$label]): ?>
        <a href="applications.php?view=<?= $key ?>"<?= $key === $view ? ' aria-current="page"' : '' ?>><?= $h($label) ?><b><?= $counts[$key] ?></b></a>
    <?php endforeach; ?>
</nav>

<section class="admin-panel data-panel">
    <table class="admin-table">
        <thead>
            <tr>
                <th>Applicant</th>
                <th>Pathway</th>
                <th>Applied</th>
                <th><?= $view === 'approved' ? 'Link' : ($view === 'active' ? 'Username' : 'Status') ?></th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!$applicants): ?>
                <tr><td colspan="5" class="table-note">No applications here. <?= $h($views[$view][1]) ?>.</td></tr>
            <?php endif; ?>
            <?php foreach ($applicants as $applicant):
                $name = trim($applicant['first_name'] . ' ' . $applicant['last_name']);
            ?>
                <tr>
                    <td><?= $h($name) ?><br><small><?= $h($applicant['email']) ?></small></td>
                    <td><?= $pathwayLabel($applicant['pathway']) ?></td>
                    <td><?= $date($applicant['created_at']) ?></td>
                    <td>
                        <?php if ($view === 'approved'): ?>
                            <?php if ($applicant['link_expires_at'] && strtotime($applicant['link_expires_at']) > time()): ?>
                                <span class="badge info">Sent <?= $date($applicant['link_sent_at']) ?></span><br><small>Expires <?= $date($applicant['link_expires_at']) ?></small>
                            <?php else: ?>
                                <span class="badge warn">Link expired</span>
                            <?php endif; ?>
                        <?php elseif ($view === 'active'): ?>
                            <?= $h($applicant['username'] ?: '—') ?><br><small><?= $h($applicant['user_code']) ?></small>
                        <?php elseif ($view === 'pending' && $applicant['email_verified_at'] === null): ?>
                            <span class="badge">Email not confirmed</span><br><small>Applicant hasn’t entered their emailed code yet</small>
                        <?php else: ?>
                            <span class="badge <?= $view === 'pending' ? 'warn' : '' ?>"><?= $h($views[$view][0]) ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="application-actions">
                            <?php
                            $button = static function (
                                string $action,
                                string $label,
                                string $class,
                                string $title,
                                string $message,
                                string $tone = ''
                            ) use ($applicant, $view, $h): string {
                                return '<form method="post">' . mentora_csrf_field()
                                    . '<input type="hidden" name="user_id" value="' . (int) $applicant['id'] . '">'
                                    . '<input type="hidden" name="action" value="' . $h($action) . '">'
                                    . '<input type="hidden" name="view" value="' . $h($view) . '">'
                                    . '<button class="admin-btn ' . $h($class) . '" type="submit" data-confirm-title="' . $h($title) . '" data-confirm="' . $h($message) . '"'
                                    . ($tone !== '' ? ' data-confirm-tone="' . $h($tone) . '"' : '') . '>' . $h($label) . '</button></form>';
                            };
                            if ($view === 'pending') {
                                echo $button(
                                    'approve',
                                    'Approve',
                                    'primary',
                                    "Approve {$name}?",
                                    'You’ll get a one-time link to send from your Gmail so they can create their username and password.'
                                );
                                echo $button(
                                    'reject',
                                    'Decline',
                                    'danger',
                                    "Decline {$name}’s application?",
                                    'They won’t be able to create an account. You can reopen it later.',
                                    'danger'
                                );
                            } elseif ($view === 'approved') {
                                echo $button(
                                    'resend',
                                    'Resend link',
                                    'primary',
                                    "Send {$name} a new link?",
                                    'A new link replaces the old one, which stops working.'
                                );
                                echo $button(
                                    'reject',
                                    'Decline',
                                    'danger',
                                    "Decline {$name}’s application?",
                                    'Their link stops working and they won’t be able to create an account.',
                                    'danger'
                                );
                            } elseif ($view === 'rejected') {
                                echo $button('reopen', 'Reopen', '', "Reopen {$name}’s application?", 'It moves back to Waiting so you can approve it.');
                            } else {
                                echo '<span class="badge">Active</span>';
                            }
                            ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <div class="table-note"><?= $h($views[$view][1]) ?>.</div>
</section>

<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
