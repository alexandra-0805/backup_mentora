<?php
// Consolidated report (HR): recruitment, AI onboarding, training, hours, evaluations and attendance for a date range.
declare(strict_types=1);
require_once __DIR__ . '/../includes/workflow.php';
require_once __DIR__ . '/../includes/report_summary.php';
require_once __DIR__ . '/../includes/screening.php';
$pageTitle = 'Consolidated report builder';
$activePage = 'consolidated_report.php';
require __DIR__ . '/../includes/admin_layout.php';

[$from, $to] = mentora_report_range($_GET['from'] ?? null, $_GET['to'] ?? null);
$scope = in_array($_GET['scope'] ?? '', ['all', 'internship', 'employee_va'], true) ? $_GET['scope'] : 'all';
$summary = mentora_report_summary($pdo, $from, $to, $scope);
$query = http_build_query(['from' => $from, 'to' => $to, 'scope' => $scope]);
$presets = [
    'This week' => [date('Y-m-d', strtotime('monday this week')), date('Y-m-d')],
    'This month' => [date('Y-m-01'), date('Y-m-d')],
    'Last 30 days' => [date('Y-m-d', strtotime('-29 days')), date('Y-m-d')],
    'Last month' => [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('last day of last month'))],
];
?>
<div class="admin-heading">
    <div>
        <h1>Consolidated report builder</h1>
        <p>Choose a date range and group, then print or download a workspace summary.</p>
    </div>
    <div class="admin-actions">
        <a class="admin-btn" href="intern_reports.php">Intern reports<?= $navCounts['consolidated_report.php'] ? ' (' . (int) $navCounts['consolidated_report.php'] . ' waiting)' : '' ?></a><button class="admin-btn"
            type="button"
            onclick="window.print()">Print / save as PDF</button><a class="admin-btn primary" href="export.php?type=summary&amp;<?= mentora_h($query) ?>"><?= mentora_icon('download') ?>Download PDF</a>
    </div>
</div>
<div class="admin-grid report-layout">
    <section class="admin-panel">
        <h2>Report setup</h2><p class="panel-subtitle">Scope and date range</p>
        <form method="get" class="form-grid">
            <div class="field">
                <label for="from">From</label><input id="from" type="date" name="from" max="<?= date('Y-m-d') ?>" value="<?= mentora_h($from) ?>"></div>
            <div class="field"><label for="to">To</label><input id="to" type="date" name="to" max="<?= date('Y-m-d') ?>" value="<?= mentora_h($to) ?>"></div>
            <div class="field full">
                <label for="scope">People</label><select id="scope" name="scope">
                    <option value="all">Interns and employees</option><option value="internship"<?= $scope === 'internship' ? ' selected' : '' ?>>Interns only</option><option value="employee_va"<?= $scope === 'employee_va' ? ' selected' : '' ?>>Employees only</option>
                </select>
            </div>
            <div class="field full"><button class="admin-btn primary" type="submit">Update preview</button></div>
        </form>
        <div class="field">
            <label>Quick ranges</label>
            <div class="row-actions">
                <?php foreach ($presets as $label => [$a, $b]): ?><a class="admin-btn" href="?<?= mentora_h(http_build_query(['from' => $a, 'to' => $b, 'scope' => $scope])) ?>"><?= $label ?></a><?php endforeach; ?>
            </div>
        </div>
        <div class="field"><label>Sources</label>
            <?php foreach (['Recruitment and applications' => $summary['applications'], 'AI screening and assessments' => (int) $summary['screening']['screened'] + (int) $summary['assessments']['assessed'] + $summary['agent_reports'], 'Training and evaluations' => $summary['modules'] + $summary['evaluations'], 'Daily reports' => $summary['reports'], 'Attendance' => $summary['present']] as $source => $records): ?>
                <div class="notice-row">
                    <span><?= $source ?></span><span class="badge <?= $records ? '' : 'warn' ?>"><?= $records ? $records . ' records' : 'No records' ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
    <section class="admin-panel">
        <h2>Report preview</h2>
        <p class="panel-subtitle"><?= date('d M Y', strtotime($from)) ?> – <?= date('d M Y', strtotime($to)) ?> · <?= ['all' => 'Interns and employees', 'internship' => 'Interns', 'employee_va' => 'Employees'][$scope] ?> · <?= $summary['active_people'] ?> active people</p>
        <div class="report-stats">
            <div class="report-stat">
                <span class="report-stat-label">Applications</span>
                <strong class="report-stat-value"><?= $summary['applications'] ?></strong>
                <span class="report-stat-note"><?= $summary['approved'] ?> approved · <?= $summary['declined'] ?> declined</span>
            </div>
            <div class="report-stat">
                <span class="report-stat-label">New accounts</span>
                <strong class="report-stat-value"><?= $summary['activated'] ?></strong>
                <span class="report-stat-note">Created a username &amp; password</span>
            </div>
            <div class="report-stat">
                <span class="report-stat-label">Approved hours</span>
                <strong class="report-stat-value"><?= mentora_hours($summary['hours']) ?><small>h</small></strong>
                <span class="report-stat-note"><?= $summary['reports'] ?> reports · <?= $summary['reports_waiting'] ?> waiting</span>
            </div>
            <div class="report-stat">
                <span class="report-stat-label">Modules completed</span>
                <strong class="report-stat-value"><?= $summary['modules'] ?></strong>
                <span class="report-stat-note"><?= $summary['training_completed'] ?> finished all training</span>
            </div>
            <div class="report-stat">
                <span class="report-stat-label">Evaluations published</span>
                <strong class="report-stat-value"><?= $summary['evaluations'] ?></strong>
                <span class="report-stat-note">Average <?= $summary['average_score'] !== null ? mentora_hours($summary['average_score']) . ' / 5' : '—' ?></span>
            </div>
            <div class="report-stat">
                <span class="report-stat-label">Attendance</span>
                <strong class="report-stat-value"><?= $summary['present'] ?><small>days</small></strong>
                <span class="report-stat-note"><?= mentora_hours($summary['worked_hours']) ?> hrs worked · open shift</span>
            </div>
        </div>
        <h2 class="report-section-title">AI-supported onboarding</h2>
        <div class="report-stats is-quad">
            <div class="report-stat">
                <span class="report-stat-label">Employee VAs screened</span>
                <strong class="report-stat-value"><?= (int) $summary['screening']['screened'] ?></strong>
                <span class="report-stat-note"><?= (int) $summary['screening']['suitable'] ?> suitable · <?= (int) $summary['screening']['for_review'] ?> for review · <?= (int) $summary['screening']['unsuitable'] ?> flagged</span>
            </div>
            <div class="report-stat">
                <span class="report-stat-label">Average AI review score</span>
                <?php if ($summary['screening']['average'] !== null): ?>
                    <strong class="report-stat-value"><?= round((float) $summary['screening']['average']) ?><small>/100</small></strong>
                <?php else: ?>
                    <strong class="report-stat-value is-empty">—</strong>
                <?php endif; ?>
                <span class="report-stat-note"><?= $summary['screening']['average'] !== null ? 'Latest review per applicant' : 'No applications screened yet' ?></span>
            </div>
            <div class="report-stat">
                <span class="report-stat-label">Interns assessed</span>
                <strong class="report-stat-value"><?= (int) $summary['assessments']['assessed'] ?></strong>
                <span class="report-stat-note"><?= (int) $summary['assessments']['ready'] ?> ready to employ<?= $summary['assessments']['average'] !== null ? ' · avg ' . round((float) $summary['assessments']['average']) . '/100' : '' ?></span>
            </div>
            <div class="report-stat">
                <span class="report-stat-label">Agent reports</span>
                <strong class="report-stat-value"><?= $summary['agent_reports'] ?></strong>
                <span class="report-stat-note">Employability reports with AI summaries</span>
            </div>
        </div>
        <h2 class="report-section-title">Department skill gaps (today)</h2>
        <div class="report-table-wrap">
        <table class="admin-table"><thead><tr><th>Department</th><th>People</th><th>Lacking skills</th></tr></thead><tbody>
            <?php foreach (mentora_department_skill_map($pdo) as $gap): ?>
            <tr>
                <td><?= mentora_h($gap['name']) ?></td>
                <td><?= (int) $gap['people'] ?></td>
                <td><?= $gap['required'] ? mentora_skill_chips($gap['lacking'], $gap['lacking'], 'No gaps') : '<small>No required skills set</small>' ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody></table>
        </div>
        <h2 class="report-section-title">By department</h2>
        <div class="report-table-wrap">
        <table class="admin-table"><thead><tr><th>Department</th><th>People</th><th>Approved hours</th><th>Avg. score</th></tr></thead><tbody>
            <?php foreach ($summary['departments'] as $row): ?>
            <tr>
                <td><?= mentora_h($row['name']) ?></td>
                <td><?= (int) $row['people'] ?></td>
                <td><?= mentora_hours($row['hours']) ?></td>
                <td><?= $row['score'] !== null ? mentora_hours($row['score']) : '—' ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody></table>
        </div>
    </section>
</div>
<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
