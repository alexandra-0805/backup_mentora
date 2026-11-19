<?php

/**
 * Applicant Evaluation (applicant/intern/evaluation.php, applicant/employee/evaluation.php; $pathway set there).
 * Shows this person's evaluations written by an agent (agent/individual_evaluation.php). Only published
 * ones show scores and feedback; drafts and pending reviews show as "In review".
 */

declare(strict_types=1);

require_once __DIR__ . '/../workflow.php';

$folder = mentora_pathway_folder($pathway);
$pageTitle = $folder === 'intern' ? 'Evaluation' : 'Employee evaluation';
$activePage = 'evaluation.php';
require __DIR__ . '/../applicant_layout.php';

$userId = (int) $currentUser['id'];
$isIntern = $folder === 'intern';
$criteria = mentora_evaluation_criteria($folder);

$evaluations = [];
if ($userId > 0) {
    $query = $pdo->prepare('SELECT e.*, CONCAT(v.first_name, " ", v.last_name) AS evaluator_name FROM evaluations e LEFT JOIN users v ON v.id = e.evaluator_id WHERE e.user_id = ? ORDER BY COALESCE(e.published_at, e.updated_at) DESC, e.id DESC');
    $query->execute([$userId]);
    $evaluations = $query->fetchAll();
}
$published = array_values(array_filter($evaluations, static fn($e) => $e['status'] === 'published'));
$inReview = array_values(array_filter($evaluations, static fn($e) => $e['status'] !== 'published'));
$selectedId = (int) ($_GET['id'] ?? 0);
$latest = null;
foreach ($published as $evaluation) {
    if ($selectedId === 0 || (int) $evaluation['id'] === $selectedId) {
        $latest = $evaluation;
        break;
    }
}
$latest ??= $published[0] ?? null;
$ratings = $latest ? (json_decode((string) $latest['ratings'], true) ?: []) : [];
$person = $userId > 0 ? mentora_person($pdo, $userId) : null;
$offerText = match (true) {
    !$latest => ['In review', 'After your evaluation'],
    !empty($person['department_id']) => ['Accepted', 'Welcome to ' . $person['department_name']],
    default => ['In progress', 'HR will contact you'],
};
?>
<div class="admin-heading"><div><h1><?= $isIntern ? 'Evaluation' : 'Employee evaluation' ?></h1><p><?= $isIntern ? 'Review your evaluation scores, criteria results, feedback and next steps.' : 'Your evaluation results, feedback, and next steps in the employee pathway.' ?></p></div><?php if ($latest): ?><button class="admin-btn" type="button" onclick="window.print()">Download evaluation</button><?php endif; ?></div>
<div class="metric-grid">
    <div class="metric-card"><small>Overall evaluation</small><strong><?= $latest && $latest['score'] !== null ? mentora_hours($latest['score']) . ' / 5' : 'Pending' ?></strong><span><?= $latest ? mentora_h($latest['review_type']) . ' · ' . date('d M Y', strtotime($latest['published_at'] ?? $latest['updated_at'])) : ($inReview ? 'In review' : 'Not started') ?></span></div>
    <div class="metric-card"><small><?= $isIntern ? 'Evaluations published' : 'Interview result' ?></small><strong><?= $isIntern ? count($published) : ($latest ? 'Passed' : 'Pending') ?></strong><span><?= $isIntern ? count($inReview) . ' in review' : 'People team review' ?></span></div>
    <div class="metric-card"><small><?= $isIntern ? 'Evaluator' : 'Offer status' ?></small><strong><?= $isIntern ? mentora_h($latest['evaluator_name'] ?? '—') : $offerText[0] ?></strong><span><?= $isIntern ? 'Supervising agent' : mentora_h($offerText[1]) ?></span></div>
</div>
<div class="admin-grid">
    <section class="pathway-card">
        <h3>Evaluation criteria & results</h3>
        <table class="admin-table"><thead><tr><th>Criterion</th><th>Rating</th><th>Result</th></tr></thead><tbody>
            <?php foreach ($criteria as $criterion): $rating = isset($ratings[$criterion]) ? (int) $ratings[$criterion] : null; ?>
                <tr><td><?= mentora_h($criterion) ?></td><td><?= $rating !== null ? $rating . ' / 5' : '— / 5' ?></td><td><?= $rating === null ? 'Awaiting evaluator notes' : ($rating >= 4 ? 'Strong' : ($rating === 3 ? 'Meets expectations' : 'Needs development')) ?></td></tr>
            <?php endforeach; ?>
        </tbody></table>
    </section>
    <section class="pathway-card">
        <h3>Feedback & next steps</h3>
        <?php if ($latest): ?>
            <p><strong>Strengths</strong><br><?= nl2br(mentora_h($latest['strengths'] ?: '—')) ?></p>
            <p><strong>Areas for improvement</strong><br><?= nl2br(mentora_h($latest['improvements'] ?: '—')) ?></p>
            <p><strong>Agreed next steps</strong><br><?= mentora_h($latest['next_steps'] ?: '—') ?></p>
        <?php else: ?>
            <p>Your evaluation results and development feedback will appear here once published by your evaluator.</p>
        <?php endif; ?>
        <a class="admin-btn primary" href="training.php">View training plan</a>
    </section>
</div>
<?php if (count($published) > 1 || $inReview): ?>
    <section class="pathway-card data-panel">
        <h3>Evaluation history</h3>
        <table class="admin-table"><thead><tr><th>Review</th><th>Date</th><th>Score</th><th>Status</th></tr></thead><tbody>
            <?php foreach ($evaluations as $evaluation): $isPublished = $evaluation['status'] === 'published'; ?>
                <tr><td><?= $isPublished ? '<a href="evaluation.php?id=' . (int) $evaluation['id'] . '">' . mentora_h($evaluation['review_type']) . '</a>' : mentora_h($evaluation['review_type']) ?></td>
                    <td><?= $isPublished ? date('d M Y', strtotime($evaluation['published_at'] ?? $evaluation['updated_at'])) : ($evaluation['due_date'] ? 'Due ' . date('d M Y', strtotime($evaluation['due_date'])) : '—') ?></td>
                    <td><?= $isPublished && $evaluation['score'] !== null ? mentora_hours($evaluation['score']) . ' / 5' : '—' ?></td>
                    <td><span class="badge <?= $isPublished ? '' : 'info' ?>"><?= $isPublished ? 'Published' : 'In review' ?></span></td></tr>
            <?php endforeach; ?>
        </tbody></table>
    </section>
<?php endif; ?>
<div class="applicant-banner"><strong><?= $latest ? 'Your evaluation is private.' : 'Evaluation results will appear here once published.' ?></strong><?= $latest ? 'Only you and authorized Mentora team members can see it.' : 'Contact the people team if you need clarification.' ?></div>
<?php require __DIR__ . '/../applicant_footer.php'; ?>
