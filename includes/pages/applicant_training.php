<?php

/**
 * Applicant Training (applicant/intern/training.php, applicant/employee/training.php; $pathway set there).
 *   - Self-study modules from mentora_training_modules(), 1 hour each, done in order: each opens only
 *     after the one before it (module.php); passing its quiz completes it -> training_progress
 *     -> training_records via mentora_sync_training(); agents see it in the Training tracker.
 *   - Certificate of completion: uploaded by the agent (or HR); downloadable once every module is done.
 *   - Resources: published "Training resources" documents uploaded by agents (agent Documents).
 *   - Assessments: this person's evaluations (agent Evaluation list).
 */

declare(strict_types=1);

require_once __DIR__ . '/../workflow.php';
require_once __DIR__ . '/../training_certificate.php';

$folder = mentora_pathway_folder($pathway ?? 'intern'); // $pathway is set by applicant/{intern,employee}/training.php
$modules = mentora_training_modules($folder);

$pageTitle = $folder === 'intern' ? 'Internship training' : 'Employee training & onboarding';
$activePage = 'training.php';
require __DIR__ . '/../applicant_layout.php';

$userId = (int) $currentUser['id'];
$signedIn = $userId > 0;
$isIntern = $folder === 'intern';
$person = $signedIn ? mentora_person($pdo, $userId) : null;

$done = [];
$quizScores = [];
$record = null;
$evaluations = [];
if ($signedIn) {
    $query = $pdo->prepare('SELECT module_key, completed_at FROM training_progress WHERE user_id = ?');
    $query->execute([$userId]);
    $done = $query->fetchAll(PDO::FETCH_KEY_PAIR);
    $query = $pdo->prepare('SELECT module_key, quiz_score FROM training_progress WHERE user_id = ? AND quiz_score IS NOT NULL');
    $query->execute([$userId]);
    $quizScores = $query->fetchAll(PDO::FETCH_KEY_PAIR);
    $query = $pdo->prepare('SELECT * FROM training_records WHERE user_id = ?');
    $query->execute([$userId]);
    $record = $query->fetch() ?: null;
    $query = $pdo->prepare('SELECT review_type, score, status, due_date, published_at
        FROM evaluations
        WHERE user_id = ?
        ORDER BY COALESCE(published_at, due_date, updated_at) DESC');
    $query->execute([$userId]);
    $evaluations = $query->fetchAll();
}
$resources = $pdo->query("SELECT id, name, original_name, size, created_at
    FROM documents
    WHERE status = 'published' AND category = 'Training resources' AND stored_name IS NOT NULL
    ORDER BY created_at DESC LIMIT 6")
    ->fetchAll();

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
<div class="admin-heading">
    <div>
        <h1><?= $isIntern ? 'Your internship training' : 'Employee training & onboarding' ?></h1>
        <p><?= $isIntern
            ? 'Build practical skills, complete your learning plan, and keep your internship on track.'
            : 'Complete required training, review learning resources, and track your onboarding checks.' ?></p>
    </div>
    <?php if (!$isIntern): ?><span class="badge info">Onboarding</span><?php endif; ?>
</div>
<?= mentora_flash_html() ?>
<?php if ($onHold): ?>
<div class="alert">Your training is on hold<?= !empty($record['notes']) ? ': ' . mentora_h($record['notes']) : '' ?>. Please contact your supervising agent.</div>
<?php endif; ?>
<?php if (!$signedIn): ?>
<div class="notice">You’re viewing a preview. <a href="../../auth/signin.php">Sign in</a> to track your training.</div>
<?php endif; ?>
<div class="metric-grid">
    <div class="metric-card">
        <small><?= $isIntern ? 'Modules completed' : 'Required modules' ?></small><strong><?= $completeCount ?> of <?= $total ?></strong><span><?= $percent ?>% complete</span>
    </div>
    <div class="metric-card">
        <small>Assessment average</small><strong><?= $assessmentAverage !== null ? mentora_hours($assessmentAverage) . ' / 5' : '—' ?></strong><span><?= $published ? count($published) . ' published' : 'No assessments yet' ?></span>
    </div>
    <?php if ($isIntern): ?>
        <div class="metric-card">
            <small>Internship hours</small><strong><?= mentora_hours($hours) ?> / <?= $requiredHours ?></strong><span>Tracked automatically</span>
        </div>
        <div class="metric-card"><small>Remaining</small><strong><?= mentora_hours(max(0, $requiredHours - $hours)) ?></strong><span>Hours to go</span></div>
    <?php else: ?>
        <div class="metric-card">
            <small>Onboarding readiness</small><strong><?= $readyCount ?> of <?= count($readiness) ?></strong><span><?= $readyCount === count($readiness) ? 'Ready' : 'In progress' ?></span>
        </div>
        <div class="metric-card"><small>Work hours</small><strong><?= mentora_hours($hours) ?></strong><span>Tracked automatically</span></div>
    <?php endif; ?>
</div>
<?php
$allDone = $completeCount >= $total;
$certificate = $signedIn ? mentora_training_certificate($pdo, $userId) : null;
?>
<section class="pathway-card certificate-card<?= $allDone && $certificate ? ' is-earned' : '' ?>" id="certificate">
    <div>
        <span class="module-eyebrow">Certificate of completion · Mentora PH</span>
        <h3><?= $isIntern ? 'Student Virtual Assistant Training Program' : 'Professional Virtual Assistant Training Program' ?></h3>
        <p><?php if (!$allDone): ?>Finish all <?= $total ?> self-study modules, in order, to download your certificate from Mentora PH.
            <?php elseif ($certificate): ?>You’ve completed every module. Your certificate<?= $certificate['uploaded_by_name']
                ? ' from ' . mentora_h($certificate['uploaded_by_name'])
                : '' ?> is ready to download.
            <?php else: ?>You’ve completed every module. Your <?= $isIntern ? 'supervising agent' : 'HR team' ?> has been asked to upload your certificate; you’ll get a notification when it’s ready.<?php endif; ?>
            </p>
        <div class="bar" aria-hidden="true"><i style="width:<?= $percent ?>%"></i></div>
        <small><?= $completeCount ?> of <?= $total ?> modules complete</small>
    </div>
    <?php if ($signedIn && $allDone && $certificate): ?>
        <span class="row-actions">
            <a class="admin-btn primary"
                href="../../account/document.php?id=<?= (int) $certificate['id'] ?>&amp;download=1"><?= mentora_icon('download') ?>Download certificate</a>
            <a class="admin-btn"
                href="../../account/document.php?id=<?= (int) $certificate['id'] ?>"
                target="_blank"
                rel="noopener"><?= mentora_icon('view') ?>View</a>
        </span>
    <?php elseif ($allDone): ?>
        <span class="badge warn">Waiting for upload</span>
    <?php else: ?>
        <span class="badge info"><?= $total - $completeCount ?> module<?= $total - $completeCount === 1 ? '' : 's' ?> to go</span>
    <?php endif; ?>
</section>
<div class="admin-grid">
    <section class="pathway-card" id="modules">
        <h3><?= $isIntern ? 'Assigned training modules' : 'Required training modules' ?> <span class="badge info">Self-study</span></h3>
        <p class="panel-subtitle">Each module takes about 1 hour. They open one at a time: pass a module’s quiz to unlock the next.</p>
        <div class="bar" aria-hidden="true"><i style="width:<?= $percent ?>%"></i></div>
        <table class="admin-table"><thead><tr><th>Module</th><th>Duration</th><th>Status</th><th><span class="sr-only">Action</span></th></tr></thead><tbody>
            <?php foreach ($modules as $key => [$title, $duration]):
                $isDone = isset($done[$key]);
                $isOpen = mentora_module_unlocked($modules, array_keys($done), $key); ?>
                <tr class="<?= $isOpen ? '' : 'module-row-locked' ?>">
                    <td>
                        <?php if ($isOpen): ?><a class="module-link"
                            href="module.php?m=<?= mentora_h($key) ?>"><?= mentora_h($title) ?></a><?php else: ?><?= mentora_h($title) ?><?php endif; ?><?= $isDone
                                ? '<br><small>Done ' . date('d M Y', strtotime($done[$key])) . (isset($quizScores[$key]) ? ' · quiz ' . (int) $quizScores[$key] . '%' : '') . '</small>'
                                : '' ?></td>
                    <td><?= $duration ?> hr</td>
                    <td><span class="badge <?= $isDone ? '' : ($isOpen ? 'info' : 'warn') ?>"><?= $isDone ? 'Complete' : ($isOpen ? 'In progress' : 'Locked') ?></span>
                    </td>
                    <td class="row-actions">
                        <?php if ($isDone): ?><a class="admin-btn" href="module.php?m=<?= mentora_h($key) ?>"><?= mentora_icon('view') ?>Review</a>
                        <?php elseif ($isOpen && !$onHold): ?><a class="admin-btn primary" href="module.php?m=<?= mentora_h($key) ?>">Start module</a>
                        <?php else: ?><span class="admin-btn is-locked"
                            aria-disabled="true"
                            title="Finish the module before it to unlock">Locked</span><?php endif; ?>
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
                <div class="notice-row">
                    <span><?= mentora_h($evaluation['review_type']) ?><br><small><?= $evaluation['due_date']
                        ? 'Due ' . date('d M Y', strtotime($evaluation['due_date']))
                        : 'Date to be set' ?></small></span><span class="badge <?= $overdue ? 'warn' : 'info' ?>"><?= $overdue ? 'Due soon' : 'Upcoming' ?></span>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <ul class="checklist">
                <?php foreach ($readiness as [$label, $ok]): ?><li class="<?= $ok ? '' : 'pending' ?>"><?= mentora_h($label) ?></li><?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <h3>Learning resources</h3>
        <?php if (!$resources): ?><p>Your agent hasn’t shared training resources yet.</p><?php endif; ?>
        <?php foreach ($resources as $resource): ?>
            <div class="notice-row">
                <span><?= mentora_h($resource['name']) ?><br><small><?= mentora_format_bytes((int) $resource['size']) ?> · <?= date('d M Y', strtotime($resource['created_at'])) ?></small></span><a class="admin-btn" href="../../account/document.php?id=<?= (int) $resource['id'] ?>" target="_blank" rel="noopener"><?= mentora_icon('view') ?>Open</a>
            </div>
        <?php endforeach; ?>
    </section>
</div>
<?php require __DIR__ . '/../applicant_footer.php'; ?>
