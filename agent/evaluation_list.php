<?php

/**
 * Evaluations (agents and HR): create one for an intern or employee (pending, they're notified),
 * open it to score (agent/individual_evaluation.php), or delete one that isn't published.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/workflow.php';

if (mentora_is_post()) {
    $me = mentora_post_user(['agent', 'admin'], 'agent/evaluation_list.php');
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'create') {
        $person = mentora_person($pdo, (int) ($_POST['user_id'] ?? 0));
        $type = (string) ($_POST['review_type'] ?? '');
        $due = (string) ($_POST['due_date'] ?? '');
        $dueDate = $due !== '' ? DateTimeImmutable::createFromFormat('!Y-m-d', $due) : null;
        $error = match (true) {
            !$person || $person['role'] !== 'applicant' || $person['status'] !== 'active' => 'Please choose an intern or employee.',
            !in_array($type, MENTORA_REVIEW_TYPES, true) => 'Please choose a review type.',
            $due !== '' && (!$dueDate || $dueDate->format('Y-m-d') !== $due) => 'Please enter a valid due date.',
            default => null,
        };
        if ($error !== null) {
            mentora_flash($error, 'error');
            mentora_redirect('agent/evaluation_list.php#create');
        }
        $pdo->prepare("INSERT INTO evaluations (user_id, evaluator_id, review_type, status, due_date) VALUES (?, ?, ?, 'pending', ?)")
            ->execute([$person['id'], $me['id'], $type, $due !== '' ? $due : null]);
        $evaluationId = (int) $pdo->lastInsertId();
        mentora_notify(
            $pdo,
            (int) $person['id'],
            'Evaluation scheduled: ' . $type,
            $due !== '' ? 'Due ' . date('d M Y', strtotime($due)) . '. Your results will appear once published.' : 'Your results will appear once published.',
            'applicant/' . mentora_pathway_folder($person['pathway']) . '/evaluation.php'
        );
        mentora_flash($type . ' created for ' . trim($person['first_name'] . ' ' . $person['last_name']) . '. Score it now or later.');
        mentora_redirect('agent/individual_evaluation.php?id=' . $evaluationId);
    }
    if ($action === 'delete') {
        $delete = $pdo->prepare("DELETE FROM evaluations WHERE id = ? AND status <> 'published'");
        $delete->execute([(int) ($_POST['id'] ?? 0)]);
        mentora_flash($delete->rowCount() ? 'Evaluation deleted.' : 'Published evaluations can’t be deleted.', $delete->rowCount() ? 'success' : 'error');
    }
    mentora_redirect('agent/evaluation_list.php');
}

$pageTitle = 'Evaluation';
$activePage = 'evaluation_list.php';
require __DIR__ . '/../includes/agent_layout.php';

$rows = $pdo->query(
    "SELECT e.id, e.review_type, e.score, e.status, e.due_date, e.published_at, e.updated_at,
            u.id AS user_id, u.first_name, u.last_name, u.email, u.pathway, CONCAT(v.first_name, ' ', v.last_name) AS evaluator_name
     FROM evaluations e
     JOIN users u ON u.id = e.user_id AND u.status = 'active'
     LEFT JOIN users v ON v.id = e.evaluator_id
     ORDER BY e.status = 'published', e.due_date IS NULL, e.due_date, e.updated_at DESC"
)->fetchAll();
$today = date('Y-m-d');
$isOverdue = static fn(array $r): bool => $r['status'] !== 'published' && $r['due_date'] !== null && $r['due_date'] < $today;
$people = mentora_workspace_people($pdo, 'all');
$preselect = (int) ($_GET['intern'] ?? 0);
$statusBadge = ['published' => ['', 'Published'], 'draft' => ['info', 'Draft'], 'pending' => ['warn', 'Pending']];
?>
<div class="admin-heading">
    <div>
        <h1>Evaluation</h1>
        <p>Review intern and employee performance and turn feedback into clear development goals.</p>
    </div>
    <div class="admin-actions">
        <a class="admin-btn" href="export.php?type=evaluations"><?= mentora_icon('download') ?>Export evaluations</a><a class="admin-btn primary" href="#create">+ Create evaluation</a>
    </div>
</div>
<?= mentora_flash_html() ?>
<div class="metric-grid">
    <div class="metric-card">
        <small>Published evaluations</small><strong><?= count(array_filter($rows, static fn($r) => $r['status'] === 'published')) ?></strong><span>Visible to the person</span>
    </div>
    <div class="metric-card">
        <small>Draft evaluations</small><strong><?= count(array_filter($rows, static fn($r) => $r['status'] === 'draft')) ?></strong><span>Continue reviewing</span>
    </div>
    <div class="metric-card">
        <small>Pending evaluations</small><strong><?= count(array_filter($rows, static fn($r) => $r['status'] === 'pending')) ?></strong><span>Assigned, not started</span>
    </div>
    <div class="metric-card">
        <small>Overdue evaluations</small><strong><?= count(array_filter($rows, $isOverdue)) ?></strong><span><?= count(array_filter($rows, $isOverdue)) ? 'Past the due date' : 'Nothing overdue' ?></span>
    </div>
</div>
<section class="admin-panel data-panel">
    <div class="table-toolbar" data-table-filter="evaluation-table">
        <input type="search" data-filter="search" placeholder="Search person or evaluation" aria-label="Search evaluations">
        <select data-filter="status" aria-label="Status">
            <option value="all">All statuses</option>
            <option value="pending">Pending</option>
            <option value="draft">Draft</option>
            <option value="published">Published</option>
            <option value="overdue">Overdue</option>
        </select>
        <select data-filter="pathway" aria-label="Pathway">
            <option value="all">Interns &amp; employees</option>
            <option value="intern">Interns</option>
            <option value="employee">Employees</option>
        </select>
    </div>
    <table class="admin-table" id="evaluation-table">
        <thead>
            <tr>
                <th>Person</th>
                <th>Review type</th>
                <th>Due date</th>
                <th>Score</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $row): [$badgeClass, $badgeText] = $statusBadge[$row['status']]; $overdue = $isOverdue($row); ?>
            <tr data-status="<?= $overdue ? 'overdue' : $row['status'] ?>" data-pathway="<?= mentora_pathway_folder($row['pathway']) ?>">
                <td><?= mentora_h($row['first_name'] . ' ' . $row['last_name']) ?><br><small><?= mentora_pathway_label($row['pathway']) ?> · <?= mentora_h($row['email']) ?></small>
                </td>
                <td><?= mentora_h($row['review_type']) ?><br><small><?= mentora_h($row['evaluator_name'] ?? '') ?></small></td>
                <td><?= $row['due_date'] ? date('d M Y', strtotime($row['due_date'])) : 'Not set' ?></td>
                <td><?= $row['score'] !== null ? mentora_hours($row['score']) . ' / 5' : '—' ?></td>
                <td><span class="badge <?= $overdue ? 'warn' : $badgeClass ?>"><?= $overdue ? 'Overdue' : $badgeText ?></span></td>
                <td class="row-actions"><a class="admin-btn<?= $row['status'] === 'published' ? '' : ' primary' ?>"
                    href="individual_evaluation.php?id=<?= (int) $row['id'] ?>"><?= $row['status'] === 'published' ? 'View' : 'Review' ?></a>
                    <?php if ($row['status'] !== 'published'): ?>
                    <form method="post"><?= mentora_csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $row['id'] ?>"><button class="admin-btn" type="submit" data-confirm-title="Delete this evaluation?" data-confirm="The <?= mentora_h($row['review_type']) ?> for <?= mentora_h($row['first_name']) ?> will be removed." data-confirm-action="Delete">Delete</button>
                    </form>
                    <?php endif; ?>
                    </td>
            </tr>
        <?php endforeach; ?>
    </tbody></table>
    <div class="table-note">Showing <span data-filter-count="evaluation-table"><?= count($rows) ?></span> evaluation(s).</div>
</section>
<section class="admin-panel" id="create" style="margin-top:16px">
    <h2>Create evaluation</h2>
    <p class="panel-subtitle">The person is notified, and the evaluation stays private until you publish it.</p>
    <form method="post" class="form-grid">
        <?= mentora_csrf_field() ?><input type="hidden" name="action" value="create">
        <div class="field">
            <label for="user_id">Intern or employee *</label><select id="user_id" name="user_id" required>
                <option value="">Choose a person</option>
            <?php foreach (['internship' => 'Interns', 'employee_va' => 'Employees'] as $pathwayKey => $groupLabel): ?>
            <optgroup label="<?= $groupLabel ?>"><?php foreach ($people as $person): if ($person['pathway'] !== $pathwayKey) { continue; } ?>
                <option value="<?= (int) $person['id'] ?>"<?= $preselect === (int) $person['id'] ? ' selected' : '' ?>><?= mentora_h($person['name'] . ' · ' . $person['email']) ?></option>
                <?php endforeach; ?>
            </optgroup>
            <?php endforeach; ?>
        </select></div>
        <div class="field">
            <label for="review_type">Review type *</label><select id="review_type" name="review_type" required><?php foreach (MENTORA_REVIEW_TYPES as $type): ?>
                <option<?= $type === 'Midpoint evaluation' ? ' selected' : '' ?>><?= $type ?></option>
                <?php endforeach; ?></select>
        </div>
        <div class="field">
            <label for="due_date">Due date</label><input id="due_date"
                type="date"
                name="due_date"
                min="<?= $today ?>"
                value="<?= date('Y-m-d', strtotime('+7 days')) ?>"></div>
        <div class="field">
            <label>&nbsp;</label><button class="admin-btn primary" type="submit"<?= $people ? '' : ' disabled' ?>>Create evaluation</button>
        </div>
    </form>
</section>
<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
