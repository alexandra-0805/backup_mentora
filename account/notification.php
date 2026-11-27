<?php

/**
 * The bell panel's actions (includes/notification_panel.php):
 *   GET  (no id) [?page=2]            the archive: every notification older than the 10 newest the panel shows
 *   GET  ?id=12                       open one notification: mark it read, go to the page it is about
 *   POST mark_notifications_done=1    "Mark as done": mark all of them read, go back to the page you were on
 * Only your own notifications (or ones for everyone), and only pages inside Mentora.
 *
 * "Mark as done" is handled here (not on the page you're on), so pages with their own forms never
 * mistake it for theirs and answer "Your session expired".
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth_check.php';

$user = mentora_require_login();
$userId = (int) $user['id'];
$home = mentora_home_path($user);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($_SESSION['notification_action_token'] ?? '');
    $tokenOk = $token !== '' && hash_equals($token, (string) ($_POST['notification_action_token'] ?? ''));
    $deleteId = filter_input(INPUT_POST, 'delete_notification', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

    if ($deleteId) {
        // Archive: swipe left, Delete. Only your own notifications (ones for everyone are shared, so they stay).
        $valid = false;
        if ($tokenOk) {
            $delete = $pdo->prepare('DELETE FROM notifications WHERE id = ? AND user_id = ?');
            $delete->execute([$deleteId, $userId]);
            $valid = $delete->rowCount() === 1;
        }
    } elseif (isset($_POST['delete_archive'])) {
        // Archive: "Delete all". Removes your own notifications from the first archived one (as the page showed it,
        // archive_from + archive_from_id) down to the oldest, so one that arrived since, and pushed an older one
        // out of the bell panel, is never deleted unseen. Ones for everyone stay.
        $from = (string) ($_POST['archive_from'] ?? '');
        $fromId = filter_input(INPUT_POST, 'archive_from_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $valid = $tokenOk && $fromId && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $from) === 1;
        if ($valid) {
            $pdo->prepare('DELETE FROM notifications WHERE user_id = ? AND (created_at < ? OR (created_at = ? AND id <= ?))')
                ->execute([$userId, $from, $from, $fromId]);
        }
    } else {
        $valid = isset($_POST['mark_notifications_done']) && $tokenOk;
        if ($valid) {
            $pdo->prepare('UPDATE notifications SET read_at = NOW() WHERE (user_id = ? OR user_id IS NULL) AND read_at IS NULL')->execute([$userId]);
        }
    }

    // The bell panel and the archive send their actions in the background (Accept: application/json) and update themselves.
    if (str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')) {
        header('Content-Type: application/json');
        http_response_code($valid ? 200 : 400);
        echo json_encode(['ok' => $valid]);
        exit;
    }

    // Back to the page the panel was opened on: a path on this site only (no other sites, no line breaks).
    $back = (string) ($_POST['back'] ?? '');
    if (preg_match('~^/(?![/\\\\])[^\r\n\\\\]*$~', $back) === 1) {
        header('Location: ' . $back);
        exit;
    }
    mentora_redirect($home);
}

if (!isset($_GET['id'])) {
    mentora_render_notification_archive($pdo, $userId, $home);
    exit;
}

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$id) {
    mentora_redirect($home);
}

$query = $pdo->prepare('SELECT id, user_id, link FROM notifications WHERE id = ? AND (user_id = ? OR user_id IS NULL)');
$query->execute([$id, $userId]);
$notification = $query->fetch();
if (!$notification) {
    mentora_redirect($home);
}

// Personal notifications are marked read (ones for everyone keep the existing "Mark as done" behaviour).
if ($notification['user_id'] !== null) {
    $pdo->prepare('UPDATE notifications SET read_at = NOW() WHERE id = ? AND read_at IS NULL')->execute([$id]);
}

// A page inside Mentora only: "folder/page.php" with an optional simple query string and #section; no other sites.
$link = (string) ($notification['link'] ?? '');
$safe = preg_match('~^(?:[a-z0-9_-]+/)*[a-z0-9_&-]+\.php(?:\?[A-Za-z0-9_=&%.-]*)?(?:#[A-Za-z0-9_-]*)?$~i', $link) === 1 && !str_contains($link, '..');

mentora_redirect($safe ? $link : $home);

/**
 * The archive: your notifications older than the MENTORA_NOTIFICATION_PANEL_LIMIT newest (those stay in the bell
 * panel), newest first, 20 per page. Each one opens like it does in the panel (?id=...).
 */
function mentora_render_notification_archive(PDO $pdo, int $userId, string $home): void
{
    $perPage = 20;
    $skip = MENTORA_NOTIFICATION_PANEL_LIMIT;

    $count = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? OR user_id IS NULL');
    $count->execute([$userId]);
    $archived = max(0, (int) $count->fetchColumn() - $skip);
    $pages = max(1, (int) ceil($archived / $perPage));
    $page = min($pages, max(1, (int) filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT)));

    $offset = $skip + ($page - 1) * $perPage;
    $query = $pdo->prepare('SELECT id, user_id, title, body, link, read_at, created_at FROM notifications
        WHERE user_id = ? OR user_id IS NULL ORDER BY created_at DESC, id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset);
    $query->execute([$userId]);
    $items = $archived > 0 ? $query->fetchAll() : [];

    // "Delete all": the first (newest) archived notification marks where the archive starts; your own from there down.
    $first = $pdo->prepare('SELECT id, created_at FROM notifications WHERE user_id = ? OR user_id IS NULL
        ORDER BY created_at DESC, id DESC LIMIT 1 OFFSET ' . $skip);
    $first->execute([$userId]);
    $archiveStart = $first->fetch() ?: null;
    $ownArchived = 0;
    if ($archiveStart) {
        $own = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND (created_at < ? OR (created_at = ? AND id <= ?))');
        $own->execute([$userId, $archiveStart['created_at'], $archiveStart['created_at'], (int) $archiveStart['id']]);
        $ownArchived = (int) $own->fetchColumn();
    }
    $deleteAllMessage = 'Every notification in your archive will be permanently deleted. This can’t be undone.'
        . ($ownArchived < $archived ? ' Notifications sent to everyone stay.' : '');

    $h = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    $homeHref = '../' . $home;
    $actionToken = $_SESSION['notification_action_token'] ??= bin2hex(random_bytes(32));
    $backUrl = (string) ($_SERVER['REQUEST_URI'] ?? '');

    $pageTitle = 'Notification archive | Mentora';
    $bodyClass = 'account-page notifications-page';
    $hideTopbar = true;
    $hideFooter = true;
    $themeFollowsDevice = false; // the person's Light / Dark / Auto choice from Settings, like Messages
    require __DIR__ . '/../includes/header.php';
    ?>
    <main class="notification-archive">
        <div class="chat-page-bar">
            <a class="chat-back-link" href="<?= $h($homeHref) ?>">
                <svg class="chat-svg" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m15 6-6 6 6 6"/></svg>
                Back to workspace
            </a>
            <h1>Notification archive</h1>
            <?php if ($ownArchived > 0): ?>
                <form class="notification-archive-delete-all" method="post" action="notification.php">
                    <input type="hidden" name="notification_action_token" value="<?= $h($actionToken) ?>">
                    <input type="hidden" name="back" value="<?= $h(strtok($backUrl, '?')) ?>">
                    <input type="hidden" name="archive_from" value="<?= $h($archiveStart['created_at']) ?>">
                    <input type="hidden" name="archive_from_id" value="<?= (int) $archiveStart['id'] ?>">
                    <input type="hidden" name="delete_archive" value="1">
                    <?php // Asks first (includes/confirm_dialog.php), then deletes and reloads the (now empty) archive. ?>
                    <button type="submit"
                        data-confirm="<?= $h($deleteAllMessage) ?>"
                        data-confirm-title="Delete all archived notifications?"
                        data-confirm-action="Delete all"
                        data-confirm-tone="danger">
                        <svg class="chat-svg" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4 7h16"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M6 7l1 12a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-12"/><path d="M9 7V4h6v3"/></svg>
                        Delete all
                    </button>
                </form>
            <?php endif; ?>
        </div>
        <p class="notification-archive-intro">
            The bell shows your <?= MENTORA_NOTIFICATION_PANEL_LIMIT ?> newest notifications. Older ones are kept here<?= $archived > 0 ? ' (<span data-archive-count>' . $archived . '</span>)' : '' ?>.
            <?php if ($items): ?>Slide one to the left to delete it.<?php endif; ?>
        </p>

        <section class="notification-archive-list" aria-label="Archived notifications">
            <?php if (!$items): ?>
                <p class="notification-archive-empty">No archived notifications yet.</p>
            <?php else: ?>
                <?php foreach ($items as $item):
                    $clickable = trim((string) ($item['link'] ?? '')) !== '';
                    $tag = $clickable ? 'a' : 'article';
                    // Ones for everyone (user_id NULL) are shared by all accounts, so only your own can be deleted.
                    $deletable = $item['user_id'] !== null && (int) $item['user_id'] === $userId;
                ?>
                    <div class="notification-archive-row<?= $deletable ? ' is-swipeable' : '' ?>">
                        <<?= $tag ?> class="notification-archive-item<?= $item['read_at'] ? '' : ' unread' ?>"<?= $clickable ? ' href="?id=' . (int) $item['id'] . '"' : '' ?>>
                            <span class="notification-archive-dot" aria-hidden="true"></span>
                            <div>
                                <strong><?= $h($item['title']) ?></strong>
                                <p><?= $h($item['body']) ?></p>
                                <small><?= $h($item['created_at']) ?><?= $item['read_at'] ? '' : ' · Unread' ?></small>
                            </div>
                            <?php if ($clickable): ?>
                                <svg class="chat-svg" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m9 6 6 6-6 6"/></svg>
                            <?php endif; ?>
                        </<?= $tag ?>>
                        <?php if ($deletable): // revealed by sliding the notification to the left (or by Tab) ?>
                            <form class="notification-archive-delete" method="post" action="notification.php" data-delete-form>
                                <input type="hidden" name="notification_action_token" value="<?= $h($actionToken) ?>">
                                <input type="hidden" name="back" value="<?= $h($backUrl) ?>">
                                <input type="hidden" name="delete_notification" value="<?= (int) $item['id'] ?>">
                                <?php // Asks first (includes/confirm_dialog.php, from the footer), then the form is sent. ?>
                                <button type="submit"
                                    aria-label="Delete notification: <?= $h($item['title']) ?>"
                                    data-confirm="“<?= $h($item['title']) ?>” will be permanently removed from your archive. This can’t be undone."
                                    data-confirm-title="Delete this notification?"
                                    data-confirm-action="Delete"
                                    data-confirm-tone="danger">
                                    <svg class="chat-svg" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4 7h16"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M6 7l1 12a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-12"/><path d="M9 7V4h6v3"/></svg>
                                    <span>Delete</span>
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>

        <?php if ($pages > 1): ?>
            <nav class="notification-archive-pages" aria-label="Archive pages">
                <?php if ($page > 1): ?>
                    <a href="?page=<?= $page - 1 ?>">‹ Newer</a>
                <?php endif; ?>
                <span>Page <?= $page ?> of <?= $pages ?></span>
                <?php if ($page < $pages): ?>
                    <a href="?page=<?= $page + 1 ?>">Older ›</a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    </main>
    <script>
        // Slide a notification to the left (finger or mouse) to show its Delete button; slide right, tap it,
        // tap elsewhere or press Escape to close. Delete asks "Delete this notification?" first (data-confirm on
        // the button), then removes it in the background without reloading.
        (() => {
            const OPEN = 88; // px: the width of the Delete button (CSS --archive-delete-width)
            const rows = [...document.querySelectorAll('.notification-archive-row.is-swipeable')];
            const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

            const setOpen = (row, open) => {
                row.classList.toggle('is-open', open);
                row.querySelector('.notification-archive-item').style.transform = '';
            };
            const closeOthers = (keep) => rows.forEach((row) => row !== keep && setOpen(row, false));

            rows.forEach((row) => {
                const item = row.querySelector('.notification-archive-item');
                let startX = 0;
                let startY = 0;
                let base = 0;
                let offset = 0;
                let tracking = false;
                let dragging = false;
                let justDragged = false;

                item.addEventListener('dragstart', (event) => event.preventDefault()); // no link drag with the mouse

                item.addEventListener('pointerdown', (event) => {
                    if (event.button !== 0) {
                        return;
                    }
                    tracking = true;
                    dragging = false;
                    justDragged = false;
                    startX = event.clientX;
                    startY = event.clientY;
                    base = row.classList.contains('is-open') ? -OPEN : 0;
                    offset = base;
                });

                item.addEventListener('pointermove', (event) => {
                    if (!tracking) {
                        return;
                    }
                    const dx = event.clientX - startX;
                    const dy = event.clientY - startY;
                    if (!dragging) {
                        if (Math.abs(dy) > 8 && Math.abs(dy) > Math.abs(dx)) {
                            tracking = false; // scrolling up or down, not sliding
                            return;
                        }
                        if (Math.abs(dx) < 8) {
                            return;
                        }
                        dragging = true;
                        closeOthers(row);
                        row.classList.add('is-dragging');
                        item.setPointerCapture(event.pointerId);
                    }
                    offset = Math.min(0, Math.max(-OPEN * 1.3, base + dx));
                    item.style.transform = `translateX(${offset}px)`;
                });

                const finish = (cancelled) => {
                    if (!tracking) {
                        return;
                    }
                    tracking = false;
                    if (!dragging) {
                        return;
                    }
                    dragging = false;
                    justDragged = true;
                    row.classList.remove('is-dragging');
                    setOpen(row, cancelled ? base !== 0 : offset < -OPEN / 2);
                };
                item.addEventListener('pointerup', () => finish(false));
                item.addEventListener('pointercancel', () => finish(true));

                // A slide is not a click; and while open, tapping the notification just closes it.
                item.addEventListener('click', (event) => {
                    if (justDragged || row.classList.contains('is-open')) {
                        event.preventDefault();
                        if (!justDragged) {
                            setOpen(row, false);
                        }
                    }
                    justDragged = false;
                });
            });

            // While the "Delete this notification?" dialog is up, the row stays open behind it.
            const confirmOpen = () => Boolean(document.querySelector('#mentoraConfirmDialog[open]'));
            document.addEventListener('pointerdown', (event) => {
                if (!confirmOpen() && !event.target.closest('.notification-archive-row.is-open')) {
                    closeOthers(null);
                }
            });
            document.addEventListener('keydown', (event) => {
                if (event.key === 'Escape' && !confirmOpen()) {
                    closeOthers(null);
                }
            });

            const removeRow = (row) => {
                const count = document.querySelector('[data-archive-count]');
                if (count) {
                    count.textContent = String(Math.max(0, Number(count.textContent) - 1));
                }
                const done = () => {
                    row.remove();
                    if (!document.querySelector('.notification-archive-row')) {
                        window.location.reload(); // page emptied: show the next ones (or "No archived notifications")
                    }
                };
                if (reducedMotion) {
                    done();
                    return;
                }
                row.style.height = `${row.offsetHeight}px`;
                row.offsetHeight; // apply the starting height before animating to 0
                row.classList.add('is-removing');
                row.style.height = '0px';
                row.addEventListener('transitionend', done, { once: true });
                setTimeout(done, 400); // in case transitionend doesn't fire
            };

            document.querySelectorAll('[data-delete-form]').forEach((form) => {
                if (!window.fetch) {
                    return;
                }
                form.addEventListener('submit', async (event) => {
                    event.preventDefault();
                    const button = form.querySelector('button');
                    button.disabled = true;
                    try {
                        const response = await fetch(form.action, {
                            method: 'POST',
                            body: new FormData(form),
                            headers: { Accept: 'application/json' },
                            credentials: 'same-origin'
                        });
                        const result = await response.json();
                        if (!response.ok || !result.ok) {
                            throw new Error('not deleted');
                        }
                        removeRow(form.closest('.notification-archive-row'));
                    } catch (error) {
                        form.submit(); // fall back to the normal form (reloads the archive)
                    }
                });
            });
        })();
    </script>
    <?php
    require __DIR__ . '/../includes/footer.php';
}
