<?php
$pathway = 'intern';
$pageTitle = 'Overview';
$activePage = 'overview.php';
require __DIR__ . '/../../includes/applicant_layout.php';
require_once __DIR__ . '/../../includes/hiring_progress.php';
$q = $pdo->prepare('SELECT * FROM training_records WHERE user_id=?');
$q->execute([$currentUser['id']]);
$training = $q->fetch();
$a = $pdo->prepare('SELECT desired_role,required_hours,created_at FROM applications WHERE user_id=? ORDER BY id DESC LIMIT 1');
$a->execute([$currentUser['id']]);
$application = $a->fetch() ?: [];
$requiredHours = max(1, (int)($application['required_hours'] ?? 480));
$hoursLogged = (float)($training['hours_logged'] ?? 0);
$progress = mentora_hiring_progress($pdo, $currentUser, 'intern');
?>
<div class="admin-heading">
  <div>
    <h1>Your internship journey</h1>
    <p>Hi <?= htmlspecialchars($currentUser['first_name']) ?>, here’s your progress from application to hands-on experience.</p>
  </div>
</div>
<div class="applicant-banner">
  <?php if ($progress['current']): ?>
    <strong>Up next: <?= htmlspecialchars($progress['current']['label']) ?></strong><?= htmlspecialchars($progress['current']['detail']) ?>. Your placement, learning modules, and task reports stay connected in this workspace.
  <?php else: ?>
    <strong>You've completed every step</strong>Your placement, learning modules, and task reports stay connected in this workspace.
  <?php endif; ?>
</div>
<?php mentora_render_hiring_progress($progress, 'Application progress'); ?>
<div class="admin-grid">
  <section class="pathway-card">
    <h3>Intern application summary</h3>
    <p>
        <strong><?= htmlspecialchars($currentUser['first_name'] . ' ' . $currentUser['last_name']) ?></strong><br><?= htmlspecialchars($currentUser['email']) ?></p>
    <div class="notice-row">
        <span>Preferred role</span><strong><?= htmlspecialchars(($application['desired_role'] ?? '') !== '' ? $application['desired_role'] : 'Virtual Assistant Intern') ?></strong>
    </div>
    <div class="notice-row">
        <span>Application received</span><strong><?= date('d M Y', strtotime($application['created_at'] ?? $currentUser['created_at'] ?? 'now')) ?></strong>
    </div>
  </section>
  <section class="pathway-card">
    <h3>School & required hours</h3>
    <p>Required internship hours</p>
    <strong class="kpi-large"><?= $requiredHours ?> hours</strong>
    <div class="bar"><i style="width:<?= min(100, round($hoursLogged / $requiredHours * 100, 1)) ?>%"></i></div>
    <p><?= htmlspecialchars(rtrim(rtrim(number_format($hoursLogged, 1, '.', ''), '0'), '.')) ?> hours rendered · tracked automatically</p>
  </section>
</div>
<?php require __DIR__ . '/../../includes/applicant_footer.php'; ?>