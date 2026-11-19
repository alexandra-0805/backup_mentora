<?php
/**
 * GIF search in Messages (optional).
 *
 * 1. Create a free API key at https://developers.giphy.com/ (choose "API", not "SDK").
 * 2. Copy this file to config/giphy.local.php (that file is not committed to git).
 * 3. Paste the key below.
 *
 * Or set the MENTORA_GIPHY_API_KEY environment variable instead.
 * Without a key, people can still send GIF files with the attachment (📎) button.
 * Note: with a key, GIF search words are sent to GIPHY, and GIFs load from GIPHY's servers.
 */
return [
    'api_key' => '',
];
