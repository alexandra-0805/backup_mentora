<?php

/**
 * Starting rules for client work, shown on Tasks & deliverables (includes/pages/client_tasks.php: HR, agents and
 * interns' / employees' My tasks) and Clients (includes/pages/clients.php). Text only: it describes how the existing
 * pages are meant to be used and changes nothing about them.
 *
 * Written for how Mentora works: one task list for every client (filters: client, owner, status; a client's service
 * is its service scope), each client's shared folder and website on its Clients record, and each task's file in its
 * Deliverable link. (The original rule about a Link Bank with favourites was folded into the Clients record, since
 * the Link Bank pages were removed.)
 */

declare(strict_types=1);

/** The rules box. $forStaff: HR and agents (they manage clients and tasks); otherwise an intern's or employee's own tasks. */
function mentora_work_rules(bool $forStaff): string
{
    $rules = $forStaff
        ? [
            ['One task tracker for all clients', 'Every piece of client work is one task in Tasks &amp; deliverables, linked to its client and an owner. Use the filters to narrow it down by client, owner and status; a client’s service is in its service scope on Clients.'],
            ['Working files live in the client’s shared folder', 'Keep working files in the client’s shared folder (Google Drive). When a file is ready, paste its link into the task’s <strong>Deliverable link</strong>.'],
            ['Recurring links belong on the client', 'Save each client’s shared folder and website on its record in <strong>Clients</strong>, with a clear service scope, so every task for that client can reach them in one click.'],
        ]
        : [
            ['All your client work is in one place', 'Every task given to you is listed here, with its client. Use the status filter to see what’s left to do.'],
            ['Work in the client’s shared folder', 'Keep your working files in the client’s shared folder (open it from the task’s client). When a file is ready, paste its link into the task’s <strong>Deliverable link</strong> and update the status.'],
            ['Use the links on the task', 'The client’s shared folder link is shown with each task, so you don’t need to keep your own copies of client links.'],
        ];
    $items = '';
    foreach ($rules as [$title, $text]) {
        $items .= '<li><strong>' . $title . '.</strong> ' . $text . '</li>';
    }
    return '<details class="work-rules" open><summary>Starting rules</summary><ol>' . $items . '</ol></details>';
}
