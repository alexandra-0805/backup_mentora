<?php

/**
 * AI screening (HR): Employee VA applications sorted by the AI resume review.
 *   Suitable   fills a department's lacking skills (that department was notified too)
 *   For review everything else, with the AI summary of skills and attainment; "flagged unsuitable" ones included
 * HR accepts a candidate into a department, declines, reopens, or re-runs the review. The department
 * skill gaps are shown on the same page.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/screening.php';

if (mentora_is_post()) {
    $view = isset(MENTORA_SCREENING_VIEWS[$_POST['view'] ?? '']) ? $_POST['view'] : 'suitable';
    $admin = mentora_post_user(['admin'], 'admin/screening.php?view=' . $view);
    $action = (string) ($_POST['action'] ?? '');
    $userId = (int) ($_POST['user_id'] ?? 0);
    $back = 'admin/screening.php?view=' . $view;

    if ($action === 'screen_all') {
        $done = 0;
        foreach (mentora_unscreened_employees($pdo) as $person) {
            $done += mentora_ai_review_application($pdo, (int) $person['id']) ? 1 : 0;
        }
        mentora_flash($done ? 'Review finished for ' . $done . ' application(s).' : 'Nothing was waiting for a review.');
        mentora_redirect($back);
    }

    $person = mentora_person($pdo, $userId);
    $application = $pdo->prepare('SELECT id, status FROM applications WHERE user_id = ? ORDER BY id DESC LIMIT 1');
    $application->execute([$userId]);
    $application = $application->fetch();
    if (!$person || $person['pathway'] !== 'employee_va' || !$application) {
        mentora_flash('That application couldn’t be found.', 'error');
        mentora_redirect($back);
    }
    $name = trim($person['first_name'] . ' ' . $person['last_name']);

    if ($action === 'rerun') {
        $review = mentora_ai_review_application($pdo, $userId);
        mentora_flash($review ? $name . ': ' . MENTORA_AI_CATEGORIES[$review['category']][0] . ' · ' . $review['score'] . '/100.' : 'The review couldn’t run for ' . $name . '.', $review ? 'success' : 'error');
        mentora_redirect($back . '#r' . $userId);
    }
    if ($action === 'accept' && $application['status'] === 'pending') {
        $departmentId = (int) ($_POST['department_id'] ?? 0);
        $department = array_values(array_filter(mentora_departments($pdo), static fn($d) => (int) $d['id'] === $departmentId))[0] ?? null;
        if (!$department) {
            mentora_flash('Please choose a department.', 'error');
            mentora_redirect($back . '#r' . $userId);
        }
        $pdo->beginTransaction();
        $pdo->prepare("UPDATE applications SET status = 'approved', reviewed_at = NOW() WHERE id = ?")->execute([$application['id']]);
        $pdo->prepare('UPDATE users SET department_id = ? WHERE id = ?')->execute([$departmentId, $userId]);
        mentora_notify($pdo, $userId, 'Application accepted', 'Welcome aboard! You’re assigned to ' . $department['name'] . '. Start your employee training next.', 'applicant/employee/training.php');
        foreach (mentora_department_agents($pdo, $departmentId) as $agentId) {
            mentora_notify($pdo, $agentId, 'New team member: ' . $name, 'HR accepted ' . $name . ' into ' . $department['name'] . '.', 'agent/candidates.php?view=accepted#r' . $userId);
        }
        $pdo->commit();
        mentora_flash($name . ' was accepted and assigned to ' . $department['name'] . '.');
        mentora_redirect('admin/screening.php?view=accepted#r' . $userId);
    }
    if ($action === 'decline' && $application['status'] === 'pending') {
        $pdo->prepare("UPDATE applications SET status = 'rejected', reviewed_at = NOW() WHERE id = ?")->execute([$application['id']]);
        mentora_notify($pdo, $userId, 'Application update', 'Thank you for applying. Your application wasn’t selected this time; we’ll keep your details on file.', 'applicant/employee/overview.php');
        mentora_flash($name . '’s application was declined.');
        mentora_redirect($back);
    }
    if ($action === 'reopen' && $application['status'] !== 'pending') {
        $pdo->prepare("UPDATE applications SET status = 'pending', reviewed_at = NULL WHERE id = ?")->execute([$application['id']]);
        mentora_flash($name . '’s application is back in screening.');
        mentora_redirect('admin/screening.php?view=review#r' . $userId);
    }
    mentora_flash('That action isn’t available for this application anymore.', 'error');
    mentora_redirect($back);
}

$pageTitle = 'Screening';
$activePage = 'dashboard.php'; // not in the sidebar; highlight the Dashboard it's opened from
require __DIR__ . '/../includes/admin_layout.php';

$view = isset(MENTORA_SCREENING_VIEWS[$_GET['view'] ?? '']) ? $_GET['view'] : 'suitable';
$counts = mentora_screening_counts($pdo);
$rows = mentora_screening_rows($pdo, $view);
$unscreened = mentora_unscreened_employees($pdo);
$departments = mentora_departments($pdo);
$gaps = mentora_department_skill_map($pdo);
$lackingTotal = array_sum(array_map(static fn($d) => count($d['lacking']), $gaps));
?>
<div class="admin-heading">
    <div>
        <h1>Screening</h1>
        <p>Employee VA applications reviewed automatically and matched to the skills each department is lacking.</p>
    </div>
    <div class="admin-actions">
        <a class="admin-btn" href="export.php?type=screening"><?= mentora_icon('download') ?>Export screening</a>
        <a class="admin-btn primary" href="department.php">Edit required skills</a>
    </div>
</div>
<?= mentora_flash_html() ?>
<div class="metric-grid">
    <a class="metric-card" href="?view=suitable"><small>Suitable</small><strong><?= $counts['suitable'] ?></strong><span>Fill lacking skills · waiting</span></a>
    <a class="metric-card" href="?view=review"><small>For review</small><strong><?= $counts['review'] ?></strong><span><?= $counts['flagged'] ?> flagged unsuitable</span></a>
    <a class="metric-card" href="?view=accepted"><small>Accepted</small><strong><?= $counts['accepted'] ?></strong><span>Assigned to a department</span></a>
    <div class="metric-card"><small>Lacking skills</small><strong><?= $lackingTotal ?></strong><span>Across <?= count($gaps) ?> departments</span></div>
</div>
<p class="notice">Review engine: <strong><?= mentora_h(mentora_ai_model_label()) ?></strong>. <?= mentora_ai_model_enabled()
    ? 'The writing model writes each summary; the rules engine checks it against the departments’ real skill gaps.'
    : 'No writing model is set up, so the built-in rules engine reviews applications. Add a model in ai/config.local.php for written summaries.' ?></p>
<?php if ($unscreened): ?>
    <section class="admin-panel">
        <h2>Waiting for a review <span class="badge warn"><?= count($unscreened) ?></span></h2>
        <p class="panel-subtitle">Applications submitted before screening was turned on, or whose review didn’t finish.</p>
        <?php foreach (array_slice($unscreened, 0, 5) as $person): ?>
            <div class="list-row"><span><?= mentora_h($person['first_name'] . ' ' . $person['last_name']) ?><br><small><?= mentora_h($person['email']) ?> · applied <?= date('d M Y', strtotime($person['applied_at'])) ?></small></span></div>
        <?php endforeach; ?>
        <form method="post"><?= mentora_csrf_field() ?><input type="hidden" name="action" value="screen_all"><input type="hidden" name="view" value="<?= mentora_h($view) ?>"><button class="admin-btn primary" type="submit">Run review for all</button></form>
    </section>
<?php endif; ?>
<nav class="application-tabs" aria-label="Screening results">
    <?php foreach (MENTORA_SCREENING_VIEWS as $key => [$label]): ?>
        <a href="?view=<?= $key ?>"<?= $key === $view ? ' aria-current="page"' : '' ?>><?= mentora_h($label) ?><b><?= $counts[$key] ?></b></a>
    <?php endforeach; ?>
</nav>
<p class="panel-subtitle"><?= mentora_h(MENTORA_SCREENING_VIEWS[$view][1]) ?>.</p>
<?php if (!$rows): ?>
    <section class="admin-panel"><p class="panel-subtitle">No candidates here yet. Applications appear as soon as they are reviewed.</p></section>
<?php endif; ?>
<?php foreach ($rows as $row) { mentora_render_screening_card($row, 'admin', $departments); } ?>
<?php mentora_render_skill_gaps($gaps); ?>
<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
