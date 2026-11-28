<?php

/**
 * End-of-day report: the one daily report format for everyone.
 *
 *   Date (mm/dd/yyyy, calendar picker) · Day (Monday–Sunday) · Time in · Time out · Hours worked ·
 *   Tasks completed · Notes (optional)
 *
 * Where reports live:
 *   Interns and employees  work_reports (Reporting page, includes/pages/applicant_reporting.php): sent to their
 *                          agent (interns) or HR (employees) for approval; approved hours count, as before.
 *   Agents                 eod_updates (Reports page, agent/agent_reports.php, "End-of-day reports" tab); one per day.
 *   HR                     sees everyone's in End-of-day reports (admin/eod_updates.php).
 *
 * Tables of reports show a total after every 5 reports (days and hours worked) and an overall total.
 */

declare(strict_types=1);

require_once __DIR__ . '/workflow.php';

const MENTORA_WEEKDAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
/** Reports can be sent (or corrected) for this many days back. */
const MENTORA_EOD_REPORT_DAYS = 30;

/** "11/28/2026" from "2026-11-28". */
function mentora_eod_date(?string $date): string
{
    return $date ? date('m/d/Y', strtotime($date)) : '—';
}

/** "9:00 AM" from "09:00:00" (or "—"). */
function mentora_eod_time(?string $time): string
{
    return $time ? date('g:i A', strtotime('2000-01-01 ' . $time)) : '—';
}

/**
 * Read and check an End-of-day report from a form ($_POST). Returns [values, error]:
 * values = date (Y-m-d), day, time_in / time_out (H:i), hours (1 decimal), tasks, notes (or null).
 */
function mentora_eod_from_post(array $post): array
{
    $date = trim((string) ($post['report_date'] ?? ''));
    $day = (string) ($post['day_name'] ?? '');
    $timeIn = trim((string) ($post['time_in'] ?? ''));
    $timeOut = trim((string) ($post['time_out'] ?? ''));
    $hours = round((float) ($post['hours'] ?? 0), 1);
    $tasks = trim((string) ($post['tasks'] ?? ''));
    $notes = trim((string) ($post['notes'] ?? ''));
    $values = ['date' => $date, 'day' => $day, 'time_in' => $timeIn, 'time_out' => $timeOut, 'hours' => $hours, 'tasks' => $tasks, 'notes' => $notes];

    $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    $validTime = static fn(string $t): bool => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t) === 1;
    $minutes = static fn(string $t): int => (int) substr($t, 0, 2) * 60 + (int) substr($t, 3, 2);
    $span = $validTime($timeIn) && $validTime($timeOut) ? ($minutes($timeOut) - $minutes($timeIn)) / 60 : 0;

    $error = match (true) {
        !$parsedDate || $parsedDate->format('Y-m-d') !== $date => 'Please choose the date.',
        $date > date('Y-m-d') => 'You can’t send a report for a future date.',
        $date < date('Y-m-d', strtotime('-' . MENTORA_EOD_REPORT_DAYS . ' days')) => 'Reports can be sent for the last ' . MENTORA_EOD_REPORT_DAYS . ' days only.',
        !in_array($day, MENTORA_WEEKDAYS, true) => 'Please choose the day.',
        $day !== $parsedDate->format('l') => mentora_eod_date($date) . ' is a ' . $parsedDate->format('l') . '. Please choose that day.',
        !$validTime($timeIn) => 'Please enter your time in.',
        !$validTime($timeOut) => 'Please enter your time out.',
        $span <= 0 => 'Time out must be later than time in.',
        $hours < 0.1 || $hours > 16 => 'Hours worked must be between 0.1 and 16.',
        $hours > round($span, 1) + 0.05 => 'Hours worked can’t be more than the time between time in and time out (' . number_format($span, 1) . ' hrs).',
        $tasks === '' => 'Please write the tasks you completed.',
        mb_strlen($tasks) > 4000 || mb_strlen($notes) > 2000 => 'Your report is too long. Please shorten it.',
        default => null,
    };
    $values['notes'] = $notes !== '' ? $notes : null;
    return [$values, $error];
}

/**
 * The form fields (inside a <form> that has the CSRF token). $draft: date, day, time_in, time_out, hours, tasks, notes.
 * Picking a date selects its day; times fill in the hours worked (both can still be changed).
 */
function mentora_eod_fields(array $draft): string
{
    $today = date('Y-m-d');
    $date = (string) ($draft['date'] ?? $today);
    $day = (string) ($draft['day'] ?? date('l', strtotime($date)));
    $hours = $draft['hours'] ?? '';
    $options = '';
    foreach (MENTORA_WEEKDAYS as $weekday) {
        $options .= '<option value="' . $weekday . '"' . ($weekday === $day ? ' selected' : '') . '>' . $weekday . '</option>';
    }
    ob_start(); ?>
    <div class="form-grid eod-report-fields" data-eod-fields>
        <div class="field"><label for="eod-date">Date * <small>(mm/dd/yyyy)</small></label>
            <input id="eod-date" type="date" name="report_date" required min="<?= date('Y-m-d', strtotime('-' . MENTORA_EOD_REPORT_DAYS . ' days')) ?>" max="<?= $today ?>" value="<?= mentora_h($date) ?>" data-eod-date></div>
        <div class="field"><label for="eod-day">Day *</label>
            <select id="eod-day" name="day_name" required data-eod-day><?= $options ?></select></div>
        <div class="field"><label for="eod-in">Time in *</label>
            <input id="eod-in" type="time" name="time_in" required value="<?= mentora_h(substr((string) ($draft['time_in'] ?? ''), 0, 5)) ?>" data-eod-in></div>
        <div class="field"><label for="eod-out">Time out *</label>
            <input id="eod-out" type="time" name="time_out" required value="<?= mentora_h(substr((string) ($draft['time_out'] ?? ''), 0, 5)) ?>" data-eod-out></div>
        <div class="field"><label for="eod-hours">Hours worked *</label>
            <input id="eod-hours" type="number" name="hours" required min="0.1" max="16" step="0.1" inputmode="decimal" value="<?= mentora_h($hours === '' || $hours === null ? '' : mentora_hours($hours)) ?>" data-eod-hours>
            <small>Filled in from your times; change it if you took a break.</small></div>
        <div class="field full"><label for="eod-tasks">Tasks completed *</label>
            <textarea id="eod-tasks" name="tasks" rows="4" required maxlength="4000" placeholder="What you completed today"><?= mentora_h($draft['tasks'] ?? '') ?></textarea></div>
        <div class="field full"><label for="eod-notes">Notes <small>(optional)</small></label>
            <textarea id="eod-notes" name="notes" rows="2" maxlength="2000" placeholder="Anything else to add"><?= mentora_h($draft['notes'] ?? '') ?></textarea></div>
    </div>
    <script>
        // The day follows the date; the hours follow the times (until you type your own hours).
        (() => {
            const box = document.currentScript.previousElementSibling;
            const date = box.querySelector('[data-eod-date]');
            const day = box.querySelector('[data-eod-day]');
            const timeIn = box.querySelector('[data-eod-in]');
            const timeOut = box.querySelector('[data-eod-out]');
            const hours = box.querySelector('[data-eod-hours]');
            const names = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
            let hoursTyped = hours.value !== '';
            date.addEventListener('change', () => {
                if (date.value) {
                    day.value = names[new Date(date.value + 'T12:00:00').getDay()];
                }
            });
            const fillHours = () => {
                if (hoursTyped || !timeIn.value || !timeOut.value) {
                    return;
                }
                const toMinutes = (value) => Number(value.slice(0, 2)) * 60 + Number(value.slice(3, 5));
                const span = (toMinutes(timeOut.value) - toMinutes(timeIn.value)) / 60;
                hours.value = span > 0 ? String(Math.round(span * 10) / 10) : '';
            };
            timeIn.addEventListener('change', fillHours);
            timeOut.addEventListener('change', fillHours);
            hours.addEventListener('input', () => { hoursTyped = hours.value !== ''; });
            fillHours();
        })();
    </script>
    <?php
    return (string) ob_get_clean();
}

/**
 * Download one person's reports as a PDF (includes/pdf_export.php): oldest first, a total row after every 5 days
 * and an overall total, like the table on the page. $extraColumns: [heading => fn(array $row): string (plain text)].
 */
function mentora_eod_pdf(string $title, string $who, array $rows, array $extraColumns = []): never
{
    require_once __DIR__ . '/pdf_export.php';
    usort($rows, static fn(array $a, array $b): int => [$a['report_date'], $a['id'] ?? 0] <=> [$b['report_date'], $b['id'] ?? 0]);
    $header = ['Date', 'Day', 'Time in', 'Time out', 'Hours worked', 'Tasks completed', 'Notes', ...array_keys($extraColumns)];
    $blank = array_fill(0, count($extraColumns), '');
    $lines = [];
    $blockHours = 0.0;
    $blockDays = 0;
    $blockStart = null;
    $allHours = 0.0;
    foreach (array_values($rows) as $index => $row) {
        $hours = (float) $row['hours'];
        $blockHours += $hours;
        $allHours += $hours;
        $blockDays++;
        $blockStart ??= $row['report_date'];
        $lines[] = [
            mentora_eod_date($row['report_date']),
            $row['day_name'] ?: date('l', strtotime($row['report_date'])),
            mentora_eod_time($row['time_in'] ?? null),
            mentora_eod_time($row['time_out'] ?? null),
            mentora_hours($hours),
            (string) $row['tasks'],
            (string) ($row['notes'] ?? ''),
            ...array_map(static fn(callable $cell): string => $cell($row), array_values($extraColumns)),
        ];
        if ($blockDays === 5 || $index === count($rows) - 1) {
            $lines[] = [
                $blockDays === 5 ? 'TOTAL, 5 DAYS' : 'TOTAL SO FAR',
                $blockDays . ' day' . ($blockDays === 1 ? '' : 's'),
                '', '',
                mentora_hours($blockHours) . ' hrs',
                mentora_eod_date($blockStart) . ($blockDays > 1 ? ' to ' . mentora_eod_date($row['report_date']) : ''),
                '', ...$blank,
            ];
            $blockHours = 0.0;
            $blockDays = 0;
            $blockStart = null;
        }
    }
    if (count($rows) > 5) {
        $lines[] = ['OVERALL TOTAL', count($rows) . ' days', '', '', mentora_hours($allHours) . ' hrs', '', '', ...$blank];
    }
    $subtitle = $who . ' · ' . count($rows) . ' report' . (count($rows) === 1 ? '' : 's') . ' · ' . mentora_hours($allHours)
        . ' hrs worked · Generated ' . date('m/d/Y, g:i A');
    $pdf = mentora_pdf_table($title, $subtitle, $header, $lines ?: [array_merge(['No reports yet.'], array_fill(0, count($header) - 1, ''))]);
    $fileName = 'end-of-day-reports-' . strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $who), '-')) . '-' . date('Y-m-d') . '.pdf';
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    header('Content-Length: ' . strlen($pdf));
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo $pdf;
    exit;
}

/**
 * A table of one person's reports, oldest first, with a total after every 5 reports (days and hours) and an
 * overall total. Each row: report_date, day_name, time_in, time_out, hours, tasks, notes. $extraColumns adds
 * columns at the end: [heading => fn(array $row): string (HTML)].
 */
function mentora_eod_table(array $rows, string $tableId, array $extraColumns = [], string $emptyText = 'No reports yet.'): void
{
    usort($rows, static fn(array $a, array $b): int => [$a['report_date'], $a['id'] ?? 0] <=> [$b['report_date'], $b['id'] ?? 0]);
    $columns = 7 + count($extraColumns);
    $blockHours = 0.0;
    $blockDays = 0;
    $blockStart = null;
    $allHours = 0.0;
    ?>
    <div class="table-scroll">
    <table class="admin-table eod-report-table" id="<?= mentora_h($tableId) ?>" data-no-pager>
        <thead>
            <tr><th>Date</th><th>Day</th><th>Time in</th><th>Time out</th><th>Hours worked</th><th>Tasks completed</th><th>Notes</th>
                <?php foreach (array_keys($extraColumns) as $heading): ?><th><?= mentora_h($heading) ?></th><?php endforeach; ?></tr>
        </thead>
        <tbody>
        <?php if (!$rows): ?>
            <tr><td colspan="<?= $columns ?>" class="panel-subtitle"><?= mentora_h($emptyText) ?></td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $index => $row):
            $hours = (float) $row['hours'];
            $blockHours += $hours;
            $allHours += $hours;
            $blockDays++;
            $blockStart ??= $row['report_date'];
        ?>
            <tr>
                <td><?= mentora_eod_date($row['report_date']) ?></td>
                <td><?= mentora_h($row['day_name'] ?: date('l', strtotime($row['report_date']))) ?></td>
                <td><?= mentora_eod_time($row['time_in'] ?? null) ?></td>
                <td><?= mentora_eod_time($row['time_out'] ?? null) ?></td>
                <td><?= mentora_hours($hours) ?></td>
                <td class="eod-text"><?= nl2br(mentora_h((string) $row['tasks'])) ?></td>
                <td class="eod-text"><?= ($row['notes'] ?? '') !== '' ? nl2br(mentora_h((string) $row['notes'])) : '<span class="panel-subtitle">—</span>' ?></td>
                <?php foreach ($extraColumns as $render): ?><td><?= $render($row) ?></td><?php endforeach; ?>
            </tr>
            <?php if ($blockDays === 5 || $index === array_key_last($rows)): ?>
                <tr class="eod-total-row">
                    <td colspan="4"><?= $blockDays === 5 ? 'Total for 5 days' : 'Total for ' . $blockDays . ' day' . ($blockDays === 1 ? '' : 's') . ' so far' ?>
                        <small>(<?= mentora_eod_date($blockStart) ?> – <?= mentora_eod_date($row['report_date']) ?>)</small></td>
                    <td><strong><?= mentora_hours($blockHours) ?> hrs</strong></td>
                    <td colspan="<?= $columns - 5 ?>"><strong><?= $blockDays ?> day<?= $blockDays === 1 ? '' : 's' ?></strong> worked</td>
                </tr>
                <?php $blockHours = 0.0; $blockDays = 0; $blockStart = null; ?>
            <?php endif; ?>
        <?php endforeach; ?>
        <?php if (count($rows) > 5): ?>
            <tr class="eod-total-row eod-grand-total">
                <td colspan="4">Overall total</td>
                <td><strong><?= mentora_hours($allHours) ?> hrs</strong></td>
                <td colspan="<?= $columns - 5 ?>"><strong><?= count($rows) ?> days</strong> worked</td>
            </tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
    <?php
}
