<?php
/**
 * "Continue with Google" settings.
 *
 * 1. Copy this file to config/google.local.php (that copy holds your secret, so don't share or commit it).
 * 2. In Google Cloud Console -> APIs & Services -> Credentials, create an
 *    "OAuth client ID" of type "Web application".
 * 3. Under "Authorized redirect URIs" add exactly:
 *       http://localhost/backup_mentora/auth/google_callback.php
 * 4. Paste the client ID and client secret below.
 *
 * Environment variables MENTORA_GOOGLE_CLIENT_ID / MENTORA_GOOGLE_CLIENT_SECRET /
 * MENTORA_GOOGLE_REDIRECT_URI override these values if set.
 */
return [
    'client_id' => '',       // e.g. 1234567890-abc123.apps.googleusercontent.com
    'client_secret' => '',   // e.g. GOCSPX-...
    'redirect_uri' => '',    // leave empty to use http://<this host>/backup_mentora/auth/google_callback.php
];
