<?php
// Applies the saved workspace settings (dark mode, theme color, text size, UI style, language,
// badge/preview options, auto sign-out) on every admin, agent and applicant page.
// Included at the top of <body> by the three workspace layouts; edited on each Settings page
// (includes/settings_panel.php). Settings are kept in this browser (localStorage).
$settingsSignOutUrl = str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/applicant/')
    ? '../../auth/signout.php'
    : '../auth/signout.php';
?>
<script>
    window.MentoraSettings = (() => {
        const KEY = 'mentora-workspace-settings';
        const LEGACY_THEME_KEY = 'mentora-workspace-theme';
        const SIGN_OUT_URL = <?= json_encode($settingsSignOutUrl) ?>;

        const defaults = {
            theme: 'system',
            themeRev: 2, // see THEME_REV in theme_boot.php
            accent: 'teal',
            textSize: 'default',
            uiStyle: 'rounded',
            language: 'en-US',
            badges: true,
            previews: true,
            topicA: true,
            topicB: true,
            topicC: true,
            visibility: 'workspace',
            onlineStatus: true,
            privacyExtra: true,
            autoSignOut: 'off'
        };

        const read = () => {
            let saved = {};
            try {
                saved = JSON.parse(window.localStorage.getItem(KEY) || '{}') || {};
                const legacyTheme = window.localStorage.getItem(LEGACY_THEME_KEY);
                if (!('theme' in saved) && (legacyTheme === 'dark' || legacyTheme === 'light')) {
                    saved.theme = legacyTheme;
                }
            } catch (error) {
                saved = {};
            }
            const settings = { ...defaults };
            Object.keys(defaults).forEach((key) => {
                if (key in saved && typeof saved[key] === typeof defaults[key]) {
                    settings[key] = saved[key];
                }
            });
            return settings;
        };

        const write = (settings) => {
            try {
                window.localStorage.setItem(KEY, JSON.stringify(settings));
                window.localStorage.setItem(LEGACY_THEME_KEY, settings.theme);
            } catch (error) {
                // Storage blocked (private mode): settings still apply for this page view.
            }
            if (window.MentoraTheme) {
                window.MentoraTheme.set(settings.theme, { save: false, animate: false });
            }
        };

        // animate: cross-fade the light/dark switch (used for live previews on the Settings page).
        const apply = (settings, animate = false) => {
            const body = document.body;
            if (window.MentoraTheme) {
                window.MentoraTheme.paint(settings.theme, animate);
            } else {
                body.classList.toggle('workspace-dark-mode', settings.theme === 'dark');
            }
            body.classList.toggle('accent-custom', settings.accent !== 'teal');
            body.dataset.accent = settings.accent;
            body.dataset.textSize = settings.textSize;
            body.dataset.uiStyle = settings.uiStyle;
            body.classList.toggle('hide-header-badges', !settings.badges);
            body.classList.toggle('hide-message-previews', !settings.previews);
            // The language preference is not written to <html lang>: workspace pages are in English (US),
            // and the Settings page marks only its own translated content with the chosen language.
        };

        // Auto sign-out after a period without any mouse, touch, keyboard or scroll activity.
        let idleMinutes = 0;
        let lastActivity = Date.now();
        let watching = false;
        const watchIdle = (settings) => {
            idleMinutes = settings.autoSignOut === 'off' ? 0 : Number(settings.autoSignOut) || 0;
            lastActivity = Date.now();
            if (watching) {
                return;
            }
            watching = true;
            ['pointerdown', 'pointermove', 'keydown', 'scroll', 'touchstart'].forEach((type) => {
                window.addEventListener(type, () => { lastActivity = Date.now(); }, { passive: true });
            });
            window.setInterval(() => {
                if (idleMinutes && Date.now() - lastActivity > idleMinutes * 60000) {
                    window.location.href = SIGN_OUT_URL;
                }
            }, 15000);
        };

        const current = read();
        apply(current);
        watchIdle(current);

        // Another tab saved new settings: apply them here too (theme is handled by MentoraTheme).
        window.addEventListener('storage', (event) => {
            if (event.key === KEY && !document.querySelector('[data-settings]')) {
                apply(read());
            }
        });

        return { defaults, read, write, apply, watchIdle };
    })();
</script>
