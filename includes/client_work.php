<?php

/**
 * Client work: Clients and Tasks & Deliverables (tables in config/db.php), shared by includes/pages/clients.php and
 * client_tasks.php, which the admin, agent and applicant workspaces open through small wrapper files.
 * (End-of-day reports have their own format and pages: includes/eod_report.php.)
 *
 * Who does what:
 *   Clients        HR admins and agents add and edit; only HR deletes (a client with tasks can't be deleted)
 *   Tasks          HR admins and agents add, assign, edit and delete; interns and employees see their own
 *                  ("My tasks") and update the status and deliverable link
 * (The Link bank pages were removed; its link_bank table is still in config/db.php, unused.)
 */

declare(strict_types=1);

require_once __DIR__ . '/workflow.php';

const MENTORA_CLIENT_STATUSES = ['active' => 'Active', 'paused' => 'Paused', 'ended' => 'Ended'];
const MENTORA_TASK_STATUSES = ['to_do' => 'To do', 'in_progress' => 'In progress', 'in_review' => 'In review', 'done' => 'Done', 'blocked' => 'Blocked'];
const MENTORA_TASK_PRIORITIES = ['low' => 'Low', 'normal' => 'Normal', 'high' => 'High', 'urgent' => 'Urgent'];
/** Days back an End-of-Day Update can still be sent or corrected. */
const MENTORA_EOD_DAYS_BACK = 7;

/** Badge colour for a client or task status / priority (admin.css .badge, .badge.info / .warn / .danger). */
function mentora_work_badge(string $value): string
{
    $class = match ($value) {
        'in_progress', 'in_review', 'high' => ' info',
        'paused', 'to_do', 'low', 'normal' => ' warn',
        'blocked', 'urgent', 'ended' => ' danger',
        default => '',
    };
    $label = MENTORA_TASK_STATUSES[$value] ?? MENTORA_CLIENT_STATUSES[$value] ?? MENTORA_TASK_PRIORITIES[$value] ?? ucfirst($value);
    return '<span class="badge' . $class . '">' . mentora_h($label) . '</span>';
}

/**
 * A web address from a form: '' is "none" (null); a missing scheme gets https://; only http(s) is accepted.
 * Sets $error when the address can't be used.
 */
function mentora_clean_url(string $value, ?string &$error): ?string
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }
    if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $value)) {
        $value = 'https://' . $value;
    }
    $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));
    if (!in_array($scheme, ['http', 'https'], true) || !filter_var($value, FILTER_VALIDATE_URL) || strlen($value) > 500) {
        $error = 'Please enter a valid web address (https://…).';
        return null;
    }
    return $value;
}

/** A Y-m-d date from a form, or null. */
function mentora_clean_date(string $value): ?string
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', trim($value));
    return $date && $date->format('Y-m-d') === trim($value) ? $date->format('Y-m-d') : null;
}

/** A link that opens in a new tab (or '—'). */
function mentora_external_link(?string $url, string $label = 'Open'): string
{
    if ($url === null || $url === '') {
        return '<span class="panel-subtitle">—</span>';
    }
    return '<a href="' . mentora_h($url) . '" target="_blank" rel="noopener noreferrer">' . mentora_h($label) . '</a>';
}

/** HR admins and agents (client owners): [id => name]. */
function mentora_staff_people(PDO $pdo): array
{
    $query = $pdo->query("SELECT id, CONCAT(first_name, ' ', last_name) AS name FROM users
        WHERE status = 'active' AND role IN ('admin', 'agent') ORDER BY first_name, last_name");
    return array_column($query->fetchAll(), 'name', 'id');
}

/** Everyone who can own a task: [id => ['name', 'label', 'role', 'pathway']]. */
function mentora_task_people(PDO $pdo): array
{
    $people = [];
    foreach ($pdo->query("SELECT id, first_name, last_name, role, pathway FROM users WHERE status = 'active'
        ORDER BY FIELD(role, 'admin', 'agent', 'applicant'), first_name, last_name") as $row) {
        $people[(int) $row['id']] = [
            'name' => trim($row['first_name'] . ' ' . $row['last_name']),
            'label' => match ($row['role']) { 'admin' => 'HR', 'agent' => 'Agent', default => mentora_pathway_label((string) $row['pathway']) },
            'role' => $row['role'],
            'pathway' => (string) $row['pathway'],
        ];
    }
    return $people;
}

/** Clients for a dropdown: [id => name] (ended clients last). */
function mentora_client_options(PDO $pdo): array
{
    $query = $pdo->query("SELECT id, name FROM clients ORDER BY status = 'ended', name");
    return array_column($query->fetchAll(), 'name', 'id');
}

/** Where someone sees their tasks (for notifications): a path from the app root. */
function mentora_tasks_path(array $person): string
{
    return match ($person['role']) {
        'admin' => 'admin/tasks.php',
        'agent' => 'agent/tasks.php',
        default => 'applicant/' . mentora_pathway_folder((string) $person['pathway']) . '/tasks.php',
    };
}

/** Tell someone a task was given to them (not when they gave it to themselves). */
function mentora_notify_task_owner(PDO $pdo, int $ownerId, int $byUserId, string $title, string $clientName): void
{
    if ($ownerId <= 0 || $ownerId === $byUserId) {
        return;
    }
    $query = $pdo->prepare("SELECT id, role, pathway FROM users WHERE id = ? AND status = 'active'");
    $query->execute([$ownerId]);
    $owner = $query->fetch();
    if ($owner) {
        mentora_notify($pdo, $ownerId, 'New task: ' . $title, 'For ' . $clientName . '. Open it in your tasks.', mentora_tasks_path($owner));
    }
}

/** Options for a <select>: [value => label], with the current value selected. */
function mentora_select_options(array $options, mixed $selected = null, ?string $emptyLabel = null): string
{
    $html = $emptyLabel !== null ? '<option value="">' . mentora_h($emptyLabel) . '</option>' : '';
    foreach ($options as $value => $label) {
        $html .= '<option value="' . mentora_h($value) . '"' . ((string) $value === (string) $selected ? ' selected' : '') . '>' . mentora_h($label) . '</option>';
    }
    return $html;
}
