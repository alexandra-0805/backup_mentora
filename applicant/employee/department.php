<?php
// The employee's department (users.department_id, set by HR), colleagues and latest feedback from reviews.
$pathway = 'employee';
$pageTitle = 'Assigned department';
$activePage = 'department.php';
require_once __DIR__ . '/../../includes/workflow.php';
require __DIR__ . '/../../includes/applicant_layout.php';

$userId = (int) $currentUser['id'];
$person = $userId > 0 ? mentora_person($pdo, $userId) : null;
$department = null;
$colleagues = [];
if (!empty($person['department_id'])) {
    $query = $pdo->prepare('SELECT name, description FROM departments WHERE id = ?');
    $query->execute([$person['department_id']]);
    $department = $query->fetch() ?: null;
    $query = $pdo->prepare("SELECT first_name, last_name, role, pathway
        FROM users
        WHERE department_id = ? AND id <> ? AND status = 'active'
        ORDER BY role, first_name LIMIT 12");
    $query->execute([$person['department_id'], $userId]);
    $colleagues = $query->fetchAll();
}
// Latest written feedback: a reviewed report note or the latest published evaluation's next steps.
$feedback = null;
if ($userId > 0) {
    $query = $pdo->prepare("SELECT r.review_note AS note, r.reviewed_at AS at, CONCAT(v.first_name, ' ', v.last_name) AS author
        FROM work_reports r
        LEFT JOIN users v ON v.id = r.reviewer_id
        WHERE r.user_id = ? AND r.review_note IS NOT NULL AND r.review_note <> ''
        ORDER BY r.reviewed_at DESC LIMIT 1");
    $query->execute([$userId]);
    $feedback = $query->fetch() ?: null;
    $query = $pdo->prepare("SELECT e.next_steps AS note, e.published_at AS at, CONCAT(v.first_name, ' ', v.last_name) AS author
        FROM evaluations e
        LEFT JOIN users v ON v.id = e.evaluator_id
        WHERE e.user_id = ? AND e.status = 'published' AND e.next_steps IS NOT NULL
        ORDER BY e.published_at DESC LIMIT 1");
    $query->execute([$userId]);
    $evaluationFeedback = $query->fetch() ?: null;
    if ($evaluationFeedback && (!$feedback || $evaluationFeedback['at'] > $feedback['at'])) {
        $feedback = $evaluationFeedback;
    }
}
?>
<div class="admin-heading">
    <div>
        <h1>Assigned department</h1>
        <p>Review your assigned department, role, schedule, colleagues and responsibilities.</p>
    </div><span class="badge <?= $department ? '' : 'warn' ?>"><?= $department ? 'Assignment confirmed' : 'Awaiting assignment' ?></span>
</div>
<section class="pathway-card">
    <h2><?= $department ? mentora_h($department['name']) : 'Department pending' ?></h2>
    <p><?= $department ? mentora_h($department['description']) : 'HR will assign your department after your onboarding. You’ll get a notification.' ?></p>
    <div class="admin-grid">
        <div><small>Employee</small><br><strong><?= mentora_h($fullName) ?></strong></div>
        <div>
            <small>Work schedule</small><br><strong><?= MENTORA_SHIFT_LABEL ?></strong>
        </div>
        <div><small>Role</small><br><strong><?= mentora_h(($person['desired_role'] ?? '') !== '' ? $person['desired_role'] : 'Employee VA') ?></strong></div>
    </div>
</section>
<div class="admin-grid">
    <section class="pathway-card">
        <h3>Role responsibilities</h3>
        <ul class="checklist">
            <li>Maintain calendars, inboxes and operations trackers.</li>
            <li>Prepare accurate updates and handoff notes.</li>
            <li>Escalate blockers and protect client data.</li>
            <li>Submit a daily work report.</li>
        </ul>
        <a class="admin-btn" href="reporting.php">Go to reporting</a>
    </section>
    <section class="pathway-card">
        <h3>Colleagues<?= $department ? ' in ' . mentora_h($department['name']) : '' ?></h3>
        <?php if (!$colleagues): ?>
        <p><?= $department ? 'No colleagues listed yet.' : 'Your colleagues will appear once you’re assigned.' ?></p>
        <?php endif; ?>
        <?php foreach ($colleagues as $colleague): ?>
            <div class="notice-row">
                <span><?= mentora_h($colleague['first_name'] . ' ' . $colleague['last_name']) ?></span><span class="badge info"><?= $colleague['role'] === 'applicant'
                    ? mentora_pathway_label($colleague['pathway'])
                    : ucfirst($colleague['role']) ?></span>
            </div>
        <?php endforeach; ?>
    </section>
</div>
<section class="pathway-card">
    <h3>Latest manager feedback</h3>
    <?php if ($feedback): ?>
        <p>“<?= mentora_h($feedback['note']) ?>”</p>
        <small><?= mentora_h($feedback['author'] ?: 'Mentora team') ?> · <?= date('d M Y', strtotime($feedback['at'])) ?></small>
    <?php else: ?>
        <p>Feedback on your reports and evaluations will appear here. Department changes are coordinated by HR; contact the people team if anything looks incorrect.</p>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/../../includes/applicant_footer.php'; ?>
