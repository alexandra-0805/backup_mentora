<?php
/**
 * Shared Settings experience for the admin, agent, employee and intern workspaces.
 *
 * Expects, from the including settings page:
 *   $settingsRole    'admin' | 'agent' | 'employee' | 'intern'
 *   $settingsAccount list of account sections:
 *       ['key', 'label', 'i18n', 'icon', 'summary', 'rows' => [[i18nKey, label, value], ...],
 *        'note' => [i18nKey, text] (optional), 'link' => [i18nKey, text, href] (optional)]
 *
 * Preferences are applied live and saved in this browser through window.MentoraSettings
 * (includes/workspace_theme_runtime.php). Styles: assets/css/settings.css.
 */
$settingsRole = $settingsRole ?? 'admin';
$settingsAccount = $settingsAccount ?? [];
$settingsRoot = str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/applicant/') ? '../../' : '../';
$se = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$isApplicant = in_array($settingsRole, ['employee', 'intern'], true);

$settingsIcons = [
    'moon' => '<path d="M20 14.5A8 8 0 0 1 9.5 4a8 8 0 1 0 10.5 10.5Z"/>',
    'sun' => '<circle cx="12" cy="12" r="4"/><path d="M12 2.5v2M12 19.5v2M2.5 12h2M19.5 12h2M5.3 5.3l1.4 1.4M17.3 17.3l1.4 1.4M5.3 18.7l1.4-1.4M17.3 6.7l1.4-1.4"/>',
    'monitor' => '<rect x="3" y="4" width="18" height="12" rx="2"/><path d="M8 20h8M12 16v4"/>',
    'palette' => '<path d="M12 3a9 9 0 0 0 0 18c1.1 0 1.7-.8 1.7-1.7 0-.5-.2-.9-.5-1.2-.3-.3-.5-.7-.5-1.2 0-.9.8-1.7 1.7-1.7H16a5 5 0 0 0 5-5C21 6.4 17 3 12 3Z"/><circle cx="7.5" cy="11" r="1"/><circle cx="10" cy="7" r="1"/><circle cx="14.5" cy="7" r="1"/>',
    'globe' => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/>',
    'bell' => '<path d="M6 16V11a6 6 0 0 1 12 0v5l1.5 2h-15Z"/><path d="M10 20a2 2 0 0 0 4 0"/>',
    'lock' => '<rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/>',
    'shield' => '<path d="M12 3 5 6v5c0 4.5 3 8.3 7 10 4-1.7 7-5.5 7-10V6Z"/>',
    'user' => '<circle cx="12" cy="8" r="4"/><path d="M4 20a8 8 0 0 1 16 0"/>',
    'building' => '<rect x="4" y="3" width="16" height="18" rx="1"/><path d="M8 7h2M14 7h2M8 11h2M14 11h2M8 15h2M14 15h2M10 21v-3h4v3"/>',
    'sparkle' => '<path d="M12 3v4M12 17v4M3 12h4M17 12h4M6 6l2.5 2.5M15.5 15.5 18 18M6 18l2.5-2.5M15.5 8.5 18 6"/>',
    'chevron' => '<path d="m9 6 6 6-6 6"/>',
    'back' => '<path d="m15 6-6 6 6 6"/>',
    'check' => '<path d="m5 12 5 5 9-10"/>',
    'search' => '<circle cx="11" cy="11" r="6.5"/><path d="m16 16 4.5 4.5"/>',
];
$settingsIcon = static fn(string $name): string =>
    '<svg class="settings-svg" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' . ($settingsIcons[$name] ?? '') . '</svg>';

$accents = [
    'teal' => ['Mentora teal', '#0b625d'],
    'ocean' => ['Ocean', '#155e85'],
    'forest' => ['Forest', '#2f6b35'],
    'plum' => ['Plum', '#5e3a75'],
    'amber' => ['Amber', '#8a5a12'],
];
$textSizes = ['small' => 'Small', 'default' => 'Default', 'large' => 'Large', 'xlarge' => 'Extra large'];
$uiStyles = ['rounded' => 'Rounded', 'soft' => 'Soft', 'crisp' => 'Crisp'];
// Languages with a full translation of this page (English US is the primary language).
$languages = [
    'en-US' => ['EN', 'English (US)', 'United States'],
    'en-GB' => ['EN', 'English (UK)', 'United Kingdom'],
    'fil' => ['FIL', 'Filipino', 'Philippines'],
    'es' => ['ES', 'Español', 'Spanish'],
];
// Every other language people can choose (BCP 47 code => English name). Native names are
// filled in by the browser (Intl.DisplayNames); the English name is the fallback and subtitle.
$worldLanguages = [
    'af' => 'Afrikaans', 'ak' => 'Akan (Twi)', 'sq' => 'Albanian', 'am' => 'Amharic', 'ar' => 'Arabic',
    'hy' => 'Armenian', 'as' => 'Assamese', 'ay' => 'Aymara', 'az' => 'Azerbaijani', 'bm' => 'Bambara',
    'eu' => 'Basque', 'be' => 'Belarusian', 'bn' => 'Bengali', 'bho' => 'Bhojpuri', 'bik' => 'Bikol',
    'bs' => 'Bosnian', 'br' => 'Breton', 'bg' => 'Bulgarian', 'my' => 'Burmese', 'yue' => 'Cantonese',
    'ca' => 'Catalan', 'ceb' => 'Cebuano', 'ckb' => 'Central Kurdish', 'ny' => 'Chichewa',
    'zh-Hans' => 'Chinese (Simplified)', 'zh-Hant' => 'Chinese (Traditional)', 'co' => 'Corsican',
    'hr' => 'Croatian', 'cs' => 'Czech', 'da' => 'Danish', 'dv' => 'Dhivehi', 'doi' => 'Dogri',
    'nl' => 'Dutch', 'dz' => 'Dzongkha', 'en-AU' => 'English (Australia)', 'en-CA' => 'English (Canada)',
    'en-IN' => 'English (India)', 'eo' => 'Esperanto', 'et' => 'Estonian', 'ee' => 'Ewe', 'fo' => 'Faroese',
    'fj' => 'Fijian', 'fi' => 'Finnish', 'fr' => 'French', 'fr-CA' => 'French (Canada)', 'fy' => 'Frisian',
    'ff' => 'Fula', 'gl' => 'Galician', 'ka' => 'Georgian', 'de' => 'German', 'el' => 'Greek',
    'gn' => 'Guarani', 'gu' => 'Gujarati', 'ht' => 'Haitian Creole', 'ha' => 'Hausa', 'haw' => 'Hawaiian',
    'he' => 'Hebrew', 'hil' => 'Hiligaynon', 'hi' => 'Hindi', 'hmn' => 'Hmong', 'hu' => 'Hungarian',
    'is' => 'Icelandic', 'ig' => 'Igbo', 'ilo' => 'Ilocano', 'id' => 'Indonesian', 'ga' => 'Irish',
    'it' => 'Italian', 'ja' => 'Japanese', 'jv' => 'Javanese', 'pam' => 'Kapampangan', 'kn' => 'Kannada',
    'kk' => 'Kazakh', 'km' => 'Khmer', 'rw' => 'Kinyarwanda', 'ko' => 'Korean', 'kri' => 'Krio',
    'ku' => 'Kurdish (Kurmanji)', 'ky' => 'Kyrgyz', 'lo' => 'Lao', 'la' => 'Latin', 'lv' => 'Latvian',
    'ln' => 'Lingala', 'lt' => 'Lithuanian', 'lg' => 'Luganda', 'lb' => 'Luxembourgish', 'mk' => 'Macedonian',
    'mai' => 'Maithili', 'mg' => 'Malagasy', 'ms' => 'Malay', 'ml' => 'Malayalam', 'mt' => 'Maltese',
    'mi' => 'Māori', 'mr' => 'Marathi', 'mni' => 'Meitei (Manipuri)', 'lus' => 'Mizo', 'mn' => 'Mongolian',
    'ne' => 'Nepali', 'nb' => 'Norwegian (Bokmål)', 'nn' => 'Norwegian (Nynorsk)', 'or' => 'Odia',
    'om' => 'Oromo', 'pag' => 'Pangasinan', 'ps' => 'Pashto', 'fa' => 'Persian', 'pl' => 'Polish',
    'pt-BR' => 'Portuguese (Brazil)', 'pt-PT' => 'Portuguese (Portugal)', 'pa' => 'Punjabi', 'qu' => 'Quechua',
    'ro' => 'Romanian', 'rm' => 'Romansh', 'ru' => 'Russian', 'sm' => 'Samoan', 'sa' => 'Sanskrit',
    'gd' => 'Scottish Gaelic', 'nso' => 'Sepedi', 'sr' => 'Serbian', 'st' => 'Sesotho', 'sn' => 'Shona',
    'sd' => 'Sindhi', 'si' => 'Sinhala', 'sk' => 'Slovak', 'sl' => 'Slovenian', 'so' => 'Somali',
    'es-419' => 'Spanish (Latin America)', 'es-MX' => 'Spanish (Mexico)', 'su' => 'Sundanese',
    'sw' => 'Swahili', 'sv' => 'Swedish', 'tg' => 'Tajik', 'ta' => 'Tamil', 'tt' => 'Tatar', 'te' => 'Telugu',
    'th' => 'Thai', 'ti' => 'Tigrinya', 'ts' => 'Tsonga', 'tn' => 'Tswana', 'tr' => 'Turkish',
    'tk' => 'Turkmen', 'uk' => 'Ukrainian', 'ur' => 'Urdu', 'ug' => 'Uyghur', 'uz' => 'Uzbek',
    'vi' => 'Vietnamese', 'war' => 'Waray', 'cy' => 'Welsh', 'wo' => 'Wolof', 'xh' => 'Xhosa',
    'yi' => 'Yiddish', 'yo' => 'Yoruba', 'zu' => 'Zulu',
];
asort($worldLanguages, SORT_NATURAL | SORT_FLAG_CASE);
$languageBadge = static fn(string $code): string => strtoupper(substr(explode('-', $code)[0], 0, 3));
$translatedLanguageCodes = array_keys($languages);
$topicsByRole = [
    'admin' => [
        ['topicA', 'topic_newApplications', 'New applications', 'When someone applies or updates their form'],
        ['topicB', 'topic_attendance', 'Attendance exceptions', 'Missing time-outs'],
        ['topicC', 'topic_reports', 'Report reminders', 'When scheduled reports are ready'],
    ],
    'agent' => [
        ['topicA', 'topic_evaluations', 'Evaluation reminders', 'Reviews that are due or overdue'],
        ['topicB', 'topic_training', 'Training updates', 'Module completions and new assignments'],
        ['topicC', 'topic_documents', 'Document updates', 'New or changed team documents'],
    ],
    'employee' => [
        ['topicA', 'topic_onboarding', 'Training & onboarding', 'New modules and onboarding checks'],
        ['topicB', 'topic_department', 'Department & manager updates', 'Assignments, schedules and feedback'],
        ['topicC', 'topic_dailyReport', 'Daily work report reminders', 'A nudge before the end of your shift'],
    ],
    'intern' => [
        ['topicA', 'topic_tasks', 'Assigned tasks', 'New work from your supervising agent'],
        ['topicB', 'topic_hours', 'Daily activity & hours', 'Report reminders and approved hours'],
        ['topicC', 'topic_trainingReminders', 'Training reminders', 'Upcoming modules and assessments'],
    ],
];
$topics = $topicsByRole[$settingsRole] ?? $topicsByRole['admin'];
$privacyExtra = $isApplicant
    ? ['privacy_shareProgress', 'Share progress with my mentor', 'Your agent can see your training and report progress']
    : ['privacy_showEmail', 'Show my email on my profile', 'People in your workspace can contact you directly'];
$visibilities = ['workspace' => 'Everyone in the workspace', 'team' => 'My team only', 'hr' => 'HR and admins only'];
$autoSignOuts = ['off' => 'Never', '15' => 'After 15 minutes', '30' => 'After 30 minutes', '60' => 'After 60 minutes'];

// One list row that opens a detail view ($iconHtml replaces the icon, e.g. with the user's avatar).
$settingsRow = static function (
    string $view,
    string $icon,
    string $i18n,
    string $label,
    string $summary = '',
    string $iconHtml = ''
) use ($se, $settingsIcon): string {
    return '<a class="settings-row" href="#' . $se($view) . '" data-open-view="' . $se($view) . '">'
        . '<span class="settings-row-icon">' . ($iconHtml !== '' ? $iconHtml : $settingsIcon($icon)) . '</span>'
        . '<span class="settings-row-copy"><strong data-i18n="' . $se($i18n) . '">' . $se($label) . '</strong>'
        . '<small data-summary="' . $se($view) . '">' . $se($summary) . '</small></span>'
        . '<span class="settings-row-end">' . $settingsIcon('chevron') . '</span></a>';
};

// Header of a detail view (Back button is shown on narrow screens only).
$settingsViewHeader = static function (string $i18n, string $title) use ($se, $settingsIcon): string {
    return '<header class="settings-view-header">'
        . '<a class="settings-back" href="#" aria-label="Back to settings">' . $settingsIcon('back') . '<span data-i18n="back">Back</span></a>'
        . '<h2 data-i18n="' . $se($i18n) . '">' . $se($title) . '</h2></header>';
};

// A row with an on/off switch.
$settingsSwitchRow = static function (string $setting, string $i18n, string $label, string $hint, string $icon = '') use ($se, $settingsIcon): string {
    return '<label class="settings-row settings-switch-row">'
        . ($icon !== '' ? '<span class="settings-row-icon">' . $settingsIcon($icon) . '</span>' : '')
        . '<span class="settings-row-copy"><strong data-i18n="' . $se($i18n) . '">' . $se($label) . '</strong>'
        . '<small data-i18n="' . $se($i18n) . 'Hint">' . $se($hint) . '</small></span>'
        . '<span class="settings-switch"><input type="checkbox" role="switch" data-setting="' . $se($setting) . '">'
        . '<span aria-hidden="true"></span></span></label>';
};

// A list of single-choice rows with a check mark on the selected one.
$settingsChoiceRows = static function (string $setting, array $options, string $prefix) use ($se, $settingsIcon): string {
    $html = '';
    foreach ($options as $value => $label) {
        $html .= '<button class="settings-row settings-choice" type="button" data-setting="' . $se($setting) . '" data-value="' . $se($value) . '">'
            . '<span class="settings-row-copy"><strong data-i18n="' . $se($prefix . $value) . '">' . $se($label) . '</strong></span>'
            . '<span class="settings-check">' . $settingsIcon('check') . '</span></button>';
    }
    return $html;
};
?>
<div class="settings-shell" data-settings>
    <div class="settings-list">
        <section class="settings-card">
            <?= $settingsSwitchRow('theme', 'darkMode', 'Dark mode', 'Easier on the eyes at night', 'moon') ?>
        </section>

        <p class="settings-group-label" data-i18n="groupPersonalization">Personalization</p>
        <nav class="settings-card" aria-label="Personalization">
            <?= $settingsRow('appearance', 'palette', 'appearance', 'Appearance') ?>
            <?= $settingsRow('language', 'globe', 'language', 'Language') ?>
        </nav>

        <p class="settings-group-label" data-i18n="groupAlerts">Alerts</p>
        <nav class="settings-card" aria-label="Alerts">
            <?= $settingsRow('notifications', 'bell', 'notifications', 'Notifications') ?>
        </nav>

        <p class="settings-group-label" data-i18n="groupPrivacy">Privacy &amp; security</p>
        <nav class="settings-card" aria-label="Privacy and security">
            <?= $settingsRow('privacy', 'lock', 'privacy', 'Privacy') ?>
            <?= $settingsRow('security', 'shield', 'security', 'Security') ?>
        </nav>

        <?php if ($settingsAccount): ?>
            <p class="settings-group-label" data-i18n="groupAccount">Account</p>
            <nav class="settings-card" aria-label="Account">
                <?php foreach ($settingsAccount as $section): ?>
                    <?= $settingsRow(
                        $section['key'],
                        $section['icon'],
                        $section['i18n'],
                        $section['label'],
                        $section['summary'] ?? '',
                        isset($section['avatar']) ? mentora_avatar($section['avatar']['url'], $section['avatar']['initials']) : ''
                    ) ?>
                <?php endforeach; ?>
            </nav>
        <?php endif; ?>

        <p class="settings-footnote" data-i18n="savedNote">Settings are saved on this device and browser.</p>
    </div>

    <div class="settings-detail">
        <!-- Appearance -->
        <section class="settings-view" data-view="appearance">
            <?= $settingsViewHeader('appearance', 'Appearance') ?>

            <div class="settings-card settings-preview">
                <span class="settings-preview-icon"><?= $settingsIcon('sparkle') ?></span>
                <div>
                    <strong data-i18n="previewTitle">Weekly summary</strong>
                    <small data-i18n="previewText">Your plan for the week is ready.</small>
                </div>
                <span class="settings-preview-button" data-i18n="previewOpen">Open</span>
            </div>

            <div class="settings-group-head">
                <p class="settings-group-label" data-i18n="mode">Mode</p>
                <span class="settings-current" data-current="theme"></span>
            </div>
            <div class="settings-card settings-tiles settings-tiles-3">
                <?php foreach (['light' => ['Light', 'sun'], 'dark' => ['Dark', 'moon'], 'system' => ['Auto (device)', 'monitor']] as $value => [$label, $icon]): ?>
                    <button class="settings-tile settings-mode" type="button" data-setting="theme" data-value="<?= $se($value) ?>">
                        <?= $settingsIcon($icon) ?>
                        <small data-i18n="theme_<?= $se($value) ?>"><?= $se($label) ?></small>
                    </button>
                <?php endforeach; ?>
            </div>
            <p class="settings-footnote" data-i18n="modeHint">Auto follows your device's light or dark setting. If it doesn't change, set your browser's appearance to "System default" (in Edge: Settings &gt; Appearance &gt; Overall appearance).</p>

            <div class="settings-group-head">
                <p class="settings-group-label" data-i18n="themeColor">Theme color</p>
                <span class="settings-current" data-current="accent"></span>
            </div>
            <div class="settings-card settings-swatches">
                <?php foreach ($accents as $value => [$label, $hex]): ?>
                    <button class="settings-swatch" type="button" data-setting="accent" data-value="<?= $se($value) ?>"
                        style="--swatch: <?= $se($hex) ?>" aria-label="<?= $se($label) ?>" title="<?= $se($label) ?>">
                        <span class="sr-only" data-i18n="accent_<?= $se($value) ?>"><?= $se($label) ?></span>
                    </button>
                <?php endforeach; ?>
            </div>

            <div class="settings-group-head">
                <p class="settings-group-label" data-i18n="textSize">Text size</p>
                <span class="settings-current" data-current="textSize"></span>
            </div>
            <div class="settings-card settings-tiles settings-tiles-4">
                <?php foreach ($textSizes as $value => $label): ?>
                    <button class="settings-tile settings-size-<?= $se($value) ?>" type="button" data-setting="textSize" data-value="<?= $se($value) ?>">
                        <span aria-hidden="true">Aa</span>
                        <span class="sr-only" data-i18n="size_<?= $se($value) ?>"><?= $se($label) ?></span>
                    </button>
                <?php endforeach; ?>
            </div>

            <div class="settings-group-head">
                <p class="settings-group-label" data-i18n="uiStyle">UI style</p>
                <span class="settings-current" data-current="uiStyle"></span>
            </div>
            <div class="settings-card settings-tiles settings-tiles-3">
                <?php foreach ($uiStyles as $value => $label): ?>
                    <button class="settings-tile settings-style settings-style-<?= $se($value) ?>"
                        type="button"
                        data-setting="uiStyle"
                        data-value="<?= $se($value) ?>">
                        <span class="settings-style-shape" aria-hidden="true"></span>
                        <small data-i18n="style_<?= $se($value) ?>"><?= $se($label) ?></small>
                    </button>
                <?php endforeach; ?>
            </div>
        </section>

        <!-- Language -->
        <section class="settings-view" data-view="language" hidden>
            <?= $settingsViewHeader('language', 'Language') ?>

            <label class="settings-search">
                <?= $settingsIcon('search') ?>
                <input type="search"
                    data-language-search
                    placeholder="Search languages"
                    data-i18n-placeholder="searchLanguages"
                    autocomplete="off"
                    aria-label="Search languages">
            </label>

            <div data-language-group>
                <p class="settings-group-label" data-i18n="groupTranslated">Translated</p>
                <div class="settings-card">
                    <?php foreach ($languages as $value => [$code, $name, $region]): ?>
                        <button class="settings-row settings-choice" type="button" data-setting="language" data-value="<?= $se($value) ?>"
                            data-search="<?= $se(strtolower($name . ' ' . $region . ' ' . $value)) ?>">
                            <span class="settings-code"><?= $se($code) ?></span>
                            <span class="settings-row-copy"><strong lang="<?= $se($value) ?>"><?= $se($name) ?></strong><small><?= $se($region) ?></small></span>
                            <span class="settings-check"><?= $settingsIcon('check') ?></span>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>

            <div data-language-group>
                <p class="settings-group-label" data-i18n="groupAllLanguages">All languages</p>
                <div class="settings-card settings-collapsible is-collapsed" id="settings-all-languages" data-language-collapsible>
                    <?php foreach ($worldLanguages as $value => $englishName): ?>
                        <button class="settings-row settings-choice" type="button" data-setting="language" data-value="<?= $se($value) ?>"
                            data-search="<?= $se(strtolower($englishName . ' ' . $value)) ?>">
                            <span class="settings-code"><?= $se($languageBadge($value)) ?></span>
                            <span class="settings-row-copy"><strong lang="<?= $se($value) ?>" data-native-name><?= $se($englishName) ?></strong><small><?= $se($englishName) ?></small></span>
                            <span class="settings-check"><?= $settingsIcon('check') ?></span>
                        </button>
                    <?php endforeach; ?>
                    <button class="settings-row settings-collapse-toggle"
                        type="button"
                        aria-expanded="false"
                        aria-controls="settings-all-languages"
                        data-language-toggle>
                        <span class="settings-row-copy"><strong data-language-toggle-label>Show all <?= count($worldLanguages) ?> languages</strong></span>
                        <span class="settings-collapse-chevron"><?= $settingsIcon('chevron') ?></span>
                    </button>
                </div>
            </div>

            <p class="settings-footnote" data-language-empty data-i18n="noLanguages" hidden>No languages match your search.</p>
            <p class="settings-footnote" data-i18n="languageNote">English (US) is the primary language. Languages under Translated also change this settings page; for any other language your choice is saved, and Mentora shows English (US) until its translation is ready.</p>
        </section>

        <!-- Notifications -->
        <section class="settings-view" data-view="notifications" hidden>
            <?= $settingsViewHeader('notifications', 'Notifications') ?>
            <p class="settings-group-label" data-i18n="groupInApp">In the workspace</p>
            <div class="settings-card">
                <?= $settingsSwitchRow('badges', 'badges', 'Unread badges', 'Show counts on the message and notification icons', 'bell') ?>
                <?= $settingsSwitchRow('previews', 'previews', 'Message previews', 'Show message text in the Messages panel', 'sparkle') ?>
            </div>
            <p class="settings-group-label" data-i18n="groupTopics">Notify me about</p>
            <div class="settings-card">
                <?php foreach ($topics as [$setting, $i18n, $label, $hint]): ?>
                    <?= $settingsSwitchRow($setting, $i18n, $label, $hint) ?>
                <?php endforeach; ?>
            </div>
        </section>

        <!-- Privacy -->
        <section class="settings-view" data-view="privacy" hidden>
            <?= $settingsViewHeader('privacy', 'Privacy') ?>
            <p class="settings-group-label" data-i18n="groupVisibility">Who can see my profile</p>
            <div class="settings-card">
                <?= $settingsChoiceRows('visibility', $visibilities, 'vis_') ?>
            </div>
            <p class="settings-group-label" data-i18n="groupActivity">Activity &amp; sharing</p>
            <div class="settings-card">
                <?= $settingsSwitchRow('onlineStatus', 'onlineStatus', "Show when I'm active", "Let teammates see that you're online") ?>
                <?= $settingsSwitchRow('privacyExtra', $privacyExtra[0], $privacyExtra[1], $privacyExtra[2]) ?>
            </div>
        </section>

        <!-- Security -->
        <section class="settings-view" data-view="security" hidden>
            <?= $settingsViewHeader('security', 'Security') ?>
            <div class="settings-card">
                <a class="settings-row" href="<?= $se($settingsRoot) ?>auth/forgot_password.php">
                    <span class="settings-row-icon"><?= $settingsIcon('lock') ?></span>
                    <span class="settings-row-copy"><strong data-i18n="password">Password</strong><small data-i18n="passwordHint">Change it with a code sent to your email</small></span>
                    <span class="settings-pill" data-i18n="passwordChange">Change</span>
                </a>
                <div class="settings-row">
                    <span class="settings-row-icon"><?= $settingsIcon('shield') ?></span>
                    <span class="settings-row-copy"><strong data-i18n="twoStep">Two-step verification</strong><small data-i18n="twoStepHint">An extra code when you sign in</small></span>
                    <span class="badge info" data-i18n="comingSoon">Coming soon</span>
                </div>
            </div>
            <p class="settings-group-label" data-i18n="groupAutoSignOut">Sign me out after inactivity</p>
            <div class="settings-card">
                <?= $settingsChoiceRows('autoSignOut', $autoSignOuts, 'auto_') ?>
            </div>
            <div class="settings-card">
                <a class="settings-row settings-danger" href="<?= $se($settingsRoot) ?>auth/signout.php">
                    <span class="settings-row-copy"><strong data-i18n="signOutDevice">Sign out of this device</strong></span>
                    <span class="settings-row-end"><?= $settingsIcon('chevron') ?></span>
                </a>
            </div>
        </section>

        <!-- Account (role-specific details) -->
        <?php foreach ($settingsAccount as $section): ?>
            <section class="settings-view" data-view="<?= $se($section['key']) ?>" hidden>
                <?= $settingsViewHeader($section['i18n'], $section['label']) ?>
                <?php if (isset($section['avatar'])):
                    $photo = $section['avatar'];
                    $avatarStatusMessages = [
                        'saved' => ['avatar_saved', 'Your profile photo was updated.', true],
                        'removed' => ['avatar_removed', 'Your profile photo was removed.', true],
                        'too-big' => ['avatar_too_big', 'That image is larger than 2 MB. Please choose a smaller one.', false],
                        'bad-type' => ['avatar_bad_type', 'Please choose a JPG, PNG or WebP image.', false],
                        'unreadable' => ['avatar_unreadable', "We couldn't read that image. Please try another one.", false],
                        'error' => ['avatar_error', "We couldn't save your photo. Please try again.", false],
                    ];
                    $avatarStatus = $avatarStatusMessages[is_string($_GET['avatar'] ?? null) ? $_GET['avatar'] : ''] ?? null;
                ?>
                    <div class="settings-card settings-avatar-card">
                        <div data-avatar-preview>
                            <?= mentora_avatar($photo['url'], $photo['initials'], (string) $photo['name'], 'avatar-xl') ?>
                        </div>
                        <div class="settings-avatar-copy">
                            <strong data-i18n="profilePhoto">Profile photo</strong>
                            <?php if (!empty($photo['canEdit'])): ?>
                                <small data-i18n="profilePhotoHint">JPG, PNG or WebP, up to 2 MB. We'll crop it to a square.</small>
                                <div class="settings-avatar-actions">
                                    <form method="post" action="<?= $se($settingsRoot) ?>account/avatar.php" enctype="multipart/form-data" data-avatar-form>
                                        <?= mentora_csrf_field() ?>
                                        <input type="hidden" name="action" value="upload">
                                        <label class="btn-solid theme-primary settings-avatar-pick">
                                            <input class="sr-only" type="file" name="avatar" accept="image/jpeg,image/png,image/webp,image/*" data-avatar-input>
                                            <span data-i18n="<?= $photo['url'] ? 'changePhoto' : 'uploadPhoto' ?>"><?= $photo['url'] ? 'Change photo' : 'Upload photo' ?></span>
                                        </label>
                                    </form>
                                    <?php if ($photo['url']): ?>
                                        <form method="post" action="<?= $se($settingsRoot) ?>account/avatar.php">
                                            <?= mentora_csrf_field() ?>
                                            <input type="hidden" name="action" value="remove">
                                            <button class="btn-solid theme-secondary" type="submit" data-avatar-remove data-i18n="removePhoto">Remove</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <small data-i18n="avatarSignIn">Sign in to add a profile photo.</small>
                            <?php endif; ?>
                            <p class="settings-avatar-status<?= $avatarStatus && !$avatarStatus[2] ? ' is-error' : '' ?>" role="status" data-avatar-status
                                data-i18n="<?= $se($avatarStatus[0] ?? 'avatar_saved') ?>"<?= $avatarStatus ? '' : ' hidden' ?>><?= $se($avatarStatus[1] ?? 'Your profile photo was updated.') ?></p>
                        </div>
                    </div>
                <?php endif; ?>
                <div class="settings-card">
                    <?php foreach ($section['rows'] as [$i18n, $label, $value]): ?>
                        <div class="settings-row settings-info-row">
                            <small data-i18n="<?= $se($i18n) ?>"><?= $se($label) ?></small>
                            <strong><?= $se($value) ?></strong>
                        </div>
                    <?php endforeach; ?>
                    <?php if (!empty($section['link'])): ?>
                        <a class="settings-row" href="<?= $se($section['link'][2]) ?>">
                            <span class="settings-row-copy"><strong data-i18n="<?= $se($section['link'][0]) ?>"><?= $se($section['link'][1]) ?></strong></span>
                            <span class="settings-row-end"><?= $settingsIcon('chevron') ?></span>
                        </a>
                    <?php endif; ?>
                </div>
                <?php if (!empty($section['note'])): ?>
                    <p class="settings-footnote" data-i18n="<?= $se($section['note'][0]) ?>"><?= $se($section['note'][1]) ?></p>
                <?php endif; ?>
            </section>
        <?php endforeach; ?>
    </div>

    <div class="settings-changes" hidden>
        <span class="settings-changes-dot" aria-hidden="true"></span>
        <span class="settings-changes-count" aria-live="polite"></span>
        <button class="settings-discard" type="button" data-i18n="discard">Discard</button>
        <button class="settings-save" type="button" data-i18n="save">Save changes</button>
    </div>
    <div class="settings-toast" role="status" hidden></div>
</div>
<script>
    // Before the page is drawn: open the view named in the address (e.g. #profile), so a refresh
    // doesn't flash an empty panel or jump. The full script below keeps it in sync afterwards.
    (() => {
        const shell = document.querySelector('[data-settings]');
        const requested = window.location.hash.slice(1);
        const target = requested ? shell.querySelector('.settings-view[data-view="' + CSS.escape(requested) + '"]') : null;
        if (target) {
            shell.querySelectorAll('.settings-view').forEach((view) => { view.hidden = view !== target; });
            shell.classList.add('is-viewing');
        }
    })();
</script>

<script>
    (() => {
        const shell = document.querySelector('[data-settings]');
        const api = window.MentoraSettings;
        if (!shell || !api) {
            return;
        }

        // English (US) text comes from the markup; these are the other languages plus dynamic strings.
        const I18N = {
            'en-US': {
                darkModeAuto: 'Matching your device setting',
                showAllLanguages: 'Show all {n} languages',
                showFewerLanguages: 'Show fewer languages',
                avatar_saved: 'Your profile photo was updated.',
                avatar_removed: 'Your profile photo was removed.',
                avatar_too_big: 'That image is larger than 2 MB. Please choose a smaller one.',
                avatar_bad_type: 'Please choose a JPG, PNG or WebP image.',
                avatar_unreadable: "We couldn't read that image. Please try another one.",
                avatar_error: "We couldn't save your photo. Please try again.",
                confirmPhotoTitle: 'Use this photo?',
                confirmPhotoText: 'It will show on your profile and across your workspace.',
                usePhoto: 'Use photo',
                confirmRemovePhotoTitle: 'Remove your photo?',
                confirmRemovePhotoText: 'Your initials will show instead.',
                confirmSaveTitle: 'Save your changes?',
                confirmSaveText: 'These settings will be saved on this device and browser.',
                confirmDiscardTitle: 'Discard your changes?',
                confirmDiscardText: 'These unsaved changes will be undone.',
                confirmSignOutTitle: 'Sign out of this device?',
                confirmSignOutText: "You'll need to sign in again to open your workspace.",
                signOutShort: 'Sign out',
                cancel: 'Cancel',
                on: 'On',
                off: 'Off',
                changeOne: '{n} change',
                changeMany: '{n} changes',
                saved: 'Settings saved',
                topicsOn: '{n} of {total} topics on',
                autoOff: 'Auto sign-out off',
                autoOn: 'Auto sign-out after {n} min'
            },
            'en-GB': {
                groupPersonalization: 'Personalisation',
                themeColor: 'Theme colour',
                organization: 'Organisation'
            },
            fil: {
                settingsTitle: 'Settings',
                settingsSubtitle: 'Gawing sa iyo ang Mentora.',
                darkMode: 'Dark mode',
                darkModeHint: 'Mas magaan sa mata sa gabi',
                darkModeAuto: 'Sinusunod ang setting ng device mo',
                profilePhoto: 'Larawan sa profile',
                profilePhotoHint: 'JPG, PNG o WebP, hanggang 2 MB. Gagawin itong parisukat.',
                uploadPhoto: 'Mag-upload ng larawan',
                changePhoto: 'Palitan ang larawan',
                removePhoto: 'Alisin',
                avatarSignIn: 'Mag-sign in para makapagdagdag ng larawan sa profile.',
                avatar_saved: 'Na-update ang larawan mo sa profile.',
                avatar_removed: 'Naalis ang larawan mo sa profile.',
                avatar_too_big: 'Lampas sa 2 MB ang larawan. Pumili ng mas maliit.',
                avatar_bad_type: 'Pumili ng JPG, PNG o WebP na larawan.',
                avatar_unreadable: 'Hindi mabasa ang larawang iyon. Sumubok ng iba.',
                avatar_error: 'Hindi na-save ang larawan mo. Pakisubukan ulit.',
                confirmPhotoTitle: 'Gamitin ang larawang ito?',
                confirmPhotoText: 'Makikita ito sa profile mo at sa buong workspace.',
                usePhoto: 'Gamitin',
                confirmRemovePhotoTitle: 'Alisin ang larawan mo?',
                confirmRemovePhotoText: 'Ang initials mo ang makikita sa halip.',
                confirmSaveTitle: 'I-save ang mga pagbabago?',
                confirmSaveText: 'Mase-save ang mga setting na ito sa device at browser na ito.',
                confirmDiscardTitle: 'I-discard ang mga pagbabago?',
                confirmDiscardText: 'Babawiin ang mga pagbabagong hindi pa naka-save.',
                confirmSignOutTitle: 'Mag-sign out sa device na ito?',
                confirmSignOutText: 'Kailangan mong mag-sign in ulit para mabuksan ang workspace mo.',
                signOutShort: 'Mag-sign out',
                cancel: 'Kanselahin',
                on: 'Naka-on',
                off: 'Naka-off',
                mode: 'Mode',
                theme_light: 'Maliwanag',
                theme_dark: 'Madilim',
                theme_system: 'Auto (device)',
                modeHint: 'Sinusunod ng Auto ang light o dark na setting ng device mo. Kung hindi ito nagbabago, gawing "System default" ang appearance ng browser mo (sa Edge: Settings > Appearance > Overall appearance).',
                groupPersonalization: 'Pag-personalize',
                appearance: 'Itsura',
                language: 'Wika',
                groupAlerts: 'Mga alerto',
                notifications: 'Mga notification',
                groupPrivacy: 'Privacy at seguridad',
                privacy: 'Privacy',
                security: 'Seguridad',
                groupAccount: 'Account',
                savedNote: 'Naka-save ang settings sa device at browser na ito.',
                back: 'Bumalik',
                previewTitle: 'Lingguhang buod',
                previewText: 'Handa na ang plano mo para sa linggong ito.',
                previewOpen: 'Buksan',
                themeColor: 'Kulay ng tema',
                accent_teal: 'Mentora teal',
                accent_ocean: 'Karagatan',
                accent_forest: 'Kagubatan',
                accent_plum: 'Plum',
                accent_amber: 'Amber',
                textSize: 'Laki ng teksto',
                size_small: 'Maliit',
                size_default: 'Default',
                size_large: 'Malaki',
                size_xlarge: 'Napakalaki',
                uiStyle: 'Istilo ng UI',
                style_rounded: 'Bilugan',
                style_soft: 'Malambot',
                style_crisp: 'Matalas',
                languageNote: 'English (US) ang pangunahing wika. Ang mga wika sa ilalim ng May salin ay nagsasalin din ng pahinang ito ng settings; para sa ibang wika, nase-save ang pinili mo at English (US) muna ang makikita hanggang handa na ang salin.',
                searchLanguages: 'Maghanap ng wika',
                groupTranslated: 'May salin',
                groupAllLanguages: 'Lahat ng wika',
                noLanguages: 'Walang wikang tumugma sa hinanap mo.',
                showAllLanguages: 'Ipakita ang lahat ng {n} wika',
                showFewerLanguages: 'Ipakita ang mas kaunti',
                groupInApp: 'Sa workspace',
                badges: 'Mga badge ng hindi pa nababasa',
                badgesHint: 'Ipakita ang bilang sa mga icon ng mensahe at notification',
                previews: 'Preview ng mensahe',
                previewsHint: 'Ipakita ang laman ng mensahe sa Messages panel',
                groupTopics: 'Abisuhan ako tungkol sa',
                topic_newApplications: 'Bagong aplikasyon',
                topic_newApplicationsHint: 'Kapag may nag-apply o nag-update ng form',
                topic_attendance: 'Mga exception sa attendance',
                topic_attendanceHint: 'Kulang na time-out',
                topic_reports: 'Paalala sa report',
                topic_reportsHint: 'Kapag handa na ang naka-iskedyul na report',
                topic_evaluations: 'Paalala sa evaluation',
                topic_evaluationsHint: 'Mga review na due o lampas na',
                topic_training: 'Update sa training',
                topic_trainingHint: 'Natapos na module at bagong assignment',
                topic_documents: 'Update sa dokumento',
                topic_documentsHint: 'Bago o binagong dokumento ng team',
                topic_onboarding: 'Training at onboarding',
                topic_onboardingHint: 'Bagong module at onboarding check',
                topic_department: 'Update mula sa department at manager',
                topic_departmentHint: 'Assignment, iskedyul at feedback',
                topic_dailyReport: 'Paalala sa daily work report',
                topic_dailyReportHint: 'Paalala bago matapos ang shift mo',
                topic_tasks: 'Mga naka-assign na task',
                topic_tasksHint: 'Bagong trabaho mula sa iyong supervising agent',
                topic_hours: 'Daily activity at oras',
                topic_hoursHint: 'Paalala sa report at naaprubahang oras',
                topic_trainingReminders: 'Paalala sa training',
                topic_trainingRemindersHint: 'Paparating na module at assessment',
                groupVisibility: 'Sino ang makakakita ng profile ko',
                vis_workspace: 'Lahat sa workspace',
                vis_team: 'Team ko lang',
                vis_hr: 'HR at admin lang',
                groupActivity: 'Aktibidad at pagbabahagi',
                onlineStatus: 'Ipakita kapag active ako',
                onlineStatusHint: 'Makikita ng mga kasama mo na online ka',
                privacy_shareProgress: 'I-share ang progress sa mentor ko',
                privacy_shareProgressHint: 'Makikita ng agent mo ang progress mo sa training at report',
                privacy_showEmail: 'Ipakita ang email ko sa profile',
                privacy_showEmailHint: 'Direktang makakausap ka ng mga tao sa workspace',
                password: 'Password',
                passwordHint: 'Palitan gamit ang code na ipapadala sa email mo',
                passwordChange: 'Palitan',
                twoStep: 'Two-step verification',
                twoStepHint: 'Dagdag na code tuwing magsa-sign in',
                comingSoon: 'Malapit na',
                groupAutoSignOut: 'I-sign out ako kapag walang aktibidad',
                auto_off: 'Hindi kailanman',
                auto_15: 'Pagkalipas ng 15 minuto',
                auto_30: 'Pagkalipas ng 30 minuto',
                auto_60: 'Pagkalipas ng 60 minuto',
                signOutDevice: 'Mag-sign out sa device na ito',
                profile: 'Profile',
                workspace: 'Workspace',
                fullName: 'Buong pangalan',
                email: 'Email',
                role: 'Tungkulin',
                userId: 'User ID',
                pathway: 'Pathway',
                organization: 'Organisasyon',
                workspaceName: 'Pangalan ng workspace',
                contactEmail: 'Contact email',
                country: 'Bansa',
                workingDays: 'Mga araw ng trabaho',
                defaultShift: 'Default na shift',
                openProfile: 'Buksan ang profile ko',
                openUsers: 'Users & access',
                accountNoteAdmin: 'Pamahalaan ang mga account at tungkulin sa Users & access.',
                accountNoteStaff: 'Ang HR ang nag-aayos ng pagbabago sa pangalan, email at tungkulin.',
                accountNoteApplicant: 'Ang HR ang nag-aayos ng pagbabago sa pangalan at email.',
                discard: 'I-discard',
                save: 'I-save',
                changeOne: '{n} pagbabago',
                changeMany: '{n} pagbabago',
                saved: 'Na-save ang settings',
                topicsOn: '{n} sa {total} topic ang naka-on',
                autoOff: 'Naka-off ang auto sign-out',
                autoOn: 'Auto sign-out pagkalipas ng {n} min'
            },
            es: {
                settingsTitle: 'Configuración',
                settingsSubtitle: 'Haz que Mentora se sienta tuyo.',
                darkMode: 'Modo oscuro',
                darkModeHint: 'Más cómodo para la vista de noche',
                darkModeAuto: 'Según la configuración de tu dispositivo',
                profilePhoto: 'Foto de perfil',
                profilePhotoHint: 'JPG, PNG o WebP, hasta 2 MB. La recortaremos en un cuadrado.',
                uploadPhoto: 'Subir foto',
                changePhoto: 'Cambiar foto',
                removePhoto: 'Quitar',
                avatarSignIn: 'Inicia sesión para añadir una foto de perfil.',
                avatar_saved: 'Tu foto de perfil se actualizó.',
                avatar_removed: 'Tu foto de perfil se quitó.',
                avatar_too_big: 'La imagen supera los 2 MB. Elige una más pequeña.',
                avatar_bad_type: 'Elige una imagen JPG, PNG o WebP.',
                avatar_unreadable: 'No pudimos leer esa imagen. Prueba con otra.',
                avatar_error: 'No pudimos guardar tu foto. Inténtalo de nuevo.',
                confirmPhotoTitle: '¿Usar esta foto?',
                confirmPhotoText: 'Se mostrará en tu perfil y en todo tu espacio de trabajo.',
                usePhoto: 'Usar foto',
                confirmRemovePhotoTitle: '¿Quitar tu foto?',
                confirmRemovePhotoText: 'Se mostrarán tus iniciales en su lugar.',
                confirmSaveTitle: '¿Guardar los cambios?',
                confirmSaveText: 'Esta configuración se guardará en este dispositivo y navegador.',
                confirmDiscardTitle: '¿Descartar los cambios?',
                confirmDiscardText: 'Se desharán los cambios sin guardar.',
                confirmSignOutTitle: '¿Cerrar sesión en este dispositivo?',
                confirmSignOutText: 'Tendrás que iniciar sesión de nuevo para abrir tu espacio de trabajo.',
                signOutShort: 'Cerrar sesión',
                cancel: 'Cancelar',
                on: 'Activado',
                off: 'Desactivado',
                mode: 'Modo',
                theme_light: 'Claro',
                theme_dark: 'Oscuro',
                theme_system: 'Automático (dispositivo)',
                modeHint: 'Automático sigue el modo claro u oscuro de tu dispositivo. Si no cambia, configura la apariencia de tu navegador en "Predeterminado del sistema" (en Edge: Configuración > Apariencia > Apariencia general).',
                groupPersonalization: 'Personalización',
                appearance: 'Apariencia',
                language: 'Idioma',
                groupAlerts: 'Alertas',
                notifications: 'Notificaciones',
                groupPrivacy: 'Privacidad y seguridad',
                privacy: 'Privacidad',
                security: 'Seguridad',
                groupAccount: 'Cuenta',
                savedNote: 'La configuración se guarda en este dispositivo y navegador.',
                back: 'Volver',
                previewTitle: 'Resumen semanal',
                previewText: 'Tu plan de la semana está listo.',
                previewOpen: 'Abrir',
                themeColor: 'Color del tema',
                accent_teal: 'Verde azulado Mentora',
                accent_ocean: 'Océano',
                accent_forest: 'Bosque',
                accent_plum: 'Ciruela',
                accent_amber: 'Ámbar',
                textSize: 'Tamaño del texto',
                size_small: 'Pequeño',
                size_default: 'Predeterminado',
                size_large: 'Grande',
                size_xlarge: 'Muy grande',
                uiStyle: 'Estilo de la interfaz',
                style_rounded: 'Redondeado',
                style_soft: 'Suave',
                style_crisp: 'Nítido',
                languageNote: 'El inglés (EE. UU.) es el idioma principal. Los idiomas de la sección Traducidos también cambian esta página de configuración; para los demás, tu elección se guarda y Mentora se muestra en inglés (EE. UU.) hasta que su traducción esté lista.',
                searchLanguages: 'Buscar idiomas',
                groupTranslated: 'Traducidos',
                groupAllLanguages: 'Todos los idiomas',
                noLanguages: 'Ningún idioma coincide con tu búsqueda.',
                showAllLanguages: 'Mostrar los {n} idiomas',
                showFewerLanguages: 'Mostrar menos idiomas',
                groupInApp: 'En el espacio de trabajo',
                badges: 'Insignias de no leídos',
                badgesHint: 'Mostrar contadores en los íconos de mensajes y notificaciones',
                previews: 'Vista previa de mensajes',
                previewsHint: 'Mostrar el texto en el panel de mensajes',
                groupTopics: 'Avisarme sobre',
                topic_newApplications: 'Nuevas solicitudes',
                topic_newApplicationsHint: 'Cuando alguien solicita o actualiza su formulario',
                topic_attendance: 'Excepciones de asistencia',
                topic_attendanceHint: 'Salidas sin registrar',
                topic_reports: 'Recordatorios de informes',
                topic_reportsHint: 'Cuando los informes programados estén listos',
                topic_evaluations: 'Recordatorios de evaluación',
                topic_evaluationsHint: 'Revisiones pendientes o vencidas',
                topic_training: 'Novedades de formación',
                topic_trainingHint: 'Módulos completados y nuevas asignaciones',
                topic_documents: 'Novedades de documentos',
                topic_documentsHint: 'Documentos del equipo nuevos o modificados',
                topic_onboarding: 'Formación e incorporación',
                topic_onboardingHint: 'Nuevos módulos y controles de incorporación',
                topic_department: 'Novedades del departamento y del gerente',
                topic_departmentHint: 'Asignaciones, horarios y comentarios',
                topic_dailyReport: 'Recordatorios del informe diario',
                topic_dailyReportHint: 'Un aviso antes de terminar tu turno',
                topic_tasks: 'Tareas asignadas',
                topic_tasksHint: 'Nuevo trabajo de tu agente supervisor',
                topic_hours: 'Actividad diaria y horas',
                topic_hoursHint: 'Recordatorios de informes y horas aprobadas',
                topic_trainingReminders: 'Recordatorios de formación',
                topic_trainingRemindersHint: 'Próximos módulos y evaluaciones',
                groupVisibility: 'Quién puede ver mi perfil',
                vis_workspace: 'Todos en el espacio de trabajo',
                vis_team: 'Solo mi equipo',
                vis_hr: 'Solo RR. HH. y administradores',
                groupActivity: 'Actividad y uso compartido',
                onlineStatus: 'Mostrar cuando estoy activo',
                onlineStatusHint: 'Tus compañeros verán que estás en línea',
                privacy_shareProgress: 'Compartir mi progreso con mi mentor',
                privacy_shareProgressHint: 'Tu agente puede ver tu progreso en formación e informes',
                privacy_showEmail: 'Mostrar mi correo en mi perfil',
                privacy_showEmailHint: 'Las personas de tu espacio de trabajo pueden contactarte directamente',
                password: 'Contraseña',
                passwordHint: 'Cámbiala con un código enviado a tu correo',
                passwordChange: 'Cambiar',
                twoStep: 'Verificación en dos pasos',
                twoStepHint: 'Un código adicional al iniciar sesión',
                comingSoon: 'Próximamente',
                groupAutoSignOut: 'Cerrar sesión por inactividad',
                auto_off: 'Nunca',
                auto_15: 'Después de 15 minutos',
                auto_30: 'Después de 30 minutos',
                auto_60: 'Después de 60 minutos',
                signOutDevice: 'Cerrar sesión en este dispositivo',
                profile: 'Perfil',
                workspace: 'Espacio de trabajo',
                fullName: 'Nombre completo',
                email: 'Correo electrónico',
                role: 'Rol',
                userId: 'ID de usuario',
                pathway: 'Ruta',
                organization: 'Organización',
                workspaceName: 'Nombre del espacio de trabajo',
                contactEmail: 'Correo de contacto',
                country: 'País',
                workingDays: 'Días laborables',
                defaultShift: 'Turno predeterminado',
                openProfile: 'Abrir mi perfil',
                openUsers: 'Usuarios y acceso',
                accountNoteAdmin: 'Administra cuentas y roles en Usuarios y acceso.',
                accountNoteStaff: 'RR. HH. gestiona los cambios de nombre, correo y rol.',
                accountNoteApplicant: 'RR. HH. gestiona los cambios de nombre y correo.',
                discard: 'Descartar',
                save: 'Guardar cambios',
                changeOne: '{n} cambio',
                changeMany: '{n} cambios',
                saved: 'Configuración guardada',
                topicsOn: '{n} de {total} temas activados',
                autoOff: 'Cierre automático desactivado',
                autoOn: 'Cierre automático tras {n} min'
            }
        };
        const TRANSLATED = <?= json_encode($translatedLanguageCodes) ?>;

        const page = document.querySelector('#main-content') || document;
        const translatable = [...page.querySelectorAll('[data-i18n]')];
        const placeholders = [...page.querySelectorAll('[data-i18n-placeholder]')];
        const englishText = {};
        translatable.forEach((el) => { englishText[el.dataset.i18n] = el.textContent; });
        placeholders.forEach((el) => { englishText[el.dataset.i18nPlaceholder] = el.getAttribute('placeholder'); });

        // Show each language in its own words (e.g. 日本語, العربية), using the browser's language data.
        shell.querySelectorAll('[data-native-name]').forEach((el) => {
            const code = el.closest('[data-value]').dataset.value;
            try {
                const name = new Intl.DisplayNames([code], { type: 'language', fallback: 'none' }).of(code);
                if (name && name.toLowerCase() !== code.toLowerCase()) {
                    el.textContent = name.charAt(0).toLocaleUpperCase(code) + name.slice(1);
                    const row = el.closest('[data-search]');
                    row.dataset.search += ' ' + name.toLowerCase();
                }
            } catch (error) {
                // Unknown to this browser: keep the English name.
            }
        });

        // Language search.
        const languageSearch = shell.querySelector('[data-language-search]');
        const languageGroups = [...shell.querySelectorAll('[data-language-group]')];
        const languageEmpty = shell.querySelector('[data-language-empty]');
        // "All languages" starts collapsed (only the chosen language stays visible); searching looks through all of them.
        const languageCollapsible = shell.querySelector('[data-language-collapsible]');
        const languageToggle = shell.querySelector('[data-language-toggle]');
        const worldLanguageCount = languageCollapsible ? languageCollapsible.querySelectorAll('[data-setting="language"]').length : 0;
        const labelLanguageToggle = () => {
            if (!languageToggle) {
                return;
            }
            const expanded = !languageCollapsible.classList.contains('is-collapsed');
            languageToggle.setAttribute('aria-expanded', String(expanded));
            languageToggle.querySelector('[data-language-toggle-label]').textContent = expanded
                ? t('showFewerLanguages')
                : t('showAllLanguages').replace('{n}', worldLanguageCount);
        };
        if (languageToggle) {
            languageToggle.addEventListener('click', () => {
                const collapsing = !languageCollapsible.classList.contains('is-collapsed');
                languageCollapsible.classList.toggle('is-collapsed', collapsing);
                labelLanguageToggle();
                if (collapsing) {
                    languageCollapsible.closest('[data-language-group]').scrollIntoView({ block: 'nearest' });
                }
            });
        }

        if (languageSearch) {
            languageSearch.addEventListener('input', () => {
                const query = languageSearch.value.trim().toLowerCase();
                if (languageCollapsible) {
                    languageCollapsible.classList.toggle('is-searching', query !== '');
                    languageToggle.hidden = query !== '';
                }
                let anyVisible = false;
                languageGroups.forEach((group) => {
                    let groupVisible = false;
                    group.querySelectorAll('[data-search]').forEach((row) => {
                        const match = !query || row.dataset.search.includes(query);
                        row.hidden = !match;
                        groupVisible = groupVisible || match;
                    });
                    group.hidden = !groupVisible;
                    anyVisible = anyVisible || groupVisible;
                });
                languageEmpty.hidden = anyVisible;
            });
        }

        let saved = api.read();
        let draft = { ...saved };
        const t = (key) => (I18N[draft.language] && I18N[draft.language][key])
            ?? englishText[key]
            ?? I18N['en-US'][key]
            ?? key;

        const views = [...shell.querySelectorAll('[data-view]')];
        const rows = [...shell.querySelectorAll('[data-open-view]')];
        const changesBar = shell.querySelector('.settings-changes');
        const changesCount = shell.querySelector('.settings-changes-count');
        const toast = shell.querySelector('.settings-toast');
        const topicKeys = ['topicA', 'topicB', 'topicC'];

        const render = () => {
            translatable.forEach((el) => { el.textContent = t(el.dataset.i18n); });
            placeholders.forEach((el) => {
                el.setAttribute('placeholder', t(el.dataset.i18nPlaceholder));
                el.setAttribute('aria-label', t(el.dataset.i18nPlaceholder));
            });
            labelLanguageToggle();
            // Only this page is translated, so only it carries the chosen language (screen readers, spell check).
            page.lang = TRANSLATED.includes(draft.language) ? draft.language : 'en-US';

            const resolveTheme = (pref) => (window.MentoraTheme ? window.MentoraTheme.resolve(pref) : pref);
            shell.querySelectorAll('input[data-setting]').forEach((input) => {
                const key = input.dataset.setting;
                input.checked = key === 'theme' ? resolveTheme(draft.theme) === 'dark' : Boolean(draft[key]);
            });
            const darkHint = shell.querySelector('[data-i18n="darkModeHint"]');
            if (darkHint) {
                darkHint.textContent = t(draft.theme === 'system' ? 'darkModeAuto' : 'darkModeHint');
            }
            shell.querySelectorAll('button[data-setting]').forEach((button) => {
                const selected = String(draft[button.dataset.setting]) === button.dataset.value;
                button.setAttribute('aria-pressed', String(selected));
                button.classList.toggle('is-selected', selected);
            });

            const current = {
                theme: t('theme_' + draft.theme),
                accent: t('accent_' + draft.accent),
                textSize: t('size_' + draft.textSize),
                uiStyle: t('style_' + draft.uiStyle)
            };
            shell.querySelectorAll('[data-current]').forEach((el) => { el.textContent = current[el.dataset.current] || ''; });

            const topicsOn = topicKeys.filter((key) => draft[key]).length;
            const summaries = {
                appearance: [current.accent, current.textSize, current.uiStyle].join(' · '),
                language: (shell.querySelector(`[data-setting="language"][data-value="${CSS.escape(draft.language)}"] strong`) || {}).textContent || draft.language,
                notifications: t('topicsOn').replace('{n}', topicsOn).replace('{total}', topicKeys.length),
                privacy: t('vis_' + draft.visibility),
                security: draft.autoSignOut === 'off' ? t('autoOff') : t('autoOn').replace('{n}', draft.autoSignOut)
            };
            shell.querySelectorAll('[data-summary]').forEach((el) => {
                if (el.dataset.summary in summaries) {
                    el.textContent = summaries[el.dataset.summary];
                }
            });

            // "Remove" photo asks first (red), in the page's language.
            const removePhotoButton = shell.querySelector('[data-avatar-remove]');
            if (removePhotoButton) {
                Object.assign(removePhotoButton.dataset, {
                    confirm: t('confirmRemovePhotoText'),
                    confirmTitle: t('confirmRemovePhotoTitle'),
                    confirmAction: t('removePhoto'),
                    confirmCancel: t('cancel'),
                    confirmTone: 'danger'
                });
            }

            // "Sign out of this device" asks first, in the page's language.
            const signOutLink = shell.querySelector('.settings-danger');
            if (signOutLink) {
                Object.assign(signOutLink.dataset, {
                    confirm: t('confirmSignOutText'),
                    confirmTitle: t('confirmSignOutTitle'),
                    confirmAction: t('signOutShort'),
                    confirmCancel: t('cancel'),
                    confirmTone: 'danger'
                });
            }

            const changed = Object.keys(draft).filter((key) => draft[key] !== saved[key]).length;
            changesBar.hidden = changed === 0;
            changesCount.textContent = t(changed === 1 ? 'changeOne' : 'changeMany').replace('{n}', changed);
        };

        const update = (key, value) => {
            draft = { ...draft, [key]: value };
            api.apply(draft, true); // live preview; only "Save changes" stores it
            render();
        };

        // The sun/moon button in the top bar saves the theme straight away: keep this page in step.
        if (window.MentoraTheme) {
            window.MentoraTheme.onChange(({ preference, committed }) => {
                if (committed) {
                    saved = { ...saved, theme: preference };
                    draft = { ...draft, theme: preference };
                }
                render();
            });
        }

        shell.addEventListener('change', (event) => {
            const input = event.target.closest('input[data-setting]');
            if (input) {
                const key = input.dataset.setting;
                update(key, key === 'theme' ? (input.checked ? 'dark' : 'light') : input.checked);
            }
        });

        shell.addEventListener('click', (event) => {
            const button = event.target.closest('button[data-setting]');
            if (button) {
                update(button.dataset.setting, button.dataset.value);
            }
        });

        // ---- Confirmations (dialog from includes/confirm_dialog.php) ----
        const askFirst = (options) => (window.MentoraConfirm ? window.MentoraConfirm(options) : Promise.resolve(true));
        const GROUP_LABELS = {
            theme: 'mode', accent: 'themeColor', textSize: 'textSize', uiStyle: 'uiStyle',
            language: 'language', visibility: 'groupVisibility', autoSignOut: 'groupAutoSignOut'
        };
        // "Theme color: Ocean", "Unread badges: Off" ... one line per changed setting.
        const describeChanges = () => Object.keys(draft).filter((key) => draft[key] !== saved[key]).map((key) => {
            const value = draft[key];
            const choice = shell.querySelector(`button[data-setting="${key}"][data-value="${CSS.escape(String(value))}"]`);
            if (choice) {
                const valueText = (choice.querySelector('strong, small, .sr-only') || choice).textContent.trim()
                    || choice.getAttribute('aria-label') || String(value);
                return `${t(GROUP_LABELS[key] || key)}: ${valueText}`;
            }
            const input = shell.querySelector(`input[data-setting="${key}"]`);
            if (input) {
                const label = input.closest('.settings-row').querySelector('strong').textContent.trim();
                return `${label}: ${t(value ? 'on' : 'off')}`;
            }
            return key;
        });

        let toastTimer;
        shell.querySelector('.settings-save').addEventListener('click', async () => {
            const confirmed = await askFirst({
                title: t('confirmSaveTitle'),
                message: t('confirmSaveText'),
                items: describeChanges(),
                confirmLabel: t('save'),
                cancelLabel: t('cancel')
            });
            if (!confirmed) {
                return;
            }
            api.write(draft);
            saved = { ...draft };
            api.watchIdle(saved);
            render();
            toast.textContent = t('saved');
            toast.hidden = false;
            clearTimeout(toastTimer);
            toastTimer = setTimeout(() => { toast.hidden = true; }, 2600);
        });

        shell.querySelector('.settings-discard').addEventListener('click', async () => {
            const confirmed = await askFirst({
                title: t('confirmDiscardTitle'),
                message: t('confirmDiscardText'),
                items: describeChanges(),
                confirmLabel: t('discard'),
                cancelLabel: t('cancel'),
                tone: 'danger'
            });
            if (!confirmed) {
                return;
            }
            draft = { ...saved };
            api.apply(draft, true);
            render();
        });

        // ---- Profile photo ----
        const AVATAR_MAX_BYTES = 2 * 1024 * 1024;
        const avatarInput = shell.querySelector('[data-avatar-input]');
        const avatarPreview = shell.querySelector('[data-avatar-preview]');
        const avatarStatus = shell.querySelector('[data-avatar-status]');

        const showAvatarStatus = (key, ok) => {
            if (!avatarStatus) {
                return;
            }
            avatarStatus.dataset.i18n = key;
            avatarStatus.textContent = t(key);
            avatarStatus.classList.toggle('is-error', !ok);
            avatarStatus.hidden = false;
        };

        // Centre-crop to a square and shrink to at most 512px. Done in the browser, so the server
        // never has to process large images (and needs no image library).
        const squarePhoto = async (file, maxSize = 512) => {
            const bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });
            const side = Math.min(bitmap.width, bitmap.height);
            const size = Math.min(maxSize, side);
            const canvas = document.createElement('canvas');
            canvas.width = size;
            canvas.height = size;
            const context = canvas.getContext('2d');
            context.fillStyle = '#fff'; // transparent PNGs get a white background
            context.fillRect(0, 0, size, size);
            context.drawImage(bitmap, (bitmap.width - side) / 2, (bitmap.height - side) / 2, side, side, 0, 0, size, size);
            if (bitmap.close) {
                bitmap.close();
            }
            return new Promise((resolve, reject) => {
                canvas.toBlob((blob) => (blob ? resolve(blob) : reject(new Error('Could not encode photo'))), 'image/jpeg', 0.9);
            });
        };

        if (avatarInput && avatarPreview) {
            avatarInput.addEventListener('change', async () => {
                const file = avatarInput.files && avatarInput.files[0];
                if (!file) {
                    return;
                }
                if (!file.type.startsWith('image/')) {
                    showAvatarStatus('avatar_bad_type', false);
                    avatarInput.value = '';
                    return;
                }

                let photo;
                try {
                    photo = await squarePhoto(file);
                } catch (error) {
                    if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) {
                        showAvatarStatus('avatar_unreadable', false);
                        avatarInput.value = '';
                        return;
                    }
                    photo = file; // browser couldn't process it: let the server check the original
                }
                if (photo.size > AVATAR_MAX_BYTES) {
                    showAvatarStatus('avatar_too_big', false);
                    avatarInput.value = '';
                    return;
                }

                // Show it in place, then ask before saving.
                const previewUrl = URL.createObjectURL(photo);
                const previous = [...avatarPreview.childNodes];
                const image = document.createElement('img');
                image.className = 'avatar-img';
                image.src = previewUrl;
                image.alt = '';
                const frame = document.createElement('span');
                frame.className = 'avatar avatar-xl';
                frame.append(image);
                avatarPreview.replaceChildren(frame);

                const confirmed = await askFirst({
                    title: t('confirmPhotoTitle'),
                    message: t('confirmPhotoText'),
                    confirmLabel: t('usePhoto'),
                    cancelLabel: t('cancel')
                });
                if (!confirmed) {
                    avatarPreview.replaceChildren(...previous);
                    URL.revokeObjectURL(previewUrl);
                    avatarInput.value = '';
                    return;
                }

                if (photo !== file) {
                    const transfer = new DataTransfer();
                    transfer.items.add(new File([photo], 'avatar.jpg', { type: 'image/jpeg' }));
                    avatarInput.files = transfer.files;
                }
                avatarInput.closest('form').submit();
            });
        }

        // After an upload/remove the page opens on Profile with a message; drop it from the address bar.
        if (new URLSearchParams(window.location.search).has('avatar')) {
            history.replaceState(null, '', window.location.pathname + window.location.hash);
        }

        // Views: two columns on wide screens, drill-down (list -> view -> Back) on narrow screens.
        const showView = () => {
            const requested = window.location.hash.slice(1);
            const name = views.some((view) => view.dataset.view === requested) ? requested : '';
            const visible = name || 'appearance';
            views.forEach((view) => { view.hidden = view.dataset.view !== visible; });
            rows.forEach((row) => {
                const active = row.dataset.openView === visible;
                row.classList.toggle('is-active', active);
                if (active) {
                    row.setAttribute('aria-current', 'true');
                } else {
                    row.removeAttribute('aria-current');
                }
            });
            const wasViewing = shell.classList.contains('is-viewing');
            shell.classList.toggle('is-viewing', name !== '');
            if (wasViewing !== (name !== '') && window.matchMedia('(max-width: 900px)').matches) {
                shell.scrollIntoView({ block: 'start' });
            }
        };

        shell.querySelectorAll('.settings-back').forEach((back) => {
            back.addEventListener('click', (event) => {
                event.preventDefault();
                history.pushState(null, '', window.location.pathname + window.location.search);
                showView();
            });
        });

        window.addEventListener('hashchange', showView);
        window.addEventListener('popstate', showView);
        showView();
        render();
    })();
</script>
