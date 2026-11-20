<?php
$userId = (int) ($_SESSION['user']['id'] ?? 0);
$messageQuery = $userId
    ? $pdo->prepare('SELECT m.subject,
            m.body,
            m.created_at,
            COALESCE(CONCAT(u.first_name, " ", u.last_name), "Mentora support") AS sender_name
        FROM messages m
        LEFT JOIN users u ON u.id = m.sender_id
        WHERE m.recipient_id = ? OR m.recipient_id IS NULL
        ORDER BY m.created_at DESC')
    : $pdo->prepare('SELECT m.subject, m.body, m.created_at, "Mentora support" AS sender_name
        FROM messages m
        WHERE m.recipient_id IS NULL
        ORDER BY m.created_at DESC');

if ($userId) {
    $messageQuery->execute([$userId]);
} else {
    $messageQuery->execute();
}

$messageItems = $messageQuery->fetchAll();
?>
<span class="message-popover">
    <button class="header-icon-link message-toggle<?= $unreadMessageCount > 0 ? ' has-unread' : '' ?>"
        type="button"
        aria-label="Messages<?= $unreadMessageCount > 0 ? ', ' . $unreadMessageCount . ' unread' : '' ?>"
        aria-expanded="false"
        aria-controls="message-toast-panel"
        title="Messages"><svg class="header-icon-svg mail-icon"
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
        <g class="mail-body">
            <rect class="mail-envelope" x="3.5" y="6.5" width="17" height="12" rx="2.5"/>
            <path class="mail-flap" d="m4 7.5 8 6 8-6"/>
        </g>
    </svg><?php if ($unreadMessageCount > 0): ?><span class="header-icon-badge"><?= $unreadMessageCount > 99 ? '99+' : $unreadMessageCount ?></span><?php endif; ?></button>
    <aside class="message-toast-panel" id="message-toast-panel" hidden aria-label="Messages">
        <div class="message-toast-header">
            <strong>Messages</strong>
            <button class="message-toast-close" type="button" aria-label="Close messages">×</button>
        </div>
        <div class="message-toast-list">
            <?php if (!$messageItems): ?>
                <p class="message-toast-empty">No messages yet.</p>
            <?php else: ?>
                <?php foreach ($messageItems as $message): ?>
                    <article class="message-toast-item">
                        <span class="message-toast-avatar"><?= htmlspecialchars(strtoupper(substr($message['sender_name'], 0, 1)), ENT_QUOTES, 'UTF-8') ?></span>
                        <div>
                            <strong><?= htmlspecialchars($message['sender_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                            <h3><?= htmlspecialchars($message['subject'], ENT_QUOTES, 'UTF-8') ?></h3>
                            <p><?= trim((string) $message['body']) !== '' ? htmlspecialchars($message['body'], ENT_QUOTES, 'UTF-8') : '📎 Attachment' ?></p>
                            <small><?= htmlspecialchars($message['created_at'], ENT_QUOTES, 'UTF-8') ?></small>
                        </div>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <a class="message-toast-all"
            href="<?= str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/applicant/')
                ? '../../account/messages.php'
                : '../account/messages.php' ?>">Open all messages</a>
    </aside>
</span>
<script>
    (() => {
        const popover = document.querySelector('.message-popover');
        const toggle = popover ? popover.querySelector('.message-toggle') : null;
        const panel = popover ? popover.querySelector('.message-toast-panel') : null;
        const closeButton = popover ? popover.querySelector('.message-toast-close') : null;

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
