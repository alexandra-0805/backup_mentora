<?php

/**
 * Agents' employability reports with their AI summary: the report card (agent/agent_reports.php,
 * admin/intern_reports.php) and the list of interns HR can employ.
 */

declare(strict_types=1);

require_once __DIR__ . '/workflow.php';
require_once __DIR__ . '/../ai/bootstrap.php';

/** One report. $hr = true adds HR's "mark reviewed" form (posts action=review to the current page). */
function mentora_render_agent_report(array $report, bool $hr): void
{
    $findings = mentora_ai_decode($report['ai_findings']);
    $reviewed = $report['status'] === 'reviewed';
    ?>
    <article class="admin-panel screening-card" id="rep<?= (int) $report['id'] ?>">
        <header class="screening-head">
            <div>
                <h2><?= mentora_h($report['title']) ?></h2>
                <p class="panel-subtitle"><?= mentora_h($report['agent_name'] ?? 'Agent') ?> · <?= date('d M Y, g:i A', strtotime($report['created_at'])) ?></p>
            </div>
            <span class="badge <?= $reviewed ? '' : 'warn' ?>"><?= $reviewed ? 'Reviewed by HR' : 'Waiting for HR' ?></span>
        </header>
        <h3>AI summary</h3>
        <p class="screening-summary"><?= $report['ai_summary'] ? mentora_h($report['ai_summary']) : '<em>Summary not available yet.</em>' ?></p>
        <?php if ($findings): ?>
            <table class="admin-table">
                <thead><tr><th>Intern</th><th>Agent</th><th>AI assessment</th><th>Verdict</th></tr></thead>
                <tbody>
                <?php foreach ($findings as $finding): [$label, $class] = MENTORA_REPORT_VERDICTS[$finding['verdict']] ?? ['—', 'info']; ?>
                    <tr>
                        <td><?= mentora_h($finding['name']) ?><br><small><?= mentora_h($finding['department'] ?? 'No team') ?></small></td>
                        <td><?= $finding['recommended'] ? 'Recommended' : 'Mentioned' ?></td>
                        <td><?= $finding['tier'] ? mentora_h($finding['tier']) . ' · ' . (int) $finding['score'] . '/100' : '—' ?></td>
                        <td><span class="badge <?= $class ?>"><?= $label ?></span><br><small><?= mentora_h($finding['reason']) ?></small></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <details class="assessment-details"><summary>Full report</summary><p><?= nl2br(mentora_h($report['body'])) ?></p></details>
        <?php if ($reviewed): ?>
            <p class="notice screening-dept"><strong>HR review:</strong> <?= mentora_h($report['reviewer_name'] ?? 'HR') ?> · <?= date('d M Y', strtotime($report['reviewed_at'])) ?><?= $report['review_note'] ? ': “' . mentora_h($report['review_note']) . '”' : '' ?></p>
        <?php endif; ?>
        <footer class="screening-actions">
            <?php if ($hr && !$reviewed): ?>
                <form method="post" class="screening-assess">
                    <?= mentora_csrf_field() ?><input type="hidden" name="action" value="review"><input type="hidden" name="id" value="<?= (int) $report['id'] ?>">
                    <input name="note" maxlength="500" placeholder="Note to the agent (optional)" aria-label="Note to the agent">
                    <button class="admin-btn primary" type="submit">Mark reviewed</button>
                </form>
            <?php endif; ?>
            <form method="post"><?= mentora_csrf_field() ?><input type="hidden" name="action" value="resummarize"><input type="hidden" name="id" value="<?= (int) $report['id'] ?>"><button class="admin-btn" type="submit" title="Summarize again with the interns’ latest AI assessments">Refresh AI summary</button></form>
        </footer>
        <small class="screening-engine">AI summary by <?= mentora_h($report['engine'] ?: MENTORA_AI_RULES_ENGINE) ?></small>
    </article>
    <?php
}

/** Reports with agent and reviewer names, newest first (optionally only those waiting for HR). */
function mentora_agent_reports(PDO $pdo, bool $waitingOnly = false): array
{
    return $pdo->query(
        "SELECT r.*, CONCAT(a.first_name, ' ', a.last_name) AS agent_name, CONCAT(v.first_name, ' ', v.last_name) AS reviewer_name
         FROM agent_reports r LEFT JOIN users a ON a.id = r.agent_id LEFT JOIN users v ON v.id = r.reviewed_by
         " . ($waitingOnly ? "WHERE r.status = 'submitted'" : '') . '
         ORDER BY r.status = \'reviewed\', r.created_at DESC'
    )->fetchAll();
}

/**
 * Interns HR can employ: ready by their AI assessment and/or recommended in an agent's report.
 * Each row: ranking fields + 'recommended_by' (agent names) + 'verdict'.
 */
function mentora_employable_interns(PDO $pdo): array
{
    $recommendations = [];
    foreach (mentora_agent_reports($pdo) as $report) {
        foreach (mentora_ai_decode($report['recommended']) as $id) {
            $recommendations[(int) $id][$report['agent_name'] ?? 'Agent'] = $report['created_at'];
        }
    }
    $rows = [];
    foreach (mentora_intern_rankings($pdo) as $intern) {
        $ready = $intern['ai'] && (int) $intern['ai']['ready'] === 1;
        $recommendedBy = array_keys($recommendations[(int) $intern['id']] ?? []);
        if (!$ready && !$recommendedBy) {
            continue;
        }
        $intern['recommended_by'] = $recommendedBy;
        $intern['verdict'] = $ready && $recommendedBy ? 'employable' : ($recommendedBy ? 'check' : 'ready_only');
        $rows[] = $intern;
    }
    usort($rows, static fn($a, $b) => [$a['verdict'] !== 'employable', -($a['ai']['score'] ?? 0)] <=> [$b['verdict'] !== 'employable', -($b['ai']['score'] ?? 0)]);

    return $rows;
}
