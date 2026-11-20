<?php
$userId = (int) ($_SESSION['user']['id'] ?? 0);
$notificationActionToken = $_SESSION['notification_action_token'] ??= bin2hex(random_bytes(32));

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['mark_notifications_done'], $_POST['notification_action_token'])
    && hash_equals($notificationActionToken, (string) $_POST['notification_action_token'])
) {
    $markReadQuery = $userId
        ? $pdo->prepare('UPDATE notifications SET read_at = NOW() WHERE (user_id = ? OR user_id IS NULL) AND read_at IS NULL')
        : $pdo->prepare('UPDATE notifications SET read_at = NOW() WHERE user_id IS NULL AND read_at IS NULL');

    if ($userId) {
        $markReadQuery->execute([$userId]);
    } else {
        $markReadQuery->execute();
    }
}

$notificationQuery = $userId
    ? $pdo->prepare('SELECT id, title, body, link, read_at, created_at FROM notifications WHERE user_id = ? OR user_id IS NULL ORDER BY created_at DESC')
    : $pdo->prepare('SELECT id, title, body, link, read_at, created_at FROM notifications WHERE user_id IS NULL ORDER BY created_at DESC');
// Clicking a notification opens account/notification.php (marks it read, then goes to its page).
$notificationOpenUrl = (str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/applicant/') ? '../../' : '../') . 'account/notification.php?id=';

if ($userId) {
    $notificationQuery->execute([$userId]);
} else {
    $notificationQuery->execute();
}

$notificationItems = $notificationQuery->fetchAll();
$unreadNotificationCount = 0;
foreach ($notificationItems as $notification) {
    if (!$notification['read_at']) {
        $unreadNotificationCount++;
    }
}
?>
<span class="notification-popover">
    <button class="header-icon-link notification-toggle<?= $unreadNotificationCount > 0 ? ' has-unread' : '' ?>"
        type="button"
        aria-label="Notifications<?= $unreadNotificationCount > 0 ? ', ' . $unreadNotificationCount . ' unread' : '' ?>"
        aria-expanded="false"
        aria-controls="notification-panel"
        title="Notifications"><svg class="header-icon-svg bell-icon"
            viewBox="0 0 24 24"
            width="18"
            height="18"
            fill="none"
            stroke="currentColor"
            stroke-width="2"
            stroke-linecap="round"
            stroke-linejoin="round"
            aria-hidden="true"
            focusable="false">
        <g class="bell-body">
            <path d="M4.5 18h2V11a5.5 5.5 0 0 1 11 0v7h2"/>
            <path class="bell-clapper" d="M10.6 20.6a1.6 1.4 0 0 0 2.8 0"/>
        </g>
    </svg><?php if ($unreadNotificationCount > 0): ?><span class="header-icon-badge"><?= $unreadNotificationCount > 99 ? '99+' : $unreadNotificationCount ?></span><?php endif; ?></button>
    <aside class="notification-panel" id="notification-panel" hidden aria-label="Notifications">
        <div class="notification-panel-header">
            <strong>Notifications</strong>
            <div class="notification-panel-actions">
                <form method="post">
                    <input type="hidden" name="notification_action_token" value="<?= htmlspecialchars($notificationActionToken, ENT_QUOTES, 'UTF-8') ?>">
                    <button class="notification-mark-done" type="submit" name="mark_notifications_done" value="1"
                        data-confirm-title="Mark all as done?" data-confirm="All of your notifications will be marked as read." data-confirm-action="Mark as done">Mark as done</button>
                </form>
                <button class="notification-close" type="button" aria-label="Close notifications">×</button>
            </div>
        </div>
        <div class="notification-panel-list">
            <?php if (!$notificationItems): ?>
                <p class="notification-empty">You’re all caught up.</p>
            <?php else: ?>
                <?php foreach ($notificationItems as $notification):
                    $clickable = $userId && trim((string) ($notification['link'] ?? '')) !== '';
                    $tag = $clickable ? 'a' : 'article';
                ?>
                    <<?= $tag ?> class="notification-item<?= $notification['read_at'] ? '' : ' unread' ?><?= $clickable ? ' is-link' : '' ?>"<?= $clickable
                        ? ' href="' . htmlspecialchars($notificationOpenUrl . (int) $notification['id'], ENT_QUOTES, 'UTF-8') . '"'
                        : '' ?>>
                        <span class="notification-item-dot" aria-hidden="true"></span>
                        <div>
                            <strong><?= htmlspecialchars($notification['title'], ENT_QUOTES, 'UTF-8') ?></strong>
                            <p><?= htmlspecialchars($notification['body'], ENT_QUOTES, 'UTF-8') ?></p>
                            <small><?= htmlspecialchars($notification['created_at'], ENT_QUOTES, 'UTF-8') ?></small>
                        </div>
                        <?php if ($clickable): ?>
                            <svg class="notification-item-go"
                                viewBox="0 0 24 24"
                                width="16"
                                height="16"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="2"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                aria-hidden="true"
                                focusable="false">
                                <path d="m9 6 6 6-6 6"/>
                            </svg>
                        <?php endif; ?>
                    </<?= $tag ?>>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </aside>
</span>
<script>
    (() => {
        const popover = document.querySelector('.notification-popover');
        const toggle = popover ? popover.querySelector('.notification-toggle') : null;
        const panel = popover ? popover.querySelector('.notification-panel') : null;
        const closeButton = popover ? popover.querySelector('.notification-close') : null;

        if (!popover || !toggle || !panel || !closeButton) {
            return;
        }

        const setVisibility = (isOpen) => {
            panel.hidden = !isOpen;
            toggle.setAttribute('aria-expanded', String(isOpen));
        };

        toggle.addEventListener('click', () => {
            setVisibility(panel.hidden);
        });

        closeButton.addEventListener('click', () => {
            setVisibility(false);
            toggle.focus();
        });

        document.addEventListener('click', (event) => {
            if (!popover.contains(event.target)) {
                setVisibility(false);
            }
        });

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                setVisibility(false);
                toggle.focus();
            }
        });
    })();
</script>
