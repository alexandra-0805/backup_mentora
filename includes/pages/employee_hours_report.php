<?php

/**
 * Employee hours report (HR admins): every active employee's work hours, counted automatically while they click,
 * type or scroll in Mentora (open shift, no required total): today (live: employees who are active now count up
 * every second), this week, all their tracked hours, today's activity and approved report hours.
 * See includes/intern_hours.php for how hours are counted (same rules as interns).
 *
 * admin/employee_hours.php
 *   ?format=json  the live figures (the page refreshes itself with these every 15 seconds)
 *   ?format=pdf   download the report
 */

declare(strict_types=1);

require_once __DIR__ . '/../workflow.php';
require_once __DIR__ . '/../intern_hours.php';
require_once __DIR__ . '/../pdf_export.php';

$format = (string) ($_GET['format'] ?? '');

if ($format === 'json' || $format === 'pdf') {
    mentora_require_login(['admin']);
    $rows = mentora_intern_hours($pdo, null, 'employee_va');
    if ($format === 'json') {
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        echo json_encode(['now' => time(), 'employees' => array_map('mentora_intern_hours_live', $rows)]);
        exit;
    }
    $stateLabel = ['working' => 'Active now', 'out' => 'Paused', 'absent' => 'Not active today'];
    mentora_pdf('Employee hours', 'employee-hours-' . date('Y-m-d-Hi') . '.pdf', [
        'Employee', 'User ID', 'Department', 'Today', 'Hours today', 'Hours this week', 'Total tracked hours',
        'First activity', 'Last activity', 'Days present', 'Approved report hours',
    ], array_map(static fn(array $r): array => [
        $r['name'], $r['user_code'], $r['department_name'] ?? '', $stateLabel[$r['state']] ?? $r['state'],
        round($r['today_seconds'] / 3600, 2), round($r['week_seconds'] / 3600, 2), round($r['rendered_seconds'] / 3600, 2),
        $r['time_in_today'] ? date('g:i A', strtotime($r['time_in_today'])) : '', $r['time_out_today'] ? date('g:i A', strtotime($r['time_out_today'])) : '',
        $r['days_present'], mentora_hours($r['approved_hours']),
    ], $rows));
}

$pageTitle = 'Employee hours';
$activePage = 'employee_list.php'; // not in the sidebar; highlight the Employee list it's opened from
require __DIR__ . '/../admin_layout.php';

$rows = mentora_intern_hours($pdo, null, 'employee_va');
foreach ($rows as $row) {
    mentora_save_intern_hours($pdo, $row); // everyone's work hours up to date, also for employees not signed in lately
}
$sum = static fn(string $key): int => array_sum(array_map('intval', array_column($rows, $key)));
$working = count(array_filter($rows, static fn(array $r): bool => $r['state'] === 'working'));

/** Today's status text, the same as the page's script shows. */
$todayText = static function (array $r): array {
    $live = mentora_intern_hours_live($r);
    return match (true) {
        $r['active_now'] => ['Active now', 'info'],
        $r['time_in_today'] !== null && $r['paused_by'] === 'offline' => ['Paused, status Offline', ''],
        $r['time_in_today'] !== null && $r['paused_by'] === 'signed_out' => ['Signed out (last active ' . $live['lastActive'] . ')', ''],
        $r['time_in_today'] !== null => ['Paused (last active ' . $live['lastActive'] . ')', ''],
        default => ['Not active today', 'warn'],
    };
};
?>
<div class="admin-heading">
    <div>
        <h1>Employee hours</h1>
        <p>Every employee’s work hours, counted automatically while they click, type or scroll in Mentora. Employees who are active now count up live.</p>
    </div>
    <div class="admin-actions">
        <a class="admin-btn" href="employee_hours.php?format=pdf"><?= mentora_icon('download') ?>Export PDF</a>
    </div>
</div>

<div class="metric-grid">
    <div class="metric-card"><small>Employees</small><strong><?= count($rows) ?></strong><span>Active employee VAs</span></div>
    <div class="metric-card"><small>Active now</small><strong data-hours-working><?= $working ?></strong><span>Using Mentora right now</span></div>
    <div class="metric-card"><small>Hours today</small><strong data-hours-today><?= mentora_duration($sum('today_seconds')) ?></strong><span>All employees</span></div>
    <div class="metric-card"><small>Hours this week</small><strong data-hours-week><?= mentora_duration($sum('week_seconds')) ?></strong><span>Since Monday, all employees</span></div>
</div>

<section class="admin-panel data-panel">
    <h2>Hours by employee <span class="live-pill"><i aria-hidden="true"></i>Live</span></h2>
    <p class="panel-subtitle">
        Open shift: hours count automatically while an employee clicks, types or scrolls in Mentora. After <?= MENTORA_ACTIVITY_IDLE_MINUTES ?> minutes
        with no activity it pauses until they’re active again; it also pauses while their status is Offline and when they sign out, and resumes
        when they sign in and use Mentora. Only the number of clicks and keystrokes is recorded, never what was typed. Approved report hours
        come from the daily reports HR approves.
    </p>
    <div class="table-toolbar" data-table-filter="employee-hours-table">
        <input type="search" data-filter="search" placeholder="Search employee or department" aria-label="Search employees">
        <select data-filter="state" aria-label="Today">
            <option value="all">Any status</option>
            <option value="working">Active now</option>
            <option value="out">Paused (active earlier today)</option>
            <option value="absent">Not active today</option>
        </select>
    </div>
    <table class="admin-table hours-table" id="employee-hours-table">
        <thead>
            <tr>
                <th>Employee</th>
                <th>Department</th>
                <th>Today</th>
                <th>Hours today</th>
                <th>This week</th>
                <th>Total tracked</th>
                <th>Approved reports</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!$rows): ?>
                <tr class="table-empty-row"><td colspan="8">No active employees yet.</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $r): [$today, $todayBadge] = $todayText($r); ?>
                <tr data-id="<?= (int) $r['id'] ?>" data-state="<?= mentora_h($r['state']) ?>" data-live="<?= mentora_h(json_encode(mentora_intern_hours_live($r))) ?>">
                    <td><?= mentora_h($r['name']) ?><br><small><?= mentora_h($r['user_code'] ?? '') ?></small></td>
                    <td><?= mentora_h($r['department_name'] ?? 'Not assigned') ?></td>
                    <td><span class="badge <?= $todayBadge ?>" data-cell="today"><?= mentora_h($today) ?></span></td>
                    <td><strong data-cell="today-hours"><?= mentora_duration((int) $r['today_seconds'], (bool) $r['active_now']) ?></strong></td>
                    <td data-cell="week"><?= mentora_duration((int) $r['week_seconds']) ?></td>
                    <td data-cell="total"><?= mentora_duration((int) $r['rendered_seconds']) ?></td>
                    <td><?= mentora_hours($r['approved_hours']) ?>h</td>
                    <td><a class="admin-btn" href="user_edit.php?id=<?= (int) $r['id'] ?>">Manage</a></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <div class="table-note">Showing <span data-filter-count="employee-hours-table"><?= count($rows) ?></span> employee(s) · Updated <span data-hours-updated>just now</span>.</div>
</section>

<script>
    (() => {
        // Live figures: every second for employees who are active now, and fresh data from the server every 15 seconds.
        // Time comes from the server (window.mentoraNow, includes/topbar_clock.php).
        const now = () => (window.mentoraNow ? window.mentoraNow() : Date.now()) / 1000;
        const duration = (s, withSeconds) => window.mentoraDuration ? window.mentoraDuration(s, withSeconds) : Math.round(s / 360) / 10 + 'h';
        const rows = [...document.querySelectorAll('#employee-hours-table tbody tr[data-live]')];
        const cell = (row, name) => row.querySelector(`[data-cell="${name}"]`);
        let updatedAt = Date.now();

        const render = () => {
            const t = now();
            let working = 0, today = 0, week = 0;
            rows.forEach((row) => {
                const live = JSON.parse(row.dataset.live);
                const rendered = window.mentoraRendered ? window.mentoraRendered(live, t) : live.closed;
                const running = rendered - live.closed;
                const state = live.activeNow ? 'working' : (live.firstActive ? 'out' : 'absent');
                working += live.activeNow ? 1 : 0;
                today += live.today + running;
                week += live.week + running;

                cell(row, 'today-hours').textContent = duration(live.today + running, live.activeNow);
                cell(row, 'week').textContent = duration(live.week + running);
                cell(row, 'total').textContent = duration(rendered);
                const badge = cell(row, 'today');
                if (live.activeNow) {
                    badge.textContent = 'Active now';
                    badge.className = 'badge info';
                } else if (live.firstActive) {
                    badge.textContent = live.pausedBy === 'offline' ? 'Paused, status Offline'
                        : (live.pausedBy === 'signed_out' ? 'Signed out' : 'Paused') + ` (last active ${live.lastActive})`;
                    badge.className = 'badge';
                } else {
                    badge.textContent = 'Not active today';
                    badge.className = 'badge warn';
                }
                row.dataset.state = state;
            });
            const setText = (selector, text) => { const el = document.querySelector(selector); if (el) { el.textContent = text; } };
            setText('[data-hours-working]', working);
            setText('[data-hours-today]', duration(today));
            setText('[data-hours-week]', duration(week));
            const minutes = Math.floor((Date.now() - updatedAt) / 60000);
            setText('[data-hours-updated]', minutes < 1 ? 'just now' : minutes + ' min ago');
        };

        const refresh = async () => {
            try {
                const response = await fetch('employee_hours.php?format=json', { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
                if (!response.ok) {
                    return;
                }
                const data = await response.json();
                const byId = new Map(data.employees.map((live) => [String(live.id), live]));
                rows.forEach((row) => {
                    const live = byId.get(row.dataset.id);
                    if (live) {
                        row.dataset.live = JSON.stringify(live);
                    }
                });
                updatedAt = Date.now();
                render();
            } catch (error) { /* offline for a moment: keep ticking with what we have */ }
        };

        render();
        setInterval(render, 1000);
        setInterval(refresh, 15000);
    })();
</script>
<?php require __DIR__ . '/../admin_footer.php'; ?>
