<?php
/**
 * Qualifications form questions, asked on the workspace "Form" page (includes/application_form.php)
 * after the applicant has an account and has chosen a pathway.
 *
 *   Intern       school, course, required OJT hours, area of interest -> straight to training
 *   Employee VA  fill in the form (role, education, experience, skills) OR upload a resume (role + file)
 *                -> AI-supported review (ai/resume_review.php)
 *
 * Pathways use the database values: 'employee_va' or 'internship'.
 */

// Fallback when the departments table can't be read; normally the options are Mentora's departments.
const MENTORA_INTEREST_OPTIONS = ['Web Developer', 'Marketing Services', 'Accounting', 'Psychotherapist'];

/** Highest educational attainment (Employee VA form; the AI review's "attainment"). */
const MENTORA_EDUCATION_LEVELS = [
    'high_school' => 'High school / Senior high',
    'vocational' => 'Vocational / TESDA certificate',
    'college_undergrad' => 'College undergraduate',
    'bachelor' => 'Bachelor’s degree',
    'postgraduate' => 'Master’s degree or higher',
];

/** "Area of interest" choices: the department names HR manages in admin/department.php. */
function mentora_interest_options(): array
{
    global $pdo;
    static $options = null;
    if ($options === null) {
        try {
            $options = $pdo instanceof PDO ? $pdo->query('SELECT name FROM departments ORDER BY name')->fetchAll(PDO::FETCH_COLUMN) : [];
        } catch (Throwable $exception) {
            $options = [];
        }
        $options = $options ?: MENTORA_INTEREST_OPTIONS;
    }

    return $options;
}
const MENTORA_APPLICATION_FIELDS = ['submission_mode', 'mobile', 'location', 'desired_role', 'school', 'course', 'required_hours', 'interest', 'education', 'experience_years', 'start_date', 'skills'];

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
    if (!in_array($values['interest'], mentora_interest_options(), true)) {
        $values['interest'] = mentora_interest_options()[0];
    }
    if ($values['submission_mode'] !== 'resume') {
        $values['submission_mode'] = 'form';
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
 * $hasResume: an uploaded (or already saved) resume, required when an employee chooses "Upload my resume".
 */
function mentora_application_errors(array $values, string $pathway, bool $hasResume = false): array
{
    $errors = [];
    if ($pathway === 'internship') {
        if ($values['school'] === '' || $values['course'] === '') {
            $errors[] = 'Please enter your school and course.';
        }
        if (!ctype_digit($values['required_hours']) || (int) $values['required_hours'] < 1 || (int) $values['required_hours'] > 5000) {
            $errors[] = 'Required internship hours must be a whole number between 1 and 5000.';
        }
        if (!in_array($values['interest'], mentora_interest_options(), true)) {
            $errors[] = 'Please choose an area of interest.';
        }
    } else {
        if ($values['desired_role'] === '') {
            $errors[] = 'Please enter the employee VA role you want to apply for.';
        }
        if (!in_array($values['submission_mode'], ['form', 'resume'], true)) {
            $errors[] = 'Please choose to fill in the form or upload your resume.';
        } elseif ($values['submission_mode'] === 'resume' && !$hasResume) {
            $errors[] = 'Please upload your resume (PDF, Word or text file).';
        } elseif ($values['submission_mode'] === 'form') {
            if ($values['education'] === '') {
                $errors[] = 'Please choose your highest educational attainment.';
            }
            if (mb_strlen($values['skills']) < 30) {
                $errors[] = 'Please describe your skills and experience (at least 30 characters), or upload your resume instead.';
            }
        }
        if ($values['education'] !== '' && !isset(MENTORA_EDUCATION_LEVELS[$values['education']])) {
            $errors[] = 'Please choose an educational attainment from the list.';
        }
        if ($values['experience_years'] !== '' && (!ctype_digit($values['experience_years']) || (int) $values['experience_years'] > 50)) {
            $errors[] = 'Years of experience must be a whole number from 0 to 50.';
        }
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
        'submission_mode' => $isIntern ? 'form' : $values['submission_mode'],
        'mobile' => $orNull($values['mobile']),
        'location' => $orNull($values['location']),
        'school' => $isIntern ? $values['school'] : null,
        'course' => $isIntern ? $values['course'] : null,
        'required_hours' => $isIntern ? (int) $values['required_hours'] : null,
        'desired_role' => $isIntern ? null : $values['desired_role'],
        'start_date' => $orNull($values['start_date']),
        'interest' => $isIntern ? $values['interest'] : null,
        'education' => $isIntern ? null : $orNull($values['education']),
        'experience_years' => $isIntern || $values['experience_years'] === '' ? null : (int) $values['experience_years'],
        'skills' => $orNull($values['skills']),
    ];
}

/**
 * Insert a new application for $userId, or update $existingId. Returns the application id.
 */
function mentora_save_application(PDO $pdo, int $userId, array $row, ?int $existingId = null): int
{
    $columns = array_keys($row);
    if ($existingId) {
        $pdo->prepare('UPDATE applications SET ' . implode(', ', array_map(static fn($c) => "$c = ?", $columns)) . ' WHERE id = ?')
            ->execute([...array_values($row), $existingId]);
        return $existingId;
    }
    $pdo->prepare('INSERT INTO applications (user_id, ' . implode(', ', $columns) . ') VALUES (?' . str_repeat(', ?', count($columns)) . ')')
        ->execute([$userId, ...array_values($row)]);

    return (int) $pdo->lastInsertId();
}

/**
 * Print the qualifications questions for $pathway ('employee_va' or 'internship').
 * $resume: the employee's saved resume (documents row) or null. Used by includes/application_form.php.
 */
function mentora_render_application_fields(array $values, string $pathway, ?array $resume = null): void
{
    $h = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    $isIntern = $pathway === 'internship';
    ?>
    <?php if (!$isIntern): ?>
        <fieldset class="application-mode">
            <legend>How would you like to apply? *</legend>
            <div class="pathway-choice">
                <label>
                    <input type="radio" name="submission_mode" value="form" data-mode-toggle<?= $values['submission_mode'] === 'form' ? ' checked' : '' ?>>
                    <span><strong>Fill in the form</strong><small>Tell us your education, experience and skills.</small></span>
                </label>
                <label>
                    <input type="radio" name="submission_mode" value="resume" data-mode-toggle<?= $values['submission_mode'] === 'resume' ? ' checked' : '' ?>>
                    <span><strong>Upload my resume</strong><small>PDF, Word or text file. The AI reads it for you.</small></span>
                </label>
            </div>
            <small>Either way, an AI-supported review summarizes your skills and matches you to the department that needs them; the HR team makes the decision.</small>
        </fieldset>
    <?php endif; ?>
    <div class="form-grid">
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
                    <?php foreach (mentora_interest_options() as $option): ?>
                        <option <?= $values['interest'] === $option ? 'selected' : '' ?>><?= $h($option) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php else: ?>
            <div class="field full">
                <label for="desired_role">Role you’re applying for *</label>
                <input id="desired_role" name="desired_role" required maxlength="190" placeholder="e.g. Customer support VA, Executive assistant, Bookkeeping VA" value="<?= $h($values['desired_role']) ?>">
            </div>
            <div class="field full" data-mode="resume">
                <label for="resume">Resume<?= $resume ? '' : ' *' ?></label>
                <input id="resume" type="file" name="resume" accept=".pdf,.docx,.doc,.txt,application/pdf,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/msword,text/plain">
                <small><?= $resume
                    ? 'Saved: ' . $h($resume['original_name']) . '. Choose a new file only to replace it.'
                    : 'PDF, Word (.docx / .doc) or text, up to 10 MB. A text-based PDF works best; scanned images can’t be read by the AI.' ?></small>
            </div>
            <div class="field" data-mode="form">
                <label for="education">Highest educational attainment *</label>
                <select id="education" name="education">
                    <option value="">Choose one</option>
                    <?php foreach (MENTORA_EDUCATION_LEVELS as $key => $label): ?>
                        <option value="<?= $key ?>"<?= $values['education'] === $key ? ' selected' : '' ?>><?= $h($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field" data-mode="form">
                <label for="experience_years">Years of work experience</label>
                <input id="experience_years" type="number" name="experience_years" min="0" max="50" placeholder="e.g. 2" value="<?= $h($values['experience_years']) ?>">
            </div>
        <?php endif; ?>

        <div class="field">
            <label for="mobile">Mobile number</label>
            <input id="mobile" type="tel" name="mobile" maxlength="40" autocomplete="tel" placeholder="e.g. 0917 123 4567" value="<?= $h($values['mobile']) ?>">
        </div>
        <div class="field">
            <label for="location">City / province</label>
            <input id="location" name="location" maxlength="190" autocomplete="address-level2" placeholder="e.g. Quezon City, Metro Manila" value="<?= $h($values['location']) ?>">
        </div>
        <div class="field">
            <label for="start_date">Preferred start date</label>
            <input id="start_date" type="date" name="start_date" value="<?= $h($values['start_date']) ?>">
        </div>
        <div class="field full"<?= $isIntern ? '' : ' data-mode="form"' ?>>
            <label for="skills">Skills and experience<?= $isIntern ? '' : ' *' ?></label>
            <textarea id="skills" name="skills" rows="6" maxlength="5000" placeholder="<?= $isIntern
                ? 'Tools you can use, school projects, organisations, and what you hope to learn during your internship.'
                : 'Your work experience, tools and software you use (e.g. Excel, QuickBooks, Canva, WordPress), languages, and the kind of clients you have supported.' ?>"><?= $h($values['skills']) ?></textarea>
        </div>
    </div>
    <?php if (!$isIntern): ?>
        <script>
            // Show the fields for the chosen way to apply (form or resume). Without JavaScript both stay visible.
            (() => {
                const toggles = document.querySelectorAll('[data-mode-toggle]');
                const update = () => {
                    const mode = document.querySelector('[data-mode-toggle]:checked')?.value || 'form';
                    document.querySelectorAll('[data-mode]').forEach((field) => { field.hidden = field.dataset.mode !== mode; });
                };
                toggles.forEach((toggle) => toggle.addEventListener('change', update));
                update();
            })();
        </script>
    <?php endif; ?>
    <?php
}
