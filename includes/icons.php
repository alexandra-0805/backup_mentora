<?php
/**
 * Mentora line icons for the public pages (landing, About us).
 * Usage: <?= mentora_icon('book') ?>. Colour comes from CSS (.page-icon uses currentColor).
 */
function mentora_icon(string $name): string
{
    static $paths = [
        'sprout' => '<path d="M12 21v-8m0 0c-4.5 0-7-2.5-7-7 4.5 0 7 2.5 7 7Zm0 0c0-4.5 2.5-7 7-7 0 4.5-2.5 7-7 7Z"/>',
        'heart' => '<path d="M20.8 8.6c0 5.2-8.8 10.1-8.8 10.1S3.2 13.8 3.2 8.6A4.6 4.6 0 0 1 12 6.2a4.6 4.6 0 0 1 8.8 2.4Z"/>',
        'lock' => '<rect x="5" y="10" width="14" height="10" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3m-4 4v2"/>',
        'briefcase' => '<rect x="3" y="7" width="18" height="13" rx="2"/><path d="M8 7V5a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2m-13 5h18m-10 0v2h4v-2"/>',
        'graduation' => '<path d="m3 9 9-5 9 5-9 5-9-5Z"/><path d="M7 11v5c2.8 2.5 7.2 2.5 10 0v-5M21 9v6"/>',
        'book' => '<path d="M4 5.5A2.5 2.5 0 0 1 6.5 3H20v16H6.5A2.5 2.5 0 0 0 4 21.5v-16Z"/><path d="M4 19h16"/>',
        'message-heart' => '<path d="M20 11.5a7.5 7.5 0 0 1-8 7.5 8.8 8.8 0 0 1-3-.5L4 20l1.5-4A7.3 7.3 0 0 1 4.5 12 7.5 7.5 0 0 1 12 4.5a7.5 7.5 0 0 1 8 7Z"/><path d="M9 11.5c0-1.6 2-2.2 3-.8 1-1.4 3-.8 3 .8 0 1.6-3 3.2-3 3.2S9 13.1 9 11.5Z"/>',
        'check-circle' => '<circle cx="12" cy="12" r="9"/><path d="m8 12 2.5 2.5L16 9"/>',
        'document' => '<path d="M6 3h8l4 4v14H6V3Z"/><path d="M14 3v5h4m-9 4h6m-6 4h6"/>',
        'calendar' => '<rect x="4" y="5" width="16" height="16" rx="2"/><path d="M8 3v4m8-4v4M4 10h16"/>',
        'contact' => '<circle cx="12" cy="8" r="3"/><path d="M5 20a7 7 0 0 1 14 0"/>',
        'shield-check' => '<path d="M12 3 19 6v5c0 4.5-3 7.5-7 10-4-2.5-7-5.5-7-10V6l7-3Z"/><path d="m9 12 2 2 4-4"/>',
        'users' => '<circle cx="9" cy="8" r="3"/><path d="M3 20a6 6 0 0 1 12 0"/><path d="M16 5.5a3 3 0 0 1 0 5.5M18 20a6 6 0 0 0-3-5.2"/>',
        'target' => '<circle cx="12" cy="12" r="8"/><circle cx="12" cy="12" r="4"/><path d="M12 12h.01"/>',
    ];

    return '<svg class="page-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' . ($paths[$name] ?? '') . '</svg>';
}
