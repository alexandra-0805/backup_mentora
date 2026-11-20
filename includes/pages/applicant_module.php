<?php

/**
 * One training module (applicant/intern/module.php?m=<key>, applicant/employee/module.php?m=<key>;
 * $pathway set there): objectives, lessons, a practice checklist and a quiz.
 * Self-study, in order: a module opens only after the one before it is done (mentora_module_unlocked).
 * Passing the quiz (MENTORA_QUIZ_PASS_PERCENT) marks the module done -> training_progress (with the
 * score) -> training_records via mentora_sync_training(). The quiz can be retaken any time.
 * Finishing the last module asks the agent to upload the certificate (includes/training_certificate.php).
 */

declare(strict_types=1);

require_once __DIR__ . '/../workflow.php';
require_once __DIR__ . '/../training_content.php';
require_once __DIR__ . '/../training_certificate.php';

$folder = mentora_pathway_folder($pathway ?? 'intern'); // $pathway is set by applicant/{intern,employee}/module.php
$modules = mentora_training_modules($folder);
$key = is_string($_GET['m'] ?? null) && isset($modules[$_GET['m']]) ? $_GET['m'] : (string) array_key_first($modules);
$content = mentora_module_content($key);
$self = 'applicant/' . $folder . '/module.php?m=' . $key;

if (mentora_is_post() && $content !== null) {
    $me = mentora_post_user(['applicant'], $self);
    if (!mentora_module_unlocked($modules, mentora_done_modules($pdo, (int) $me['id']), $key)) {
        mentora_flash('Finish the modules before this one first. Modules open one at a time, in order.', 'error');
        mentora_redirect('applicant/' . $folder . '/training.php#modules');
    }
    $answers = (array) ($_POST['answer'] ?? []);
    $results = [];
    $correct = 0;
    foreach ($content['quiz'] as $index => $question) {
        $given = isset($answers[$index]) && is_numeric($answers[$index]) ? (int) $answers[$index] : null;
        $isRight = $given === $question[2];
        $correct += $isRight ? 1 : 0;
        $results[$index] = ['given' => $given, 'right' => $isRight];
    }
    $score = (int) round($correct / count($content['quiz']) * 100);
    $passed = $score >= MENTORA_QUIZ_PASS_PERCENT;
    $_SESSION['module_quiz'][$key] = ['results' => $results, 'score' => $score, 'passed' => $passed];
    if ($passed) {
        $pdo->prepare('INSERT INTO training_progress (user_id, module_key, quiz_score)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE quiz_score = GREATEST(COALESCE(quiz_score, 0), VALUES(quiz_score))')
            ->execute([(int) $me['id'], $key, $score]);
        mentora_sync_training($pdo, (int) $me['id'], $me['pathway']);
        mentora_after_module_finished($pdo, mentora_person($pdo, (int) $me['id']));
        $allDone = mentora_training_complete($pdo, (int) $me['id'], $me['pathway']);
        mentora_flash('You passed with ' . $score . '%. “' . $modules[$key][0] . '” is marked as done.' . ($allDone ? ' You’ve finished every module; your certificate is on your Training page once your agent uploads it.' : ' The next module is now open.'));
    } else {
        mentora_flash('You scored ' . $score . '%. You need ' . MENTORA_QUIZ_PASS_PERCENT . '% to pass. Review the lessons and try again.', 'error');
    }
    mentora_redirect($self . '#quiz');
}

$pageTitle = $modules[$key][0];
$activePage = 'training.php';
require __DIR__ . '/../applicant_layout.php';

$userId = (int) $currentUser['id'];
$signedIn = $userId > 0;
$keys = array_keys($modules);
$position = array_search($key, $keys, true);
$previous = $keys[$position - 1] ?? null;
$next = $keys[$position + 1] ?? null;
$progress = null;
$doneKeys = $signedIn ? mentora_done_modules($pdo, $userId) : [];
if ($signedIn) {
    $query = $pdo->prepare('SELECT quiz_score, completed_at FROM training_progress WHERE user_id = ? AND module_key = ?');
    $query->execute([$userId, $key]);
    $progress = $query->fetch() ?: null;
}
$unlocked = mentora_module_unlocked($modules, $doneKeys, $key);
$blocker = mentora_module_blocker($modules, $doneKeys, $key);
$nextOpen = $next !== null && mentora_module_unlocked($modules, $doneKeys, $next);
$allDone = !array_diff(array_keys($modules), $doneKeys);
$quiz = $_SESSION['module_quiz'][$key] ?? null;
unset($_SESSION['module_quiz'][$key]);
$letters = ['A', 'B', 'C', 'D'];
?>
<div class="admin-heading module-heading">
    <div>
        <span class="module-eyebrow">Self-study · Module <?= $position + 1 ?> of <?= count($modules) ?> · <?= $modules[$key][1] ?> hour</span>
        <h1><?= mentora_h($modules[$key][0]) ?></h1>
        <p><?= mentora_h($content['summary'] ?? '') ?></p>
    </div>
    <div class="admin-actions">
        <span class="badge <?= $progress ? '' : ($unlocked ? 'info' : 'warn') ?>"><?= $progress
            ? 'Complete' . ($progress['quiz_score'] !== null ? ' · ' . (int) $progress['quiz_score'] . '%' : '')
            : ($unlocked ? 'In progress' : 'Locked') ?></span>
    </div>
</div>
<?= mentora_flash_html() ?>
<?php if (!$unlocked): ?>
    <section class="pathway-card module-locked">
        <span class="module-lock" aria-hidden="true">🔒</span>
        <h3>This module is locked</h3>
        <p>Modules open one at a time. Finish “<?= mentora_h($modules[$blocker][0]) ?>” and pass its quiz to unlock the next one.</p>
        <a class="admin-btn primary" href="module.php?m=<?= mentora_h($blocker) ?>">Go to <?= mentora_h($modules[$blocker][0]) ?></a>
        <a class="admin-btn" href="training.php">All modules</a>
    </section>
<?php elseif (!$content): ?>
    <section class="pathway-card"><p>This module’s lessons aren’t available yet. <a href="training.php">Back to training</a></p></section>
<?php else: ?>
<div class="module-layout">
    <nav class="module-nav pathway-card" aria-label="In this module">
        <strong>In this module</strong>
        <ol>
            <li><a href="#objectives">What you’ll learn</a></li>
            <?php foreach ($content['lessons'] as $index => $lesson): ?>
            <li><a href="#lesson-<?= $index + 1 ?>"><?= mentora_h($lesson[0]) ?></a>
            </li>
            <?php endforeach; ?>
            <li><a href="#practice">Practice</a></li>
            <li><a href="#quiz">Quiz</a></li>
        </ol>
        <a class="admin-btn" href="training.php">← All modules</a>
    </nav>
    <div class="module-body">
        <section class="pathway-card" id="objectives">
            <h3>What you’ll learn</h3>
            <ul class="checklist"><?php foreach ($content['objectives'] as $objective): ?><li><?= mentora_h($objective) ?></li><?php endforeach; ?></ul>
        </section>
        <?php foreach ($content['lessons'] as $index => [$title, $intro, $points, $tip]): ?>
            <section class="pathway-card module-lesson" id="lesson-<?= $index + 1 ?>">
                <span class="module-eyebrow">Lesson <?= $index + 1 ?></span>
                <h2><?= mentora_h($title) ?></h2>
                <p><?= mentora_h($intro) ?></p>
                <ul><?php foreach ($points as $point): ?><li><?= mentora_h($point) ?></li><?php endforeach; ?></ul>
                <?php if ($tip): ?><p class="module-tip"><strong>Tip</strong><?= mentora_h($tip) ?></p><?php endif; ?>
            </section>
        <?php endforeach; ?>
        <section class="pathway-card" id="practice">
            <h3>Practice</h3>
            <p>Do these in your own accounts or a sample, and tick them off as you go.</p>
            <div data-practice="<?= mentora_h($folder . '-' . $key . '-' . $userId) ?>">
                <?php foreach ($content['practice'] as $index => $task): ?>
                    <label class="check-row"><input type="checkbox" id="practice-<?= $index ?>"> <span><?= mentora_h($task) ?></span></label>
                <?php endforeach; ?>
            </div>
        </section>
        <section class="pathway-card module-quiz" id="quiz">
            <h3>Check your understanding</h3>
            <p><?= count($content['quiz']) ?> questions · pass with <?= MENTORA_QUIZ_PASS_PERCENT ?>% to complete this module<?= $progress ? '. You’ve already passed; retake it any time.' : '.' ?></p>
            <?php if ($quiz): ?>
                <div class="quiz-score <?= $quiz['passed'] ? 'is-pass' : 'is-fail' ?>"><strong><?= (int) $quiz['score'] ?>%</strong><?= $quiz['passed']
                    ? 'Passed. Module complete.'
                    : 'Not passed yet. See the explanations below, then try again.' ?><?php if ($quiz['passed'] && $next): ?> <a class="admin-btn primary" href="module.php?m=<?= mentora_h($next) ?>">Next module: <?= mentora_h($modules[$next][0]) ?> →</a><?php elseif ($quiz['passed'] && $allDone): ?> <a class="admin-btn primary" href="training.php#certificate">Get your certificate</a><?php endif; ?>
                </div>
            <?php endif; ?>
            <form method="post">
                <?= mentora_csrf_field() ?>
                <?php foreach ($content['quiz'] as $index => [$question, $options, $answer, $why]):
                    $result = $quiz['results'][$index] ?? null; ?>
                    <fieldset class="quiz-question<?= $result ? ($result['right'] ? ' is-right' : ' is-wrong') : '' ?>">
                        <legend><?= $index + 1 ?>. <?= mentora_h($question) ?></legend>
                        <?php foreach ($options as $optionIndex => $option):
                            $mark = $result && $optionIndex === $answer ? ' is-answer' : ($result && $result['given'] === $optionIndex ? ' is-chosen' : ''); ?>
                            <label class="quiz-option<?= $mark ?>"><input type="radio"
                                name="answer[<?= $index ?>]"
                                value="<?= $optionIndex ?>"
                                required<?= $result && $result['given'] === $optionIndex ? ' checked' : '' ?>> <b><?= $letters[$optionIndex] ?></b> <span><?= mentora_h($option) ?></span></label>
                        <?php endforeach; ?>
                        <?php if ($result): ?>
                        <p class="quiz-why"><?= $result['right'] ? '✓ Correct. ' : '✗ The answer is ' . $letters[$answer] . '. ' ?><?= mentora_h($why) ?></p>
                        <?php endif; ?>
                    </fieldset>
                <?php endforeach; ?>
                <button class="admin-btn primary"
                    type="submit"<?= $signedIn ? '' : ' disabled' ?>><?= $quiz || $progress ? 'Submit again' : 'Submit answers' ?></button>
                <?php if (!$signedIn): ?><p class="panel-subtitle"><a href="../../auth/signin.php">Sign in</a> to take the quiz.</p><?php endif; ?>
            </form>
        </section>
        <div class="module-pager">
            <?php if ($previous): ?><a class="admin-btn"
                href="module.php?m=<?= mentora_h($previous) ?>">← <?= mentora_h($modules[$previous][0]) ?></a><?php else: ?><span></span><?php endif; ?>
            <?php if ($next && $nextOpen): ?><a class="admin-btn primary"
                href="module.php?m=<?= mentora_h($next) ?>"><?= mentora_h($modules[$next][0]) ?> →</a>
            <?php elseif ($next): ?><span class="admin-btn is-locked" aria-disabled="true">🔒 <?= mentora_h($modules[$next][0]) ?> · pass this quiz to unlock</span>
            <?php else: ?><a class="admin-btn primary"
                href="training.php<?= $allDone ? '#certificate' : '' ?>"><?= $allDone ? 'Get your certificate' : 'Back to training' ?></a><?php endif; ?>
        </div>
    </div>
</div>
<script>
    // Practice ticks are a personal checklist, remembered in this browser only.
    (() => {
        const box = document.querySelector('[data-practice]');
        if (!box) return;
        const storageKey = 'mentora-practice-' + box.dataset.practice;
        let saved = [];
        try { saved = JSON.parse(localStorage.getItem(storageKey) || '[]'); } catch (error) { saved = []; }
        const inputs = [...box.querySelectorAll('input[type="checkbox"]')];
        inputs.forEach((input, index) => {
            input.checked = saved.includes(index);
            input.addEventListener('change', () => {
                const ticked = inputs.flatMap((item, i) => item.checked ? [i] : []);
                try { localStorage.setItem(storageKey, JSON.stringify(ticked)); } catch (error) { /* private window */ }
            });
        });
    })();
</script>
<?php endif; ?>
<?php require __DIR__ . '/../applicant_footer.php'; ?>
