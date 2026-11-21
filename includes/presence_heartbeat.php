<?php
// Keeps a signed-in person online while they use an open page: every 2 minutes, if there was mouse,
// keyboard, touch or scroll activity in the last 10 minutes, ping account/presence.php. Once they close
// Mentora or step away, the pings stop and after MENTORA_ONLINE_MINUTES (5) they show as Offline.
// Included by includes/workspace_theme_runtime.php (workspaces) and includes/footer.php (Messages).
if (empty($_SESSION['user']['id'])) {
    return;
}
$presenceUrl = (str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/applicant/') ? '../../' : '../') . 'account/presence.php';
?>
<script>
    (() => {
        const url = <?= json_encode($presenceUrl, JSON_UNESCAPED_SLASHES) ?>;
        let lastActivity = Date.now();
        ['pointerdown', 'pointermove', 'keydown', 'scroll', 'touchstart'].forEach((type) => {
            window.addEventListener(type, () => { lastActivity = Date.now(); }, { passive: true });
        });
        const ping = () => {
            if (Date.now() - lastActivity < 10 * 60000) {
                fetch(url, { method: 'POST', credentials: 'same-origin', cache: 'no-store' }).catch(() => {});
            }
        };
        window.setInterval(ping, 2 * 60000);
        // Back to the tab after a while away: count as here again straight away.
        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'visible') {
                lastActivity = Date.now();
                ping();
            }
        });
    })();
</script>
