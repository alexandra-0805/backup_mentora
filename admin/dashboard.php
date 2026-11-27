<?php
// HR dashboard: people, departments, your latest notifications, today's attendance, AI onboarding and what's waiting.
declare(strict_types=1);
require_once __DIR__ . '/../includes/workflow.php';
require_once __DIR__ . '/../includes/report_summary.php';
require_once __DIR__ . '/../includes/screening.php';
$pageTitle = 'Dashboard';
$activePage = 'dashboard.php';
require __DIR__ . '/../includes/admin_layout.php';

$count = static fn(string $sql): int => (int) $pdo->query($sql)->fetchColumn();
$counts = [
    'employees' => $count("SELECT COUNT(*) FROM users WHERE role = 'applicant' AND pathway = 'employee_va' AND status = 'active'"),
    'interns' => $count("SELECT COUNT(*) FROM users WHERE role = 'applicant' AND pathway = 'internship' AND status = 'active'"),
    'agents' => $count("SELECT COUNT(*) FROM users WHERE role = 'agent' AND status = 'active'"),
    'departments' => $count('SELECT COUNT(*) FROM departments'),
];
$departments = $pdo->query("SELECT d.name, COUNT(u.id) AS people
    FROM departments d
    LEFT JOIN users u ON u.department_id = d.id AND u.status = 'active'
    GROUP BY d.id
    ORDER BY d.name")
    ->fetchAll();
$adminId = (int) ($_SESSION['user']['id'] ?? 0);
$notes = $pdo->prepare('SELECT id, title, link, read_at, created_at FROM notifications WHERE user_id = ? OR user_id IS NULL ORDER BY created_at DESC LIMIT 4');
$notes->execute([$adminId]);
$notes = $notes->fetchAll();
$today = date('Y-m-d');
$checkedIn = $count("SELECT COUNT(*)
    FROM attendance a
    JOIN users u ON u.id = a.user_id AND u.status = 'active'
    WHERE a.work_date = CURDATE() AND a.time_in IS NOT NULL");
// Open shift: nobody is late; "working now" = timed in today and not timed out yet.
$workingNow = $count("SELECT COUNT(*) FROM attendance WHERE work_date = CURDATE() AND time_in IS NOT NULL AND time_out IS NULL");
$checkedOut = $count("SELECT COUNT(*) FROM attendance WHERE work_date = CURDATE() AND time_out IS NOT NULL");
$pendingApplications = $count("SELECT COUNT(*) FROM users WHERE role = 'applicant' AND status = 'pending'");
$reportsWaiting = $count("SELECT COUNT(*) FROM work_reports r JOIN users u ON u.id = r.user_id AND u.status = 'active' WHERE r.status = 'submitted'");
$evaluationsOpen = $count("SELECT COUNT(*) FROM evaluations e JOIN users u ON u.id = e.user_id AND u.status = 'active' WHERE e.status <> 'published'");
$month = mentora_report_summary($pdo, date('Y-m-01'), $today);
$screening = mentora_screening_counts($pdo);
$agentReportsWaiting = $count("SELECT COUNT(*) FROM agent_reports WHERE status = 'submitted'");
$readyInterns = $count("SELECT COUNT(*) FROM ai_assessments a JOIN (SELECT user_id, MAX(id) AS id FROM ai_assessments GROUP BY user_id) l ON l.id = a.id
    JOIN users u ON u.id = a.user_id AND u.status = 'active' WHERE a.ready = 1");
$gaps = array_filter(mentora_department_skill_map($pdo), static fn($d) => $d['lacking']);
$hour = (int) date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
?>
<div class="admin-heading">
    <div>
        <h1>Dashboard</h1>
        <p><?= $greeting ?>. A clear view of your people, recruitment, onboarding, and today's priorities.</p>
    </div>
    <div class="admin-actions"><a class="admin-btn"
        href="export.php?type=summary&amp;from=<?= date('Y-m-01') ?>&amp;to=<?= $today ?>"><?= mentora_icon('download') ?>Export dashboard</a><a class="admin-btn" href="consolidated_report.php">View consolidated reports</a><a class="admin-btn" href="screening.php">AI screening</a>
    </div>
</div>
<?= mentora_flash_html() ?>
<div class="metric-grid">
    <?php foreach ([['Employees', $counts['employees'], 'Active employee accounts', 'employee_list.php'], ['Interns', $counts['interns'], 'Interns in the workspace', 'intern_list.php'], ['Agent accounts', $counts['agents'], 'Team coordination', 'agent_list.php'], ['Departments', $counts['departments'], 'Current workspace', 'department.php']] as [$label, $value, $hint, $href]): ?><a class="metric-card" href="<?= $href ?>"><small><?= $label ?></small><strong><?= $value ?></strong><span><?= $hint ?></span></a><?php endforeach; ?>
</div>
<div class="admin-grid">
    <section class="admin-panel"><h2>People across departments</h2><p class="panel-subtitle">Active people in each department</p>
        <?php $max = max(1, ...array_map('intval', array_column($departments, 'people')), ...[1]); foreach ($departments as $department): ?>
        <div class="bar-row">
            <span><?= mentora_h($department['name']) ?></span>
            <div class="bar"><i style="width:<?= round((int) $department['people'] / $max * 100) ?>%"></i>
            </div>
            <small><?= (int) $department['people'] ?> people</small>
        </div>
        <?php endforeach; ?>
    </section>
    <section class="admin-panel"><h2>Notifications</h2><p class="panel-subtitle">Latest updates for you</p>
        <?php if (!$notes): ?><p class="panel-subtitle">You’re all caught up.</p><?php endif; ?>
        <?php foreach ($notes as $note): ?>
        <div class="notice-row">
            <span><?= $note['link']
                ? '<a href="../account/notification.php?id=' . (int) $note['id'] . '">' . mentora_h($note['title']) . '</a>'
                : mentora_h($note['title']) ?><br><small><?= date('d M, g:i A', strtotime($note['created_at'])) ?></small></span><b class="badge <?= $note['read_at'] ? '' : 'info' ?>"><?= $note['read_at'] ? 'Seen' : 'New' ?></b>
        </div>
        <?php endforeach; ?>
    </section>
</div>
<div class="admin-grid">
    <section class="admin-panel">
        <h2>Monitoring & attendance</h2>
        <p class="panel-subtitle">Today · <?= date('l, d M') ?></p>
        <div class="metric-grid">
            <a class="metric-card" href="monitoring.php"><small>Checked in</small><strong><?= $checkedIn ?></strong><span>of <?= $counts['employees'] + $counts['interns'] ?> people</span></a><a class="metric-card" href="monitoring.php"><small>Working now</small><strong><?= $workingNow ?></strong><span>Timed in · open shift</span></a><a class="metric-card" href="monitoring.php"><small>Timed out</small><strong><?= $checkedOut ?></strong><span>Finished the day</span></a>
        </div>
    </section>
    <section class="admin-panel"><h2>Waiting for you</h2><p class="panel-subtitle">Decisions and reviews</p>
        <div class="notice-row">
            <span><a href="applications.php?view=pending">Applications to confirm</a></span><b class="badge <?= $pendingApplications ? 'warn' : '' ?>"><?= $pendingApplications ?></b>
        </div>
        <div class="notice-row">
            <span><a href="screening.php?view=suitable">Suitable candidates to assess</a><br><small>AI found skills a department is lacking</small></span><b class="badge <?= $screening['suitable'] ? 'warn' : '' ?>"><?= $screening['suitable'] ?></b>
        </div>
        <div class="notice-row">
            <span><a href="screening.php?view=review">Applications for HR review</a><br><small><?= $screening['flagged'] ?> flagged unsuitable</small></span><b class="badge <?= $screening['review'] ? 'info' : '' ?>"><?= $screening['review'] ?></b>
        </div>
        <div class="notice-row">
            <span><a href="intern_reports.php">Agent reports · interns ready to employ</a></span><b class="badge <?= $agentReportsWaiting ? 'warn' : '' ?>"><?= $agentReportsWaiting ?> · <?= $readyInterns ?></b>
        </div>
        <div class="notice-row">
            <span><a href="monitoring.php#reports">Daily reports to approve</a></span><b class="badge <?= $reportsWaiting ? 'warn' : '' ?>"><?= $reportsWaiting ?></b>
        </div>
        <div class="notice-row">
            <span><a href="../agent/evaluation_list.php">Evaluations in progress</a></span><b class="badge info"><?= $evaluationsOpen ?></b>
        </div>
        <div class="notice-row">
            <span><a href="consolidated_report.php">This month: approved hours</a></span><b class="badge"><?= mentora_hours($month['hours']) ?> h</b>
        </div>
    </section>
</div>
<section class="admin-panel" style="margin-top:16px">
    <h2>Departments lacking skills</h2>
    <p class="panel-subtitle">The AI screening notifies you and the department when an applicant has these skills.</p>
    <?php if (!$gaps): ?><p class="panel-subtitle">No skill gaps right now, or no required skills set yet in <a href="department.php">Departments</a>.</p><?php endif; ?>
    <?php foreach ($gaps as $gap): ?>
        <div class="notice-row"><span><?= mentora_h($gap['name']) ?><br><small><?= (int) $gap['people'] ?> people</small></span><?= mentora_skill_chips($gap['lacking'], $gap['lacking']) ?></div>
    <?php endforeach; ?>
</section>
<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
