<?php

/**
 * AI assessment and ranking of interns after training.
 *
 * Runs automatically when an intern finishes their last training module (includes/training_certificate.php),
 * and on demand from agent/intern_assessments.php. It scores the intern from their own records:
 *
 *   quiz scores 35 · training completion 15 · approved hours vs required 15 · daily report quality 15
 *   · published evaluations 15 · attendance 5   (out of 100)
 *
 * and gives a tier (Top performer / Strong / Developing / Needs support). Interns who finished training
 * with a score of MENTORA_READY_SCORE or more are "ready" for the agent's employability report.
 * The supervising agent is notified, so the intern moves into the agent's hands.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/workflow.php';
require_once __DIR__ . '/engine.php';

const MENTORA_READY_SCORE = 70;

/** tier => [minimum score, badge class] (highest first). */
const MENTORA_ASSESSMENT_TIERS = [
    'Top performer' => [85, ''],
    'Strong' => [70, ''],
    'Developing' => [55, 'info'],
    'Needs support' => [0, 'warn'],
];

function mentora_assessment_tier(int $score): string
{
    foreach (MENTORA_ASSESSMENT_TIERS as $tier => [$minimum]) {
        if ($score >= $minimum) {
            return $tier;
        }
    }

    return 'Needs support';
}

/** The numbers an assessment is based on. */
function mentora_intern_metrics(PDO $pdo, array $intern): array
{
    $id = (int) $intern['id'];
    $one = static function (string $sql) use ($pdo, $id): array {
        $query = $pdo->prepare($sql);
        $query->execute([$id]);
        return $query->fetch() ?: [];
    };
    $modules = array_keys(mentora_training_modules('internship'));
    $marks = implode(',', array_fill(0, count($modules), '?'));
    $progress = $pdo->prepare("SELECT COUNT(*) AS done, AVG(quiz_score) AS quiz, MIN(completed_at) AS first_at, MAX(completed_at) AS last_at
        FROM training_progress WHERE user_id = ? AND module_key IN ($marks)");
    $progress->execute([$id, ...$modules]);
    $progress = $progress->fetch() ?: [];
    $reports = $one("SELECT SUM(status = 'approved') AS approved, SUM(status = 'returned') AS returned, SUM(status = 'submitted') AS waiting FROM work_reports WHERE user_id = ?");
    $evaluation = $one("SELECT AVG(score) AS average, COUNT(*) AS published FROM evaluations WHERE user_id = ? AND status = 'published' AND score IS NOT NULL");
    $attendance = $one('SELECT COUNT(*) AS days FROM attendance WHERE user_id = ? AND time_in IS NOT NULL');
    $hours = $one('SELECT hours_logged FROM training_records WHERE user_id = ?');

    return [
        'modules_done' => (int) ($progress['done'] ?? 0),
        'modules_total' => count($modules),
        'quiz_average' => isset($progress['quiz']) ? round((float) $progress['quiz'], 1) : null,
        'training_days' => !empty($progress['first_at']) ? (int) floor((strtotime($progress['last_at']) - strtotime($progress['first_at'])) / 86400) + 1 : null,
        'hours' => (float) ($hours['hours_logged'] ?? 0),
        'required_hours' => mentora_required_hours($intern),
        'reports_approved' => (int) ($reports['approved'] ?? 0),
        'reports_returned' => (int) ($reports['returned'] ?? 0),
        'reports_waiting' => (int) ($reports['waiting'] ?? 0),
        'evaluation_average' => isset($evaluation['average']) ? round((float) $evaluation['average'], 1) : null,
        'evaluations' => (int) ($evaluation['published'] ?? 0),
        'attendance_days' => (int) ($attendance['days'] ?? 0),
    ];
}

/** Score out of 100 from the metrics (neutral values where there is no record yet). */
function mentora_intern_score(array $m): int
{
    $reviewed = $m['reports_approved'] + $m['reports_returned'];
    $reportValue = $reviewed > 0 ? 0.6 * ($m['reports_approved'] / $reviewed) + 0.4 * min(1, $reviewed / 5) : 0.4;

    return (int) round(
        (($m['quiz_average'] ?? 70) / 100) * 35
        + ($m['modules_total'] ? min(1, $m['modules_done'] / $m['modules_total']) : 0) * 15
        + min(1, $m['hours'] / max(1, $m['required_hours'])) * 15
        + $reportValue * 15
        + ($m['evaluation_average'] !== null ? $m['evaluation_average'] / 5 : 0.6) * 15
        + min(1, $m['attendance_days'] / 20) * 5
    );
}

/**
 * Assess one intern and save it (ai_assessments). The supervising agent (or every agent) is
 * notified when it's the first assessment or the tier changed. Returns the saved row, or null.
 */
function mentora_ai_assess_intern(PDO $pdo, int $userId, bool $notify = true): ?array
{
    $intern = mentora_person($pdo, $userId);
    if (!$intern || $intern['role'] !== 'applicant' || $intern['pathway'] !== 'internship') {
        return null;
    }
    $m = mentora_intern_metrics($pdo, $intern);
    $score = max(0, min(100, mentora_intern_score($m)));
    $tier = mentora_assessment_tier($score);
    $complete = $m['modules_done'] >= $m['modules_total'];
    $ready = $complete && $score >= MENTORA_READY_SCORE;
    $name = trim($intern['first_name'] . ' ' . $intern['last_name']);

    $strengths = [];
    $focus = [];
    if (($m['quiz_average'] ?? 0) >= 85) {
        $strengths[] = 'High quiz average (' . mentora_hours($m['quiz_average']) . '%).';
    } elseif ($m['quiz_average'] !== null && $m['quiz_average'] < 75) {
        $focus[] = 'Quiz average ' . mentora_hours($m['quiz_average']) . '%: review the module lessons.';
    }
    if ($m['reports_approved'] >= 3 && $m['reports_returned'] === 0) {
        $strengths[] = 'Every reviewed daily report was approved.';
    } elseif ($m['reports_returned'] > 0) {
        $focus[] = $m['reports_returned'] . ' daily report' . ($m['reports_returned'] === 1 ? ' was' : 's were') . ' returned for fixes.';
    }
    if ($m['evaluation_average'] !== null && $m['evaluation_average'] >= 4) {
        $strengths[] = 'Evaluation average ' . mentora_hours($m['evaluation_average']) . ' / 5.';
    } elseif ($m['evaluation_average'] === null) {
        $focus[] = 'No published evaluation yet.';
    }
    if (!$complete) {
        $focus[] = ($m['modules_total'] - $m['modules_done']) . ' training module(s) still to finish.';
    }
    if ($m['hours'] < $m['required_hours']) {
        $focus[] = mentora_hours($m['required_hours'] - $m['hours']) . ' internship hours still to complete.';
    }

    $summary = $name . ' finished ' . $m['modules_done'] . ' of ' . $m['modules_total'] . ' training modules'
        . ($m['quiz_average'] !== null ? ' with an average quiz score of ' . mentora_hours($m['quiz_average']) . '%' : '')
        . ($m['training_days'] ? ' in ' . $m['training_days'] . ' day' . ($m['training_days'] === 1 ? '' : 's') : '') . '. '
        . mentora_hours($m['hours']) . ' of ' . $m['required_hours'] . ' internship hours are approved from '
        . ($m['reports_approved'] + $m['reports_returned']) . ' reviewed report(s)'
        . ($m['evaluation_average'] !== null ? '; evaluation average ' . mentora_hours($m['evaluation_average']) . ' / 5' : '') . '. '
        . 'AI ranking: ' . $tier . ' (' . $score . '/100), ' . ($ready ? 'ready for an employment recommendation.' : 'keep developing before an employment recommendation.');
    $engine = MENTORA_AI_RULES_ENGINE;

    // The AI model writes the narrative; the score and ranking stay rule-based so every intern is ranked the same way.
    $answer = mentora_ai_json(
        'You are the training analyst of Mentora Philippines, which trains student virtual assistants (interns). '
            . 'Write a fair, specific assessment of one intern for their supervising agent, based only on the numbers given.',
        'Intern metrics: ' . mentora_ai_encode($m + ['score' => $score, 'tier' => $tier, 'ready_for_employment' => $ready])
            . "\nReturn JSON: {\"summary\": \"3-4 sentences\", \"strengths\": [up to 3 short points], \"focus_areas\": [up to 3 short points]}"
    );
    if ($answer !== null && mentora_ai_text($answer['summary'] ?? '') !== '') {
        $summary = mentora_ai_text($answer['summary']);
        $strengths = mentora_ai_list($answer['strengths'] ?? [], 3) ?: $strengths;
        $focus = mentora_ai_list($answer['focus_areas'] ?? [], 3) ?: $focus;
        $engine = mentora_ai_model_label();
    }

    $previous = $pdo->prepare('SELECT tier FROM ai_assessments WHERE user_id = ? ORDER BY id DESC LIMIT 1');
    $previous->execute([$userId]);
    $previousTier = $previous->fetchColumn() ?: null;

    $pdo->prepare('INSERT INTO ai_assessments (user_id, score, tier, ready, summary, metrics, engine) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$userId, $score, $tier, $ready ? 1 : 0, $summary, mentora_ai_encode($m + ['strengths' => $strengths, 'focus' => $focus]), mb_substr($engine, 0, 120)]);

    if ($notify && $previousTier !== $tier) {
        $title = 'AI assessment: ' . $name . ' · ' . $tier . ' (' . $score . '/100)';
        $body = ($ready ? 'Ready for employment. Add them to your employability report.' : 'Not ready for employment yet. ') . ' ' . mb_strimwidth($summary, 0, 160, '…');
        $link = 'agent/intern_assessments.php#i' . $userId;
        if (!empty($intern['agent_id'])) {
            mentora_notify($pdo, (int) $intern['agent_id'], $title, $body, $link);
        } else {
            mentora_notify_roles($pdo, ['agent'], $title, $body, $link);
        }
        if ($previousTier === null) {
            mentora_notify($pdo, $userId, 'Training assessment complete', 'Your supervising agent will review your training results and next steps with you.', 'applicant/intern/training.php');
        }
    }

    return ['score' => $score, 'tier' => $tier, 'ready' => $ready, 'summary' => $summary];
}

/**
 * Active interns ranked by their latest AI assessment (unassessed last), optionally one agent's.
 * Each row: the mentora_workspace_people() fields + assessment fields + 'rank'.
 */
function mentora_intern_rankings(PDO $pdo, ?int $agentId = null): array
{
    $interns = mentora_workspace_people($pdo, 'internship', $agentId);
    $latest = $pdo->query(
        'SELECT a.user_id, a.score, a.tier, a.ready, a.summary, a.metrics, a.engine, a.created_at AS assessed_at
         FROM ai_assessments a JOIN (SELECT user_id, MAX(id) id FROM ai_assessments GROUP BY user_id) l ON l.id = a.id'
    )->fetchAll(PDO::FETCH_UNIQUE);
    foreach ($interns as &$intern) {
        $assessment = $latest[$intern['id']] ?? null;
        $intern['ai'] = $assessment ? $assessment + ['metrics' => mentora_ai_decode($assessment['metrics'])] : null;
    }
    unset($intern);
    usort($interns, static fn(array $a, array $b): int => [$b['ai'] !== null, $b['ai']['score'] ?? 0, $b['percent']] <=> [$a['ai'] !== null, $a['ai']['score'] ?? 0, $a['percent']]);
    $rank = 0;
    foreach ($interns as &$intern) {
        $intern['rank'] = $intern['ai'] !== null ? ++$rank : null;
    }
    unset($intern);

    return $interns;
}
