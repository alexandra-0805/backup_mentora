<?php
// The intern's team (users.department_id) and supervising agent (users.agent_id), both set by HR or an agent.
$pathway = 'intern';
$pageTitle = 'Assigned team & agent';
$activePage = 'team-agent.php';
require_once __DIR__ . '/../../includes/workflow.php';
require __DIR__ . '/../../includes/applicant_layout.php';

$userId = (int) $currentUser['id'];
$person = $userId > 0 ? mentora_person($pdo, $userId) : null;
$teammates = [];
$department = null;
if (!empty($person['department_id'])) {
    $query = $pdo->prepare('SELECT name, description FROM departments WHERE id = ?');
    $query->execute([$person['department_id']]);
    $department = $query->fetch() ?: null;
    $query = $pdo->prepare("SELECT first_name, last_name, role, pathway
        FROM users
        WHERE department_id = ? AND id <> ? AND status = 'active'
        ORDER BY role, first_name LIMIT 12");
    $query->execute([$person['department_id'], $userId]);
    $teammates = $query->fetchAll();
}
$agent = null;
if (!empty($person['agent_id'])) {
    $query = $pdo->prepare("SELECT id, first_name, last_name, email, avatar_path FROM users WHERE id = ? AND status = 'active'");
    $query->execute([$person['agent_id']]);
    $agent = $query->fetch() ?: null;
}
$role = ($person['desired_role'] ?? '') !== '' ? $person['desired_role'] : 'Virtual Assistant Intern';
?>
<div class="admin-heading">
    <div>
        <h1>Your assigned team & agent</h1>
        <p>Meet your placement team, know who you report to, and prepare for each shift.</p>
    </div>
</div>
<div class="applicant-banner">
    <?php if ($department && $agent): ?><strong>Your placement is confirmed</strong>You’re on the <?= mentora_h($department['name']) ?> team with <?= mentora_h($agent['first_name']) ?> as your supervising agent.<?php else: ?><strong>Your placement is being prepared</strong><?php endif; ?>
</div>
<div class="admin-grid">
    <section class="pathway-card">
        <h2><?= $department ? mentora_h($department['name']) : 'Team to be assigned' ?></h2>
        <p><?= $department ? mentora_h($department['description']) : 'Your team assignment will appear here.' ?></p>
        <div class="notice-row"><span>Your role</span><strong><?= mentora_h($role) ?></strong></div>
        <div class="notice-row"><span>Work arrangement</span><strong>Remote · Philippines</strong></div>
        <div class="notice-row">
            <span>Shift</span><strong><?= MENTORA_SHIFT_LABEL ?></strong>
        </div>
    </section>
    <section class="pathway-card">
        <h3>Your supervising agent</h3>
        <?php if ($agent): $agentName = trim($agent['first_name'] . ' ' . $agent['last_name']); ?>
            <div class="person-row"><?= mentora_avatar(mentora_avatar_url($agent['avatar_path'] ?? null, '../../'), mb_strtoupper(mb_substr($agent['first_name'], 0, 1) . mb_substr($agent['last_name'], 0, 1)), $agentName) ?><div>
                    <strong><?= mentora_h($agentName) ?></strong><br><small><?= mentora_h($agent['email']) ?></small>
                </div>
            </div>
            <p>Your agent approves your daily reports and hours and writes your evaluations.</p>
            <a class="admin-btn primary" href="../../account/messages.php?to=<?= (int) $agent['id'] ?>">Message <?= mentora_h($agent['first_name']) ?></a>
            <span class="badge">Assigned</span>
        <?php else: ?>
            <p>Assignment pending. Your agent’s details and contact will appear here.</p>
            <span class="badge warn">Awaiting assignment</span>
        <?php endif; ?>
    </section>
</div>
<div class="admin-grid">
    <section class="pathway-card">
        <h3>Your responsibilities</h3>
        <ul class="checklist">
            <li>Maintain accurate client records and shared trackers.</li>
            <li>Assist with research and document preparation.</li>
            <li>Follow data privacy guidelines.</li>
            <li>Submit daily task reports.</li>
        </ul>
        <a class="admin-btn" href="reporting.php">Go to task reporting</a>
    </section>
    <section class="pathway-card">
        <h3>Teammates</h3>
        <?php if (!$teammates): ?>
        <p><?= $department ? 'You’re the first one on this team.' : 'Your teammates will appear here once you’re assigned.' ?></p>
        <?php endif; ?>
        <?php foreach ($teammates as $mate): ?>
            <div class="notice-row">
                <span><?= mentora_h($mate['first_name'] . ' ' . $mate['last_name']) ?></span><span class="badge info"><?= $mate['role'] === 'applicant'
                    ? mentora_pathway_label($mate['pathway'])
                    : ucfirst($mate['role']) ?></span>
            </div>
        <?php endforeach; ?>
    </section>
</div>
<?php require __DIR__ . '/../../includes/applicant_footer.php'; ?>
