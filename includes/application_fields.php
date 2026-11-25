<?php
/**
 * Qualifications form questions, asked on the workspace "Form" page (includes/application_form.php)
 * after the applicant has an account and has chosen a pathway.
 *
 *   Intern       school, course, required OJT hours, area of interest (a virtual assistant role that fits the
 *                course, see MENTORA_COURSE_INTERESTS) -> straight to training
 *   Employee VA  fill in the form (role, education, experience, optional skills) OR upload a resume (role + file)
 *                OR share a portfolio link (role + link, optional note) -> AI-supported review (ai/resume_review.php)
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
 * $groups: lists of options (shown together in A–Z order, without group headings). $attributes: extra attributes.
 * $sort: false keeps the options in the order given (e.g. grouped by team). $label '' prints no label (give
 * an aria-label in $attributes instead).
 */
function mentora_render_combo_field(string $name, string $label, array $groups, string $value, bool $required, string $placeholder, string $hint = '', array $attributes = [], bool $sort = true): void
{
    $h = static fn($text): string => htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
    $all = array_merge(...array_values($groups));
    $options = $sort ? mentora_sort_options($all) : array_values(array_unique($all));
    $shown = mentora_choice_match($value, $groups) ?? $value;
    ?>
    <?php if ($label !== ''): ?>
        <label for="<?= $h($name) ?>"><?= $h($label) ?><?= $required ? ' *' : '' ?></label>
    <?php endif; ?>
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
/**
 * "Role you're applying for" on the Employee VA form: the VA niches (labels from MENTORA_VA_INTERESTS) each
 * department does, keyed by the department's name in the `departments` table. Every niche appears once.
 */
const MENTORA_DEPARTMENT_ROLES = [
    'Web Developer' => [
        'Web Development', 'Website Maintenance', 'Technical Support (IT Helpdesk)', 'E-commerce Store Support (Shopify)',
    ],
    'Marketing Services' => [
        'Social Media Management', 'SEO (Search Engine Optimization)', 'Content Writing & Copywriting', 'Email Marketing',
        'Online Ads (Facebook / Google Ads)', 'Lead Generation', 'Graphic Design (Canva)', 'Video Editing',
    ],
    'Accounting' => [
        'Accounting Assistant', 'Bookkeeping', 'Accounts Payable & Receivable', 'Bank & Account Reconciliation',
        'Billing & Collections', 'Payroll & Invoicing', 'Expense Tracking & Management', 'Budgeting & Forecasting',
        'Financial Reporting', 'Tax Preparation Assistant', 'Audit Assistant',
    ],
    'Psychotherapist' => [
        'Medical Virtual Assistant (Client intake)', 'Healthcare Scheduling & Records',
    ],
];

/** Admin and support niches every team uses: listed last, under their own heading. */
const MENTORA_GENERAL_VA_ROLES = [
    'General Virtual Assistant (Admin support)', 'Executive Assistant', 'Data Entry', 'Email & Calendar Management',
    'Customer Service Support', 'Research Assistant', 'Transcription', 'Project Management Assistant',
    'HR & Recruitment Assistant', 'Travel & Booking Assistant',
];
const MENTORA_GENERAL_ROLE_GROUP = 'Admin & general support';

/**
 * The role choices, grouped by department: [['name', 'about', 'skills', 'roles' => [...]], ...], A–Z by department,
 * with "Admin & general support" last. Departments come from the database when $pdo is given, so a team HR adds
 * shows up too (with one role, "<name> Virtual Assistant", until it is added to MENTORA_DEPARTMENT_ROLES).
 * Roles of a listed department that no longer exists stay choosable under general support.
 */
function mentora_employee_role_groups(?PDO $pdo = null): array
{
    $departments = $pdo
        ? $pdo->query('SELECT name, description, required_skills FROM departments ORDER BY name')->fetchAll()
        : array_map(static fn(string $name): array => ['name' => $name, 'description' => '', 'required_skills' => ''], array_keys(MENTORA_DEPARTMENT_ROLES));

    $groups = [];
    $used = [];
    foreach ($departments as $department) {
        $roles = null;
        foreach (MENTORA_DEPARTMENT_ROLES as $name => $list) {
            if (strcasecmp($name, (string) $department['name']) === 0) {
                $roles = $list;
                $used[] = $name;
            }
        }
        $groups[] = [
            'name' => (string) $department['name'],
            'about' => trim((string) ($department['description'] ?? '')),
            'skills' => trim((string) ($department['required_skills'] ?? '')),
            'roles' => mentora_sort_options($roles ?? [$department['name'] . ' Virtual Assistant']),
        ];
    }
    $general = MENTORA_GENERAL_VA_ROLES;
    foreach (MENTORA_DEPARTMENT_ROLES as $name => $list) {
        if (!in_array($name, $used, true)) {
            $general = array_merge($general, $list);
        }
    }
    $groups[] = [
        'name' => MENTORA_GENERAL_ROLE_GROUP,
        'about' => 'Admin and support work that every Mentora team needs.',
        'skills' => '',
        'roles' => mentora_sort_options($general),
    ];

    return $groups;
}

/** Every role in $groups (from mentora_employee_role_groups), as one list. */
function mentora_employee_role_options(array $groups): array
{
    return array_merge(...array_column($groups, 'roles'));
}

/** The department a role belongs to ("Bookkeeping" -> "Accounting"), or null for a general support role. */
function mentora_role_department(string $role): ?string
{
    foreach (MENTORA_DEPARTMENT_ROLES as $name => $roles) {
        if (in_array($role, $roles, true)) {
            return $name;
        }
    }
    if (preg_match('/^(.+) Virtual Assistant$/', $role, $match) && !in_array($role, MENTORA_GENERAL_VA_ROLES, true)) {
        return $match[1]; // "<department> Virtual Assistant" for a department HR added
    }

    return null;
}

const MENTORA_APPLICATION_FIELDS = ['submission_mode', 'mobile', 'location', 'desired_role', 'school', 'course', 'required_hours', 'interest', 'education', 'experience_years', 'start_date', 'skills', 'portfolio_url'];

/**
 * Country codes for "Mobile number" on the Employee VA form: code => country. The Philippines comes first and
 * is the default. The number is saved as "<code> <digits>", e.g. "+63 9171234567".
 */
const MENTORA_PHONE_COUNTRIES = [
    '+63' => 'Philippines',
    '+1' => 'United States / Canada',
    '+44' => 'United Kingdom',
    '+61' => 'Australia',
    '+64' => 'New Zealand',
    '+65' => 'Singapore',
    '+852' => 'Hong Kong',
    '+81' => 'Japan',
    '+82' => 'South Korea',
    '+971' => 'United Arab Emirates',
    '+966' => 'Saudi Arabia',
    '+974' => 'Qatar',
];

/**
 * The typeable country code choices: code => "Philippines (+63)". Typing the country, "63" or "+63" picks one
 * (mentora_choice_match also accepts the part in brackets).
 */
function mentora_phone_country_options(): array
{
    $options = [];
    foreach (MENTORA_PHONE_COUNTRIES as $code => $country) {
        $options[$code] = $country . ' (' . $code . ')';
    }

    return $options;
}

/** The country code a typed or chosen country stands for ("philippines", "63", "Philippines (+63)" -> "+63"), or null. */
function mentora_phone_country_code(string $typed): ?string
{
    $options = mentora_phone_country_options();
    $match = mentora_choice_match($typed, [array_values($options)]);

    return $match !== null ? (string) array_search($match, $options, true) : null;
}

/**
 * A saved mobile number split for the form: [country code, number]. "+63 9171234567" -> ['+63', '9171234567'].
 * A number saved before the dropdown ("0917 123 4567") comes back under +63; one with a code that isn't
 * listed keeps its own "+..." (mentora_phone_join then keeps that code).
 */
function mentora_phone_split(string $mobile): array
{
    $mobile = trim($mobile);
    $codes = array_keys(MENTORA_PHONE_COUNTRIES);
    usort($codes, static fn(string $a, string $b): int => strlen($b) <=> strlen($a)); // "+852" before "+8..."
    foreach ($codes as $code) {
        if (str_starts_with($mobile, $code)) {
            return [$code, ltrim(substr($mobile, strlen($code)))];
        }
    }

    return ['+63', $mobile];
}

/**
 * The chosen country code and the typed number as one value to save: "+63" + "0917-123-4567" -> "+63 9171234567".
 * Spaces, dashes and brackets are dropped, so is the leading 0 of a local number and a repeated country code
 * ("639171234567"). A number typed with its own "+..." keeps that code. Returns '' when no digits were typed.
 */
function mentora_phone_join(string $code, string $number): string
{
    $number = trim($number);
    $digits = (string) preg_replace('/\D/', '', $number);
    if ($digits === '') {
        return '';
    }
    if (str_starts_with($number, '+')) {
        return '+' . $digits;
    }
    $countryDigits = ltrim($code, '+');
    if (str_starts_with($digits, $countryDigits) && strlen($digits) > 10) {
        $digits = substr($digits, strlen($countryDigits));
    }
    $digits = ltrim($digits, '0');

    return $digits === '' ? '' : $code . ' ' . $digits;
}

/** A saved mobile number that can be called: a Philippine mobile is 9 + 9 digits; other countries 6 to 14 digits. */
function mentora_phone_valid(string $mobile): bool
{
    if (preg_match('/^\+63 ?(\d+)$/', $mobile, $match)) {
        return (bool) preg_match('/^9\d{9}$/', $match[1]);
    }

    return (bool) preg_match('/^\+\d{1,4} ?\d{6,14}$/', $mobile) && strlen((string) preg_replace('/\D/', '', $mobile)) <= 15;
}

/** Ways an Employee VA can apply (applications.submission_mode). */
const MENTORA_SUBMISSION_MODES = ['form' => 'Form', 'resume' => 'Resume upload', 'portfolio' => 'Portfolio link'];

/**
 * A typed portfolio link made ready to save: "behance.net/maria" -> "https://behance.net/maria".
 * Returns '' for an empty value; anything else is checked by mentora_portfolio_url_valid().
 */
function mentora_portfolio_url_normalize(string $url): string
{
    $url = trim($url);
    if ($url !== '' && !preg_match('~^[a-z][a-z0-9+.-]*://~i', $url)) {
        $url = 'https://' . $url;
    }

    return $url;
}

/** Only real web links (http / https with a host) are accepted, so the saved link is always safe to open. */
function mentora_portfolio_url_valid(string $url): bool
{
    if (mb_strlen($url) > 500 || filter_var($url, FILTER_VALIDATE_URL) === false) {
        return false;
    }
    $parts = parse_url($url);

    return in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
        && str_contains((string) ($parts['host'] ?? ''), '.');
}

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
    if (!isset(MENTORA_SUBMISSION_MODES[$values['submission_mode']])) {
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
    $values['portfolio_url'] = mentora_portfolio_url_normalize($values['portfolio_url']);
    // Employee VA form: the country code and the number box are saved together ("+63 9171234567").
    // An empty or unknown country counts as the Philippines. The number box takes digits only: spaces and dashes
    // are tidied away, but letters or other symbols are kept so the check below rejects them.
    if (array_key_exists('mobile_country', $_POST)) {
        $code = mentora_phone_country_code(mentora_post_string('mobile_country')) ?? '+63';
        $values['mobile'] = preg_match('/[^\d\s\-]/', $values['mobile'])
            ? $code . ' ' . $values['mobile']
            : mentora_phone_join($code, $values['mobile']);
    }

    return $values;
}

/**
 * Validation messages for the fields that apply to $pathway (empty array = valid).
 * $hasResume: an uploaded (or already saved) resume, required when an employee chooses "Upload my resume".
 * $roleOptions: the employee roles that can be chosen (mentora_employee_role_options); null = the built-in list.
 */
function mentora_application_errors(array $values, string $pathway, bool $hasResume = false, ?array $roleOptions = null): array
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
            $errors[] = 'Please choose the employee VA role you want to apply for.';
        } elseif (!in_array($values['desired_role'], $roleOptions ?? mentora_employee_role_options(mentora_employee_role_groups()), true)) {
            $errors[] = 'Please choose a role from the list.';
        }
        if (!isset(MENTORA_SUBMISSION_MODES[$values['submission_mode']])) {
            $errors[] = 'Please choose to fill in the form, upload your resume or share your portfolio link.';
        } elseif ($values['submission_mode'] === 'resume' && !$hasResume) {
            $errors[] = 'Please upload your resume (PDF, Word or text file).';
        } elseif ($values['submission_mode'] === 'portfolio' && $values['portfolio_url'] === '') {
            $errors[] = 'Please add the link to your portfolio.';
        } elseif ($values['submission_mode'] === 'form') {
            if ($values['education'] === '') {
                $errors[] = 'Please choose your highest educational attainment.';
            }
            // Skills and experience is optional (the AI review notes it when it's short or empty).
        }
        if ($values['education'] !== '' && !isset(MENTORA_EDUCATION_LEVELS[$values['education']])) {
            $errors[] = 'Please choose an educational attainment from the list.';
        }
        if ($values['experience_years'] !== '' && (!ctype_digit($values['experience_years']) || (int) $values['experience_years'] > 50)) {
            $errors[] = 'Years of experience must be a whole number from 0 to 50.';
        }
        if ($values['submission_mode'] === 'portfolio' && $values['portfolio_url'] !== '' && !mentora_portfolio_url_valid($values['portfolio_url'])) {
            $errors[] = 'Please enter a valid portfolio link, e.g. https://www.behance.net/yourname.';
        }
        // City / province is required for Employee VAs (optional for interns); the start date is optional for both.
        if ($values['location'] === '') {
            $errors[] = 'Please enter your city or province.';
        }
    }

    // Mobile number: required on both forms (country code + digits, "+63 9171234567").
    if ($values['mobile'] === '') {
        $errors[] = 'Please enter your mobile number.';
    } elseif (!mentora_phone_valid($values['mobile'])) {
        $errors[] = 'Please enter a valid mobile number using numbers only, e.g. 9171234567 for a Philippine number.';
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
        // Kept when they switch to another way to apply, but only a valid link is ever saved.
        'portfolio_url' => !$isIntern && mentora_portfolio_url_valid($values['portfolio_url']) ? $values['portfolio_url'] : null,
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
 * $resume: the employee's saved resume (documents row) or null. $roleGroups: the employee role choices
 * (mentora_employee_role_groups($pdo)); empty = the built-in list. Used by includes/application_form.php.
 */
function mentora_render_application_fields(array $values, string $pathway, ?array $resume = null, array $roleGroups = []): void
{
    $h = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    $isIntern = $pathway === 'internship';
    $roleGroups = $roleGroups ?: mentora_employee_role_groups();
    // The team of the role chosen now, for the hint under the dropdown.
    $roleGroup = null;
    foreach ($roleGroups as $group) {
        if (in_array($values['desired_role'], $group['roles'], true)) {
            $roleGroup = $group;
        }
    }
    // An older typed answer that isn't in the list: they choose again, and are told what they wrote before.
    $oldRole = !$isIntern && $values['desired_role'] !== '' && $roleGroup === null ? $values['desired_role'] : '';
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
                <label>
                    <input type="radio" name="submission_mode" value="portfolio" data-mode-toggle<?= $values['submission_mode'] === 'portfolio' ? ' checked' : '' ?>>
                    <span><strong>Share my portfolio link</strong><small>Google Drive, Behance, Canva, your website or LinkedIn.</small></span>
                </label>
            </div>
            <small>Whichever you choose, an AI-supported review summarizes your skills and matches you to the department that needs them; the HR team makes the decision.</small>
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
                <?php
                // Typeable dropdown, listed team by team (same order as mentora_employee_role_groups). The team the
                // chosen role belongs to, what it does and the skills it looks for are shown under the field.
                $roleTeams = [];
                foreach ($roleGroups as $group) {
                    $roleTeams[$group['name']] = ['about' => $group['about'], 'skills' => $group['skills'], 'roles' => $group['roles']];
                }
                mentora_render_combo_field('desired_role', 'Role you’re applying for', array_column($roleGroups, 'roles'), $values['desired_role'], true,
                    'Type or choose a role, e.g. Bookkeeping or Social Media', '', [
                        'data-strict' => 'Please choose a role from the list.',
                        'data-empty-text' => 'No matching role. Choose one from the list.',
                        'aria-describedby' => 'desired_role-hint',
                        'data-role-teams' => json_encode($roleTeams, JSON_UNESCAPED_UNICODE),
                    ], false);
                ?>
                <small id="desired_role-hint" class="role-hint" data-role-hint>
                    <?php if ($roleGroup): ?>
                        <strong><?= $h($roleGroup['name']) ?></strong><?= $roleGroup['about'] !== '' ? ': ' . $h($roleGroup['about']) : '' ?><?= $roleGroup['skills'] !== '' ? '. Skills this team looks for: ' . $h($roleGroup['skills']) . '.' : '' ?>
                    <?php elseif ($oldRole !== ''): ?>
                        “<?= $h($oldRole) ?>” isn’t one of the roles. Please choose the closest one from the list.
                    <?php else: ?>
                        Type part of a role to find it, or open the list to browse them all.
                    <?php endif; ?>
                </small>
            </div>
            <div class="field full" data-mode="resume">
                <label for="resume">Resume<?= $resume ? '' : ' *' ?></label>
                <input id="resume" type="file" name="resume" accept=".pdf,.docx,.doc,.txt,application/pdf,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/msword,text/plain">
                <small><?= $resume
                    ? 'Saved: ' . $h($resume['original_name']) . '. Choose a new file only to replace it.'
                    : 'PDF, Word (.docx / .doc) or text, up to 10 MB. A text-based PDF works best; scanned images can’t be read by the AI.' ?></small>
            </div>
            <div class="field full" data-mode="portfolio">
                <label for="portfolio_url">Portfolio link *</label>
                <?php // type="text" (checked on the server): a hidden url field with a typo would block the other ways to apply. ?>
                <input id="portfolio_url" type="text" name="portfolio_url" spellcheck="false" autocapitalize="off" maxlength="500" inputmode="url" autocomplete="url"
                    placeholder="https://www.behance.net/yourname" value="<?= $h($values['portfolio_url']) ?>">
                <small>Make sure anyone with the link can open it (for Google Drive: Share → “Anyone with the link”). The HR team opens it to see your work.</small>
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
            <?php
            // Both pathways (required): a typeable country code beside the number, saved together ("+63 9171234567").
            [$mobileCode, $mobileNumber] = mentora_phone_split($values['mobile']);
            $countryOptions = mentora_phone_country_options(); ?>
            <label for="mobile">Mobile number *</label>
            <div class="phone-field">
                <?php mentora_render_combo_field('mobile_country', '', [array_values($countryOptions)], $countryOptions[$mobileCode], true, 'Country', '', [
                    'aria-label' => 'Country code',
                    'data-strict' => 'Please choose a country code from the list.',
                    'data-empty-text' => 'No matching country. Choose one from the list.',
                ], false); ?>
                <?php // Digits only (the page script blocks anything else and checks the length as you type). ?>
                <input id="mobile" type="tel" name="mobile" required maxlength="16" inputmode="numeric" pattern="[0-9]*"
                    autocomplete="tel-national" placeholder="9171234567" value="<?= $h(preg_replace('/\D/', '', $mobileNumber)) ?>"
                    aria-describedby="mobile-hint mobile-error" data-phone-number>
            </div>
            <small id="mobile-hint">Numbers only. Philippines: the 10 digits after +63, starting with 9 (e.g. 9171234567).</small>
            <small id="mobile-error" class="field-error" aria-live="polite"></small>
        </div>
        <div class="field">
            <?php mentora_render_combo_field('location', 'City / province', MENTORA_LOCATION_OPTIONS, $values['location'], !$isIntern, 'Type or choose your city or province'); ?>
        </div>
        <div class="field">
            <label for="start_date">Preferred start date <span class="field-optional">(optional)</span></label>
            <input id="start_date" type="date" name="start_date" value="<?= $h($values['start_date']) ?>" aria-describedby="start_date-hint">
            <small id="start_date-hint">Leave it empty if you can start anytime.</small>
        </div>
        <?php // Optional everywhere. Employee VA: shown with the form and the portfolio link (it helps the AI match skills). ?>
        <div class="field full"<?= $isIntern ? '' : ' data-mode="form portfolio"' ?>>
            <label for="skills">Skills and experience <span class="field-optional">(optional)</span></label>
            <textarea id="skills" name="skills" rows="6" maxlength="5000" placeholder="<?= $isIntern
                ? 'Tools you can use, school projects, organisations, and what you hope to learn during your internship.'
                : 'Your work experience, tools and software you use, languages, and the kind of clients you have supported.' ?>"<?= $isIntern ? '' : ' aria-describedby="skills-hint"' ?>><?= $h($values['skills']) ?></textarea>
            <?php if (!$isIntern): ?>
                <small id="skills-hint">A few lines about your skills and tools help the AI match you with the right department.</small>
            <?php endif; ?>
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
                        empty.textContent = input.dataset.strict ? (input.dataset.emptyText || 'No matching role. Choose one from the list.') : 'Not in the list. What you typed will be kept.';
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
            // Show the fields for the chosen way to apply (form, resume or portfolio link). data-mode can list
            // several ways ("form portfolio"). Without JavaScript every field stays visible.
            (() => {
                const toggles = document.querySelectorAll('[data-mode-toggle]');
                const portfolio = document.getElementById('portfolio_url');
                const update = () => {
                    const mode = document.querySelector('[data-mode-toggle]:checked')?.value || 'form';
                    document.querySelectorAll('[data-mode]').forEach((field) => {
                        field.hidden = !field.dataset.mode.split(' ').includes(mode);
                    });
                    // The link is required only for this way to apply (the browser then asks for it before sending).
                    if (portfolio) {
                        portfolio.required = mode === 'portfolio';
                    }
                };
                // "behance.net/name" is fine: show the https:// the server adds.
                portfolio?.addEventListener('blur', () => {
                    const value = portfolio.value.trim();
                    if (value !== '' && !/^[a-z][a-z0-9+.-]*:\/\//i.test(value)) {
                        portfolio.value = 'https://' + value;
                    }
                });
                toggles.forEach((toggle) => toggle.addEventListener('change', update));
                update();
            })();

            // Under the role dropdown: the team the chosen role belongs to, what it does and the skills it looks for.
            (() => {
                const role = document.getElementById('desired_role');
                const hint = document.querySelector('[data-role-hint]');
                if (!role?.dataset.roleTeams || !hint) {
                    return;
                }
                const teams = JSON.parse(role.dataset.roleTeams);
                const show = () => {
                    const chosen = role.mentoraCombo ? role.mentoraCombo.match(role.value) : role.value;
                    const name = Object.keys(teams).find((team) => teams[team].roles.includes(chosen));
                    if (!name) {
                        hint.textContent = 'Type part of a role to find it, or open the list to browse them all.';
                        return;
                    }
                    const team = document.createElement('strong');
                    team.textContent = name;
                    const about = teams[name].about ? ': ' + teams[name].about : '';
                    const skills = teams[name].skills ? '. Skills this team looks for: ' + teams[name].skills + '.' : '';
                    hint.replaceChildren(team, document.createTextNode(about + skills));
                };
                role.addEventListener('change', show); // a role picked from the list, or typed and matched on leaving the field
                role.addEventListener('input', () => { if (role.value.trim() === '') show(); });
            })();
        </script>
    <?php endif; ?>
    <script>
            // Both pathways. Mobile number: digits only, checked as you type against the chosen country (same rules as
            // mentora_phone_valid on the server). Philippines: 10 digits starting with 9; elsewhere 6 to 14 digits.
            (() => {
                const number = document.querySelector('[data-phone-number]');
                const country = document.getElementById('mobile_country');
                const error = document.getElementById('mobile-error');
                if (!number || !country || !error) {
                    return;
                }
                const chosen = () => (country.mentoraCombo ? country.mentoraCombo.match(country.value) : null) || 'Philippines (+63)';
                const countryDigits = () => (chosen().match(/\(\+(\d+)\)$/) || [])[1] || '63';
                const isPhilippines = () => countryDigits() === '63';
                const maxDigits = () => (isPhilippines() ? 10 : 14);

                // Keep only digits, and tidy what people often paste: "0917…" -> "917…", "63917…" -> "917…".
                const clean = () => {
                    let digits = number.value.replace(/\D/g, '');
                    const code = countryDigits();
                    if (digits.startsWith(code) && digits.length > maxDigits()) {
                        digits = digits.slice(code.length);
                    }
                    if (isPhilippines()) {
                        digits = digits.replace(/^0+/, '');
                    }
                    digits = digits.slice(0, maxDigits());
                    if (number.value !== digits) {
                        number.value = digits;
                    }
                };
                const problem = () => {
                    const digits = number.value;
                    if (digits === '') {
                        return 'Please enter your mobile number.';
                    }
                    if (isPhilippines()) {
                        if (!digits.startsWith('9')) {
                            return 'Philippine mobile numbers start with 9, e.g. 9171234567.';
                        }
                        if (digits.length < 10) {
                            const left = 10 - digits.length;
                            return `${left} more digit${left === 1 ? '' : 's'} needed (10 in total).`;
                        }
                        return '';
                    }
                    return digits.length < 6 ? 'Please enter at least 6 digits.' : '';
                };

                // Messages show once the field has been left (or the form sent); a wrong first digit shows right away.
                let touched = number.value !== '';
                const check = () => {
                    const message = problem();
                    const show = message !== '' && (touched || (number.value !== '' && !message.includes('more digit')));
                    number.setCustomValidity(message);
                    number.setAttribute('aria-invalid', show ? 'true' : 'false');
                    number.classList.toggle('is-valid', message === '');
                    error.textContent = show ? message : '';
                };

                // Block letters and symbols as they're typed (pasted text is cleaned instead).
                number.addEventListener('beforeinput', (event) => {
                    if (event.inputType === 'insertText' && event.data && /\D/.test(event.data)) {
                        event.preventDefault();
                    }
                });
                number.addEventListener('input', () => { clean(); check(); });
                number.addEventListener('blur', () => { touched = true; check(); });
                number.addEventListener('invalid', () => { touched = true; check(); }); // the browser stopped the form
                country.addEventListener('change', () => { clean(); check(); });
                clean();
                check();
            })();
    </script>
    <?php
}
