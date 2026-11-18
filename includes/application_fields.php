<?php
/**
 * Qualifications form questions, asked on the workspace "Form" page (includes/application_form.php)
 * after an applicant creates their account on auth/signup.php.
 *
 * Pathways use the database values: 'employee_va' or 'internship'.
 */

const MENTORA_INTEREST_OPTIONS = ['Customer Success', 'Operations', 'Sales'];
const MENTORA_APPLICATION_FIELDS = ['mobile', 'location', 'desired_role', 'school', 'course', 'required_hours', 'interest', 'start_date', 'skills'];

/**
 * Form values for every application field, from a saved `applications` row (or defaults).
 */
function mentora_application_values(?array $application = null): array
{
    $values = [];
    foreach (MENTORA_APPLICATION_FIELDS as $field) {
        $values[$field] = (string) ($application[$field] ?? '');
    }
    if ($values['required_hours'] === '') {
        $values['required_hours'] = '480';
    }
    if (!in_array($values['interest'], MENTORA_INTEREST_OPTIONS, true)) {
        $values['interest'] = MENTORA_INTEREST_OPTIONS[0];
    }

    return $values;
}

/**
 * Overwrite $values with the submitted application fields.
 */
function mentora_application_from_post(array $values): array
{
    foreach (MENTORA_APPLICATION_FIELDS as $field) {
        $values[$field] = trim(mentora_post_string($field));
    }

    return $values;
}

/**
 * Validation messages for the fields that apply to $pathway (empty array = valid).
 */
function mentora_application_errors(array $values, string $pathway): array
{
    $errors = [];
    if ($pathway === 'internship') {
        if ($values['school'] === '' || $values['course'] === '') {
            $errors[] = 'Please enter your school and course.';
        }
        if (!ctype_digit($values['required_hours']) || (int) $values['required_hours'] < 1 || (int) $values['required_hours'] > 5000) {
            $errors[] = 'Required internship hours must be a whole number between 1 and 5000.';
        }
        if (!in_array($values['interest'], MENTORA_INTEREST_OPTIONS, true)) {
            $errors[] = 'Please choose an area of interest.';
        }
    } elseif ($values['desired_role'] === '') {
        $errors[] = 'Please enter the employee VA role you want to apply for.';
    }

    if ($values['start_date'] !== '') {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $values['start_date']);
        if (!$date || $date->format('Y-m-d') !== $values['start_date']) {
            $errors[] = 'Please enter a valid start date.';
        }
    }

    $limits = ['mobile' => [40, 'Mobile number'], 'location' => [190, 'City / province'], 'desired_role' => [190, 'Desired role'],
        'school' => [190, 'School / university'], 'course' => [190, 'Course / program'], 'skills' => [5000, 'Skills and experience']];
    foreach ($limits as $field => [$max, $label]) {
        if (mb_strlen($values[$field]) > $max) {
            $errors[] = "{$label} must be {$max} characters or fewer.";
        }
    }

    return $errors;
}

/**
 * Column values for `applications`, keeping only the fields that belong to $pathway.
 */
function mentora_application_row(array $values, string $pathway): array
{
    $isIntern = $pathway === 'internship';
    $orNull = static fn(string $value): ?string => $value === '' ? null : $value;

    return [
        'mobile' => $orNull($values['mobile']),
        'location' => $orNull($values['location']),
        'school' => $isIntern ? $values['school'] : null,
        'course' => $isIntern ? $values['course'] : null,
        'required_hours' => $isIntern ? (int) $values['required_hours'] : null,
        'desired_role' => $isIntern ? null : $values['desired_role'],
        'start_date' => $orNull($values['start_date']),
        'interest' => $isIntern ? $values['interest'] : null,
        'skills' => $orNull($values['skills']),
    ];
}

/**
 * Insert a new application for $userId, or update $existingId.
 */
function mentora_save_application(PDO $pdo, int $userId, array $row, ?int $existingId = null): void
{
    if ($existingId) {
        $pdo->prepare(
            'UPDATE applications SET mobile = ?, location = ?, school = ?, course = ?, required_hours = ?,
                 desired_role = ?, start_date = ?, interest = ?, skills = ?
             WHERE id = ?'
        )->execute([...array_values($row), $existingId]);
        return;
    }

    $pdo->prepare(
        'INSERT INTO applications (user_id, mobile, location, school, course, required_hours,
             desired_role, start_date, interest, skills)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([$userId, ...array_values($row)]);
}

/**
 * Tell the people team about a new application (if MENTORA_APPROVAL_EMAIL is set).
 */
function mentora_notify_new_application(string $name, string $email, string $pathway): void
{
    $approvalEmail = getenv('MENTORA_APPROVAL_EMAIL') ?: '';
    if (!filter_var($approvalEmail, FILTER_VALIDATE_EMAIL)) {
        return;
    }
    $message = implode("\n", [
        'A new Mentora application is pending approval.',
        '',
        'Applicant: ' . $name,
        'Email: ' . $email,
        'Pathway: ' . $pathway,
        'Review it in the Mentora admin workspace.',
    ]);
    // @: a printed mail warning would break the redirect that follows; it is logged instead.
    if (!@mail($approvalEmail, 'Mentora application pending approval: ' . $name, $message, 'From: no-reply@mentora.ph')) {
        error_log('Mentora approval email could not be sent: ' . (error_get_last()['message'] ?? 'unknown error'));
    }
}

/**
 * Print the qualifications questions for $pathway ('employee_va' or 'internship').
 * Used by the workspace "Form" page (includes/application_form.php).
 */
function mentora_render_application_fields(array $values, string $pathway): void
{
    $h = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    $isIntern = $pathway === 'internship';
    ?>
    <div class="form-grid">
        <div class="field">
            <label for="mobile">Mobile number</label>
            <input id="mobile" type="tel" name="mobile" maxlength="40" autocomplete="tel" placeholder="e.g. 0917 123 4567" value="<?= $h($values['mobile']) ?>">
        </div>
        <div class="field">
            <label for="location">City / province</label>
            <input id="location" name="location" maxlength="190" autocomplete="address-level2" placeholder="e.g. Quezon City, Metro Manila" value="<?= $h($values['location']) ?>">
        </div>

        <?php if ($isIntern): ?>
            <div class="field full">
                <label for="school">School / university *</label>
                <input id="school" name="school" required maxlength="190" placeholder="Name of your school or university" value="<?= $h($values['school']) ?>">
            </div>
            <div class="field">
                <label for="course">Course / program *</label>
                <input id="course" name="course" required maxlength="190" placeholder="e.g. BS Information Systems" value="<?= $h($values['course']) ?>">
            </div>
            <div class="field">
                <label for="required_hours">Required internship hours *</label>
                <input id="required_hours" type="number" name="required_hours" required min="1" max="5000" value="<?= $h($values['required_hours']) ?>">
                <small>The total hours your school requires (OJT).</small>
            </div>
            <div class="field">
                <label for="interest">Area of interest *</label>
                <select id="interest" name="interest">
                    <?php foreach (MENTORA_INTEREST_OPTIONS as $option): ?>
                        <option <?= $values['interest'] === $option ? 'selected' : '' ?>><?= $h($option) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php else: ?>
            <div class="field full">
                <label for="desired_role">Role you’re applying for *</label>
                <input id="desired_role" name="desired_role" required maxlength="190" placeholder="e.g. Customer support VA, Executive assistant" value="<?= $h($values['desired_role']) ?>">
            </div>
        <?php endif; ?>

        <div class="field">
            <label for="start_date">Preferred start date</label>
            <input id="start_date" type="date" name="start_date" value="<?= $h($values['start_date']) ?>">
        </div>
        <div class="field full">
            <label for="skills">Skills and experience</label>
            <textarea id="skills" name="skills" rows="5" maxlength="5000" placeholder="<?= $isIntern
                ? 'Tools you can use, school projects, organisations, and what you hope to learn during your internship.'
                : 'Your work experience, tools and software you use, languages, and the kind of clients you have supported.' ?>"><?= $h($values['skills']) ?></textarea>
        </div>
    </div>
    <?php
}
