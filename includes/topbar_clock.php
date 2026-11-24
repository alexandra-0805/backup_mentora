<?php
/**
 * Topbar: live date and time in Philippine time ("Tue, 24 Nov 2026 · 4:35 PM PHT"), and for a signed-in intern
 * their internship hours tracker ("132h 45m / 480h"), for an employee their work hours today ("3h 12m today").
 * Included by the admin, agent and applicant layouts.
 * Uses the server's clock (not the device's), so a wrong computer clock doesn't change it.
 *
 * For interns this also counts their hours automatically: while they click, type or scroll in Mentora, the page
 * reports it to account/activity.php about every 30 seconds (only how many clicks and keystrokes, never what was
 * typed), and the tracker counts up live. After MENTORA_ACTIVITY_IDLE_MINUTES with no activity it pauses until
 * they're active again. Opening a page counts as activity; it's paused while their status is Offline and once they
 * sign out, and resumes when they sign in and use Mentora. See includes/intern_hours.php.
 *
 * Optional: $hoursTracker = one row from mentora_intern_hours() (includes/intern_hours.php), set by the layout.
 */

$clockNow = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
$clockEscape = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$trackerRow = isset($hoursTracker) && is_array($hoursTracker) ? $hoursTracker : null;
?>
<?php if ($trackerRow):
    $trackerLive = mentora_intern_hours_live($trackerRow);
    $trackerUrl = (str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/applicant/') ? '../../' : '../') . 'account/activity.php';
?>
    <a class="hours-chip is-<?= $clockEscape($trackerRow['state']) ?>" href="reporting.php" data-hours-tracker="<?= $clockEscape(json_encode($trackerLive)) ?>"
        data-activity-url="<?= $clockEscape($trackerUrl) ?>" data-idle-minutes="<?= MENTORA_ACTIVITY_IDLE_MINUTES ?>">
        <i class="hours-chip-dot" aria-hidden="true"></i>
        <?php if ($trackerRow['is_intern']): ?>
            <span data-hours-text><?= $clockEscape(mentora_duration((int) $trackerRow['rendered_seconds'], $trackerRow['state'] === 'working')) ?></span>
            <small>/ <?= (int) $trackerRow['required'] ?>h</small>
            <span class="hours-chip-bar" aria-hidden="true"><b style="width:<?= (float) $trackerRow['percent'] ?>%" data-hours-bar></b></span>
        <?php else: // employees: open shift, so today's work hours ?>
            <span data-hours-text><?= $clockEscape(mentora_duration((int) $trackerRow['today_seconds'], $trackerRow['state'] === 'working')) ?></span>
            <small>today</small>
        <?php endif; ?>
    </a>
<?php endif; ?>
<span class="topbar-date" data-live-clock data-server-now="<?= time() ?>"><?= $clockEscape($clockNow->format('D, d M Y · g:i A')) ?> PHT</span>
<script>
    (() => {
        // Server time: ticks from the server's clock, so every page and tracker agrees with the hours records.
        const clock = document.querySelector('[data-live-clock]');
        const offset = Number(clock.dataset.serverNow) * 1000 - Date.now();
        window.mentoraNow = () => Date.now() + offset;

        const parts = new Intl.DateTimeFormat('en-US', {
            timeZone: 'Asia/Manila', weekday: 'short', day: '2-digit', month: 'short', year: 'numeric',
            hour: 'numeric', minute: '2-digit', hour12: true
        });
        const phTime = (ms) => Object.fromEntries(parts.formatToParts(new Date(ms)).map((p) => [p.type, p.value]));
        window.mentoraDuration = (seconds, withSeconds) => {
            seconds = Math.max(0, Math.floor(seconds));
            const text = Math.floor(seconds / 3600) + 'h ' + String(Math.floor(seconds % 3600 / 60)).padStart(2, '0') + 'm';
            return withSeconds ? text + ' ' + String(seconds % 60).padStart(2, '0') + 's' : text;
        };
        // Hours so far for live figures from includes/intern_hours.php (the running minute counts while active).
        window.mentoraRendered = (live, nowSeconds) => live.closed + (live.activeNow ? Math.max(0, Math.min(120, nowSeconds - live.base)) : 0);

        const chip = document.querySelector('[data-hours-tracker]');
        let live = chip ? JSON.parse(chip.dataset.hoursTracker) : null;
        const tick = () => {
            const t = phTime(window.mentoraNow());
            clock.textContent = `${t.weekday}, ${t.day} ${t.month} ${t.year} · ${t.hour}:${t.minute} ${t.dayPeriod} PHT`;
            if (live && !live.isIntern) {
                // Employees (open shift): today's work hours, with this week and the total in the tooltip.
                const rendered = window.mentoraRendered(live, window.mentoraNow() / 1000);
                const running = rendered - live.closed;
                chip.className = 'hours-chip is-' + (live.activeNow ? 'working' : (live.firstActive ? 'out' : 'absent')) + (live.pausedBy ? ' is-paused' : '');
                chip.querySelector('[data-hours-text]').textContent = window.mentoraDuration(live.today + running, live.activeNow);
                const label = 'Work hours, counted automatically while you click, type or scroll. Today ' + window.mentoraDuration(live.today + running)
                    + ', this week ' + window.mentoraDuration(live.week + running) + ', total ' + window.mentoraDuration(rendered) + '. '
                    + (live.pausedBy === 'offline' ? 'Paused: your status is Offline. Set it to Active on your Profile to continue.'
                        : live.pausedBy === 'signed_out' ? 'Paused: you signed out. Sign in again to continue.'
                        : live.activeNow ? 'Counting now.'
                        : live.firstActive ? 'Paused (no activity). Last active ' + live.lastActive + '.'
                        : 'Click, type or scroll in Mentora to start counting today.');
                chip.title = label;
                chip.setAttribute('aria-label', label);
            } else if (live) {
                const rendered = window.mentoraRendered(live, window.mentoraNow() / 1000);
                const percent = Math.min(100, rendered / (live.required * 3600) * 100);
                const state = percent >= 100 ? 'completed' : (live.activeNow ? 'working' : (live.firstActive ? 'out' : 'absent'));
                chip.className = 'hours-chip is-' + state + (live.pausedBy ? ' is-paused' : '');
                chip.querySelector('[data-hours-text]').textContent = window.mentoraDuration(rendered, live.activeNow);
                chip.querySelector('[data-hours-bar]').style.width = percent + '%';
                const label = 'Internship hours, counted automatically while you click, type or scroll: ' + window.mentoraDuration(rendered)
                    + ' of ' + live.required + 'h (' + (Math.round(percent * 10) / 10) + '%). '
                    + (state === 'completed' ? 'Required hours completed.'
                        : live.pausedBy === 'offline' ? 'Paused: your status is Offline. Set it to Active on your Profile to continue.'
                        : live.pausedBy === 'signed_out' ? 'Paused: you signed out. Sign in again to continue.'
                        : state === 'working' ? 'Counting now. Today: ' + window.mentoraDuration(live.today + rendered - live.closed) + '.'
                        : state === 'out' ? 'Paused (no activity). Last active ' + live.lastActive + '.'
                        : 'Click, type or scroll in Mentora to start counting today.');
                chip.title = label;
                chip.setAttribute('aria-label', label);
            }
        };
        tick();
        setInterval(tick, 1000);

        if (!chip) {
            return;
        }
        // Automatic hours: count clicks and keystrokes (never what was typed) and report about every 30 seconds while
        // there was a click or keystroke in the last few minutes. The last activity is shared between tabs and pages.
        const url = chip.dataset.activityUrl;
        const idleMs = Number(chip.dataset.idleMinutes) * 60000;
        const storageKey = 'mentora-intern-last-activity';
        const readLast = () => { try { return Number(localStorage.getItem(storageKey)) || 0; } catch (e) { return 0; } };
        let lastActivity = readLast();
        let clicks = 0, keys = 0, sending = false, signedOut = false;
        const isActive = () => Date.now() - Math.max(lastActivity, readLast()) < idleMs;
        // Signed out (here, in another tab, or the session ended): stop counting on screen too.
        const pauseSignedOut = () => {
            signedOut = true;
            if (live) {
                live.activeNow = false;
                live.pausedBy = 'signed_out';
            }
            tick();
        };
        // Still signed in? (account/presence.php: 204 yes, 401 no.) Used when another tab signs out, after the
        // Back button shows this page again, and when coming back to this tab.
        const checkSession = async () => {
            try {
                const response = await fetch(url.replace('activity.php', 'presence.php'), { method: 'POST', credentials: 'same-origin', cache: 'no-store' });
                if (response.status === 401) {
                    pauseSignedOut();
                }
            } catch (error) { /* offline for a moment */ }
        };
        const report = async () => {
            if (sending || signedOut || !isActive()) {
                return;
            }
            sending = true;
            const body = new FormData();
            body.append('clicks', String(clicks));
            body.append('keys', String(keys));
            clicks = 0;
            keys = 0;
            try {
                const response = await fetch(url, { method: 'POST', body, credentials: 'same-origin', cache: 'no-store' });
                if (response.status === 401) {
                    pauseSignedOut();
                } else if (response.ok && response.status !== 204) {
                    const data = await response.json();
                    if (data.hours) {
                        live = data.hours;
                    }
                }
            } catch (error) { /* offline for a moment: the next report catches up */ }
            sending = false;
        };
        let lastSaved = 0;
        const activity = (kind) => {
            const wasIdle = !isActive();
            if (kind === 'click') { clicks++; } else if (kind === 'key') { keys++; }
            lastActivity = Date.now();
            // Scrolling fires many times a second: share it with other tabs at most every 5 seconds.
            if (kind !== 'scroll' || lastActivity - lastSaved > 5000) {
                lastSaved = lastActivity;
                try { localStorage.setItem(storageKey, String(lastActivity)); } catch (e) { /* private window */ }
            }
            if (wasIdle) {
                report(); // back after a pause: start counting straight away
            }
        };
        window.addEventListener('pointerdown', () => activity('click'), { passive: true, capture: true });
        window.addEventListener('keydown', () => activity('key'), { passive: true, capture: true });
        // Reading a lesson or report: scrolling (page or inner panels), the mouse wheel and swiping count as activity too.
        ['scroll', 'wheel', 'touchmove'].forEach((type) => window.addEventListener(type, () => activity('scroll'), { passive: true, capture: true }));
        setInterval(report, 30000);
        // Opening or moving to a page (signing in, following a link) counts as activity: counting resumes right away.
        if (document.visibilityState === 'visible') {
            activity('load');
        }
        report();
        // Signing out pauses the time: forget this browser's last activity, so the next sign-in starts fresh. Other open
        // tabs notice (storage event) and check straight away.
        document.querySelectorAll('a[href$="signout.php"]').forEach((link) => link.addEventListener('click', () => {
            try { localStorage.removeItem(storageKey); } catch (e) { /* private window */ }
        }));
        window.addEventListener('storage', (event) => {
            if (event.key === storageKey && event.newValue === null) {
                // The other tab is on its way to sign out: check once it's done (and once more in case it was slow).
                setTimeout(checkSession, 1500);
                setTimeout(checkSession, 5000);
            }
        });
        window.addEventListener('pageshow', (event) => { if (event.persisted) { checkSession(); } });
        document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible') { checkSession(); } });
        // Paused: stop counting the running minute on screen once there's been no click or typing for a while.
        setInterval(() => { if (live && live.activeNow && !isActive() && window.mentoraNow() / 1000 - live.base > 120) { live.activeNow = false; } }, 5000);
    })();
</script>
