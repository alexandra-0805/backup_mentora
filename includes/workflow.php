<?php

/**
 * Shared workflow for the applicant, agent and admin workspaces.
 *
 *   Training      training_progress (module done) -> training_records (counts, hours, status)
 *   Hours         training_records.hours_logged: hours tracked automatically while interns and employees are
 *                 active in Mentora (includes/intern_hours.php)
 *   Reporting     work_reports: submitted by interns/employees -> approved or returned by an agent/HR
 *   Attendance    attendance: first and last activity of the day (account/activity.php) -> admin Monitoring
 *   Evaluations   evaluations: created by an agent (pending) -> draft -> published (visible to the person)
 *   Documents     documents + uploads/documents/: team resources (agents/HR) and application documents
 *                 (applicants), downloaded through account/document.php after an access check
 *   Notifications every hand-off above notifies the next person, linked to the page to act on.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/ui_icons.php';

// Open shift: no fixed hours and no late check-ins; people time in and out whenever they work.
const MENTORA_SHIFT_LABEL = 'Open shift · work any time';
const MENTORA_DEFAULT_INTERN_HOURS = 480;
const MENTORA_DOCUMENT_MAX_BYTES = 10 * 1024 * 1024;
const MENTORA_DOCUMENT_CATEGORIES = ['Training resources', 'Policies', 'Templates', 'Reports', 'Application documents', 'Certificates'];
const MENTORA_REVIEW_TYPES = ['Initial assessment', 'Midpoint evaluation', 'Final evaluation', 'Monthly review'];

// Allowed document types: extension => file contents we accept (finfo). Office files are zip/OLE inside.
const MENTORA_DOCUMENT_RULES = [
    'pdf' => ['application/pdf'],
    'doc' => ['application/msword', 'application/vnd.ms-office', 'application/CDFV2', 'application/x-ole-storage'],
    'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
    'xls' => ['application/vnd.ms-excel', 'application/vnd.ms-office', 'application/CDFV2', 'application/x-ole-storage'],
    'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'],
    'ppt' => ['application/vnd.ms-powerpoint', 'application/vnd.ms-office', 'application/CDFV2', 'application/x-ole-storage'],
    'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip', 'application/octet-stream'],
    'txt' => ['text/plain'],
    'csv' => ['text/csv', 'text/plain', 'application/csv'],
    'jpg' => ['image/jpeg'],
    'jpeg' => ['image/jpeg'],
    'png' => ['image/png'],
];

function mentora_h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** 'intern' or 'employee' (workspace folder) from either 'internship'/'employee_va' or 'intern'/'employee'. */
function mentora_pathway_folder(string $pathway): string
{
    return in_array($pathway, ['internship', 'intern'], true) ? 'intern' : 'employee';
}

function mentora_pathway_label(string $pathway): string
{
    return mentora_pathway_folder($pathway) === 'intern' ? 'Intern' : 'Employee VA';
}

/** Training modules for a pathway: key => [title, hours]. */
function mentora_training_modules(string $pathway): array
{
    return mentora_pathway_folder($pathway) === 'intern'
        ? [
            'welcome' => ['Welcome to Mentora PH', 1],
            'remote_work' => ['Remote Work Essentials', 1],
            'account_optimization' => ['Optimizing Your Accounts', 1],
            'workspace_files' => ['Google Workspace & File Standards', 1],
            'client_basics' => ['Client Communication Basics', 1],
            'data_privacy' => ['Data Privacy Essentials', 1],
            'support_simulation' => ['Client Support Simulation', 1],
        ]
        : [
            'policies' => ['Welcome & Company Policies', 1],
            'account_optimization' => ['Optimizing Your Accounts', 1],
            'va_workflow' => ['Executive VA Workflow', 1],
            'client_standards' => ['Client Communication Standards', 1],
            'security' => ['Information Security & Privacy', 1],
            'handoff' => ['Operations Handoff Simulation', 1],
        ];
}

/** Evaluation criteria (rated 1-5 each; the overall score is their average). */
function mentora_evaluation_criteria(string $pathway): array
{
    return mentora_pathway_folder($pathway) === 'intern'
        ? ['Communication', 'Digital tools & organization', 'Attention to detail', 'Problem solving', 'Learning mindset']
        : ['Client communication', 'Executive support & tools', 'Accuracy & organization', 'Problem solving', 'Role & values alignment'];
}

/* ---------- Flash messages and POST handling ---------- */

function mentora_flash(string $message, string $type = 'success'): void
{
    mentora_session_start();
    $_SESSION['mentora_flash'] = ['message' => $message, 'type' => $type];
}

function mentora_flash_html(): string
{
    mentora_session_start();
    $flash = $_SESSION['mentora_flash'] ?? null;
    unset($_SESSION['mentora_flash']);
    if (!is_array($flash) || ($flash['message'] ?? '') === '') {
        return '';
    }
    $class = ($flash['type'] ?? '') === 'error' ? 'alert' : 'alert success';

    return '<div class="' . $class . '" role="status">' . mentora_h($flash['message']) . '</div>';
}

function mentora_is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

/**
 * Start a POST action: signed in with one of $roles and a valid form token, or back to $backTo
 * (a path from the app root) with an error.
 */
function mentora_post_user(array $roles, string $backTo): array
{
    $user = mentora_current_user();
    if ($user === null) {
        mentora_redirect('auth/signin.php');
    }
    if ($roles && !in_array($user['role'], $roles, true)) {
        mentora_flash('You don’t have permission to do that.', 'error');
        mentora_redirect(mentora_home_path($user));
    }
    if (!mentora_csrf_valid($_POST['csrf_token'] ?? null)) {
        mentora_flash('Your session expired. Please try again.', 'error');
        mentora_redirect($backTo);
    }

    return $user;
}

/* ---------- Notifications ---------- */

function mentora_notify(PDO $pdo, int $userId, string $title, string $body, ?string $link = null): void
{
    $pdo->prepare('INSERT INTO notifications (user_id, title, body, link) VALUES (?, ?, ?, ?)')
        ->execute([$userId, mb_substr($title, 0, 190), $body, $link]);
}

/** Notify every active user with one of $roles (optionally except one person). */
function mentora_notify_roles(PDO $pdo, array $roles, string $title, string $body, ?string $link = null, int $exceptId = 0): void
{
    $marks = implode(',', array_fill(0, count($roles), '?'));
    $query = $pdo->prepare("SELECT id FROM users WHERE role IN ($marks) AND status = 'active' AND id <> ?");
    $query->execute([...$roles, $exceptId]);
    foreach ($query->fetchAll(PDO::FETCH_COLUMN) as $id) {
        mentora_notify($pdo, (int) $id, $title, $body, $link);
    }
}

/** Who reviews a person's work: their supervising agent if set, otherwise every agent (interns) or HR (employees). */
function mentora_notify_reviewers(PDO $pdo, array $person, string $title, string $body): void
{
    $isIntern = mentora_pathway_folder((string) $person['pathway']) === 'intern';
    $agentId = (int) ($person['agent_id'] ?? 0);
    if ($agentId > 0) {
        mentora_notify($pdo, $agentId, $title, $body, 'agent/reporting_overview.php');
        return;
    }
    if ($isIntern) {
        mentora_notify_roles($pdo, ['agent'], $title, $body, 'agent/reporting_overview.php');
    } else {
        mentora_notify_roles($pdo, ['admin'], $title, $body, 'admin/monitoring.php#reports');
    }
}

/* ---------- People ---------- */

/** A workspace person (intern / employee applicant account) with their application details. */
function mentora_person(PDO $pdo, int $userId): ?array
{
    $query = $pdo->prepare(
        "SELECT u.*, d.name AS department_name,
                CONCAT(ag.first_name, ' ', ag.last_name) AS agent_name, ag.email AS agent_email,
                a.mobile, a.location, a.school, a.course, a.required_hours, a.desired_role, a.start_date, a.interest, a.skills,
                a.created_at AS applied_at
         FROM users u
         LEFT JOIN departments d ON d.id = u.department_id
         LEFT JOIN users ag ON ag.id = u.agent_id
         LEFT JOIN applications a ON a.id = (SELECT MAX(id) FROM applications WHERE user_id = u.id)
         WHERE u.id = ?"
    );
    $query->execute([$userId]);

    return $query->fetch() ?: null;
}

/**
 * Active interns / employees with their team, agent, training and hours, for agent and admin lists.
 * $pathway: 'internship', 'employee_va' or 'all'; $agentId limits it to one agent's interns.
 */
function mentora_workspace_people(PDO $pdo, string $pathway = 'internship', ?int $agentId = null): array
{
    $where = ["u.role = 'applicant'", "u.status = 'active'"];
    $params = [];
    if ($pathway !== 'all') {
        $where[] = 'u.pathway = ?';
        $params[] = $pathway;
    }
    if ($agentId !== null) {
        $where[] = 'u.agent_id = ?';
        $params[] = $agentId;
    }
    $query = $pdo->prepare(
        "SELECT u.id, u.user_code, u.first_name, u.last_name, u.email, u.pathway, u.created_at, u.department_id, u.agent_id,
                d.name AS department_name, CONCAT(ag.first_name, ' ', ag.last_name) AS agent_name,
                COALESCE(t.modules_completed, 0) AS completed, t.modules_total, COALESCE(t.hours_logged, 0) AS hours_logged,
                COALESCE(t.status, 'not_started') AS training_status, t.notes,
                a.required_hours, a.school, a.course,
                (SELECT COUNT(*) FROM work_reports r WHERE r.user_id = u.id AND r.status = 'submitted') AS reports_waiting,
                ((COALESCE(s.upwork_url, '') <> '') + (COALESCE(s.linkedin_url, '') <> '') + (COALESCE(s.quora_url, '') <> '') + (COALESCE(s.reddit_url, '') <> '') + (COALESCE(s.instagram_url, '') <> '') + (COALESCE(s.threads_url, '') <> '') + (COALESCE(s.substack_url, '') <> '')) AS social_added
         FROM users u
         LEFT JOIN departments d ON d.id = u.department_id
         LEFT JOIN users ag ON ag.id = u.agent_id
         LEFT JOIN training_records t ON t.user_id = u.id
         LEFT JOIN social_profiles s ON s.user_id = u.id
         LEFT JOIN applications a ON a.id = (SELECT MAX(id) FROM applications WHERE user_id = u.id)
         WHERE " . implode(' AND ', $where) . '
         ORDER BY u.first_name, u.last_name'
    );
    $query->execute($params);
    $people = $query->fetchAll();
    foreach ($people as &$person) {
        $person['total'] = count(mentora_training_modules($person['pathway']));
        $person['percent'] = (int) round(min($person['completed'], $person['total']) / $person['total'] * 100);
        $person['name'] = trim($person['first_name'] . ' ' . $person['last_name']);
    }
    unset($person);

    return $people;
}

const MENTORA_TRAINING_STATUS = [
    'not_started' => ['info', 'Not started'],
    'in_training' => ['info', 'In training'],
    'completed' => ['', 'Completed'],
    'on_hold' => ['warn', 'On hold'],
];

function mentora_required_hours(?array $person): int
{
    $hours = (int) ($person['required_hours'] ?? 0);

    return $hours > 0 ? $hours : MENTORA_DEFAULT_INTERN_HOURS;
}

function mentora_hours(float|string|null $hours): string
{
    return rtrim(rtrim(number_format((float) $hours, 1, '.', ''), '0'), '.') ?: '0';
}

function mentora_active_agents(PDO $pdo): array
{
    return $pdo->query("SELECT id, first_name, last_name, email FROM users WHERE role = 'agent' AND status = 'active' ORDER BY first_name, last_name")
        ->fetchAll();
}

function mentora_departments(PDO $pdo): array
{
    return $pdo->query('SELECT id, name FROM departments ORDER BY name')->fetchAll();
}

/* ---------- Training ---------- */

/**
 * Recalculate a person's training_records row: modules done (training_progress), hours and status (completed
 * when every module is done, unless an agent put it on hold). Hours: tracked automatically while they're active in
 * Mentora (includes/intern_hours.php), for interns and employees.
 */
function mentora_sync_training(PDO $pdo, int $userId, string $pathway): void
{
    $modules = array_keys(mentora_training_modules($pathway));
    $marks = implode(',', array_fill(0, count($modules), '?'));
    $done = $pdo->prepare("SELECT COUNT(*) FROM training_progress WHERE user_id = ? AND module_key IN ($marks)");
    $done->execute([$userId, ...$modules]);
    $completed = (int) $done->fetchColumn();

    // Hours tracked automatically while they're active in Mentora (interns and employees).
    require_once __DIR__ . '/intern_hours.php';
    $tracked = mentora_intern_tracked_hours($pdo, $userId);
    if ($tracked === null) {
        // Not an active intern or employee: approved report hours.
        $hours = $pdo->prepare("SELECT COALESCE(SUM(hours), 0) FROM work_reports WHERE user_id = ? AND status = 'approved'");
        $hours->execute([$userId]);
        $tracked = (float) $hours->fetchColumn();
    }
    $approvedHours = min(99999.9, $tracked);

    $current = $pdo->prepare('SELECT status FROM training_records WHERE user_id = ?');
    $current->execute([$userId]);
    $status = (string) ($current->fetchColumn() ?: 'in_training');
    if ($status !== 'on_hold') {
        $status = $completed >= count($modules) ? 'completed' : 'in_training';
    }

    $pdo->prepare(
        'INSERT INTO training_records (user_id, modules_completed, modules_total, hours_logged, status) VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE modules_completed = VALUES(modules_completed), modules_total = VALUES(modules_total),
                                 hours_logged = VALUES(hours_logged), status = VALUES(status)'
    )->execute([$userId, $completed, count($modules), $approvedHours, $status]);
}

/* ---------- Reports ---------- */

/**
 * Approve or return a submitted report (agent Reporting overview, admin Monitoring). Approved hours are
 * added to the person's training record; the person is notified either way. Returns [ok, message].
 */
function mentora_review_report(PDO $pdo, int $reportId, array $reviewer, string $decision, string $note): array
{
    $note = mb_substr(trim($note), 0, 500);
    if (!in_array($decision, ['approve', 'return'], true)) {
        return [false, 'Please choose Approve or Return.'];
    }
    if ($decision === 'return' && $note === '') {
        return [false, 'Add a note so they know what to fix.'];
    }
    $query = $pdo->prepare("SELECT r.id, r.user_id, r.report_date, r.hours, u.first_name, u.last_name, u.pathway
        FROM work_reports r
        JOIN users u ON u.id = r.user_id
        WHERE r.id = ? AND r.status = 'submitted'");
    $query->execute([$reportId]);
    $report = $query->fetch();
    if (!$report) {
        return [false, 'That report was already reviewed.'];
    }
    $approved = $decision === 'approve';
    $pdo->prepare('UPDATE work_reports SET status = ?, reviewer_id = ?, review_note = ?, reviewed_at = NOW() WHERE id = ?')
        ->execute([$approved ? 'approved' : 'returned', $reviewer['id'], $note !== '' ? $note : null, $reportId]);
    if ($approved) {
        mentora_sync_training($pdo, (int) $report['user_id'], $report['pathway']);
    }
    $day = date('d M Y', strtotime($report['report_date']));
    mentora_notify(
        $pdo,
        (int) $report['user_id'],
        $approved ? 'Report approved: ' . $day : 'Report returned: ' . $day,
        $approved ? mentora_hours($report['hours']) . ' hrs approved by ' . $reviewer['name'] . ($note !== '' ? '. “' . $note . '”' : '.') : $reviewer['name'] . ': “' . $note . '” Please fix and resend it.',
        'applicant/' . mentora_pathway_folder($report['pathway']) . '/reporting.php' . ($approved ? '' : '?fix=1#report-form')
    );

    return [true, ($approved ? 'Approved ' : 'Returned ') . trim($report['first_name'] . ' ' . $report['last_name']) . '’s report for ' . $day . '.'];
}

/** Reports waiting for review (oldest first), optionally only interns or one agent's people. */
function mentora_reports_waiting(PDO $pdo, ?string $pathway = null, ?int $agentId = null): array
{
    $where = ["r.status = 'submitted'", "u.status = 'active'"];
    $params = [];
    if ($pathway !== null && $agentId !== null) {
        $where[] = '(u.pathway = ? OR u.agent_id = ?)';
        array_push($params, $pathway, $agentId);
    } elseif ($pathway !== null) {
        $where[] = 'u.pathway = ?';
        $params[] = $pathway;
    }
    $query = $pdo->prepare(
        'SELECT r.*, u.first_name, u.last_name, u.email, u.pathway, u.agent_id
         FROM work_reports r JOIN users u ON u.id = r.user_id
         WHERE ' . implode(' AND ', $where) . ' ORDER BY r.report_date, r.id'
    );
    $query->execute($params);

    return $query->fetchAll();
}

/**
 * Table of reports waiting for review, each with a note box and Approve / Return (posts action=review_report).
 * With nothing to review it still shows the table (with $emptyText as its only row) so the Previous / Next bar
 * (assets/js/table-filter.js) appears in the same place as when there are reports.
 */
function mentora_render_review_table(array $reports, string $tableId, string $emptyText): void
{
    if ($reports): ?>
    <div class="table-toolbar"
        data-table-filter="<?= mentora_h($tableId) ?>"><input type="search" data-filter="search" placeholder="Search name or work" aria-label="Search reports"><select data-filter="pathway" aria-label="Pathway">
            <option value="all">Interns &amp; employees</option>
            <option value="intern">Interns</option>
            <option value="employee">Employees</option>
        </select>
    </div><?php endif; ?>
    <table class="admin-table review-table" id="<?= mentora_h($tableId) ?>"><thead>
            <tr>
                <th>Person</th>
                <th>Date</th>
                <th>Hours worked</th>
                <th>Tasks completed</th>
                <th>Review</th>
            </tr>
        </thead>
        <tbody>
        <?php if (!$reports): ?>
            <tr class="table-empty-row"><td colspan="5"><?= mentora_h($emptyText) ?></td></tr>
        <?php endif; ?>
        <?php foreach ($reports as $report): $name = trim($report['first_name'] . ' ' . $report['last_name']); ?>
            <tr data-pathway="<?= mentora_pathway_folder($report['pathway']) ?>">
                <td><?= mentora_h($name) ?><br><small><?= mentora_pathway_label($report['pathway']) ?></small></td>
                <td><?= date('m/d/Y', strtotime($report['report_date'])) ?> · <?= mentora_h(($report['day_name'] ?? '') ?: date('l', strtotime($report['report_date']))) ?>
                    <?php if (!empty($report['time_in']) && !empty($report['time_out'])): // end-of-day report times ?>
                        <br><small><?= date('g:i A', strtotime('2000-01-01 ' . $report['time_in'])) ?> – <?= date('g:i A', strtotime('2000-01-01 ' . $report['time_out'])) ?></small>
                    <?php endif; ?>
                    <br><small>Sent <?= date('m/d/Y, g:i A', strtotime($report['created_at'])) ?></small>
                </td>
                <td><?= mentora_hours($report['hours']) ?></td>
                <td><?= nl2br(mentora_h(mb_strimwidth($report['summary'], 0, 400, '…'))) ?><?= $report['notes']
                    ? '<br><small>Blockers / notes: ' . mentora_h(mb_strimwidth($report['notes'], 0, 200, '…')) . '</small>'
                    : '' ?></td>
                <td>
                    <form method="post" class="review-form">
                        <?= mentora_csrf_field() ?><input type="hidden" name="action" value="review_report"><input type="hidden" name="report_id" value="<?= (int) $report['id'] ?>">
                        <input name="note" maxlength="500" placeholder="Note (required to return)" aria-label="Review note for <?= mentora_h($name) ?>">
                        <span class="row-actions">
                            <button class="admin-btn primary"
                                type="submit"
                                name="decision"
                                value="approve"
                                data-confirm-title="Approve this report?"
                                data-confirm="<?= mentora_h(mentora_hours($report['hours'])) ?> hrs will be added to <?= mentora_h($report['first_name']) ?>’s approved report hours."
                                data-confirm-action="Approve">Approve</button>
                            <button class="admin-btn"
                                type="submit"
                                name="decision"
                                value="return"
                                data-confirm-title="Return this report?"
                                data-confirm="<?= mentora_h($report['first_name']) ?> will see your note and can fix and resend it."
                                data-confirm-action="Return">Return</button>
                        </span>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody></table>
    <?php
}

/* ---------- Attendance ---------- */

/**
 * Attendance state for one person on one day: [label, badge class, worked hours or null].
 * Open shift: people time in and out whenever they work (any day, any hour), so nobody is ever
 * late or absent. "Working" = timed in, not out yet; "Present" = timed in and out; otherwise
 * "Not checked in" (today) or "No time-in" (another day).
 */
function mentora_attendance_state(?array $row, string $date): array
{
    if (!$row || empty($row['time_in'])) {
        return [$date >= date('Y-m-d') ? 'Not checked in' : 'No time-in', 'info', null];
    }
    if (empty($row['time_out'])) {
        return ['Working', 'info', null];
    }
    $worked = round(max(0, strtotime($row['time_out']) - strtotime($row['time_in'])) / 3600, 1);

    return ['Present', '', $worked];
}

/* ---------- Documents ---------- */

/**
 * Check and store an uploaded document (from $_FILES[$field]) in uploads/documents/.
 * Returns ['ok' => true, 'stored_name', 'original_name', 'mime', 'size'] or ['ok' => false, 'error'].
 */
function mentora_store_document(string $field): array
{
    $file = $_FILES[$field] ?? null;
    if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['ok' => false, 'error' => 'Please choose a file to upload.'];
    }
    if (in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || (int) $file['size'] > MENTORA_DOCUMENT_MAX_BYTES) {
        return ['ok' => false, 'error' => 'That file is larger than 10 MB.'];
    }
    if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
        return ['ok' => false, 'error' => 'The file couldn’t be uploaded. Please try again.'];
    }
    $extension = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
    $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!isset(MENTORA_DOCUMENT_RULES[$extension]) || !in_array($mime, MENTORA_DOCUMENT_RULES[$extension], true)) {
        return ['ok' => false, 'error' => 'Please upload a PDF, Word, Excel, PowerPoint, text, CSV, JPG or PNG file.'];
    }
    $extension = $extension === 'jpeg' ? 'jpg' : $extension;
    $folder = MENTORA_APP_ROOT . '/uploads/documents';
    if (!is_dir($folder) && !mkdir($folder, 0775, true) && !is_dir($folder)) {
        return ['ok' => false, 'error' => 'The file couldn’t be saved. Please try again.'];
    }
    $storedName = 'd-' . bin2hex(random_bytes(12)) . '.' . $extension;
    if (!move_uploaded_file($file['tmp_name'], $folder . '/' . $storedName)) {
        return ['ok' => false, 'error' => 'The file couldn’t be saved. Please try again.'];
    }
    $original = trim(preg_replace('/[\x00-\x1F\x7F\/\\\\]+/u', '', basename((string) $file['name'])) ?? '');

    return [
        'ok' => true,
        'stored_name' => $storedName,
        'original_name' => mb_substr($original !== '' ? $original : 'document.' . $extension, 0, 190),
        'mime' => $mime,
        'size' => (int) $file['size'],
    ];
}

/**
 * Check a document link (e.g. a Google Drive portfolio). Returns [clean URL, null] or [null, error].
 * Adds https:// when it's missing; only http(s) web addresses up to 500 characters.
 */
function mentora_clean_document_link(string $value): array
{
    $value = trim($value);
    if ($value === '') {
        return [null, 'Please paste the link.'];
    }
    if (!preg_match('~^https?://~i', $value)) {
        $value = 'https://' . ltrim($value, '/');
    }
    $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));
    if (!filter_var($value, FILTER_VALIDATE_URL) || !in_array($scheme, ['http', 'https'], true) || mb_strlen($value) > 500) {
        return [null, 'That doesn’t look like a web link. Copy the full link from your browser or the Share button in Google Drive.'];
    }

    return [$value, null];
}

/** True for Google Drive / Docs / Sheets / Slides links. */
function mentora_is_drive_link(?string $url): bool
{
    $host = strtolower((string) parse_url((string) $url, PHP_URL_HOST));

    return in_array($host, ['drive.google.com', 'docs.google.com'], true);
}

function mentora_delete_document_file(?string $storedName): void
{
    if ($storedName && preg_match('/^d-[a-f0-9]{24}\.[a-z]+$/', $storedName)) {
        @unlink(MENTORA_APP_ROOT . '/uploads/documents/' . $storedName);
    }
}

function mentora_format_bytes(int $bytes): string
{
    if ($bytes >= 1048576) {
        return round($bytes / 1048576, 1) . ' MB';
    }

    return $bytes >= 1024 ? round($bytes / 1024) . ' KB' : $bytes . ' B';
}
