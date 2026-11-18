<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth_check.php';

$userId = mentora_require_login()['id'];
$messageActionToken = $_SESSION['message_action_token'] ?? bin2hex(random_bytes(32));
$_SESSION['message_action_token'] = $messageActionToken;
$messageError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_message'])) {
    $token = (string) ($_POST['message_action_token'] ?? '');
    $recipientId = (int) ($_POST['recipient_id'] ?? 0);
    $subject = trim((string) ($_POST['subject'] ?? ''));
    $body = trim((string) ($_POST['body'] ?? ''));

    if (!$userId || !hash_equals($messageActionToken, $token)) {
        $messageError = 'Your session expired. Please reload the page and try again.';
    } elseif (!$recipientId || $subject === '' || $body === '') {
        $messageError = 'Select a conversation and enter a message before sending.';
    } elseif (mb_strlen($body) > 5000) {
        $messageError = 'Messages must be 5,000 characters or fewer.';
    } else {
        $recipientCheck = $pdo->prepare(
            'SELECT COUNT(*) FROM messages
             WHERE sender_id = ? AND (recipient_id = ? OR recipient_id IS NULL)'
        );
        $recipientCheck->execute([$recipientId, $userId]);

        if (!(int) $recipientCheck->fetchColumn()) {
            $messageError = 'That conversation is no longer available.';
        } else {
            $sendMessage = $pdo->prepare(
                'INSERT INTO messages (sender_id, recipient_id, subject, body)
                 VALUES (?, ?, ?, ?)'
            );
            $sendMessage->execute([$userId, $recipientId, $subject, $body]);
            header('Location: messages.php');
            exit;
        }
    }
}

$query = $userId
    ? $pdo->prepare('SELECT m.id, m.sender_id, m.subject, m.body, m.read_at, m.created_at, COALESCE(CONCAT(u.first_name, " ", u.last_name), "Mentora support") AS sender_name FROM messages m LEFT JOIN users u ON u.id = m.sender_id WHERE m.recipient_id = ? OR m.recipient_id IS NULL ORDER BY m.created_at DESC')
    : $pdo->prepare('SELECT m.id, m.sender_id, m.subject, m.body, m.read_at, m.created_at, "Mentora support" AS sender_name FROM messages m WHERE m.recipient_id IS NULL ORDER BY m.created_at DESC');
if ($userId) {
    $query->execute([$userId]);
} else {
    $query->execute();
}
$messages = $query->fetchAll();
$pageTitle = 'Messages | Mentora';
$bodyClass = 'account-page';
require __DIR__ . '/../includes/header.php';
?>
<style>
    body.account-page {
        background: #fff;
    }

    .account-page .topbar {
        background: #fff;
        border-bottom: 1px solid #e5e5ea;
    }

    .account-page .account-shell {
        align-items: stretch;
        background: #fff;
        min-height: calc(100vh - 66px);
        padding: 0;
    }

    .account-page .message-page-card {
        background: #fff;
        border: 0;
        border-radius: 0;
        box-shadow: none;
        max-width: none;
        padding: 0;
        width: 100%;
    }

    .account-page .message-page-card > .eyebrow,
    .account-page .message-page-card > h1,
    .account-page .message-page-card > .intro {
        display: none;
    }

    .account-page .chat-workspace {
        background: #fff;
        border: 1px solid #e5e5ea;
        border-radius: 0;
        min-height: calc(100vh - 66px);
    }

    .account-page .chat-sidebar,
    .account-page .chat-info {
        background: #f5f5f7;
        border-color: #e5e5ea;
        color: #1d1d1f;
    }

    .account-page .chat-thread {
        background: #fff;
        color: #1d1d1f;
    }

    .account-page .chat-thread-header {
        background: #fff;
        border-color: #e5e5ea;
    }

    .account-page .chat-thread-header strong,
    .account-page .chat-message-subject,
    .account-page .chat-info h2,
    .account-page .chat-sidebar-heading h2 {
        color: #1d1d1f;
    }

    .account-page .chat-sidebar-heading button,
    .account-page .chat-thread-actions button {
        background: #e5e5ea;
        color: #3a3a3c;
    }

    .account-page .chat-thread-header small,
    .account-page .chat-info > small,
    .account-page .chat-thread-empty {
        color: #6e6e73;
    }

    .account-page .chat-search {
        background: #fff;
        border: 1px solid #d1d1d6;
    }

    .account-page .chat-search input {
        color: #1d1d1f;
    }

    .account-page .chat-tabs button {
        color: #3a3a3c;
    }

    .account-page .chat-conversation:hover,
    .account-page .chat-conversation.selected {
        background: #fff;
    }

    .account-page .chat-conversation-copy strong,
    .account-page .chat-conversation-copy span {
        color: #1d1d1f;
    }

    .account-page .chat-conversation-copy small {
        color: #6e6e73;
    }

    .account-page .chat-message-bubble {
        background: #f5f5f7;
        border: 1px solid #e5e5ea;
    }

    .account-page .chat-message-text {
        color: #3a3a3c;
    }

    .account-page .chat-composer {
        border-color: #e5e5ea;
    }

    .account-page .chat-composer input {
        background: #f5f5f7;
        color: #1d1d1f;
    }

    .account-page .chat-info h3 {
        border-color: #e5e5ea;
        color: #1d1d1f;
    }

    .account-page .chat-info > button {
        background: #e5e5ea;
        color: #3a3a3c;
    }

    .account-page .chat-thread-body {
        min-height: 0;
    }

    .account-page .chat-composer-form {
        display: flex;
        gap: 8px;
        width: 100%;
    }

    .account-page .chat-composer-form input {
        min-width: 0;
    }

    .account-page .chat-composer-form button:disabled {
        cursor: not-allowed;
        opacity: .45;
    }

    .account-page .chat-action-menu {
        background: #3a3b3c;
        border: 1px solid #55575a;
        border-radius: 8px;
        box-shadow: 0 8px 20px rgba(0, 0, 0, .3);
        display: none;
        padding: 6px;
        position: absolute;
        right: 12px;
        top: 58px;
        z-index: 4;
    }

    .account-page .chat-action-menu.is-open {
        display: grid;
    }

    .account-page .chat-action-menu button {
        background: transparent;
        border: 0;
        color: #fff;
        cursor: pointer;
        padding: 8px 12px;
        text-align: left;
        white-space: nowrap;
    }

    .account-page .chat-action-menu button:hover {
        background: #505154;
        border-radius: 5px;
    }

    .account-page .chat-thread,
    .account-page .chat-info {
        position: relative;
    }

    .account-page .chat-error {
        color: #ffb4ab;
        font-size: 12px;
        margin: 0;
        padding: 8px 18px 0;
    }

    .account-page .chat-collapse-content {
        color: #b0b3b8;
        font-size: 12px;
        line-height: 1.45;
        margin: 8px 0 0;
        text-align: left;
    }

    .account-page .chat-collapse-toggle {
        cursor: pointer;
    }

    .account-page .chat-collapse-toggle.is-collapsed span {
        display: inline-block;
        transform: rotate(-90deg);
    }

    .account-page {
        background: var(--mist);
        color: var(--ink);
    }

    .account-page .account-shell,
    .account-page .message-page-card {
        background: var(--mist);
    }

    .account-page .chat-workspace {
        border-color: var(--line);
    }

    .account-page .chat-sidebar,
    .account-page .chat-info {
        background: var(--pale);
        border-color: var(--line);
        color: var(--ink);
    }

    .account-page .chat-thread,
    .account-page .chat-thread-header {
        background: var(--white);
        color: var(--ink);
    }

    .account-page .chat-thread-header {
        border-color: var(--line);
    }

    .account-page .chat-thread-header strong,
    .account-page .chat-message-subject,
    .account-page .chat-info h2,
    .account-page .chat-sidebar-heading h2 {
        color: var(--ink);
    }

    .account-page .chat-sidebar-heading button,
    .account-page .chat-thread-actions button,
    .account-page .chat-info > button {
        background: var(--pale);
        color: var(--deep);
    }

    .account-page .chat-thread-header small,
    .account-page .chat-info > small,
    .account-page .chat-thread-empty,
    .account-page .chat-conversation-copy small {
        color: var(--muted);
    }

    .account-page .chat-search {
        background: var(--white);
        border-color: var(--line);
    }

    .account-page .chat-search input,
    .account-page .chat-conversation-copy strong,
    .account-page .chat-conversation-copy span {
        color: var(--ink);
    }

    .account-page .chat-tabs button {
        color: var(--deep);
    }

    .account-page .chat-tabs button.active,
    .account-page .chat-tabs button:hover {
        background: var(--teal);
        color: var(--white);
    }

    .account-page .chat-conversation:hover,
    .account-page .chat-conversation.selected {
        background: var(--white);
    }

    .account-page .chat-avatar {
        background: var(--teal);
    }

    .account-page .chat-unread-dot {
        background: var(--teal);
    }

    .account-page .chat-message-bubble {
        background: var(--pale);
        border-color: var(--line);
    }

    .account-page .chat-message-text,
    .account-page .chat-collapse-content {
        color: var(--deep);
    }

    .account-page .chat-composer {
        border-color: var(--line);
    }

    .account-page .chat-composer input {
        background: var(--mist);
        color: var(--ink);
    }

    .account-page .chat-info h3 {
        border-color: var(--line);
        color: var(--ink);
    }

    .account-page .chat-action-menu {
        background: var(--deep);
        border-color: var(--teal);
    }

    .account-page .topbar {
        background: linear-gradient(90deg, var(--deep), var(--teal));
        border-bottom: 0;
        height: 78px;
        padding: 0 6%;
    }

    .account-page .topbar .brand,
    .account-page .topbar .brand span {
        color: var(--white);
    }

    .account-page .topbar .brand img {
        height: 58px;
        width: 58px;
    }

    .account-page .topbar .brand {
        border-radius: 999px;
        gap: 10px;
        padding: 5px 10px 5px 5px;
        transition: background-color 180ms ease, box-shadow 180ms ease, transform 180ms ease;
    }

    .account-page .topbar .brand:hover,
    .account-page .topbar .brand:focus-visible {
        background: var(--white);
        box-shadow: 0 8px 18px rgba(0, 0, 0, .18);
        color: var(--deep);
        outline: none;
        transform: translateY(-1px);
    }

    .account-page .topbar .brand:hover img,
    .account-page .topbar .brand:focus-visible img {
        transform: none;
    }

    .account-page .topbar .brand:hover span,
    .account-page .topbar .brand:focus-visible span {
        color: var(--deep);
    }

    .account-page .topbar .brand img {
        transition: transform 180ms ease;
    }

    .account-page .topbar nav {
        display: none;
    }
</style>
<main class="auth-shell account-shell">
    <section class="auth-card wide account-card utility-page message-page-card">
        <p class="eyebrow">Your conversations</p>
        <h1>Messages</h1>
        <p class="intro">Connect with HR, agents, and your Mentora support team.</p>
        <div class="chat-workspace">
                <aside class="chat-sidebar">
                    <div class="chat-sidebar-heading">
                        <h2>Chats</h2>
                        <div>
                            <button class="chat-menu-toggle" type="button" aria-label="More chat options" aria-expanded="false">•••</button>
                            <button class="chat-new-message" type="button" aria-label="New message">↗</button>
                            <div class="chat-action-menu" data-menu="sidebar">
                                <button type="button" data-action="refresh">Refresh messages</button>
                                <button type="button" data-action="clear-search">Clear search</button>
                            </div>
                        </div>
                    </div>
                    <label class="chat-search"><span aria-hidden="true">⌕</span><input class="chat-search-input" type="search" placeholder="Search messages" aria-label="Search messages"></label>
                    <div class="chat-tabs" role="tablist">
                        <button class="active" type="button" data-filter="all">All</button><button type="button" data-filter="unread">Unread</button><button type="button" data-filter="support">Support</button>
                    </div>
                    <div class="chat-conversation-list" aria-label="Conversation list">
                    <?php if (!$messages): ?>
                        <p class="chat-empty-list">No conversations yet.</p>
                    <?php else: ?>
                        <?php foreach ($messages as $message): ?>
                        <button
                            class="chat-conversation<?= $message['read_at'] ? '' : ' unread' ?>"
                            type="button"
                            data-sender="<?= htmlspecialchars($message['sender_name'], ENT_QUOTES, 'UTF-8') ?>"
                            data-subject="<?= htmlspecialchars($message['subject'], ENT_QUOTES, 'UTF-8') ?>"
                            data-body="<?= htmlspecialchars($message['body'], ENT_QUOTES, 'UTF-8') ?>"
                            data-created="<?= htmlspecialchars($message['created_at'], ENT_QUOTES, 'UTF-8') ?>"
                            data-read="<?= $message['read_at'] ? 'true' : 'false' ?>"
                            data-sender-id="<?= (int) ($message['sender_id'] ?? 0) ?>"
                        >
                            <span class="chat-avatar"><?= htmlspecialchars(strtoupper(substr($message['sender_name'], 0, 1)), ENT_QUOTES, 'UTF-8') ?></span>
                            <span class="chat-conversation-copy">
                                <strong><?= htmlspecialchars($message['sender_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                <span><?= htmlspecialchars($message['subject'], ENT_QUOTES, 'UTF-8') ?></span>
                                <small><?= htmlspecialchars($message['body'], ENT_QUOTES, 'UTF-8') ?></small>
                            </span>
                            <?php if (!$message['read_at']): ?><span class="chat-unread-dot" aria-label="Unread"></span><?php endif; ?>
                        </button>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </div>
                </aside>
                <section class="chat-thread" aria-live="polite">
                    <header class="chat-thread-header">
                        <span class="chat-avatar chat-thread-avatar"></span>
                        <div><strong class="chat-thread-sender"></strong><small>Mentora support</small></div>
                        <div class="chat-thread-actions">
                            <button type="button" aria-label="Call" data-action="call">☎</button>
                            <button class="chat-menu-toggle" type="button" aria-label="More options" aria-expanded="false">•••</button>
                            <div class="chat-action-menu" data-menu="thread">
                                <button type="button" data-action="copy">Copy message</button>
                                <button type="button" data-action="close-menu">Close menu</button>
                            </div>
                        </div>
                    </header>
                    <div class="chat-thread-body">
                        <div class="chat-thread-empty">Select a conversation to view the message.</div>
                        <article class="chat-message-bubble" hidden>
                            <small class="chat-message-time"></small>
                            <h2 class="chat-message-subject"></h2>
                            <p class="chat-message-text"></p>
                        </article>
                    </div>
                    <?php if ($messageError): ?><p class="chat-error"><?= htmlspecialchars($messageError, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
                    <form class="chat-composer chat-composer-form" method="post">
                        <input type="hidden" name="message_action_token" value="<?= htmlspecialchars($messageActionToken, ENT_QUOTES, 'UTF-8') ?>">
                        <input class="chat-recipient-id" type="hidden" name="recipient_id" value="">
                        <input class="chat-subject" type="hidden" name="subject" value="">
                        <input class="chat-message-input" type="text" name="body" placeholder="Reply to this conversation" aria-label="Reply message" maxlength="5000" disabled>
                        <button class="chat-send-button" type="submit" name="send_message" value="1" aria-label="Send message" disabled>➤</button>
                    </form>
                </section>
                <aside class="chat-info">
                    <div class="chat-info-avatar chat-thread-avatar"></div>
                    <h2 class="chat-info-sender">Mentora support</h2>
                    <small>Message details</small>
                    <button type="button" data-action="search">⌕　Search</button>
                    <h3 class="chat-collapse-toggle" tabindex="0">Chat info <span>⌄</span></h3>
                    <p class="chat-collapse-content">Messages are stored securely in your Mentora inbox.</p>
                    <h3 class="chat-collapse-toggle" tabindex="0">Support details <span>⌄</span></h3>
                    <p class="chat-collapse-content">Use the message box to reply to this conversation.</p>
                </aside>
        </div>
    </section>
</main>
<script>
    (() => {
        const items = document.querySelectorAll('.chat-conversation');
        const emptyState = document.querySelector('.chat-thread-empty');
        const detail = document.querySelector('.chat-message-bubble');
        const searchInput = document.querySelector('.chat-search-input');
        const filterButtons = document.querySelectorAll('.chat-tabs button');
        const composerInput = document.querySelector('.chat-message-input');
        const composerButton = document.querySelector('.chat-send-button');
        const recipientInput = document.querySelector('.chat-recipient-id');
        const subjectInput = document.querySelector('.chat-subject');
        const newMessageButton = document.querySelector('.chat-new-message');
        const sidebarMenu = document.querySelector('[data-menu="sidebar"]');
        const threadMenu = document.querySelector('[data-menu="thread"]');

        const showMessage = (item) => {
            const sender = item.getAttribute('data-sender') || 'Mentora support';
            const senderId = item.getAttribute('data-sender-id') || '';
            emptyState.hidden = true;
            detail.hidden = false;
            document.querySelectorAll('.chat-thread-avatar, .chat-info-avatar').forEach((avatar) => {
                avatar.textContent = sender.charAt(0).toUpperCase();
            });
            document.querySelector('.chat-thread-sender').textContent = sender;
            document.querySelector('.chat-info-sender').textContent = sender;
            detail.querySelector('.chat-message-time').textContent = item.getAttribute('data-created') || '';
            detail.querySelector('.chat-message-subject').textContent = item.getAttribute('data-subject') || '';
            detail.querySelector('.chat-message-text').textContent = item.getAttribute('data-body') || '';
            if (recipientInput && subjectInput && composerInput && composerButton) {
                recipientInput.value = senderId;
                subjectInput.value = item.getAttribute('data-subject') || '';
                const canReply = senderId !== '' && senderId !== '0';
                composerInput.disabled = !canReply;
                composerButton.disabled = !canReply;
                composerInput.placeholder = canReply ? 'Reply to this conversation' : 'This message cannot be replied to';
            }
            items.forEach((conversation) => conversation.classList.remove('selected'));
            item.classList.add('selected');
            detail.scrollIntoView({ behavior: 'smooth', block: 'center' });
        };

        items.forEach((item) => {
            item.addEventListener('click', () => showMessage(item));
        });

        const filterItems = () => {
            const search = (searchInput ? searchInput.value : '').trim().toLowerCase();
            const activeTab = document.querySelector('.chat-tabs button.active');
            const activeFilter = activeTab ? activeTab.getAttribute('data-filter') : 'all';

            items.forEach((item) => {
                const searchableText = [
                    item.getAttribute('data-sender'),
                    item.getAttribute('data-subject'),
                    item.getAttribute('data-body')
                ].join(' ').toLowerCase();
                const matchesSearch = !search || searchableText.includes(search);
                const matchesFilter = activeFilter === 'all'
                    || (activeFilter === 'unread' && item.getAttribute('data-read') !== 'true')
                    || (activeFilter === 'support' && (item.getAttribute('data-sender') || '').toLowerCase().includes('support'));
                item.hidden = !(matchesSearch && matchesFilter);
            });
        };

        if (searchInput) {
            searchInput.addEventListener('input', filterItems);
        }

        filterButtons.forEach((button) => {
            button.addEventListener('click', () => {
                filterButtons.forEach((tab) => tab.classList.remove('active'));
                button.classList.add('active');
                filterItems();
            });
        });

        const closeMenus = () => {
            document.querySelectorAll('.chat-action-menu').forEach((menu) => menu.classList.remove('is-open'));
            document.querySelectorAll('.chat-menu-toggle').forEach((button) => button.setAttribute('aria-expanded', 'false'));
        };

        document.querySelectorAll('.chat-menu-toggle').forEach((button) => {
            button.addEventListener('click', (event) => {
                event.stopPropagation();
                const menu = button.parentElement.querySelector('.chat-action-menu');
                const isOpen = menu.classList.toggle('is-open');
                button.setAttribute('aria-expanded', String(isOpen));
            });
        });

        document.querySelectorAll('[data-action="refresh"]').forEach((button) => {
            button.addEventListener('click', () => window.location.reload());
        });

        document.querySelectorAll('[data-action="clear-search"]').forEach((button) => {
            button.addEventListener('click', () => {
                if (searchInput) {
                    searchInput.value = '';
                    filterItems();
                }
                closeMenus();
            });
        });

        document.querySelectorAll('[data-action="close-menu"]').forEach((button) => {
            button.addEventListener('click', closeMenus);
        });

        document.querySelectorAll('[data-action="copy"]').forEach((button) => {
            button.addEventListener('click', async () => {
                const text = detail.querySelector('.chat-message-text').textContent;
                if (navigator.clipboard) {
                    await navigator.clipboard.writeText(text);
                }
                closeMenus();
            });
        });

        document.querySelectorAll('[data-action="search"]').forEach((button) => {
            button.addEventListener('click', () => {
                if (searchInput) {
                    searchInput.focus();
                }
            });
        });

        document.querySelectorAll('.chat-collapse-toggle').forEach((toggle) => {
            toggle.addEventListener('click', () => {
                const content = toggle.nextElementSibling;
                content.hidden = !content.hidden;
                toggle.classList.toggle('is-collapsed', content.hidden);
            });
            toggle.addEventListener('keydown', (event) => {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    toggle.click();
                }
            });
        });

        if (newMessageButton) {
            newMessageButton.addEventListener('click', () => {
                if (composerInput && !composerInput.disabled) {
                    composerInput.focus();
                } else if (items.length) {
                    showMessage(items[0]);
                    if (composerInput && !composerInput.disabled) {
                        composerInput.focus();
                    }
                }
            });
        }

        document.addEventListener('click', (event) => {
            if (!event.target.closest('.chat-action-menu') && !event.target.closest('.chat-menu-toggle')) {
                closeMenus();
            }
        });

        if (items.length) {
            showMessage(items[0]);
        }
    })();
</script>
