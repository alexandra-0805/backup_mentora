<?php

/**
 * Presence heartbeat (includes/presence_heartbeat.php): an open page that someone is using calls this
 * every couple of minutes, so they stay online. Signing in refreshes users.last_seen_at
 * (mentora_current_user -> mentora_touch_presence). 204 = still signed in, 401 = signed out.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth_check.php';

header('Cache-Control: no-store');
http_response_code(mentora_current_user() !== null ? 204 : 401);
