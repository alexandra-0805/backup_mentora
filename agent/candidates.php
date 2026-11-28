<?php

/**
 * Department candidates (agents): Employee VA applicants the AI matched to your department.
 * Suitable candidates (they have skills your team is lacking) are sent here the moment they're
 * screened, so the department can assess them right away and endorse them to HR, who decides.
 * HR admins see every department's candidates.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/screening.php';

$views = ['suitable' => 'Suitable', 'review' => 'For review', 'accepted' => 'Accepted'];

if (mentora_is_post()) {
    $me = mentora_post_user(['agent', 'admin'], 'agent/candidates.php');
    $userId = (int) ($_POST['user_id'] ?? 0);
    $verdict = (string) ($_POST['verdict'] ?? '');
    $note = mb_substr(trim((string) ($_POST['note'] ?? '')), 0, 1000);
    $view = isset($views[$_POST['view'] ?? '']) ? $_POST['view'] : 'suitable';
    $review = $pdo->prepare('SELECT r.id, r.department_id, d.name AS department_name FROM ai_reviews r LEFT JOIN departments d ON d.id = r.department_id WHERE r.user_id = ? ORDER BY r.id DESC LIMIT 1');
    $review->execute([$userId]);
    $review = $review->fetch();
    $myDepartment = (int) ($pdo->query('SELECT department_id FROM users WHERE id = ' . (int) $me['id'])->fetchColumn() ?: 0);
    $person = mentora_person($pdo, $userId);
    if (($_POST['action'] ?? '') !== 'assess' || !$review || !$person || !in_array($verdict, ['endorsed', 'not_endorsed'], true)) {
        mentora_flash('That assessment couldn’t be saved.', 'error');
    } elseif ($me['role'] !== 'admin' && (int) $review['department_id'] !== $myDepartment) {
        mentora_flash('Only agents in the candidate’s best-fit department can assess them.', 'error');
    } else {
        $pdo->prepare('UPDATE ai_reviews SET dept_verdict = ?, dept_note = ?, dept_reviewer_id = ?, dept_reviewed_at = NOW() WHERE id = ?')
            ->execute([$verdict, $note !== '' ? $note : null, $me['id'], $review['id']]);
        $name = trim($person['first_name'] . ' ' . $person['last_name']);
        mentora_notify_roles(
            $pdo,
            ['admin'],
            ($verdict === 'endorsed' ? 'Endorsed by ' : 'Not endorsed by ') . ($review['department_name'] ?? 'department') . ': ' . $name,
            $me['name'] . ($note !== '' ? ': “' . $note . '”' : ' assessed the candidate.'),
            'admin/screening.php?view=suitable#r' . $userId
        );
        mentora_flash('Your assessment of ' . $name . ' was sent to HR.');
    }
    mentora_redirect('agent/candidates.php?view=' . $view . '#r' . $userId);
}

$pageTitle = 'Department candidates';
$activePage = 'candidates.php';
require __DIR__ . '/../includes/agent_layout.php';

$me = $_SESSION['user'];
$myDepartmentId = (int) ($pdo->query('SELECT department_id FROM users WHERE id = ' . (int) $me['id'])->fetchColumn() ?: 0);
$isAdmin = $me['role'] === 'admin';
$scope = $isAdmin || $myDepartmentId === 0 ? null : $myDepartmentId;
$view = isset($views[$_GET['view'] ?? '']) ? $_GET['view'] : 'suitable';
$counts = mentora_screening_counts($pdo, $scope);
$rows = mentora_screening_rows($pdo, $view, $scope);
$gaps = mentora_department_skill_map($pdo);
?>
<div class="admin-heading">
    <div>
        <h1>Department candidates</h1>
        <p><?= $scope !== null
            ? 'Employee VA applicants screening matched to ' . mentora_h($agentDepartment) . '. Assess suitable ones and endorse them to HR.'
            : 'Employee VA applicants screening matched to each department.' ?></p>
    </div>
    <div class="admin-actions"><a class="admin-btn" href="clients.php">Clients</a><a class="admin-btn primary" href="recruitment.php">Recruitment links</a></div>
</div>
<?= mentora_flash_html() ?>
<?php if (!$isAdmin && $myDepartmentId === 0): ?>
    <div class="notice">HR hasn’t assigned you to a department yet, so you’re seeing every department’s candidates. You can assess them once you have a department.</div>
<?php endif; ?>
<nav class="application-tabs" aria-label="Candidates">
    <?php foreach ($views as $key => $label): ?>
        <a href="?view=<?= $key ?>"<?= $key === $view ? ' aria-current="page"' : '' ?>><?= $label ?><b><?= $counts[$key] ?></b></a>
    <?php endforeach; ?>
</nav>
<?php if (!$rows): ?>
    <section class="admin-panel"><p class="panel-subtitle">No candidates here. You’re notified as soon as screening finds a suitable one for your department.</p></section>
<?php endif; ?>
<?php foreach ($rows as $row) { mentora_render_screening_card($row, 'agent', []); } ?>
<?php mentora_render_skill_gaps($scope !== null ? array_intersect_key($gaps, [$scope => true]) : $gaps, $scope !== null ? 'Your department’s skill gaps' : 'Department skill gaps'); ?>
<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
