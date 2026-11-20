<?php

/**
 * Hiring progress for the signed-in applicant (applicant/employee/overview.php and
 * applicant/intern/overview.php), worked out from their own records:
 *
 *   Application       applications row (the qualifications form) for an approved account
 *   Evaluation        latest evaluations row: "published" = complete, otherwise in review
 *   Interview & offer complete once the evaluation is published and training or a department has started
 *   Training          training_records: modules completed / total, or status "completed"
 *   Department/Team   users.department_id
 *   Task reporting    training_records.hours_logged against the required internship hours
 *
 * Each step is "done", "current" (the first step not done yet) or "upcoming".
 */

declare(strict_types=1);

require_once __DIR__ . '/workflow.php';

/**
 * @return array{steps: list<array{label: string, state: string, detail: string, href: string}>,
 *               done: int, total: int, current: ?array, appliedAt: ?string, percent: int, hasAccount: bool}
 */
function mentora_hiring_progress(PDO $pdo, array $user, string $pathway): array
{
    $userId = (int) ($user['id'] ?? 0);
    $isIntern = $pathway === 'intern';

    $application = $evaluation = $training = $department = null;
    if ($userId > 0) {
        $query = $pdo->prepare('SELECT required_hours, created_at FROM applications WHERE user_id = ? ORDER BY id DESC LIMIT 1');
        $query->execute([$userId]);
        $application = $query->fetch() ?: null;

        $query = $pdo->prepare('SELECT score, status, updated_at FROM evaluations WHERE user_id = ? ORDER BY updated_at DESC, id DESC LIMIT 1');
        $query->execute([$userId]);
        $evaluation = $query->fetch() ?: null;

        $query = $pdo->prepare('SELECT modules_completed, modules_total, hours_logged, status FROM training_records WHERE user_id = ?');
        $query->execute([$userId]);
        $training = $query->fetch() ?: null;

        if (!empty($user['department_id'])) {
            $query = $pdo->prepare('SELECT name FROM departments WHERE id = ?');
            $query->execute([(int) $user['department_id']]);
            $department = $query->fetchColumn() ?: null;
        }
    }

    $date = static fn(?string $value): string => $value ? date('d M Y', strtotime($value)) : '';

    // Application
    $applicationDone = $application !== null;
    $applicationDetail = $applicationDone ? 'Submitted ' . $date($application['created_at']) : 'Complete your application form';

    // Evaluation
    $evaluationStatus = (string) ($evaluation['status'] ?? '');
    $evaluationDone = $evaluationStatus === 'published';
    $evaluationDetail = match (true) {
        $evaluationDone && $evaluation['score'] !== null => 'Score ' . rtrim(rtrim((string) $evaluation['score'], '0'), '.') . ' / 5',
        $evaluationDone => 'Results published',
        $evaluation !== null => 'In review',
        default => 'Awaiting evaluator',
    };

    // Training
    $modulesDone = (int) ($training['modules_completed'] ?? 0);
    $modulesTotal = count(mentora_training_modules($pathway));
    $trainingStarted = $training !== null;
    $trainingDone = $trainingStarted && (($training['status'] ?? '') === 'completed' || $modulesDone >= $modulesTotal);
    $trainingDetail = match (true) {
        $trainingDone => 'All ' . $modulesTotal . ' modules done',
        ($training['status'] ?? '') === 'on_hold' => 'On hold · ' . $modulesDone . ' of ' . $modulesTotal,
        $trainingStarted => $modulesDone . ' of ' . $modulesTotal . ' modules',
        default => 'Not started',
    };

    // Department / team
    $departmentDone = $department !== null;
    $departmentDetail = $departmentDone ? (string) $department : 'Awaiting assignment';

    $steps = [];
    $steps[] = ['label' => 'Application', 'done' => $applicationDone, 'detail' => $applicationDetail, 'href' => $applicationDone ? 'profile.php' : 'form.php'];
    $steps[] = ['label' => 'Evaluation', 'done' => $evaluationDone, 'detail' => $evaluationDetail, 'href' => 'evaluation.php'];

    if ($isIntern) {
        $requiredHours = max(1, (int) ($application['required_hours'] ?? 480));
        $hours = (float) ($training['hours_logged'] ?? 0);
        $hoursText = rtrim(rtrim(number_format($hours, 1, '.', ''), '0'), '.');
        $steps[] = ['label' => 'Core training', 'done' => $trainingDone, 'detail' => $trainingDetail, 'href' => 'training.php'];
        $steps[] = ['label' => 'Team assignment', 'done' => $departmentDone, 'detail' => $departmentDetail, 'href' => 'team-agent.php'];
        $steps[] = [
            'label' => 'Task reporting',
            'done' => $hours >= $requiredHours,
            'detail' => $hours > 0 ? $hoursText . ' / ' . $requiredHours . ' hrs' : 'No hours yet',
            'href' => 'reporting.php',
        ];
    } else {
        // There is no separate interview record: the offer counts as made once the published
        // evaluation is followed by training or a department assignment.
        $offerDone = $evaluationDone && ($trainingStarted || $departmentDone);
        $steps[] = [
            'label' => 'Interview & offer',
            'done' => $offerDone,
            'detail' => $offerDone ? 'Offer accepted' : ($evaluationDone ? 'HR will contact you' : 'After evaluation'),
            'href' => 'evaluation.php',
        ];
        $steps[] = ['label' => 'Training', 'done' => $trainingDone, 'detail' => $trainingDetail, 'href' => 'training.php'];
        $steps[] = ['label' => 'Department', 'done' => $departmentDone, 'detail' => $departmentDetail, 'href' => 'department.php'];
    }

    // The first step that isn't done is the current one; everything after it is upcoming.
    $doneCount = 0;
    $current = null;
    foreach ($steps as $index => $step) {
        if ($step['done']) {
            $steps[$index]['state'] = 'done';
            $doneCount++;
        } elseif ($current === null) {
            $steps[$index]['state'] = 'current';
            $current = $steps[$index] + ['number' => $index + 1];
        } else {
            $steps[$index]['state'] = 'upcoming';
        }
        unset($steps[$index]['done']);
    }

    return [
        'steps' => $steps,
        'done' => $doneCount,
        'total' => count($steps),
        'current' => $current,
        'appliedAt' => $application['created_at'] ?? ($user['created_at'] ?? null),
        'percent' => (int) round($doneCount / count($steps) * 100),
        'hasAccount' => $userId > 0,
    ];
}

/** Print the progress card (the timeline). Steps link to the page each one is about. */
function mentora_render_hiring_progress(array $progress, string $title): void
{
    $e = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $current = $progress['current'];
    $summary = $current
        ? 'Step ' . $current['number'] . ' of ' . $progress['total'] . ': ' . $current['label']
        : 'All steps complete';
    $applied = $progress['appliedAt'] ? 'Applied ' . date('d M Y', strtotime($progress['appliedAt'])) . ' · ' : '';
    ?>
    <section class="pathway-card hiring-progress" aria-labelledby="hiring-progress-title">
        <div class="hiring-progress-head">
            <div>
                <h2 id="hiring-progress-title"><?= $e($title) ?></h2>
                <p><?= $e($applied . $summary) ?></p>
            </div>
            <span class="badge<?= $current ? ' info' : '' ?>"><?= $progress['percent'] ?>% complete</span>
        </div>
        <ol class="timeline" aria-label="<?= $e($title) ?>">
            <?php foreach ($progress['steps'] as $index => $step):
                $state = $step['state'];
                $stateText = ['done' => 'Complete', 'current' => 'In progress', 'upcoming' => 'Upcoming'][$state];
            ?>
                <li class="timeline-step is-<?= $state ?><?= $state === 'done' ? '' : ' pending' ?>">
                    <a class="timeline-link" href="<?= $e($step['href']) ?>"<?= $state === 'current' ? ' aria-current="step"' : '' ?>>
                        <span class="timeline-dot" aria-hidden="true"><?= $state === 'done' ? '✓' : $index + 1 ?></span>
                        <strong><?= $e($step['label']) ?></strong>
                        <small><span class="sr-only"><?= $e($stateText) ?>: </span><?= $e($step['detail']) ?></small>
                    </a>
                </li>
            <?php endforeach; ?>
        </ol>
    </section>
    <?php
}
