<?php

/**
 * Applicant Training (applicant/intern/training.php, applicant/employee/training.php; $pathway set there).
 *   - Modules from mentora_training_modules(): "Mark as done" / "Undo" -> training_progress
 *     -> training_records (counts + status) via mentora_sync_training(); agents see it in the Training tracker.
 *   - Resources: published "Training resources" documents uploaded by agents (agent Documents).
 *   - Assessments: this person's evaluations (agent Evaluation list).
 */

declare(strict_types=1);

require_once __DIR__ . '/../workflow.php';

$folder = mentora_pathway_folder($pathway);
$self = 'applicant/' . $folder . '/training.php';
$modules = mentora_training_modules($folder);

if (mentora_is_post()) {
    $me = mentora_post_user(['applicant'], $self);
    $userId = (int) $me['id'];
    $key = (string) ($_POST['module'] ?? '');
    $action = (string) ($_POST['action'] ?? '');
    if (!isset($modules[$key])) {
        mentora_flash('That module isn’t part of your training.', 'error');
    } elseif ($action === 'complete') {
        $pdo->prepare('INSERT IGNORE INTO training_progress (user_id, module_key) VALUES (?, ?)')->execute([$userId, $key]);
        mentora_sync_training($pdo, $userId, $me['pathway']);
        mentora_flash('“' . $modules[$key][0] . '” marked as done. Nice work!');
    } elseif ($action === 'undo') {
        $pdo->prepare('DELETE FROM training_progress WHERE user_id = ? AND module_key = ?')->execute([$userId, $key]);
        mentora_sync_training($pdo, $userId, $me['pathway']);
        mentora_flash('“' . $modules[$key][0] . '” moved back to your to-do list.');
    }
    mentora_redirect($self . '#modules');
}

$pageTitle = $folder === 'intern' ? 'Your internship training' : 'Employee training & onboarding';
$activePage = 'training.php';
require __DIR__ . '/../applicant_layout.php';

$userId = (int) $currentUser['id'];
$signedIn = $userId > 0;
$isIntern = $folder === 'intern';
$person = $signedIn ? mentora_person($pdo, $userId) : null;

$done = [];
$record = null;
$evaluations = [];
if ($signedIn) {
    $query = $pdo->prepare('SELECT module_key, completed_at FROM training_progress WHERE user_id = ?');
    $query->execute([$userId]);
    $done = $query->fetchAll(PDO::FETCH_KEY_PAIR);
    $query = $pdo->prepare('SELECT * FROM training_records WHERE user_id = ?');
    $query->execute([$userId]);
    $record = $query->fetch() ?: null;
    $query = $pdo->prepare('SELECT review_type, score, status, due_date, published_at FROM evaluations WHERE user_id = ? ORDER BY COALESCE(published_at, due_date, updated_at) DESC');
    $query->execute([$userId]);
    $evaluations = $query->fetchAll();
}
$resources = $pdo->query("SELECT id, name, original_name, size, created_at FROM documents WHERE status = 'published' AND category = 'Training resources' AND stored_name IS NOT NULL ORDER BY created_at DESC LIMIT 6")->fetchAll();

$completeCount = count(array_intersect_key($done, $modules));
$total = count($modules);
$percent = (int) round($completeCount / $total * 100);
$hours = (float) ($record['hours_logged'] ?? 0);
$requiredHours = mentora_required_hours($person);
$published = array_values(array_filter($evaluations, static fn($e) => $e['status'] === 'published' && $e['score'] !== null));
$assessmentAverage = $published ? array_sum(array_column($published, 'score')) / count($published) : null;
$onHold = ($record['status'] ?? '') === 'on_hold';
$nextKey = null;
foreach ($modules as $key => $module) {
    if (!isset($done[$key])) {
        $nextKey = $key;
        break;
    }
}
// Employee onboarding readiness, from their own records.
$readiness = [
    ['Application details on file', !empty($person['applied_at'])],
    ['Department assigned', !empty($person['department_id'])],
    ['Required training complete', $completeCount >= $total],
    ['Evaluation published', (bool) $published],
];
$readyCount = count(array_filter(array_column($readiness, 1)));
?>
<div class="admin-heading"><div><h1><?= $isIntern ? 'Your internship training' : 'Employee training & onboarding' ?></h1><p><?= $isIntern ? 'Build practical skills, complete your learning plan, and keep your internship on track.' : 'Complete required training, review learning resources, and track your onboarding checks.' ?></p></div><span class="badge info"><?= $isIntern ? 'Internship pathway' : 'Onboarding' ?></span></div>
<?= mentora_flash_html() ?>
<?php if ($onHold): ?><div class="alert">Your training is on hold<?= !empty($record['notes']) ? ': ' . mentora_h($record['notes']) : '' ?>. Please contact your supervising agent.</div><?php endif; ?>
<?php if (!$signedIn): ?><div class="notice">You’re viewing a preview. <a href="../../auth/signin.php">Sign in</a> to track your training.</div><?php endif; ?>
<div class="metric-grid">
    <div class="metric-card"><small><?= $isIntern ? 'Modules completed' : 'Required modules' ?></small><strong><?= $completeCount ?> of <?= $total ?></strong><span><?= $percent ?>% complete</span></div>
    <div class="metric-card"><small>Assessment average</small><strong><?= $assessmentAverage !== null ? mentora_hours($assessmentAverage) . ' / 5' : '—' ?></strong><span><?= $published ? count($published) . ' published' : 'No assessments yet' ?></span></div>
    <?php if ($isIntern): ?>
        <div class="metric-card"><small>Approved internship hours</small><strong><?= mentora_hours($hours) ?> / <?= $requiredHours ?></strong><span>From approved reports</span></div>
        <div class="metric-card"><small>Remaining</small><strong><?= mentora_hours(max(0, $requiredHours - $hours)) ?></strong><span>Hours to go</span></div>
    <?php else: ?>
        <div class="metric-card"><small>Onboarding readiness</small><strong><?= $readyCount ?> of <?= count($readiness) ?></strong><span><?= $readyCount === count($readiness) ? 'Ready' : 'In progress' ?></span></div>
        <div class="metric-card"><small>Hours logged</small><strong><?= mentora_hours($hours) ?></strong><span>Approved work hours</span></div>
    <?php endif; ?>
</div>
<div class="admin-grid">
    <section class="pathway-card" id="modules">
        <h3><?= $isIntern ? 'Assigned training modules' : 'Required training modules' ?></h3>
        <div class="bar" aria-hidden="true"><i style="width:<?= $percent ?>%"></i></div>
        <table class="admin-table"><thead><tr><th>Module</th><th>Duration</th><th>Status</th><th><span class="sr-only">Action</span></th></tr></thead><tbody>
            <?php foreach ($modules as $key => [$title, $duration]): $isDone = isset($done[$key]); ?>
                <tr>
                    <td><?= mentora_h($title) ?><?= $isDone ? '<br><small>Done ' . date('d M Y', strtotime($done[$key])) . '</small>' : '' ?></td>
                    <td><?= $duration ?> hr</td>
                    <td><span class="badge <?= $isDone ? '' : 'info' ?>"><?= $isDone ? 'Complete' : ($key === $nextKey ? 'In progress' : 'Upcoming') ?></span></td>
                    <td>
                        <?php if ($signedIn && !$onHold): ?>
                            <form method="post"><?= mentora_csrf_field() ?><input type="hidden" name="module" value="<?= mentora_h($key) ?>">
                                <?php if ($isDone): ?>
                                    <button class="admin-btn" type="submit" name="action" value="undo" data-confirm-title="Mark as not done?" data-confirm="“<?= mentora_h($title) ?>” will move back to your to-do list.">Undo</button>
                                <?php else: ?>
                                    <button class="admin-btn primary" type="submit" name="action" value="complete" data-confirm-title="Mark this module as done?" data-confirm="Confirm you’ve finished “<?= mentora_h($title) ?>”. Your agent will see it in your training tracker.">Mark as done</button>
                                <?php endif; ?>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody></table>
    </section>
    <section class="pathway-card">
        <h3><?= $isIntern ? 'Upcoming assessments' : 'Onboarding readiness' ?></h3>
        <?php if ($isIntern): ?>
            <?php $upcoming = array_filter($evaluations, static fn($e) => $e['status'] !== 'published'); ?>
            <?php if (!$upcoming): ?><p>No assessments scheduled yet. Your agent will add them here.</p><?php endif; ?>
            <?php foreach ($upcoming as $evaluation): $overdue = $evaluation['due_date'] && $evaluation['due_date'] < date('Y-m-d'); ?>
                <div class="notice-row"><span><?= mentora_h($evaluation['review_type']) ?><br><small><?= $evaluation['due_date'] ? 'Due ' . date('d M Y', strtotime($evaluation['due_date'])) : 'Date to be set' ?></small></span><span class="badge <?= $overdue ? 'warn' : 'info' ?>"><?= $overdue ? 'Due soon' : 'Upcoming' ?></span></div>
            <?php endforeach; ?>
        <?php else: ?>
            <ul class="checklist">
                <?php foreach ($readiness as [$label, $ok]): ?><li class="<?= $ok ? '' : 'pending' ?>"><?= mentora_h($label) ?></li><?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <h3>Learning resources</h3>
        <?php if (!$resources): ?><p>Your agent hasn’t shared training resources yet.</p><?php endif; ?>
        <?php foreach ($resources as $resource): ?>
            <div class="notice-row"><span><?= mentora_h($resource['name']) ?><br><small><?= mentora_format_bytes((int) $resource['size']) ?> · <?= date('d M Y', strtotime($resource['created_at'])) ?></small></span><a class="admin-btn" href="../../account/document.php?id=<?= (int) $resource['id'] ?>" target="_blank" rel="noopener">Open</a></div>
        <?php endforeach; ?>
    </section>
</div>
<section class="applicant-banner"><strong><?= $completeCount >= $total ? 'All modules complete. Great work!' : 'Your progress is saved automatically.' ?></strong><?= $completeCount >= $total ? 'Your agent can see your completed training in the tracker.' : 'Mark each module as done when you finish it; your agent sees your progress right away.' ?></section>
<?php require __DIR__ . '/../applicant_footer.php'; ?>
