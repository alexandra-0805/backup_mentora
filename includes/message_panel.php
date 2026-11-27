<?php
require_once __DIR__ . '/messaging.php';
$userId = (int) ($_SESSION['user']['id'] ?? 0);
$messageQuery = $userId
    // To you, announcements, and group chats you're in ("Name · General"); not your own; the newest 50.
    ? $pdo->prepare('SELECT m.id,
            m.sender_id,
            m.recipient_id,
            m.group_id,
            m.subject,
            m.body,
            m.created_at,
            CONCAT(COALESCE(CONCAT(u.first_name, " ", u.last_name), "Mentora support"), IF(g.id IS NULL, "", CONCAT(" · ", g.name))) AS sender_name
        FROM messages m
        LEFT JOIN users u ON u.id = m.sender_id
        LEFT JOIN chat_groups g ON g.id = m.group_id
        WHERE (m.sender_id IS NULL OR m.sender_id <> ?)
          AND (m.recipient_id = ? OR (m.recipient_id IS NULL AND (m.group_id IS NULL OR m.group_id IN (' . mentora_my_groups_sql() . '))))
        ORDER BY m.created_at DESC, m.id DESC
        LIMIT 50')
    : $pdo->prepare('SELECT m.id, m.sender_id, m.recipient_id, m.group_id, m.subject, m.body, m.created_at, "Mentora support" AS sender_name
        FROM messages m
        WHERE m.recipient_id IS NULL AND m.group_id IS NULL
        ORDER BY m.created_at DESC');

if ($userId) {
    $messageQuery->execute([$userId, $userId, $userId]);
} else {
    $messageQuery->execute();
}

$messageItems = $messageQuery->fetchAll();
// Conversations you deleted on the Messages page stay out of here too (messages up to then).
if ($userId) {
    $messageStates = mentora_conversation_states($pdo, $userId);
    $messageItems = array_values(array_filter($messageItems, static fn(array $message): bool => !mentora_message_cleared(
        $messageStates,
        mentora_conversation_key($message, $userId),
        (int) $message['id']
    )));
}
$messagesPageHref = str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/applicant/')
    ? '../../account/messages.php'
    : '../account/messages.php';
$callEndpointHref = substr($messagesPageHref, 0, -strlen('messages.php')) . 'call.php';
// Conversation key used by account/messages.php (?c=...): u{id} per person, g{id} per group chat,
// "support" for system messages (includes/messaging.php).
$messageConversationKey = static fn(array $message): string => mentora_conversation_key($message, $userId);
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
                    <a class="message-toast-item" href="<?= htmlspecialchars($messagesPageHref . '?c=' . rawurlencode($messageConversationKey($message)), ENT_QUOTES, 'UTF-8') ?>">
                        <span class="message-toast-avatar"><?= htmlspecialchars(strtoupper(substr($message['sender_name'], 0, 1)), ENT_QUOTES, 'UTF-8') ?></span>
                        <div>
                            <strong><?= htmlspecialchars($message['sender_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                            <h3><?= htmlspecialchars($message['subject'], ENT_QUOTES, 'UTF-8') ?></h3>
                            <p><?= trim((string) $message['body']) !== '' ? htmlspecialchars(mentora_plain_mentions((string) $message['body']), ENT_QUOTES, 'UTF-8') : '📎 Attachment' ?></p>
                            <small><?= htmlspecialchars($message['created_at'], ENT_QUOTES, 'UTF-8') ?></small>
                        </div>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <a class="message-toast-all" href="<?= htmlspecialchars($messagesPageHref, ENT_QUOTES, 'UTF-8') ?>">Open all messages</a>
    </aside>
</span>
<?php if ($userId): // Someone calling you (voice or video) while you're on a workspace page: a toast that opens Messages to answer. ?>
<div class="call-toast" data-call-toast role="alert" hidden>
    <span class="call-toast-icon" aria-hidden="true">
        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" focusable="false"><rect x="3" y="6.5" width="12.5" height="11" rx="2.5"/><path d="m15.5 10.5 5-3v9l-5-3"/></svg>
    </span>
    <span class="call-toast-copy"><strong data-call-toast-name></strong><small data-call-toast-kind>is video calling you</small></span>
    <a class="call-toast-answer" data-call-toast-answer href="<?= htmlspecialchars($messagesPageHref, ENT_QUOTES, 'UTF-8') ?>">Answer</a>
    <button class="call-toast-close" type="button" data-call-toast-close aria-label="Dismiss">×</button>
</div>
<script>
    (() => {
        const toast = document.querySelector('[data-call-toast]');
        if (!toast || !window.fetch) {
            return;
        }
        const endpoint = <?= json_encode($callEndpointHref) ?>;
        const messagesPage = <?= json_encode($messagesPageHref) ?>;
        let shownId = null;
        let dismissedId = null;

        const check = async () => {
            let call = null;
            try {
                const response = await fetch(`${endpoint}?action=incoming`, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
                const result = await response.json();
                // A person calling you, or a group call just started in one of your groups.
                if (result.ok && result.call) {
                    call = {
                        id: `c${result.call.id}`,
                        name: result.call.callerName,
                        text: result.call.kind === 'voice' ? 'is voice calling you' : 'is video calling you',
                        href: `${messagesPage}?c=u${encodeURIComponent(result.call.callerId)}`,
                        answer: 'Answer'
                    };
                } else if (result.ok && result.groupCall) {
                    call = {
                        id: `g${result.groupCall.id}`,
                        name: result.groupCall.groupName,
                        text: `${result.groupCall.starterName} started a group ${result.groupCall.kind === 'voice' ? 'voice' : 'video'} call`,
                        href: `${messagesPage}?c=g${encodeURIComponent(result.groupCall.groupId)}`,
                        answer: 'Join'
                    };
                }
            } catch (error) {
                call = null;
            }
            if (call && call.id !== dismissedId) {
                if (call.id !== shownId) {
                    shownId = call.id;
                    toast.querySelector('[data-call-toast-name]').textContent = call.name;
                    toast.querySelector('[data-call-toast-kind]').textContent = call.text;
                    const answer = toast.querySelector('[data-call-toast-answer]');
                    answer.href = call.href;
                    answer.textContent = call.answer;
                    toast.hidden = false;
                }
            } else {
                shownId = null;
                toast.hidden = true;
            }
            // Check often while it rings; otherwise every 5 seconds (10 when this tab is in the background).
            setTimeout(check, call ? 2000 : (document.hidden ? 10000 : 5000));
        };
        toast.querySelector('[data-call-toast-close]').addEventListener('click', () => {
            dismissedId = shownId;
            toast.hidden = true;
        });
        check();
    })();
</script>
<?php endif; ?>
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
