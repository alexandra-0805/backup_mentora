<?php

/**
 * Video and voice calls between two people (assets/js/video-call.js on Messages; the ringing toast in
 * includes/message_panel.php). The browsers send audio (and video) to each other directly (WebRTC);
 * this only passes the call's state and the two connection descriptions (offer / answer) between them.
 *
 *   GET  ?action=incoming                         a call ringing for you right now (or null)
 *   POST action=start   callee_id kind            call someone you can message (kind: video or voice)
 *   POST action=signal  call_id kind payload      the caller's offer or the callee's answer
 *   POST action=answer  call_id                   pick up
 *   POST action=decline call_id                   turn it down
 *   POST action=end     call_id                   hang up (or stop ringing)
 *   POST action=poll    call_id after             the call's status and new signals from the other side
 *   POST action=log     call_id                   once it's over: its line in the conversation ("📹 Video call · 4:05")
 *
 * Group calls (a group chat or General; up to MENTORA_GROUP_CALL_MAX people, everyone connected to everyone):
 *   POST action=group_start  group_id kind         start one (or get the one already going on there)
 *   POST action=group_join   call_id               join: the others in it (you send each of them an offer)
 *   POST action=group_poll   call_id after         who's in it, and offers / answers sent to you
 *   POST action=group_signal call_id to_user kind payload
 *   POST action=group_leave  call_id               the last one out ends it ("📹 Group video call · 12:30" in the chat)
 * ?action=incoming also lists the group calls going on in your chats, and one ringing for you.
 * POSTs need the Messages page token (message_action_token). Every answer is JSON.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/messaging.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

$respond = static function (array $data, int $status = 200): never {
    http_response_code($status);
    echo json_encode($data);
    exit;
};

$me = mentora_current_user();
if ($me === null) {
    $respond(['ok' => false, 'error' => 'signed_out'], 401);
}
$userId = (int) $me['id'];
$token = (string) ($_SESSION['message_action_token'] ?? '');
session_write_close(); // calls poll often: don't hold the session lock while they do

mentora_expire_calls($pdo);

$action = (string) ($_REQUEST['action'] ?? '');

// ---------- Ringing for you (read-only, so a plain GET from any workspace page) ----------
if ($action === 'incoming') {
    $query = $pdo->prepare('SELECT c.id, c.caller_id, c.kind, u.first_name, u.last_name FROM calls c JOIN users u ON u.id = c.caller_id
        WHERE c.callee_id = ? AND c.status = "ringing" ORDER BY c.id DESC LIMIT 1');
    $query->execute([$userId]);
    $call = $query->fetch();

    // Group calls going on in your chats: the "Join" banner on Messages, and one ringing for you
    // (a group, not General, started in the last 45 seconds by someone else, that you haven't been in).
    $groupQuery = $pdo->prepare('SELECT c.id, c.group_id, c.kind, c.started_by, g.name AS group_name, g.is_general, u.first_name AS starter,
            TIMESTAMPDIFF(SECOND, c.created_at, NOW()) AS age,
            (SELECT COUNT(*) FROM group_call_participants p WHERE p.call_id = c.id AND p.left_at IS NULL) AS people,
            EXISTS (SELECT 1 FROM group_call_participants p WHERE p.call_id = c.id AND p.user_id = ? AND p.left_at IS NULL) AS joined,
            EXISTS (SELECT 1 FROM group_call_participants p WHERE p.call_id = c.id AND p.user_id = ?) AS was_in
        FROM group_calls c JOIN chat_groups g ON g.id = c.group_id LEFT JOIN users u ON u.id = c.started_by
        WHERE c.status = "active" AND c.group_id IN (' . mentora_my_groups_sql() . ')
        ORDER BY c.id DESC');
    $groupQuery->execute([$userId, $userId, $userId]);
    $groupCalls = [];
    $groupRinging = null;
    foreach ($groupQuery->fetchAll() as $row) {
        $groupCalls[] = ['id' => (int) $row['id'], 'groupId' => (int) $row['group_id'], 'kind' => $row['kind'],
            'people' => (int) $row['people'], 'joined' => (bool) $row['joined']];
        if ($groupRinging === null && !(int) $row['is_general'] && (int) $row['age'] < MENTORA_CALL_RING_SECONDS
            && !(int) $row['was_in'] && (int) $row['started_by'] !== $userId) {
            $groupRinging = ['id' => (int) $row['id'], 'groupId' => (int) $row['group_id'], 'groupName' => $row['group_name'],
                'kind' => $row['kind'], 'starterName' => (string) ($row['starter'] ?? 'Someone')];
        }
    }

    $respond(['ok' => true, 'call' => $call ? [
        'id' => (int) $call['id'],
        'kind' => $call['kind'],
        'callerId' => (int) $call['caller_id'],
        'callerName' => trim($call['first_name'] . ' ' . $call['last_name']),
    ] : null, 'groupCall' => $groupRinging, 'groupCalls' => $groupCalls]);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    $respond(['ok' => false, 'error' => 'method'], 405);
}
if ($token === '' || !hash_equals($token, (string) ($_POST['message_action_token'] ?? ''))) {
    $respond(['ok' => false, 'error' => 'session'], 403);
}

// ---------- Start a call ----------
if ($action === 'start') {
    $calleeId = (int) ($_POST['callee_id'] ?? 0);
    if (!mentora_can_message($pdo, $me, $calleeId)) {
        $respond(['ok' => false, 'error' => 'not_allowed'], 403);
    }
    // One call at a time: anything you left open is closed first; someone already on a call is busy.
    $open = $pdo->prepare('SELECT * FROM calls WHERE status IN ("ringing", "active") AND (caller_id = ? OR callee_id = ?)');
    $open->execute([$userId, $userId]);
    foreach ($open->fetchAll() as $old) {
        mentora_finish_call($pdo, $old, $old['status'] === 'active' ? 'ended' : 'missed');
    }
    $open->execute([$calleeId, $calleeId]);
    if ($open->fetch()) {
        $respond(['ok' => false, 'error' => 'busy']);
    }
    $kind = ($_POST['kind'] ?? '') === 'voice' ? 'voice' : 'video';
    $pdo->prepare('INSERT INTO calls (caller_id, callee_id, kind, caller_seen_at) VALUES (?, ?, ?, NOW())')->execute([$userId, $calleeId, $kind]);
    $respond(['ok' => true, 'call' => ['id' => (int) $pdo->lastInsertId()]]);
}

// ---------- Group calls: start, join, poll, signal, leave ----------
if (str_starts_with($action, 'group_')) {
    // A call in one of your group chats (General included).
    $loadGroupCall = static function (int $callId) use ($pdo, $userId): ?array {
        $query = $pdo->prepare('SELECT c.*, g.name AS group_name FROM group_calls c JOIN chat_groups g ON g.id = c.group_id WHERE c.id = ?');
        $query->execute([$callId]);
        $call = $query->fetch();
        return $call && mentora_in_group($pdo, (int) $call['group_id'], $userId) ? $call : null;
    };
    $inCall = static function (int $callId, int $personId) use ($pdo): bool {
        $query = $pdo->prepare('SELECT 1 FROM group_call_participants WHERE call_id = ? AND user_id = ? AND left_at IS NULL');
        $query->execute([$callId, $personId]);
        return (bool) $query->fetchColumn();
    };
    $others = static function (int $callId) use ($pdo, $userId): array {
        $people = [];
        foreach (mentora_group_call_people($pdo, $callId) as $personId => $name) {
            if ($personId !== $userId) {
                $people[] = ['id' => $personId, 'name' => $name];
            }
        }
        return $people;
    };

    if ($action === 'group_start') {
        // Start a call in the group, or get the one already going on there (then join it).
        $groupId = (int) ($_POST['group_id'] ?? 0);
        if (!mentora_in_group($pdo, $groupId, $userId)) {
            $respond(['ok' => false, 'error' => 'not_allowed'], 403);
        }
        $existing = $pdo->prepare('SELECT id, kind FROM group_calls WHERE group_id = ? AND status = "active" ORDER BY id DESC LIMIT 1');
        $existing->execute([$groupId]);
        $call = $existing->fetch();
        if (!$call) {
            $kind = ($_POST['kind'] ?? '') === 'voice' ? 'voice' : 'video';
            $pdo->beginTransaction();
            $pdo->prepare('INSERT INTO group_calls (group_id, kind, started_by) VALUES (?, ?, ?)')->execute([$groupId, $kind, $userId]);
            $call = ['id' => (int) $pdo->lastInsertId(), 'kind' => $kind];
            // The person who starts it is in it from the start (so it isn't closed as empty before they've joined).
            $pdo->prepare('INSERT INTO group_call_participants (call_id, user_id, joined_at, seen_at) VALUES (?, ?, NOW(), NOW())')
                ->execute([$call['id'], $userId]);
            $pdo->commit();
        }
        $respond(['ok' => true, 'call' => ['id' => (int) $call['id'], 'kind' => $call['kind']]]);
    }

    $callId = (int) ($_POST['call_id'] ?? 0);
    $call = $loadGroupCall($callId);
    if ($call === null) {
        $respond(['ok' => false, 'error' => 'not_found'], 404);
    }

    switch ($action) {
        case 'group_join':
            if ($call['status'] !== 'active') {
                $respond(['ok' => false, 'error' => 'ended']);
            }
            if (!$inCall($callId, $userId) && count(mentora_group_call_people($pdo, $callId)) >= MENTORA_GROUP_CALL_MAX) {
                $respond(['ok' => false, 'error' => 'full']);
            }
            // (Re)joining starts clean: anything left over from an earlier time in this call is dropped.
            $pdo->prepare('DELETE FROM group_call_signals WHERE call_id = ? AND (to_user = ? OR from_user = ?)')->execute([$callId, $userId, $userId]);
            $pdo->prepare('INSERT INTO group_call_participants (call_id, user_id, joined_at, seen_at) VALUES (?, ?, NOW(), NOW())
                ON DUPLICATE KEY UPDATE joined_at = NOW(), seen_at = NOW(), left_at = NULL')->execute([$callId, $userId]);
            $respond(['ok' => true, 'call' => ['id' => $callId, 'kind' => $call['kind'], 'groupId' => (int) $call['group_id'],
                'groupName' => $call['group_name'], 'startedAt' => $call['created_at']], 'people' => $others($callId)]);

        case 'group_poll':
            if ($call['status'] !== 'active' || !$inCall($callId, $userId)) {
                $respond(['ok' => true, 'status' => 'ended', 'people' => [], 'signals' => []]);
            }
            $pdo->prepare('UPDATE group_call_participants SET seen_at = NOW() WHERE call_id = ? AND user_id = ?')->execute([$callId, $userId]);
            $signals = $pdo->prepare('SELECT id, from_user, kind, payload FROM group_call_signals WHERE call_id = ? AND to_user = ? AND id > ? ORDER BY id');
            $signals->execute([$callId, $userId, max(0, (int) ($_POST['after'] ?? 0))]);
            $respond(['ok' => true, 'status' => 'active', 'people' => $others($callId), 'signals' => array_map(static fn(array $row): array => [
                'id' => (int) $row['id'], 'from' => (int) $row['from_user'], 'kind' => $row['kind'], 'payload' => $row['payload'],
            ], $signals->fetchAll())]);

        case 'group_signal':
            $toUser = (int) ($_POST['to_user'] ?? 0);
            $kind = (string) ($_POST['kind'] ?? '');
            $payload = (string) ($_POST['payload'] ?? '');
            $description = json_decode($payload, true);
            $valid = $call['status'] === 'active' && in_array($kind, ['offer', 'answer'], true) && strlen($payload) <= 200000
                && is_array($description) && ($description['type'] ?? '') === $kind && is_string($description['sdp'] ?? null)
                && $toUser !== $userId && $inCall($callId, $userId) && $inCall($callId, $toUser);
            if (!$valid) {
                $respond(['ok' => false, 'error' => 'invalid'], 400);
            }
            $pdo->prepare('INSERT INTO group_call_signals (call_id, from_user, to_user, kind, payload) VALUES (?, ?, ?, ?, ?)')
                ->execute([$callId, $userId, $toUser, $kind, json_encode(['type' => $kind, 'sdp' => $description['sdp']])]);
            $respond(['ok' => true]);

        case 'group_leave':
            $pdo->prepare('UPDATE group_call_participants SET left_at = NOW() WHERE call_id = ? AND user_id = ? AND left_at IS NULL')->execute([$callId, $userId]);
            if (!mentora_group_call_people($pdo, $callId)) {
                mentora_end_group_call($pdo, $call); // the last one out ends it
            }
            $respond(['ok' => true]);
    }
    $respond(['ok' => false, 'error' => 'unknown_action'], 400);
}

// ---------- Everything else is about one of your calls ----------
$callId = (int) ($_POST['call_id'] ?? 0);
$query = $pdo->prepare('SELECT * FROM calls WHERE id = ? AND (caller_id = ? OR callee_id = ?)');
$query->execute([$callId, $userId, $userId]);
$call = $query->fetch();
if (!$call) {
    $respond(['ok' => false, 'error' => 'not_found'], 404);
}
$isCaller = (int) $call['caller_id'] === $userId;
$open = in_array($call['status'], ['ringing', 'active'], true);

switch ($action) {
    case 'log':
        // Shown in the open conversation without reloading (MentoraChat.addMessage on the Messages page).
        $logId = (int) ($call['log_message_id'] ?? 0);
        $message = null;
        if ($logId > 0) {
            $logQuery = $pdo->prepare('SELECT id, sender_id, body, read_at, created_at FROM messages WHERE id = ?');
            $logQuery->execute([$logId]);
            $message = $logQuery->fetch() ?: null;
        }
        if ($message === null) {
            $respond(['ok' => false, 'error' => $open ? 'pending' : 'no_log']);
        }
        $mine = (int) $message['sender_id'] === $userId;
        $respond(['ok' => true, 'message' => [
            'id' => (int) $message['id'],
            'conversation' => 'u' . (int) ($isCaller ? $call['callee_id'] : $call['caller_id']),
            'mine' => $mine,
            'body' => (string) $message['body'],
            'createdAt' => (string) $message['created_at'],
            'time' => date('g:i A', (int) strtotime((string) $message['created_at'])),
            'unread' => !$mine && $message['read_at'] === null,
        ]]);

    case 'poll':
        // Checking in keeps the call alive (see mentora_expire_calls).
        $pdo->prepare('UPDATE calls SET ' . ($isCaller ? 'caller_seen_at' : 'callee_seen_at') . ' = NOW() WHERE id = ?')->execute([$callId]);
        $signals = $pdo->prepare('SELECT id, kind, payload FROM call_signals WHERE call_id = ? AND sender_id <> ? AND id > ? ORDER BY id');
        $signals->execute([$callId, $userId, max(0, (int) ($_POST['after'] ?? 0))]);
        $respond(['ok' => true, 'status' => $call['status'], 'signals' => array_map(static fn(array $row): array => [
            'id' => (int) $row['id'],
            'kind' => $row['kind'],
            'payload' => $row['payload'],
        ], $signals->fetchAll())]);

    case 'signal':
        $kind = (string) ($_POST['kind'] ?? '');
        $payload = (string) ($_POST['payload'] ?? '');
        $description = json_decode($payload, true);
        $valid = $open
            && ($kind === 'offer' ? $isCaller : ($kind === 'answer' && !$isCaller))
            && strlen($payload) <= 200000
            && is_array($description) && ($description['type'] ?? '') === $kind && is_string($description['sdp'] ?? null);
        if (!$valid) {
            $respond(['ok' => false, 'error' => 'invalid'], 400);
        }
        $pdo->prepare('INSERT INTO call_signals (call_id, sender_id, kind, payload) VALUES (?, ?, ?, ?)')
            ->execute([$callId, $userId, $kind, json_encode(['type' => $kind, 'sdp' => $description['sdp']])]);
        $respond(['ok' => true]);

    case 'answer':
        $update = $pdo->prepare('UPDATE calls SET status = "active", answered_at = NOW(), callee_seen_at = NOW() WHERE id = ? AND callee_id = ? AND status = "ringing"');
        $update->execute([$callId, $userId]);
        $respond(['ok' => $update->rowCount() === 1, 'status' => $update->rowCount() === 1 ? 'active' : $call['status']]);

    case 'decline':
        $respond(['ok' => !$isCaller && $call['status'] === 'ringing' && mentora_finish_call($pdo, $call, 'declined')]);

    case 'end':
        // Hanging up while it still rings: the caller gave up (missed call for the other person) or the callee declined.
        $status = $call['status'] === 'active' ? 'ended' : ($isCaller ? 'missed' : 'declined');
        $respond(['ok' => $open ? mentora_finish_call($pdo, $call, $status) : true]);
}

$respond(['ok' => false, 'error' => 'unknown_action'], 400);
