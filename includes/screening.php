<?php

/**
 * AI screening of Employee VA applications: lists, the candidate card and the department skill-gap panel.
 * Shared by HR (admin/screening.php) and the department's agents (agent/candidates.php).
 *
 * A candidate's decision is the application's status: pending (waiting) -> approved (accepted into a
 * department) or rejected (declined). The AI category comes from their latest ai_reviews row.
 */

declare(strict_types=1);

require_once __DIR__ . '/workflow.php';
require_once __DIR__ . '/../ai/bootstrap.php';

/** view => [tab label, description] */
const MENTORA_SCREENING_VIEWS = [
    'suitable' => ['Suitable', 'Fills a department’s lacking skills: assess them first'],
    'review' => ['For review', 'For HR to review, with the AI summary of skills and attainment'],
    'flagged' => ['Flagged unsuitable', 'Part of For review, flagged unsuitable by the AI'],
    'accepted' => ['Accepted', 'Accepted and assigned to a department'],
    'declined' => ['Declined', 'Not selected'],
];

const MENTORA_SCREENING_VIEW_SQL = [
    'suitable' => "r.category = 'suitable' AND a.status = 'pending'",
    'review' => "r.category IN ('for_review', 'unsuitable') AND a.status = 'pending'",
    'flagged' => "r.category = 'unsuitable' AND a.status = 'pending'",
    'accepted' => "a.status = 'approved'",
    'declined' => "a.status = 'rejected'",
];

/** FROM ... for every screened, active Employee VA: their latest review and latest application. */
const MENTORA_SCREENING_FROM = "FROM ai_reviews r
    JOIN (SELECT user_id, MAX(id) AS id FROM ai_reviews GROUP BY user_id) latest ON latest.id = r.id
    JOIN users u ON u.id = r.user_id AND u.role = 'applicant' AND u.status = 'active' AND u.pathway = 'employee_va'
    JOIN applications a ON a.id = (SELECT MAX(id) FROM applications WHERE user_id = u.id)";

function mentora_screening_rows(PDO $pdo, string $view, ?int $departmentId = null): array
{
    $where = MENTORA_SCREENING_VIEW_SQL[$view] ?? MENTORA_SCREENING_VIEW_SQL['suitable'];
    $params = [];
    if ($departmentId !== null) {
        $where .= ' AND r.department_id = ?';
        $params[] = $departmentId;
    }
    $query = $pdo->prepare(
        "SELECT r.*, u.first_name, u.last_name, u.email, u.user_code, u.referred_by, u.department_id AS assigned_department_id,
                d.name AS department_name, ad.name AS assigned_department_name,
                CONCAT(ref.first_name, ' ', ref.last_name) AS referrer_name,
                CONCAT(dr.first_name, ' ', dr.last_name) AS dept_reviewer_name,
                a.status AS application_status, a.desired_role, a.education, a.experience_years, a.submission_mode,
                a.resume_document_id, a.portfolio_url, a.created_at AS applied_at, a.reviewed_at AS decided_at
         " . MENTORA_SCREENING_FROM . "
         LEFT JOIN departments d ON d.id = r.department_id
         LEFT JOIN departments ad ON ad.id = u.department_id
         LEFT JOIN users ref ON ref.id = u.referred_by
         LEFT JOIN users dr ON dr.id = r.dept_reviewer_id
         WHERE $where
         ORDER BY r.score DESC, r.id DESC"
    );
    $query->execute($params);
    $rows = $query->fetchAll();
    foreach ($rows as &$row) {
        foreach (['skills', 'matched_skills', 'gap_skills', 'strengths', 'concerns'] as $field) {
            $row[$field] = mentora_ai_decode($row[$field]);
        }
        $row['name'] = trim($row['first_name'] . ' ' . $row['last_name']);
    }
    unset($row);

    return $rows;
}

/** Number of candidates in each view (optionally one department's). */
function mentora_screening_counts(PDO $pdo, ?int $departmentId = null): array
{
    $sums = [];
    foreach (MENTORA_SCREENING_VIEW_SQL as $view => $sql) {
        $sums[] = "COALESCE(SUM($sql), 0) AS `$view`";
    }
    $query = $pdo->prepare('SELECT ' . implode(', ', $sums) . ' ' . MENTORA_SCREENING_FROM . ($departmentId !== null ? ' WHERE r.department_id = ?' : ''));
    $query->execute($departmentId !== null ? [$departmentId] : []);

    return array_map('intval', $query->fetch() ?: array_fill_keys(array_keys(MENTORA_SCREENING_VIEWS), 0));
}

/** Employee VAs with a submitted application that hasn't been screened yet (e.g. applied before AI review). */
function mentora_unscreened_employees(PDO $pdo): array
{
    return $pdo->query(
        "SELECT u.id, u.first_name, u.last_name, u.email, a.created_at AS applied_at
         FROM users u JOIN applications a ON a.id = (SELECT MAX(id) FROM applications WHERE user_id = u.id)
         WHERE u.role = 'applicant' AND u.status = 'active' AND u.pathway = 'employee_va' AND a.status = 'pending'
           AND NOT EXISTS (SELECT 1 FROM ai_reviews r WHERE r.user_id = u.id)
         ORDER BY a.created_at"
    )->fetchAll();
}

/** Skills as chips; $highlight ones (a department's lacking skills) stand out. */
function mentora_skill_chips(array $skills, array $highlight = [], string $empty = 'None found'): string
{
    if (!$skills) {
        return '<small class="panel-subtitle">' . mentora_h($empty) . '</small>';
    }
    $lower = array_map('mb_strtolower', $highlight);
    $html = '<span class="skill-chips">';
    foreach ($skills as $skill) {
        $isGap = in_array(mb_strtolower((string) $skill), $lower, true);
        $html .= '<span class="skill-chip' . ($isGap ? ' is-gap' : '') . '"' . ($isGap ? ' title="A skill this department is lacking"' : '') . '>' . mentora_h($skill) . '</span>';
    }

    return $html . '</span>';
}

/**
 * One candidate. $mode 'admin': accept into a department / decline / re-run / reopen.
 * $mode 'agent': the department's assessment (endorse or not, with a note). Forms post to the current page.
 */
function mentora_render_screening_card(array $row, string $mode, array $departments): void
{
    [$categoryLabel, $categoryClass] = MENTORA_AI_CATEGORIES[$row['category']] ?? ['—', 'info'];
    $userId = (int) $row['user_id'];
    $pending = $row['application_status'] === 'pending';
    $hidden = mentora_csrf_field() . '<input type="hidden" name="user_id" value="' . $userId . '"><input type="hidden" name="view" value="' . mentora_h($_GET['view'] ?? '') . '">';
    $details = array_filter([
        $row['email'],
        $row['desired_role'] ? 'Applying as ' . $row['desired_role'] : null,
        'Applied ' . date('d M Y', strtotime($row['applied_at'])),
        MENTORA_SUBMISSION_MODES[$row['submission_mode']] ?? 'Form',
        $row['referrer_name'] ? 'Referred by ' . $row['referrer_name'] : null,
    ]);
    ?>
    <article class="admin-panel screening-card" id="r<?= $userId ?>">
        <header class="screening-head">
            <div>
                <h2><?= mentora_h($row['name']) ?> <small><?= mentora_h($row['user_code']) ?></small></h2>
                <p class="panel-subtitle"><?= mentora_h(implode(' · ', $details)) ?></p>
            </div>
            <div class="screening-score" aria-label="AI score <?= (int) $row['score'] ?> out of 100">
                <strong><?= (int) $row['score'] ?></strong><small>/100</small>
                <span class="badge <?= $categoryClass ?>"><?= mentora_h($categoryLabel) ?></span>
            </div>
        </header>
        <p class="screening-summary"><?= mentora_h($row['summary']) ?></p>
        <div class="screening-grid">
            <div>
                <h3>Best fit: <?= mentora_h($row['department_name'] ?? 'No department match') ?></h3>
                <?= mentora_skill_chips($row['skills'], $row['gap_skills']) ?>
                <?php if ($row['gap_skills']): ?><small class="screening-note">Highlighted: skills <?= mentora_h($row['department_name'] ?? '') ?> is lacking.</small><?php endif; ?>
            </div>
            <div>
                <h3>Strengths</h3>
                <?php if (!$row['strengths']): ?><small class="panel-subtitle">None noted.</small><?php endif; ?>
                <ul class="screening-list"><?php foreach ($row['strengths'] as $item): ?><li><?= mentora_h($item) ?></li><?php endforeach; ?></ul>
            </div>
            <div>
                <h3>Concerns</h3>
                <?php if (!$row['concerns']): ?><small class="panel-subtitle">None noted.</small><?php endif; ?>
                <ul class="screening-list is-concern"><?php foreach ($row['concerns'] as $item): ?><li><?= mentora_h($item) ?></li><?php endforeach; ?></ul>
            </div>
        </div>
        <?php if ($row['dept_verdict']): ?>
            <p class="notice screening-dept">
                <strong><?= mentora_h($row['department_name'] ?? 'Department') ?> assessment:</strong>
                <?= $row['dept_verdict'] === 'endorsed' ? 'Endorsed' : 'Not endorsed' ?> by <?= mentora_h($row['dept_reviewer_name'] ?? 'an agent') ?>
                · <?= date('d M Y', strtotime($row['dept_reviewed_at'])) ?><?= $row['dept_note'] ? ': “' . mentora_h($row['dept_note']) . '”' : '' ?>
            </p>
        <?php endif; ?>
        <?php if (!$pending): ?>
            <p class="notice screening-dept">
                <strong><?= $row['application_status'] === 'approved' ? 'Accepted' : 'Declined' ?></strong>
                <?= $row['decided_at'] ? ' on ' . date('d M Y', strtotime($row['decided_at'])) : '' ?>
                <?= $row['application_status'] === 'approved' && $row['assigned_department_name'] ? ' · assigned to ' . mentora_h($row['assigned_department_name']) : '' ?>
            </p>
        <?php endif; ?>
        <footer class="screening-actions">
            <?php if ($row['resume_document_id']): ?>
                <a class="admin-btn" href="../account/document.php?id=<?= (int) $row['resume_document_id'] ?>" target="_blank" rel="noopener"><?= mentora_icon('view') ?>Open resume</a>
            <?php endif; ?>
            <?php if (!empty($row['portfolio_url']) && mentora_portfolio_url_valid((string) $row['portfolio_url'])): ?>
                <?php // The applicant's own link: opened in a new tab without passing on this page (noreferrer). ?>
                <a class="admin-btn" href="<?= mentora_h($row['portfolio_url']) ?>" target="_blank" rel="noopener noreferrer nofollow"
                    title="<?= mentora_h($row['portfolio_url']) ?>"><?= mentora_icon('view') ?>Open portfolio</a>
            <?php endif; ?>
            <a class="admin-btn" href="../account/messages.php?to=<?= $userId ?>">Message</a>
            <?php if ($mode === 'admin'): ?>
                <a class="admin-btn" href="user_edit.php?id=<?= $userId ?>">Profile</a>
                <?php if ($pending): ?>
                    <form method="post" class="screening-accept">
                        <?= $hidden ?><input type="hidden" name="action" value="accept">
                        <select name="department_id" aria-label="Department for <?= mentora_h($row['name']) ?>" required>
                            <option value="">Assign to department…</option>
                            <?php foreach ($departments as $department): ?>
                                <option value="<?= (int) $department['id'] ?>"<?= (int) $department['id'] === (int) $row['department_id'] ? ' selected' : '' ?>><?= mentora_h($department['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button class="admin-btn primary" type="submit" data-confirm-title="Accept <?= mentora_h($row['name']) ?>?" data-confirm="They’re assigned to the chosen department and notified, and the department’s agents are told." data-confirm-action="Accept">Accept</button>
                    </form>
                    <form method="post"><?= $hidden ?><input type="hidden" name="action" value="decline"><button class="admin-btn" type="submit" data-confirm-title="Decline <?= mentora_h($row['name']) ?>?" data-confirm="They’re told their application wasn’t selected this time. You can reopen it later." data-confirm-tone="danger" data-confirm-action="Decline">Decline</button></form>
                <?php else: ?>
                    <form method="post"><?= $hidden ?><input type="hidden" name="action" value="reopen"><button class="admin-btn" type="submit" data-confirm-title="Reopen <?= mentora_h($row['name']) ?>’s application?" data-confirm="It goes back to the screening lists so you can decide again.">Reopen</button></form>
                <?php endif; ?>
                <form method="post"><?= $hidden ?><input type="hidden" name="action" value="rerun"><button class="admin-btn" type="submit" title="Read the application again with the current department skill gaps">Re-run AI review</button></form>
            <?php elseif ($pending): ?>
                <form method="post" class="screening-assess">
                    <?= $hidden ?><input type="hidden" name="action" value="assess">
                    <input name="note" maxlength="1000" placeholder="Your assessment for HR (optional)" aria-label="Assessment note for <?= mentora_h($row['name']) ?>" value="<?= mentora_h($row['dept_note'] ?? '') ?>">
                    <button class="admin-btn primary" type="submit" name="verdict" value="endorsed" data-confirm-title="Endorse <?= mentora_h($row['name']) ?>?" data-confirm="HR is told your department wants this candidate.">Endorse to HR</button>
                    <button class="admin-btn" type="submit" name="verdict" value="not_endorsed" data-confirm-title="Don’t endorse <?= mentora_h($row['name']) ?>?" data-confirm="HR sees your note when deciding.">Not a fit</button>
                </form>
            <?php endif; ?>
        </footer>
        <small class="screening-engine">AI review by <?= mentora_h($row['engine']) ?> · <?= date('d M Y, g:i A', strtotime($row['created_at'])) ?></small>
    </article>
    <?php
}

/** Departments with their required and lacking skills (from mentora_department_skill_map()). */
function mentora_render_skill_gaps(array $map, string $title = 'Department skill gaps'): void
{
    ?>
    <section class="admin-panel data-panel skill-gap-panel">
        <h2><?= mentora_h($title) ?></h2>
        <p class="panel-subtitle">A skill is lacking when fewer than <?= MENTORA_SKILL_TARGET ?> people in the team have it. HR sets each department’s required skills in Departments.</p>
        <table class="admin-table">
            <thead><tr><th>Department</th><th>People</th><th>Lacking skills</th><th>Covered</th></tr></thead>
            <tbody>
            <?php foreach ($map as $department): $covered = array_values(array_diff($department['required'], $department['lacking'])); ?>
                <tr>
                    <td><?= mentora_h($department['name']) ?></td>
                    <td><?= (int) $department['people'] ?></td>
                    <td><?= $department['required']
                        ? mentora_skill_chips($department['lacking'], $department['lacking'], 'No gaps')
                        : '<small class="panel-subtitle">No required skills set</small>' ?></td>
                    <td><?= mentora_skill_chips($covered, [], '—') ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$map): ?><tr><td colspan="4" class="table-note">No departments yet.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </section>
    <?php
}
