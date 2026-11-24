<?php

/**
 * Internship hours report (agents and HR admins): every active intern's required hours, the hours rendered
 * (counted automatically while they click, type or scroll in Mentora; live: interns who are active now count up every
 * second), what's left, today's activity, approved report hours and an estimated finish date.
 * See includes/intern_hours.php for how hours are counted.
 *
 * Shared by agent/intern_hours.php and admin/intern_hours.php, which set $workspace ('agent' or 'admin').
 *   ?format=json  the live figures (the page refreshes itself with these every minute)
 *   ?format=csv   download the report
 */

declare(strict_types=1);

require_once __DIR__ . '/../workflow.php';
require_once __DIR__ . '/../intern_hours.php';

$workspace = ($workspace ?? 'agent') === 'admin' ? 'admin' : 'agent';
$allowedRoles = $workspace === 'admin' ? ['admin'] : ['agent', 'admin'];
$format = (string) ($_GET['format'] ?? '');

if ($format === 'json' || $format === 'csv') {
    mentora_require_login($allowedRoles);
    $rows = mentora_intern_hours($pdo);
    if ($format === 'json') {
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        echo json_encode(['now' => time(), 'interns' => array_map('mentora_intern_hours_live', $rows)]);
        exit;
    }
    $stateLabel = ['completed' => 'Completed', 'working' => 'Active now', 'out' => 'Paused', 'absent' => 'Not active today'];
    mentora_csv('internship-hours-' . date('Y-m-d-Hi') . '.csv', [
        'Intern', 'User ID', 'School', 'Department', 'Supervising agent', 'Required hours', 'Rendered hours', 'Remaining hours',
        'Progress %', 'Today', 'Active today (hours)', 'First activity', 'Last activity', 'Days present', 'Missing time-outs',
        'Approved report hours', 'Estimated finish',
    ], array_map(static fn(array $r): array => [
        $r['name'], $r['user_code'], $r['school'] ?? '', $r['department_name'] ?? '', $r['agent_name'] ?? '', $r['required'],
        round($r['rendered_seconds'] / 3600, 2), round($r['remaining_seconds'] / 3600, 2), $r['percent'], $stateLabel[$r['state']],
        round($r['today_seconds'] / 3600, 2),
        $r['time_in_today'] ? date('g:i A', strtotime($r['time_in_today'])) : '', $r['time_out_today'] ? date('g:i A', strtotime($r['time_out_today'])) : '',
        $r['days_present'], $r['missing_timeouts'], mentora_hours($r['approved_hours']), $r['finish_estimate'] ?? '',
    ], $rows));
}

$pageTitle = 'Internship hours';
$activePage = 'intern_hours.php';
require __DIR__ . '/../' . $workspace . '_layout.php';

$me = $_SESSION['user'];
$isAgent = $me['role'] === 'agent';
$rows = mentora_intern_hours($pdo);
foreach ($rows as $row) {
    mentora_save_intern_hours($pdo, $row); // everyone's internship hours up to date, also for interns not signed in lately
}
$count = static fn(string $state): int => count(array_filter($rows, static fn(array $r): bool => $r['state'] === $state));
$totalRendered = array_sum(array_column($rows, 'rendered_seconds'));
$missing = array_sum(array_map('intval', array_column($rows, 'missing_timeouts')));
$recordLink = ($workspace === 'agent' ? '' : '../agent/') . 'edit_intern.php?id=';

/** Today's status text, the same as the page's script shows. */
$todayText = static function (array $r): array {
    $live = mentora_intern_hours_live($r);
    $today = mentora_duration((int) $r['today_seconds']);
    return match (true) {
        $r['active_now'] => ['Active now · ' . $today . ' today', 'info'],
        $r['time_in_today'] !== null && $r['paused_by'] === 'offline' => ['Paused, status Offline · ' . $today . ' today', ''],
        $r['time_in_today'] !== null && $r['paused_by'] === 'signed_out' => ['Signed out · ' . $today . ' today (last active ' . $live['lastActive'] . ')', ''],
        $r['time_in_today'] !== null => ['Paused · ' . $today . ' today (last active ' . $live['lastActive'] . ')', ''],
        $r['state'] === 'completed' => ['Completed', ''],
        default => ['Not active today', 'warn'],
    };
};
?>
<div class="admin-heading">
    <div>
        <h1>Internship hours</h1>
        <p>Every intern’s required hours against the hours they’ve rendered, counted automatically while they click, type or scroll in Mentora. Interns who are active now count up live.</p>
    </div>
    <div class="admin-actions">
        <a class="admin-btn" href="intern_hours.php?format=csv"><?= mentora_icon('download') ?>Export CSV</a>
    </div>
</div>

<div class="metric-grid">
    <div class="metric-card"><small>Interns</small><strong><?= count($rows) ?></strong><span>Active interns</span></div>
    <div class="metric-card"><small>Active now</small><strong data-hours-working><?= $count('working') ?></strong><span>Clicking or typing in Mentora</span></div>
    <div class="metric-card"><small>Hours rendered</small><strong data-hours-total><?= mentora_duration($totalRendered) ?></strong><span>All interns, counted automatically</span></div>
    <div class="metric-card"><small>Completed</small><strong data-hours-completed><?= $count('completed') ?></strong><span>Reached their required hours</span></div>
</div>

<section class="admin-panel data-panel">
    <h2>Hours by intern <span class="live-pill"><i aria-hidden="true"></i>Live</span></h2>
    <p class="panel-subtitle">
        Hours count automatically while an intern clicks, types or scrolls in Mentora (writing reports, reading lessons, answering modules).
        After <?= MENTORA_ACTIVITY_IDLE_MINUTES ?> minutes with no activity it pauses until they’re active again; it also pauses
        while their status is Offline and when they sign out, and resumes when they sign in and use Mentora. Only the number of clicks and keystrokes is
        recorded, never what was typed. Approved report hours come from the daily reports agents approve.
        <?= $missing ? 'Older days entered with the manual Time in button but no time out count 0 hours (' . $missing . ' flagged).' : '' ?>
    </p>
    <div class="table-toolbar" data-table-filter="hours-table">
        <input type="search" data-filter="search" placeholder="Search intern, school, team or agent" aria-label="Search interns">
        <?php if ($isAgent): ?>
            <select data-filter="supervisor" aria-label="Supervising agent">
                <option value="all">All interns</option>
                <option value="mine">My interns</option>
            </select>
        <?php endif; ?>
        <select data-filter="state" aria-label="Today">
            <option value="all">Any status</option>
            <option value="working">Active now</option>
            <option value="out">Paused (active earlier today)</option>
            <option value="absent">Not active today</option>
            <option value="completed">Completed</option>
        </select>
    </div>
    <table class="admin-table hours-table" id="hours-table">
        <thead>
            <tr>
                <th>Intern</th>
                <th>Team &amp; agent</th>
                <th>Today</th>
                <th>Rendered</th>
                <th>Required</th>
                <th>Remaining</th>
                <th>Progress</th>
                <th>Approved reports</th>
                <th>Est. finish</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!$rows): ?>
                <tr class="table-empty-row"><td colspan="10">No active interns yet.</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $r): [$today, $todayBadge] = $todayText($r); ?>
                <tr data-id="<?= (int) $r['id'] ?>" data-state="<?= mentora_h($r['state']) ?>"
                    data-supervisor="<?= (int) $r['agent_id'] === (int) $me['id'] ? 'mine' : 'other' ?>"
                    data-live="<?= mentora_h(json_encode(mentora_intern_hours_live($r))) ?>">
                    <td><?= mentora_h($r['name']) ?><br><small><?= mentora_h(trim(($r['user_code'] ?? '') . ($r['school'] ? ' · ' . $r['school'] : ''), ' ·')) ?></small></td>
                    <td><?= mentora_h($r['department_name'] ?? 'Not assigned') ?><br><small><?= mentora_h($r['agent_name'] ?? 'No agent') ?></small></td>
                    <td>
                        <span class="badge <?= $todayBadge ?>" data-cell="today"><?= mentora_h($today) ?></span>
                        <?php if ((int) $r['missing_timeouts'] > 0): ?>
                            <br><small class="hours-flag"><?= (int) $r['missing_timeouts'] ?> missing time-out<?= (int) $r['missing_timeouts'] === 1 ? '' : 's' ?></small>
                        <?php endif; ?>
                    </td>
                    <td><strong data-cell="rendered"><?= mentora_duration((int) $r['rendered_seconds'], (bool) $r['active_now']) ?></strong></td>
                    <td><?= (int) $r['required'] ?>h</td>
                    <td data-cell="remaining"><?= $r['remaining_seconds'] ? mentora_duration((int) $r['remaining_seconds']) : '—' ?></td>
                    <td class="hours-progress">
                        <div class="bar"><i style="width:<?= (float) $r['percent'] ?>%" data-cell="bar"></i></div>
                        <small data-cell="percent"><?= mentora_hours($r['percent']) ?>%</small>
                    </td>
                    <td><?= mentora_hours($r['approved_hours']) ?>h</td>
                    <td data-cell="finish"><?= $r['state'] === 'completed' ? 'Completed' : ($r['finish_estimate'] ? date('d M Y', strtotime($r['finish_estimate'])) : '—') ?></td>
                    <td><a class="admin-btn" href="<?= mentora_h($recordLink . (int) $r['id']) ?>">Open record</a></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <div class="table-note">Showing <span data-filter-count="hours-table"><?= count($rows) ?></span> intern(s) · Updated <span data-hours-updated>just now</span>.</div>
</section>

<script>
    (() => {
        // Live figures: every second for interns who are active now, and fresh data from the server every 15 seconds
        // (someone starting or pausing shows up without reloading). Time comes from the server (window.mentoraNow).
        const now = () => (window.mentoraNow ? window.mentoraNow() : Date.now()) / 1000;
        const duration = (s, withSeconds) => window.mentoraDuration ? window.mentoraDuration(s, withSeconds) : Math.round(s / 360) / 10 + 'h';
        const rows = [...document.querySelectorAll('#hours-table tbody tr[data-live]')];
        const cell = (row, name) => row.querySelector(`[data-cell="${name}"]`);
        const formatPercent = (p) => (Math.round(p * 10) / 10).toString();
        let updatedAt = Date.now();

        const render = () => {
            const t = now();
            let working = 0, completed = 0, total = 0;
            rows.forEach((row) => {
                const live = JSON.parse(row.dataset.live);
                const rendered = window.mentoraRendered ? window.mentoraRendered(live, t) : live.closed;
                const todaySoFar = live.today + rendered - live.closed;
                const required = live.required * 3600;
                const remaining = Math.max(0, required - rendered);
                const state = remaining === 0 ? 'completed' : (live.activeNow ? 'working' : (live.firstActive ? 'out' : 'absent'));
                total += rendered;
                working += live.activeNow ? 1 : 0;
                completed += state === 'completed' ? 1 : 0;

                cell(row, 'rendered').textContent = duration(rendered, live.activeNow);
                cell(row, 'remaining').textContent = remaining ? duration(remaining) : '—';
                cell(row, 'bar').style.width = Math.min(100, rendered / required * 100) + '%';
                cell(row, 'percent').textContent = formatPercent(Math.min(100, rendered / required * 100)) + '%';
                cell(row, 'finish').textContent = state === 'completed' ? 'Completed' : (live.finish || '—');
                const today = cell(row, 'today');
                if (live.activeNow) {
                    today.textContent = `Active now · ${duration(todaySoFar)} today`;
                    today.className = 'badge info';
                } else if (live.firstActive) {
                    today.textContent = live.pausedBy === 'offline' ? `Paused, status Offline · ${duration(todaySoFar)} today`
                        : (live.pausedBy === 'signed_out' ? 'Signed out' : 'Paused') + ` · ${duration(todaySoFar)} today (last active ${live.lastActive})`;
                    today.className = 'badge';
                } else {
                    today.textContent = state === 'completed' ? 'Completed' : 'Not active today';
                    today.className = state === 'completed' ? 'badge' : 'badge warn';
                }
                row.dataset.state = state;
            });
            const setText = (selector, text) => { const el = document.querySelector(selector); if (el) { el.textContent = text; } };
            setText('[data-hours-working]', working);
            setText('[data-hours-completed]', completed);
            setText('[data-hours-total]', duration(total));
            const minutes = Math.floor((Date.now() - updatedAt) / 60000);
            setText('[data-hours-updated]', minutes < 1 ? 'just now' : minutes + ' min ago');
        };

        const refresh = async () => {
            try {
                const response = await fetch('intern_hours.php?format=json', { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
                if (!response.ok) {
                    return;
                }
                const data = await response.json();
                const byId = new Map(data.interns.map((live) => [String(live.id), live]));
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
        setInterval(refresh, 15000); // someone signing out or going Offline shows within seconds
    })();
</script>
<?php require __DIR__ . '/../admin_footer.php'; ?>
