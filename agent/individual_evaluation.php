<?php

/**
 * Score one evaluation (agents and HR): a 1-5 rating per criterion (overall score = average),
 * strengths, improvements and next steps. "Save draft" keeps it private; "Publish" shows it to the
 * intern / employee (their Evaluation page) and notifies them.
 *   individual_evaluation.php          list of evaluations to review
 *   individual_evaluation.php?id=7     score one
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/workflow.php';

$loadEvaluation = static function (PDO $pdo, int $id): ?array {
    $query = $pdo->prepare(
        "SELECT e.*, u.first_name, u.last_name, u.email, u.pathway, u.user_code, CONCAT(v.first_name, ' ', v.last_name) AS evaluator_name
         FROM evaluations e JOIN users u ON u.id = e.user_id LEFT JOIN users v ON v.id = e.evaluator_id WHERE e.id = ?"
    );
    $query->execute([$id]);
    return $query->fetch() ?: null;
};

if (mentora_is_post()) {
    $id = (int) ($_POST['id'] ?? 0);
    $me = mentora_post_user(['agent', 'admin'], 'agent/individual_evaluation.php?id=' . $id);
    $evaluation = $loadEvaluation($pdo, $id);
    if (!$evaluation) {
        mentora_flash('That evaluation couldn’t be found.', 'error');
        mentora_redirect('agent/evaluation_list.php');
    }
    $publish = ($_POST['action'] ?? '') === 'publish';
    $criteria = mentora_evaluation_criteria($evaluation['pathway']);
    $ratings = [];
    foreach ($criteria as $index => $criterion) {
        $rating = (int) ($_POST['rating'][$index] ?? 0);
        if ($rating >= 1 && $rating <= 5) {
            $ratings[$criterion] = $rating;
        }
    }
    $strengths = mb_substr(trim((string) ($_POST['strengths'] ?? '')), 0, 4000);
    $improvements = mb_substr(trim((string) ($_POST['improvements'] ?? '')), 0, 4000);
    $nextSteps = mb_substr(trim((string) ($_POST['next_steps'] ?? '')), 0, 255);
    if ($publish && (count($ratings) < count($criteria) || $strengths === '' || $improvements === '' || $nextSteps === '')) {
        $_SESSION['evaluation_draft'] = compact('ratings', 'strengths', 'improvements', 'nextSteps') + ['id' => $id];
        mentora_flash('To publish, rate every criterion and fill in strengths, improvements and next steps.', 'error');
        mentora_redirect('agent/individual_evaluation.php?id=' . $id);
    }
    $score = $ratings ? round(array_sum($ratings) / count($ratings), 1) : null;
    $status = $publish ? 'published' : ($evaluation['status'] === 'published' ? 'published' : 'draft');
    $pdo->prepare('UPDATE evaluations
        SET evaluator_id = ?, ratings = ?, score = ?, strengths = ?, improvements = ?, next_steps = ?, feedback = ?, status = ?, published_at = IF(? AND published_at IS NULL, NOW(), published_at)
        WHERE id = ?')
        ->execute([
            $me['id'],
            json_encode($ratings, JSON_UNESCAPED_UNICODE),
            $score,
            $strengths ?: null,
            $improvements ?: null,
            $nextSteps ?: null,
            $strengths ?: null,
            $status,
            $publish ? 1 : 0,
            $id
        ]);
    if ($publish) {
        mentora_notify(
            $pdo,
            (int) $evaluation['user_id'],
            'Your ' . mb_strtolower($evaluation['review_type']) . ' is ready',
            'Overall score ' . mentora_hours($score) . ' / 5. Open it to see your feedback and next steps.',
            'applicant/' . mentora_pathway_folder($evaluation['pathway']) . '/evaluation.php?id=' . $id
        );
        mentora_flash('Evaluation published. ' . $evaluation['first_name'] . ' can see it now.');
    } else {
        mentora_flash('Draft saved. ' . $evaluation['first_name'] . ' won’t see it until you publish.');
    }
    mentora_redirect('agent/individual_evaluation.php?id=' . $id);
}

$pageTitle = 'Individual evaluation';
$activePage = 'individual_evaluation.php';
require __DIR__ . '/../includes/agent_layout.php';

$evaluation = $loadEvaluation($pdo, (int) ($_GET['id'] ?? 0));
if (!$evaluation):
    $open = $pdo->query("SELECT e.id, e.review_type, e.status, e.due_date, u.first_name, u.last_name, u.pathway
        FROM evaluations e
        JOIN users u ON u.id = e.user_id AND u.status = 'active'
        WHERE e.status <> 'published'
        ORDER BY e.due_date IS NULL, e.due_date")
        ->fetchAll();
?>
    <div class="admin-heading">
        <div>
            <h1>Individual evaluation</h1>
            <p>Choose an evaluation to score, or create a new one.</p>
        </div>
        <a class="admin-btn primary" href="evaluation_list.php#create">+ Create evaluation</a>
    </div>
    <?= mentora_flash_html() ?>
    <section class="admin-panel data-panel">
        <h2>Waiting for your review</h2>
        <?php if (!$open): ?><p class="panel-subtitle">Nothing to review. Create an evaluation from the Evaluation list.</p><?php endif; ?>
        <?php foreach ($open as $row): ?>
            <div class="list-row">
                <span><?= mentora_h($row['first_name'] . ' ' . $row['last_name']) ?> · <?= mentora_h($row['review_type']) ?><br><small><?= mentora_pathway_label($row['pathway']) ?> · <?= $row['due_date']
                    ? 'Due ' . date('d M Y', strtotime($row['due_date']))
                    : 'No due date' ?></small></span><a class="admin-btn primary"
                        href="individual_evaluation.php?id=<?= (int) $row['id'] ?>"><?= mentora_icon('view') ?>Review</a>
            </div>
        <?php endforeach; ?>
    </section>
<?php else:
    $criteria = mentora_evaluation_criteria($evaluation['pathway']);
    $saved = json_decode((string) $evaluation['ratings'], true) ?: [];
    $draft = $_SESSION['evaluation_draft'] ?? null;
    unset($_SESSION['evaluation_draft']);
    if (is_array($draft) && (int) $draft['id'] === (int) $evaluation['id']) {
        $saved = $draft['ratings'];
        $evaluation['strengths'] = $draft['strengths'];
        $evaluation['improvements'] = $draft['improvements'];
        $evaluation['next_steps'] = $draft['nextSteps'];
    }
    $name = trim($evaluation['first_name'] . ' ' . $evaluation['last_name']);
    $isPublished = $evaluation['status'] === 'published';
?>
    <form method="post">
        <?= mentora_csrf_field() ?><input type="hidden" name="id" value="<?= (int) $evaluation['id'] ?>">
        <div class="admin-heading keep-title">
            <div>
                <h1><?= mentora_h($name) ?> / <?= mentora_h($evaluation['review_type']) ?></h1>
                <p><?= mentora_pathway_label($evaluation['pathway']) ?> · <?= mentora_h($evaluation['user_code']) ?> · <?= $isPublished
                    ? 'Published ' . date('d M Y', strtotime($evaluation['published_at']))
                    : ($evaluation['due_date'] ? 'Due ' . date('d M Y', strtotime($evaluation['due_date'])) : 'No due date') ?></p>
            </div>
            <div class="admin-actions">
                <a class="admin-btn" href="evaluation_list.php">← All evaluations</a>
                <?php if (!$isPublished): ?><button class="admin-btn"
                    type="submit"
                    name="action"
                    value="draft"
                    data-confirm-title="Save this evaluation as a draft?"
                    data-confirm="Your scores and feedback will be saved. <?= mentora_h($evaluation['first_name']) ?> won't see them until you publish.">Save draft</button><?php endif; ?>
                <button class="admin-btn primary"
                    type="submit"
                    name="action"
                    value="publish"
                    data-confirm-title="<?= $isPublished ? 'Update the published evaluation?' : 'Publish this evaluation?' ?>"
                    data-confirm="<?= mentora_h($evaluation['first_name']) ?> will be able to see their scores, feedback and next steps."><?= $isPublished ? 'Update published' : 'Publish evaluation' ?></button>
            </div>
        </div>
        <?= mentora_flash_html() ?>
        <section class="admin-panel">
            <h2>Performance scoring</h2>
            <p class="panel-subtitle">Rate each criterion from 1 (needs development) to 5 (excellent). The overall score is the average<?= $evaluation['score'] !== null
                ? ': currently <strong>' . mentora_hours($evaluation['score']) . ' / 5</strong>'
                : '' ?>.</p>
            <table class="admin-table"><thead><tr><th>Criterion</th><th>Weight</th><th>Rating</th></tr></thead><tbody>
                <?php foreach ($criteria as $index => $criterion): $current = (int) ($saved[$criterion] ?? 0); ?>
                    <tr>
                        <td><label for="rating-<?= $index ?>"><?= mentora_h($criterion) ?></label>
                        </td>
                        <td><?= round(100 / count($criteria)) ?>%</td>
                        <td><select id="rating-<?= $index ?>" name="rating[<?= $index ?>]"><option value="">Not rated</option>
                                <?php for ($r = 5; $r >= 1; $r--): ?>
                                <option value="<?= $r ?>"<?= $current === $r ? ' selected' : '' ?>><?= $r ?> / 5</option>
                                <?php endfor; ?></select>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody></table>
        </section>
        <section class="admin-panel" style="margin-top:16px">
            <h2>Feedback & development plan</h2>
            <div class="form-grid">
                <div class="field">
                    <label for="strengths">Strengths *</label><textarea id="strengths" name="strengths" rows="4" maxlength="4000"><?= mentora_h($evaluation['strengths'] ?? '') ?></textarea>
                </div>
                <div class="field">
                    <label for="improvements">Areas for improvement *</label><textarea id="improvements" name="improvements" rows="4" maxlength="4000"><?= mentora_h($evaluation['improvements'] ?? '') ?></textarea>
                </div>
                <div class="field full">
                    <label for="next_steps">Agreed next steps *</label><input id="next_steps" name="next_steps" maxlength="255" value="<?= mentora_h($evaluation['next_steps'] ?? '') ?>"></div>
            </div>
        </section>
    </form>
<?php endif; ?>
<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
