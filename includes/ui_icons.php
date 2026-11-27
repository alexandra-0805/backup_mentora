<?php

/**
 * Button icons for the workspaces: upload (tray + arrow up), view (eye) and download (tray + arrow down).
 * Use inside a button or link: <a class="admin-btn"><?= mentora_icon('download') ?>Export PDF</a>
 * Hover animations live in assets/css/settings.css ("Button icons").
 */

declare(strict_types=1);

function mentora_icon(string $name): string
{
    $tray = '<path class="icon-tray" d="M4 14.5v3A2.5 2.5 0 0 0 6.5 20h11a2.5 2.5 0 0 0 2.5-2.5v-3"/>';
    $shapes = match ($name) {
        'upload' => $tray . '<g class="icon-arrow"><path d="M12 15.5V4"/><path d="m7.5 8.5 4.5-4.5 4.5 4.5"/></g>',
        'download' => $tray . '<g class="icon-arrow"><path d="M12 4v11.5"/><path d="m7.5 11 4.5 4.5 4.5-4.5"/></g>',
        'view' => '<g class="icon-eye"><path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12Z"/><circle class="icon-pupil" cx="12" cy="12" r="3"/></g>',
        default => '',
    };

    return '<svg class="btn-icon icon-' . $name . '" viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $shapes . '</svg>';
}
