<?php

/**
 * Shared rules for Messages (account/messages.php), video calls (account/call.php) and the top-bar
 * envelope (includes/message_panel.php).
 *
 * Conversation keys, as one person sees them:
 *   u{id}     everything between you and that person
 *   g{id}     a group chat you're in ("General" has every user in it)
 *   support   messages from Mentora itself (no sender)
 *   all       older announcements you sent to everyone (HR admins; new ones go to General)
 */

declare(strict_types=1);

/** Seconds a video call rings before it counts as missed. */
const MENTORA_CALL_RING_SECONDS = 45;
/** Seconds without a poll from one side before an open call is treated as hung up (tab closed, connection lost). */
const MENTORA_CALL_STALE_SECONDS = 30;

/** Roles this person can start a conversation (or a call) with. */
function mentora_message_contact_roles(array $me): array
{
    return in_array($me['role'], ['admin', 'agent'], true) ? ['admin', 'agent', 'applicant'] : ['admin', 'agent'];
}

/**
 * Can $me message (or call) $recipientId? HR admins and agents can reach everyone; interns and employees
 * can reach HR and agents; anyone can answer someone who wrote to them.
 */
function mentora_can_message(PDO $pdo, array $me, int $recipientId): bool
{
    $userId = (int) $me['id'];
    if ($recipientId <= 0 || $recipientId === $userId) {
        return false;
    }
    $query = $pdo->prepare('SELECT role, status FROM users WHERE id = ?');
    $query->execute([$recipientId]);
    $recipient = $query->fetch();
    if (!$recipient) {
        return false;
    }
    if ($recipient['status'] === 'active' && in_array($recipient['role'], mentora_message_contact_roles($me), true)) {
        return true;
    }
    // Writing in a group chat doesn't count: only a message to you (or an old announcement).
    $check = $pdo->prepare('SELECT COUNT(*) FROM messages WHERE sender_id = ? AND (recipient_id = ? OR recipient_id IS NULL) AND group_id IS NULL');
    $check->execute([$recipientId, $userId]);
    return (int) $check->fetchColumn() > 0;
}

/* ---------- Group chats ---------- */

/** The General chat's id (every user is in it). */
function mentora_general_group_id(PDO $pdo): int
{
    static $id = null;
    return $id ??= (int) $pdo->query('SELECT id FROM chat_groups WHERE is_general = 1 ORDER BY id LIMIT 1')->fetchColumn();
}

/** SQL list of the groups $userId is in (General plus their own groups); bind $userId once. */
function mentora_my_groups_sql(): string
{
    return 'SELECT id FROM chat_groups WHERE is_general = 1 UNION SELECT group_id FROM chat_group_members WHERE user_id = ?';
}

/** The groups $userId is in: [id => ['id', 'name', 'is_general', 'created_at', 'members' => [user ids], 'owner' => ?user id]]. */
function mentora_user_groups(PDO $pdo, int $userId): array
{
    $query = $pdo->prepare('SELECT id, name, is_general, created_at FROM chat_groups
        WHERE id IN (' . mentora_my_groups_sql() . ') ORDER BY is_general DESC, id');
    $query->execute([$userId]);
    $groups = [];
    foreach ($query->fetchAll() as $group) {
        $groups[(int) $group['id']] = $group + ['members' => [], 'owner' => null];
    }
    if ($groups) {
        $placeholders = implode(',', array_fill(0, count($groups), '?'));
        $members = $pdo->prepare("SELECT m.group_id, m.user_id, m.role FROM chat_group_members m JOIN users u ON u.id = m.user_id
            WHERE m.group_id IN ($placeholders) AND u.status = 'active' ORDER BY m.role = 'owner' DESC, u.first_name, u.last_name");
        $members->execute(array_keys($groups));
        foreach ($members->fetchAll() as $member) {
            $groups[(int) $member['group_id']]['members'][] = (int) $member['user_id'];
            if ($member['role'] === 'owner') {
                $groups[(int) $member['group_id']]['owner'] = (int) $member['user_id'];
            }
        }
    }
    return $groups;
}

/** Is $userId in this group? (Everyone is in General.) */
function mentora_in_group(PDO $pdo, int $groupId, int $userId): bool
{
    $query = $pdo->prepare('SELECT 1 FROM chat_groups g LEFT JOIN chat_group_members m ON m.group_id = g.id AND m.user_id = ?
        WHERE g.id = ? AND (g.is_general = 1 OR m.user_id IS NOT NULL)');
    $query->execute([$userId, $groupId]);
    return (bool) $query->fetchColumn();
}

/** Does this message say "@everyone"? */
function mentora_mentions_everyone(string $body): bool
{
    return preg_match('/(?<![\w@])@everyone\b/i', $body) === 1;
}

/*
 * Mentioning a person in a group chat: the message stores "@[Ana Cruz](12)" (picked from the list on the
 * Messages page); it shows as a highlighted "@Ana Cruz", and Ana gets a bell notification.
 */
const MENTORA_MENTION_PATTERN = '/@\[([^\]\[\n]{1,80})\]\(([1-9]\d{0,9})\)/u';

/** The text with mentions as plain "@Ana Cruz" (list previews, search, notifications, the envelope). */
function mentora_plain_mentions(string $body): string
{
    return (string) preg_replace(MENTORA_MENTION_PATTERN, '@$1', $body);
}

/**
 * Check a group message's mentions: only active people in that group count, written with their real name
 * (someone else is just plain "@text"). Returns [the message, ids of the people mentioned, not the sender].
 */
function mentora_resolve_mentions(PDO $pdo, int $groupId, string $body, int $senderId): array
{
    if (!preg_match_all(MENTORA_MENTION_PATTERN, $body, $matches)) {
        return [$body, []];
    }
    $ids = array_values(array_unique(array_map('intval', $matches[2])));
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $query = $pdo->prepare("SELECT u.id, u.first_name, u.last_name FROM users u
        WHERE u.id IN ($placeholders) AND u.status = 'active'
          AND (EXISTS (SELECT 1 FROM chat_groups g WHERE g.id = ? AND g.is_general = 1)
               OR EXISTS (SELECT 1 FROM chat_group_members m WHERE m.group_id = ? AND m.user_id = u.id))");
    $query->execute([...$ids, $groupId, $groupId]);
    $names = [];
    foreach ($query->fetchAll() as $person) {
        $names[(int) $person['id']] = str_replace(['[', ']'], '', trim($person['first_name'] . ' ' . $person['last_name']));
    }
    $body = (string) preg_replace_callback(MENTORA_MENTION_PATTERN, static fn(array $m): string => isset($names[(int) $m[2]])
        ? '@[' . $names[(int) $m[2]] . '](' . (int) $m[2] . ')'
        : '@' . $m[1], $body);
    return [$body, array_values(array_diff(array_keys($names), [$senderId]))];
}

/** A short, one-line version of a message for a notification. */
function mentora_message_excerpt(string $body): string
{
    $text = trim((string) preg_replace('/\s+/', ' ', mentora_plain_mentions($body)));
    return mb_strlen($text) > 180 ? mb_substr($text, 0, 177) . '…' : $text;
}

/** People mentioned in a group message: a bell notification each ("Ana mentioned you in Web team"). */
function mentora_notify_mentions(PDO $pdo, int $groupId, array $sender, string $groupName, array $userIds, string $body): void
{
    if (!$userIds) {
        return;
    }
    $senderName = trim((string) ($sender['name'] ?? '')) ?: 'Someone';
    $insert = $pdo->prepare('INSERT INTO notifications (user_id, title, body, link) VALUES (?, ?, ?, ?)');
    foreach ($userIds as $mentionedId) {
        $insert->execute([(int) $mentionedId, mb_substr($senderName . ' mentioned you in ' . $groupName, 0, 190), mentora_message_excerpt($body), 'account/messages.php?c=g' . $groupId]);
    }
}

/**
 * An HR admin wrote "@everyone" in a group: a bell notification for every other member
 * (General: every active user), opening the chat (account/notification.php sends them there).
 */
function mentora_notify_group_mention(PDO $pdo, int $groupId, array $sender, string $groupName, string $body): void
{
    $senderName = trim((string) ($sender['name'] ?? (($sender['first_name'] ?? '') . ' ' . ($sender['last_name'] ?? '')))) ?: 'HR';
    $title = mb_substr($senderName . ' mentioned @everyone in ' . $groupName, 0, 190);
    $excerpt = mentora_message_excerpt($body);
    $link = 'account/messages.php?c=g' . $groupId;
    $pdo->prepare("INSERT INTO notifications (user_id, title, body, link)
        SELECT u.id, ?, ?, ? FROM users u
        WHERE u.status = 'active' AND u.id <> ?
          AND (EXISTS (SELECT 1 FROM chat_groups g WHERE g.id = ? AND g.is_general = 1)
               OR EXISTS (SELECT 1 FROM chat_group_members m WHERE m.group_id = ? AND m.user_id = u.id))")
        ->execute([$title, $excerpt, $link, (int) $sender['id'], $groupId, $groupId]);
}

/** A message's conversation key for $userId (see the top of this file). */
function mentora_conversation_key(array $message, int $userId): string
{
    if (!empty($message['group_id'])) {
        return 'g' . (int) $message['group_id'];
    }
    $sender = $message['sender_id'] === null ? null : (int) $message['sender_id'];
    if ($sender === $userId) {
        return $message['recipient_id'] === null ? 'all' : 'u' . (int) $message['recipient_id'];
    }
    return $sender === null ? 'support' : 'u' . $sender;
}

/** Is this a well-formed conversation key? */
function mentora_valid_conversation_key(string $key): bool
{
    return preg_match('/^([ug][1-9]\d{0,9}|support|all)$/', $key) === 1;
}

/** SQL condition (with its values) for the messages of one conversation, as $userId sees it. */
function mentora_conversation_condition(string $key, int $userId): array
{
    if ($key[0] === 'g') {
        return ['group_id = ?', [(int) substr($key, 1)]];
    }
    if ($key === 'support') {
        return ['group_id IS NULL AND sender_id IS NULL AND (recipient_id = ? OR recipient_id IS NULL)', [$userId]];
    }
    if ($key === 'all') {
        return ['group_id IS NULL AND sender_id = ? AND recipient_id IS NULL', [$userId]];
    }
    $otherId = (int) substr($key, 1);
    return ['group_id IS NULL AND ((sender_id = ? AND recipient_id = ?) OR (sender_id = ? AND (recipient_id = ? OR recipient_id IS NULL)))', [$userId, $otherId, $otherId, $userId]];
}

/**
 * This person's archive / delete state per conversation:
 * ['u12' => ['archived_through' => ?int, 'cleared_through' => ?int], ...]
 */
function mentora_conversation_states(PDO $pdo, int $userId): array
{
    $query = $pdo->prepare('SELECT conversation_key, archived_through, cleared_through FROM message_conversations WHERE user_id = ?');
    $query->execute([$userId]);
    $states = [];
    foreach ($query->fetchAll() as $row) {
        $states[$row['conversation_key']] = [
            'archived_through' => $row['archived_through'] === null ? null : (int) $row['archived_through'],
            'cleared_through' => $row['cleared_through'] === null ? null : (int) $row['cleared_through'],
        ];
    }
    return $states;
}

/** Was this message removed by "Delete conversation" (for this person only)? */
function mentora_message_cleared(array $states, string $key, int $messageId): bool
{
    $cleared = $states[$key]['cleared_through'] ?? null;
    return $cleared !== null && $messageId <= $cleared;
}

/* ---------- Video and voice calls ---------- */

/**
 * Close a call that is still ringing or open, once (whichever request gets there first), and write
 * a line about it into the conversation: "📹 Video call · 4:05", "📞 Missed voice call",
 * "📹 Video call declined"… Returns false when the call had already finished.
 */
function mentora_finish_call(PDO $pdo, array $call, string $status): bool
{
    $update = $pdo->prepare('UPDATE calls SET status = ?, ended_at = NOW() WHERE id = ? AND status IN ("ringing", "active")');
    $update->execute([$status, (int) $call['id']]);
    if ($update->rowCount() !== 1) {
        return false;
    }

    // "📹 Video call · 4:05" / "📞 Voice call · 1:15", "… declined", "Missed …".
    [$icon, $label] = ($call['kind'] ?? 'video') === 'voice' ? ['📞', 'voice call'] : ['📹', 'video call'];
    $text = match ($status) {
        'ended' => $icon . ' ' . ucfirst($label) . ' · ' . mentora_call_length($call['answered_at'] ?? null),
        'declined' => $icon . ' ' . ucfirst($label) . ' declined',
        default => $icon . ' Missed ' . $label,
    };
    // Same topic as the conversation's latest message, so the call line doesn't start a new one.
    [$condition, $values] = mentora_conversation_condition('u' . (int) $call['callee_id'], (int) $call['caller_id']);
    $subjectQuery = $pdo->prepare("SELECT subject FROM messages WHERE $condition ORDER BY id DESC LIMIT 1");
    $subjectQuery->execute($values);
    $subject = (string) ($subjectQuery->fetchColumn() ?: 'Message');
    // A missed call stays unread for the person who was called; the others they already saw.
    $pdo->prepare('INSERT INTO messages (sender_id, recipient_id, subject, body, read_at) VALUES (?, ?, ?, ?, ' . ($status === 'missed' ? 'NULL' : 'NOW()') . ')')
        ->execute([(int) $call['caller_id'], (int) $call['callee_id'], $subject, $text]);
    // Remembered so both pages can show the line right away (account/call.php action=log).
    $pdo->prepare('UPDATE calls SET log_message_id = ? WHERE id = ?')->execute([(int) $pdo->lastInsertId(), (int) $call['id']]);
    return true;
}

/** "4:05" from when the call was answered until now. */
function mentora_call_length(?string $answeredAt): string
{
    $seconds = $answeredAt ? max(0, time() - (int) strtotime($answeredAt)) : 0;
    return sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
}

/* ---------- Group calls (a group chat or General) ---------- */

/** Everyone connects to everyone, so a group call stays small. */
const MENTORA_GROUP_CALL_MAX = 8;

/** The people in a group call right now: [user id => name]. */
function mentora_group_call_people(PDO $pdo, int $callId): array
{
    $query = $pdo->prepare('SELECT u.id, u.first_name, u.last_name FROM group_call_participants p JOIN users u ON u.id = p.user_id
        WHERE p.call_id = ? AND p.left_at IS NULL ORDER BY p.joined_at, u.id');
    $query->execute([$callId]);
    $people = [];
    foreach ($query->fetchAll() as $person) {
        $people[(int) $person['id']] = trim($person['first_name'] . ' ' . $person['last_name']);
    }
    return $people;
}

/** End a group call once (the last person left) and note it in the chat: "📹 Group video call · 12:30 · started by Ana". */
function mentora_end_group_call(PDO $pdo, array $call): void
{
    $update = $pdo->prepare('UPDATE group_calls SET status = "ended", ended_at = NOW() WHERE id = ? AND status = "active"');
    $update->execute([(int) $call['id']]);
    if ($update->rowCount() !== 1) {
        return;
    }
    $starter = $pdo->prepare('SELECT first_name FROM users WHERE id = ?');
    $starter->execute([(int) ($call['started_by'] ?? 0)]);
    $starterName = (string) ($starter->fetchColumn() ?: '');
    $text = ($call['kind'] === 'voice' ? '📞 Group voice call' : '📹 Group video call') . ' · ' . mentora_call_length((string) $call['created_at'])
        . ($starterName !== '' ? ' · started by ' . $starterName : '');
    $pdo->prepare('INSERT INTO messages (sender_id, recipient_id, group_id, subject, body) VALUES (NULL, NULL, ?, "Message", ?)')
        ->execute([(int) $call['group_id'], $text]);
}

/** People who stopped checking in have left; a call with nobody left in it is over. */
function mentora_expire_group_calls(PDO $pdo): void
{
    $pdo->prepare('UPDATE group_call_participants SET left_at = NOW() WHERE left_at IS NULL AND seen_at < NOW() - INTERVAL ? SECOND')
        ->execute([MENTORA_CALL_STALE_SECONDS]);
    $empty = $pdo->query('SELECT c.* FROM group_calls c WHERE c.status = "active" AND c.created_at < NOW() - INTERVAL 20 SECOND
        AND NOT EXISTS (SELECT 1 FROM group_call_participants p WHERE p.call_id = c.id AND p.left_at IS NULL)');
    foreach ($empty->fetchAll() as $call) {
        mentora_end_group_call($pdo, $call);
    }
}

/** Calls nobody answered in time are missed; calls where one side stopped checking in are over. */
function mentora_expire_calls(PDO $pdo): void
{
    mentora_expire_group_calls($pdo);
    $stale = $pdo->prepare('SELECT * FROM calls WHERE
        (status = "ringing" AND (created_at < NOW() - INTERVAL ? SECOND OR caller_seen_at < NOW() - INTERVAL ? SECOND))
        OR (status = "active" AND (caller_seen_at < NOW() - INTERVAL ? SECOND OR callee_seen_at < NOW() - INTERVAL ? SECOND))');
    $stale->execute([MENTORA_CALL_RING_SECONDS, MENTORA_CALL_STALE_SECONDS, MENTORA_CALL_STALE_SECONDS, MENTORA_CALL_STALE_SECONDS]);
    foreach ($stale->fetchAll() as $call) {
        mentora_finish_call($pdo, $call, $call['status'] === 'active' ? 'ended' : 'missed');
    }
}
