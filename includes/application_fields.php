<?php
/**
 * Qualifications form questions, asked on the workspace "Form" page (includes/application_form.php)
 * after the applicant has an account and has chosen a pathway.
 *
 *   Intern       school, course, required OJT hours, area of interest (a virtual assistant role that fits the
 *                course, see MENTORA_COURSE_INTERESTS) -> straight to training
 *   Employee VA  fill in the form (role, education, experience, skills) OR upload a resume (role + file)
 *                -> AI-supported review (ai/resume_review.php)
 *
 * Pathways use the database values: 'employee_va' or 'internship'.
 */

/** Highest educational attainment (Employee VA form; the AI review's "attainment"). */
const MENTORA_EDUCATION_LEVELS = [
    'high_school' => 'High school / Senior high',
    'vocational' => 'Vocational / TESDA certificate',
    'college_undergrad' => 'College undergraduate',
    'bachelor' => 'Bachelor’s degree',
    'postgraduate' => 'Master’s degree or higher',
];

require_once __DIR__ . '/school_options.php'; // MENTORA_SCHOOL_OPTIONS ("School / university" choices)

/** "Course / program" choices on the intern form (common Philippine college programs). The value saved is the label. */
const MENTORA_COURSE_OPTIONS = [
    'Computing and IT' => [
        'BS Information Technology (BSIT)',
        'BS Computer Science (BSCS)',
        'BS Information Systems (BSIS)',
        'BS Computer Engineering (BSCpE)',
        'BS Entertainment and Multimedia Computing (BSEMC)',
    ],
    'Business and accounting' => [
        'BS Accountancy (BSA)',
        'BS Accounting Information System (BSAIS)',
        'BS Management Accounting (BSMA)',
        'BS Business Administration – Marketing Management',
        'BS Business Administration – Financial Management',
        'BS Business Administration – Human Resource Management',
        'BS Business Administration – Operations Management',
        'BS Office Administration (BSOA)',
        'BS Entrepreneurship',
    ],
    'Communication and arts' => [
        'AB Communication',
        'BA Multimedia Arts',
        'AB English Language Studies',
    ],
    'Psychology and health' => [
        'BS Psychology',
        'AB Psychology',
        'BS Nursing',
    ],
    'Hospitality and education' => [
        'BS Hospitality Management (BSHM)',
        'BS Tourism Management (BSTM)',
        'Bachelor of Secondary Education (BSEd)',
        'Bachelor of Elementary Education (BEEd)',
    ],
];

/** "City / province" choices on the intern form: Metro Manila cities, every Cavite city and town, then the other provinces. */
const MENTORA_LOCATION_OPTIONS = [
    'Metro Manila' => [
        'Caloocan, Metro Manila', 'Las Piñas, Metro Manila', 'Makati, Metro Manila', 'Malabon, Metro Manila',
        'Mandaluyong, Metro Manila', 'Manila, Metro Manila', 'Marikina, Metro Manila', 'Muntinlupa, Metro Manila',
        'Navotas, Metro Manila', 'Parañaque, Metro Manila', 'Pasay, Metro Manila', 'Pasig, Metro Manila',
        'Pateros, Metro Manila', 'Quezon City, Metro Manila', 'San Juan, Metro Manila', 'Taguig, Metro Manila',
        'Valenzuela, Metro Manila',
    ],
    'Cavite' => [
        'Alfonso, Cavite', 'Amadeo, Cavite', 'Bacoor, Cavite', 'Carmona, Cavite', 'Cavite City, Cavite',
        'Dasmariñas, Cavite', 'General Emilio Aguinaldo, Cavite', 'General Mariano Alvarez, Cavite', 'General Trias, Cavite',
        'Imus, Cavite', 'Indang, Cavite', 'Kawit, Cavite', 'Magallanes, Cavite', 'Maragondon, Cavite', 'Mendez, Cavite',
        'Naic, Cavite', 'Noveleta, Cavite', 'Rosario, Cavite', 'Silang, Cavite', 'Tagaytay, Cavite', 'Tanza, Cavite',
        'Ternate, Cavite', 'Trece Martires, Cavite',
    ],
    'Other provinces' => [
        'Abra', 'Agusan del Norte', 'Agusan del Sur', 'Aklan', 'Albay', 'Antique', 'Apayao', 'Aurora', 'Basilan', 'Bataan',
        'Batanes', 'Batangas', 'Benguet', 'Biliran', 'Bohol', 'Bukidnon', 'Bulacan', 'Cagayan', 'Camarines Norte',
        'Camarines Sur', 'Camiguin', 'Capiz', 'Catanduanes', 'Cebu', 'Cotabato', 'Davao de Oro', 'Davao del Norte',
        'Davao del Sur', 'Davao Occidental', 'Davao Oriental', 'Dinagat Islands', 'Eastern Samar', 'Guimaras', 'Ifugao',
        'Ilocos Norte', 'Ilocos Sur', 'Iloilo', 'Isabela', 'Kalinga', 'La Union', 'Laguna', 'Lanao del Norte',
        'Lanao del Sur', 'Leyte', 'Maguindanao del Norte', 'Maguindanao del Sur', 'Marinduque', 'Masbate',
        'Misamis Occidental', 'Misamis Oriental', 'Mountain Province', 'Negros Occidental', 'Negros Oriental',
        'Northern Samar', 'Nueva Ecija', 'Nueva Vizcaya', 'Occidental Mindoro', 'Oriental Mindoro', 'Palawan', 'Pampanga',
        'Pangasinan', 'Quezon', 'Quirino', 'Rizal', 'Romblon', 'Samar', 'Sarangani', 'Siquijor', 'Sorsogon',
        'South Cotabato', 'Southern Leyte', 'Sultan Kudarat', 'Sulu', 'Surigao del Norte', 'Surigao del Sur', 'Tarlac',
        'Tawi-Tawi', 'Zambales', 'Zamboanga del Norte', 'Zamboanga del Sur', 'Zamboanga Sibugay',
    ],
];

/** Lowercase without accents ("Mapúa" -> "mapua", "Dasmariñas" -> "dasmarinas"), for matching and sorting. */
function mentora_plain_text(string $text): string
{
    return strtr(mb_strtolower($text), ['á' => 'a', 'à' => 'a', 'â' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'í' => 'i', 'ì' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'ñ' => 'n']);
}

/**
 * Options in A–Z order, whatever order the lists are written in (so new entries never need to be put in place).
 * Sorted by the name itself: capitals, accents and punctuation don't count, the short form in brackets is ignored
 * (so a main campus comes before its branches: "De La Salle University" < "De La Salle University – Dasmariñas"),
 * and numbers sort naturally. Duplicates are dropped.
 */
function mentora_sort_options(array $options): array
{
    $sortKey = static function (string $option): string {
        $name = (string) preg_replace('/\s*\([^)]*\)$/', '', $option);                   // drop "(CvSU)"
        $name = trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', mentora_plain_text($name))); // "–", "/", "." -> space
        return (string) preg_replace_callback('/\d+/', static fn(array $m): string => str_pad($m[0], 10, '0', STR_PAD_LEFT), $name);
    };
    $options = array_values(array_unique($options));
    usort($options, static fn(string $a, string $b): int => strcmp($sortKey($a), $sortKey($b)) ?: strcmp($a, $b));

    return $options;
}

/**
 * The list option a typed or saved value stands for, or null when it isn't in the list (the typed text is kept then).
 * Ignores case, spaces and punctuation, and also accepts an option's short form: "BSIT" or "BS Information Technology"
 * picks "BS Information Technology (BSIT)", and "Quezon City" picks "Quezon City, Metro Manila".
 * The form's script (mentora_render_application_fields) matches the same way while typing.
 */
function mentora_choice_match(string $value, array $groups): ?string
{
    // Accents don't count either: "Dasmarinas" = "Dasmariñas", "Mapua" = "Mapúa".
    $key = static fn(string $text): string => (string) preg_replace('/[^\p{L}\p{N}]+/u', '', mentora_plain_text($text));
    $wanted = $key($value);
    if ($wanted === '') {
        return null;
    }
    foreach ($groups as $options) {
        foreach ($options as $option) {
            $forms = [$option, (string) preg_replace('/\s*\(.*\)$/', '', $option), explode(',', $option)[0]];
            if (preg_match('/\(([^)]+)\)$/', $option, $short)) {
                $forms[] = $short[1];
            }
            foreach ($forms as $form) {
                if ($key($form) === $wanted) {
                    return $option;
                }
            }
        }
    }

    return null;
}

/**
 * A typeable dropdown: a text box with a list of choices that filters as you type (the form's script draws the list;
 * without JavaScript the browser's own suggestion list is used). Anything typed is kept, so nothing needs "Other".
 * $groups: lists of options (shown together in A–Z order, without group headings). $attributes: extra data-* attributes.
 */
function mentora_render_combo_field(string $name, string $label, array $groups, string $value, bool $required, string $placeholder, string $hint = '', array $attributes = []): void
{
    $h = static fn($text): string => htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
    $options = mentora_sort_options(array_merge(...array_values($groups)));
    $shown = mentora_choice_match($value, $groups) ?? $value;
    ?>
    <label for="<?= $h($name) ?>"><?= $h($label) ?><?= $required ? ' *' : '' ?></label>
    <div class="combo" data-combo>
        <input id="<?= $h($name) ?>" name="<?= $h($name) ?>" type="text" maxlength="190" autocomplete="off" spellcheck="false"
            list="<?= $h($name) ?>-choices" placeholder="<?= $h($placeholder) ?>" value="<?= $h($shown) ?>"<?= $required ? ' required' : '' ?>
            <?php foreach ($attributes as $attribute => $attributeValue): ?> <?= $h($attribute) ?>="<?= $h($attributeValue) ?>"<?php endforeach; ?>>
        <datalist id="<?= $h($name) ?>-choices">
            <?php foreach ($options as $option): ?>
                <option value="<?= $h($option) ?>"></option>
            <?php endforeach; ?>
        </datalist>
    </div>
    <?php if ($hint !== ''): ?>
        <small data-hint-for="<?= $h($name) ?>"><?= $h($hint) ?></small>
    <?php endif;
}

/**
 * "Area of interest" on the intern form: the virtual assistant (VA) work the intern wants to train for.
 * The value saved is the label; the AI skill matching (ai/skills.php) reads it with the course and skills.
 */
const MENTORA_VA_INTERESTS = [
    'General Virtual Assistant (Admin support)',
    'Executive Assistant',
    'Data Entry',
    'Email & Calendar Management',
    'Customer Service Support',
    'Research Assistant',
    'Transcription',
    'Project Management Assistant',
    'HR & Recruitment Assistant',
    'Social Media Management',
    'SEO (Search Engine Optimization)',
    'Content Writing & Copywriting',
    'Email Marketing',
    'Online Ads (Facebook / Google Ads)',
    'Lead Generation',
    'Graphic Design (Canva)',
    'Video Editing',
    'Web Development',
    'Website Maintenance',
    'Technical Support (IT Helpdesk)',
    'E-commerce Store Support (Shopify)',
    // Accounting
    'Accounting Assistant',
    'Bookkeeping',
    'Accounts Payable & Receivable',
    'Bank & Account Reconciliation',
    'Billing & Collections',
    'Payroll & Invoicing',
    'Expense Tracking & Management',
    'Budgeting & Forecasting',
    'Financial Reporting',
    'Tax Preparation Assistant',
    'Audit Assistant',
    'Medical Virtual Assistant (Client intake)',
    'Healthcare Scheduling & Records',
    'Travel & Booking Assistant',
];

/**
 * Which VA areas fit each course (keys are the labels in MENTORA_COURSE_OPTIONS). Choosing a course on the form
 * narrows "Area of interest" to its list; a course typed under "Other" can choose any area.
 */
const MENTORA_COURSE_INTERESTS = [
    'BS Information Technology (BSIT)' => ['Web Development', 'Website Maintenance', 'Technical Support (IT Helpdesk)', 'E-commerce Store Support (Shopify)', 'SEO (Search Engine Optimization)', 'Data Entry', 'Graphic Design (Canva)', 'Video Editing'],
    'BS Computer Science (BSCS)' => ['Web Development', 'Website Maintenance', 'Technical Support (IT Helpdesk)', 'SEO (Search Engine Optimization)', 'E-commerce Store Support (Shopify)', 'Data Entry'],
    'BS Information Systems (BSIS)' => ['Web Development', 'Website Maintenance', 'Technical Support (IT Helpdesk)', 'Data Entry', 'SEO (Search Engine Optimization)', 'E-commerce Store Support (Shopify)', 'Project Management Assistant'],
    'BS Computer Engineering (BSCpE)' => ['Technical Support (IT Helpdesk)', 'Web Development', 'Website Maintenance', 'E-commerce Store Support (Shopify)', 'Data Entry'],
    'BS Entertainment and Multimedia Computing (BSEMC)' => ['Graphic Design (Canva)', 'Video Editing', 'Web Development', 'Social Media Management', 'Content Writing & Copywriting'],
    'BS Accountancy (BSA)' => ['Accounting Assistant', 'Bookkeeping', 'Accounts Payable & Receivable', 'Bank & Account Reconciliation', 'Billing & Collections', 'Payroll & Invoicing', 'Expense Tracking & Management', 'Budgeting & Forecasting', 'Financial Reporting', 'Tax Preparation Assistant', 'Audit Assistant', 'Data Entry'],
    'BS Accounting Information System (BSAIS)' => ['Accounting Assistant', 'Bookkeeping', 'Accounts Payable & Receivable', 'Bank & Account Reconciliation', 'Billing & Collections', 'Payroll & Invoicing', 'Expense Tracking & Management', 'Financial Reporting', 'Data Entry', 'E-commerce Store Support (Shopify)'],
    'BS Management Accounting (BSMA)' => ['Accounting Assistant', 'Bookkeeping', 'Accounts Payable & Receivable', 'Bank & Account Reconciliation', 'Payroll & Invoicing', 'Expense Tracking & Management', 'Budgeting & Forecasting', 'Financial Reporting', 'Tax Preparation Assistant', 'Audit Assistant', 'Project Management Assistant'],
    'BS Business Administration – Marketing Management' => ['Social Media Management', 'SEO (Search Engine Optimization)', 'Content Writing & Copywriting', 'Email Marketing', 'Online Ads (Facebook / Google Ads)', 'Lead Generation', 'E-commerce Store Support (Shopify)'],
    'BS Business Administration – Financial Management' => ['Accounting Assistant', 'Bookkeeping', 'Accounts Payable & Receivable', 'Bank & Account Reconciliation', 'Billing & Collections', 'Payroll & Invoicing', 'Expense Tracking & Management', 'Budgeting & Forecasting', 'Financial Reporting', 'Data Entry', 'Research Assistant'],
    'BS Business Administration – Human Resource Management' => ['HR & Recruitment Assistant', 'General Virtual Assistant (Admin support)', 'Executive Assistant', 'Email & Calendar Management', 'Payroll & Invoicing', 'Customer Service Support'],
    'BS Business Administration – Operations Management' => ['Project Management Assistant', 'General Virtual Assistant (Admin support)', 'Executive Assistant', 'E-commerce Store Support (Shopify)', 'Customer Service Support', 'Data Entry', 'Expense Tracking & Management', 'Budgeting & Forecasting'],
    'BS Office Administration (BSOA)' => ['General Virtual Assistant (Admin support)', 'Executive Assistant', 'Data Entry', 'Email & Calendar Management', 'Customer Service Support', 'SEO (Search Engine Optimization)', 'Transcription', 'Social Media Management', 'Billing & Collections'],
    'BS Entrepreneurship' => ['E-commerce Store Support (Shopify)', 'Social Media Management', 'Lead Generation', 'Online Ads (Facebook / Google Ads)', 'Customer Service Support', 'Project Management Assistant', 'General Virtual Assistant (Admin support)', 'Bookkeeping', 'Expense Tracking & Management', 'Budgeting & Forecasting'],
    'AB Communication' => ['Content Writing & Copywriting', 'Social Media Management', 'SEO (Search Engine Optimization)', 'Email Marketing', 'Video Editing', 'Customer Service Support'],
    'BA Multimedia Arts' => ['Graphic Design (Canva)', 'Video Editing', 'Social Media Management', 'Web Development', 'Content Writing & Copywriting'],
    'AB English Language Studies' => ['Content Writing & Copywriting', 'SEO (Search Engine Optimization)', 'Transcription', 'Customer Service Support', 'Research Assistant', 'Email Marketing'],
    'BS Psychology' => ['Medical Virtual Assistant (Client intake)', 'Healthcare Scheduling & Records', 'HR & Recruitment Assistant', 'Customer Service Support', 'Research Assistant', 'General Virtual Assistant (Admin support)'],
    'AB Psychology' => ['Medical Virtual Assistant (Client intake)', 'Healthcare Scheduling & Records', 'HR & Recruitment Assistant', 'Customer Service Support', 'Research Assistant', 'General Virtual Assistant (Admin support)'],
    'BS Nursing' => ['Medical Virtual Assistant (Client intake)', 'Healthcare Scheduling & Records', 'Customer Service Support', 'Transcription', 'Data Entry'],
    'BS Hospitality Management (BSHM)' => ['Customer Service Support', 'Travel & Booking Assistant', 'General Virtual Assistant (Admin support)', 'Email & Calendar Management', 'Social Media Management'],
    'BS Tourism Management (BSTM)' => ['Travel & Booking Assistant', 'Customer Service Support', 'Social Media Management', 'Content Writing & Copywriting', 'General Virtual Assistant (Admin support)'],
    'Bachelor of Secondary Education (BSEd)' => ['Research Assistant', 'Content Writing & Copywriting', 'Transcription', 'General Virtual Assistant (Admin support)', 'Customer Service Support', 'Data Entry'],
    'Bachelor of Elementary Education (BEEd)' => ['Research Assistant', 'Content Writing & Copywriting', 'Transcription', 'General Virtual Assistant (Admin support)', 'Customer Service Support', 'Data Entry'],
];

/** Every "Area of interest" choice, A–Z. */
function mentora_va_interest_choices(): array
{
    return mentora_sort_options(MENTORA_VA_INTERESTS);
}

/**
 * The areas of interest that fit a course: its list in MENTORA_COURSE_INTERESTS (a saved short form such as "BSIT"
 * counts too), or every area for a course that isn't listed (typed under "Other") or not chosen yet.
 */
function mentora_course_interests(string $course): array
{
    $match = mentora_choice_match($course, MENTORA_COURSE_OPTIONS);

    return mentora_sort_options($match !== null && isset(MENTORA_COURSE_INTERESTS[$match]) ? MENTORA_COURSE_INTERESTS[$match] : MENTORA_VA_INTERESTS);
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
    // Older answers (department names) or an area that doesn't fit the course: ask again ("Choose an area").
    if (!in_array($values['interest'], mentora_course_interests($values['course']), true)) {
        $values['interest'] = '';
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
    // Typeable dropdowns: a typed short form or spelling ("cvsu", "bsoa", "imus cavite") is saved as the list's
    // full name; anything not in the list is kept as typed (except the area of interest, which must be in the list).
    $lists = ['school' => MENTORA_SCHOOL_OPTIONS, 'course' => MENTORA_COURSE_OPTIONS, 'location' => MENTORA_LOCATION_OPTIONS, 'interest' => [MENTORA_VA_INTERESTS]];
    foreach ($lists as $field => $groups) {
        $values[$field] = mentora_choice_match($values[$field], $groups) ?? $values[$field];
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
        if ($values['interest'] === '' || !in_array($values['interest'], mentora_va_interest_choices(), true)) {
            $errors[] = 'Please choose an area of interest from the list.';
        } elseif (!in_array($values['interest'], mentora_course_interests($values['course']), true)) {
            $errors[] = 'Please choose an area of interest that fits your course.';
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
                <?php mentora_render_combo_field('school', 'School / university', MENTORA_SCHOOL_OPTIONS, $values['school'], true, 'Type or choose your school'); ?>
            </div>
            <div class="field">
                <?php mentora_render_combo_field('course', 'Course / program', MENTORA_COURSE_OPTIONS, $values['course'], true, 'Type or choose your course'); ?>
            </div>
            <div class="field">
                <label for="required_hours">Required internship hours *</label>
                <input id="required_hours" type="number" name="required_hours" required min="1" max="5000" value="<?= $h($values['required_hours']) ?>">
                <small>The total hours your school requires (OJT).</small>
            </div>
            <div class="field">
                <?php mentora_render_combo_field('interest', 'Area of interest', [mentora_course_interests($values['course'])], $values['interest'], true,
                    'Type or choose a virtual assistant role', 'Virtual assistant work that fits your course.', [
                        'data-strict' => 'Please choose a virtual assistant role from the list.',
                        // Sent already in A–Z order, so the list the script shows matches the server's.
                        'data-course-interests' => json_encode(array_map('mentora_sort_options', MENTORA_COURSE_INTERESTS), JSON_UNESCAPED_UNICODE),
                        'data-all-interests' => json_encode(mentora_va_interest_choices(), JSON_UNESCAPED_UNICODE),
                    ]); ?>
            </div>
        <?php else: ?>
            <div class="field full">
                <label for="desired_role">Role you’re applying for *</label>
                <input id="desired_role" name="desired_role" required maxlength="190" value="<?= $h($values['desired_role']) ?>">
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
                <input id="experience_years" type="number" name="experience_years" min="0" max="50" value="<?= $h($values['experience_years']) ?>">
            </div>
        <?php endif; ?>

        <div class="field">
            <label for="mobile">Mobile number</label>
            <input id="mobile" type="tel" name="mobile" maxlength="40" autocomplete="tel" value="<?= $h($values['mobile']) ?>">
        </div>
        <div class="field">
            <?php if ($isIntern): ?>
                <?php mentora_render_combo_field('location', 'City / province', MENTORA_LOCATION_OPTIONS, $values['location'], false, 'Type or choose your city or province'); ?>
            <?php else: ?>
                <label for="location">City / province</label>
                <input id="location" name="location" maxlength="190" autocomplete="address-level2" value="<?= $h($values['location']) ?>">
            <?php endif; ?>
        </div>
        <div class="field">
            <label for="start_date">Preferred start date</label>
            <input id="start_date" type="date" name="start_date" value="<?= $h($values['start_date']) ?>">
        </div>
        <div class="field full"<?= $isIntern ? '' : ' data-mode="form"' ?>>
            <label for="skills">Skills and experience<?= $isIntern ? '' : ' *' ?></label>
            <textarea id="skills" name="skills" rows="6" maxlength="5000" placeholder="<?= $isIntern
                ? 'Tools you can use, school projects, organisations, and what you hope to learn during your internship.'
                : 'Your work experience, tools and software you use, languages, and the kind of clients you have supported.' ?>"><?= $h($values['skills']) ?></textarea>
        </div>
    </div>
    <script>
        (() => {
            // Same matching as mentora_choice_match(): case, spaces, punctuation and accents don't count, and an
            // option's short form works too ("cvsu" = "Cavite State University (CvSU)", "imus" = "Imus, Cavite").
            const norm = (text) => String(text).toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^\p{L}\p{N}]+/gu, '');
            const formsOf = (option) => {
                const forms = [option, option.replace(/\s*\(.*\)$/, ''), option.split(',')[0]];
                const short = option.match(/\(([^)]+)\)$/);
                if (short) {
                    forms.push(short[1]);
                }
                return forms.map(norm);
            };
            const matchOption = (value, options) => {
                const wanted = norm(value);
                return wanted === '' ? null : (options.find((option) => formsOf(option).includes(wanted)) || null);
            };
            const chevron = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>';

            // Typeable dropdowns: type to filter the list, or open it with the arrow, Alt+Down or a click.
            document.querySelectorAll('[data-combo]').forEach((combo) => {
                const input = combo.querySelector('input');
                const datalist = combo.querySelector('datalist');
                let options = datalist ? [...datalist.options].map((option) => option.value) : [];
                input.removeAttribute('list'); // use the list below instead of the browser's
                datalist?.remove();

                const list = document.createElement('ul');
                list.className = 'combo-list';
                list.id = input.id + '-listbox';
                list.setAttribute('role', 'listbox');
                list.hidden = true;
                const toggle = document.createElement('button');
                toggle.type = 'button';
                toggle.className = 'combo-toggle';
                toggle.tabIndex = -1;
                toggle.setAttribute('aria-label', 'Show all choices');
                toggle.innerHTML = chevron;
                combo.append(toggle, list);
                input.setAttribute('role', 'combobox');
                input.setAttribute('aria-autocomplete', 'list');
                input.setAttribute('aria-controls', list.id);
                input.setAttribute('aria-expanded', 'false');

                let shown = [];
                let active = -1;
                const setActive = (index) => {
                    active = index;
                    [...list.children].forEach((item, i) => item.classList.toggle('is-active', i === index));
                    const item = list.children[index];
                    if (item) {
                        item.scrollIntoView({ block: 'nearest' });
                        input.setAttribute('aria-activedescendant', item.id);
                    } else {
                        input.removeAttribute('aria-activedescendant');
                    }
                };
                const draw = (showAll) => {
                    const query = showAll ? '' : norm(input.value);
                    shown = query === '' ? options : options.filter((option) => norm(option).includes(query));
                    const current = matchOption(input.value, options);
                    list.replaceChildren(...shown.map((option, i) => {
                        const item = document.createElement('li');
                        item.className = 'combo-option';
                        item.id = list.id + '-' + i;
                        item.setAttribute('role', 'option');
                        item.setAttribute('aria-selected', String(option === current));
                        item.textContent = option;
                        return item;
                    }));
                    if (!shown.length) {
                        const empty = document.createElement('li');
                        empty.className = 'combo-empty';
                        empty.textContent = input.dataset.strict ? 'No matching role. Choose one from the list.' : 'Not in the list. What you typed will be kept.';
                        list.append(empty);
                    }
                    setActive(-1);
                };
                const open = (showAll) => {
                    draw(showAll);
                    list.hidden = false;
                    combo.classList.add('is-open');
                    input.setAttribute('aria-expanded', 'true');
                    if (showAll) {
                        const index = shown.indexOf(matchOption(input.value, options));
                        if (index >= 0) {
                            setActive(index);
                        }
                    }
                };
                const close = () => {
                    list.hidden = true;
                    combo.classList.remove('is-open');
                    input.setAttribute('aria-expanded', 'false');
                    input.removeAttribute('aria-activedescendant');
                };
                // A role that must come from the list (area of interest): the browser won't submit anything else.
                const check = () => {
                    if (input.dataset.strict) {
                        input.setCustomValidity(input.value.trim() !== '' && !matchOption(input.value, options) ? input.dataset.strict : '');
                    }
                };
                const choose = (option) => {
                    input.value = option;
                    close();
                    check();
                    input.dispatchEvent(new Event('change', { bubbles: true }));
                };

                input.addEventListener('focus', () => open(true));
                input.addEventListener('click', () => { if (list.hidden) { open(true); } });
                input.addEventListener('input', () => { open(false); check(); });
                input.addEventListener('keydown', (event) => {
                    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                        event.preventDefault();
                        if (list.hidden) {
                            open(true);
                            return;
                        }
                        if (shown.length) {
                            const step = event.key === 'ArrowDown' ? 1 : -1;
                            setActive((active + step + shown.length) % shown.length);
                        }
                    } else if (event.key === 'Enter' && !list.hidden && active >= 0) {
                        event.preventDefault(); // pick the highlighted choice instead of submitting the form
                        choose(shown[active]);
                    } else if (event.key === 'Escape' && !list.hidden) {
                        event.preventDefault();
                        close();
                    } else if (event.key === 'Tab') {
                        close();
                    }
                });
                // Leaving the field: a typed short form becomes the full name ("bsoa" -> "BS Office Administration (BSOA)").
                input.addEventListener('blur', () => {
                    setTimeout(close, 120);
                    const match = matchOption(input.value, options);
                    if (match && match !== input.value) {
                        input.value = match;
                        input.dispatchEvent(new Event('change', { bubbles: true }));
                    }
                    check();
                });
                list.addEventListener('mousedown', (event) => {
                    event.preventDefault(); // keep the focus in the field
                    const item = event.target.closest('.combo-option');
                    if (item) {
                        choose(shown[[...list.children].indexOf(item)]);
                    }
                });
                toggle.addEventListener('mousedown', (event) => event.preventDefault());
                toggle.addEventListener('click', () => {
                    if (!list.hidden) {
                        close();
                        return;
                    }
                    input.focus();
                    open(true);
                });

                input.mentoraCombo = {
                    options: () => options,
                    setOptions: (next) => { options = next; check(); if (!list.hidden) { draw(false); } },
                    match: (value) => matchOption(value, options),
                    check,
                };
                check();
            });

            // "Area of interest" follows the course: only the virtual assistant roles that fit it (any role for a
            // course that isn't in the list).
            const course = document.getElementById('course');
            const interest = document.getElementById('interest');
            if (!course?.mentoraCombo || !interest?.mentoraCombo || !interest.dataset.courseInterests) {
                return;
            }
            const byCourse = JSON.parse(interest.dataset.courseInterests);
            const all = JSON.parse(interest.dataset.allInterests);
            const hint = document.querySelector('[data-hint-for="interest"]');
            const refresh = () => {
                const chosenCourse = course.mentoraCombo.match(course.value);
                const fits = byCourse[chosenCourse] || all;
                interest.mentoraCombo.setOptions(fits);
                // Keep a role that still fits; clear one that doesn't fit the newly chosen course.
                interest.value = interest.mentoraCombo.match(interest.value) || (chosenCourse ? '' : interest.value);
                interest.mentoraCombo.check();
                if (hint) {
                    hint.textContent = chosenCourse
                        ? fits.length + ' virtual assistant roles fit your course.'
                        : (course.value.trim() === ''
                            ? 'Choose your course first to see the roles that fit it.'
                            : 'Your course isn’t in the list, so you can pick any virtual assistant role.');
                }
            };
            course.addEventListener('change', refresh);
            refresh();
        })();
    </script>
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
