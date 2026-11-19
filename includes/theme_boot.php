<?php
// Light / dark theme engine shared by every page (public pages and all workspaces).
// Include inside <head> so the right theme is set before anything is painted (no flash).
// Workspaces: preference 'system' (follow the device, default), 'light' or 'dark', chosen in
// Settings or with the top-bar button, stored in localStorage.
// Public pages (includes/header.php sets $themeFollowsDevice = true) have no button and always
// follow the device's light/dark setting, ignoring any saved choice.
$themeFollowsDeviceJs = !empty($themeFollowsDevice) ? 'true' : 'false';
?>
<script>
    window.MentoraTheme = (() => {
        const KEY = 'mentora-workspace-settings';
        const LEGACY_KEY = 'mentora-workspace-theme';
        const root = document.documentElement;
        const deviceDark = window.matchMedia('(prefers-color-scheme: dark)');
        const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
        const listeners = [];
        const FOLLOW_DEVICE = <?= $themeFollowsDeviceJs ?>;

        // Choices saved before the button became "smart" (it could leave people stuck on a fixed
        // mode) are reset to Auto once. THEME_REV marks settings that already went through this.
        const THEME_REV = 2;
        try {
            const saved = JSON.parse(window.localStorage.getItem(KEY) || '{}') || {};
            if (saved.themeRev !== THEME_REV) {
                saved.theme = 'system';
                saved.themeRev = THEME_REV;
                window.localStorage.setItem(KEY, JSON.stringify(saved));
                window.localStorage.setItem(LEGACY_KEY, 'system');
            }
        } catch (error) {
            // Storage unavailable: nothing to reset.
        }

        const readPreference = () => {
            if (FOLLOW_DEVICE) {
                return 'system';
            }
            try {
                const saved = JSON.parse(window.localStorage.getItem(KEY) || '{}') || {};
                if (['system', 'light', 'dark'].includes(saved.theme)) {
                    return saved.theme;
                }
                const legacy = window.localStorage.getItem(LEGACY_KEY);
                if (legacy === 'light' || legacy === 'dark') {
                    return legacy;
                }
            } catch (error) {
                // Storage unavailable: fall back to the device setting.
            }
            return 'system';
        };

        let preference = readPreference();
        let transitionTimer;

        const resolve = (pref) => (pref === 'dark' || (pref === 'system' && deviceDark.matches)) ? 'dark' : 'light';

        const labelToggles = (mode, pref) => {
            const label = (pref === 'system' ? 'Auto (matches your device). ' : '')
                + (mode === 'dark' ? 'Switch to light mode' : 'Switch to dark mode');
            document.querySelectorAll('[data-mentora-theme-toggle]').forEach((button) => {
                button.setAttribute('aria-label', label);
                button.setAttribute('title', label);
                button.setAttribute('aria-pressed', String(mode === 'dark'));
            });
        };

        // Paint a preference (without saving it). Used for live previews too.
        const paint = (pref, animate = false, committed = false) => {
            const mode = resolve(pref);
            if (animate && !reducedMotion.matches && root.dataset.theme && root.dataset.theme !== mode) {
                root.classList.add('theme-transition');
                clearTimeout(transitionTimer);
                transitionTimer = setTimeout(() => root.classList.remove('theme-transition'), 450);
            }
            root.dataset.theme = mode;
            root.dataset.themePreference = pref; // 'system' shows the "A" (Auto) badge on the button
            root.style.colorScheme = mode;
            if (document.body) {
                document.body.classList.toggle('workspace-dark-mode', mode === 'dark');
            }
            labelToggles(mode, pref);
            listeners.forEach((listener) => listener({ mode, preference: pref, committed }));
            return mode;
        };

        // Save and paint a preference.
        const set = (pref, { save = true, animate = true } = {}) => {
            preference = ['system', 'light', 'dark'].includes(pref) ? pref : 'system';
            if (save) {
                try {
                    const saved = JSON.parse(window.localStorage.getItem(KEY) || '{}') || {};
                    saved.theme = preference;
                    saved.themeRev = THEME_REV;
                    window.localStorage.setItem(KEY, JSON.stringify(saved));
                    window.localStorage.setItem(LEGACY_KEY, preference);
                } catch (error) {
                    // Not saved, but still applied to this page.
                }
            }
            return paint(preference, animate, true);
        };

        // Switch to the other mode. If that is the mode the device is already using, go back to Auto
        // instead of saving a fixed choice, so changing the device's setting keeps working.
        const toggle = () => {
            const next = resolve(preference) === 'dark' ? 'light' : 'dark';
            const deviceMode = deviceDark.matches ? 'dark' : 'light';
            return set(next === deviceMode ? 'system' : next);
        };

        // Follow the device while on Auto, and keep other open tabs in sync.
        deviceDark.addEventListener('change', () => {
            if (preference === 'system') {
                paint(preference, true, true);
            }
        });
        window.addEventListener('storage', (event) => {
            if (!FOLLOW_DEVICE && (event.key === KEY || event.key === LEGACY_KEY)) {
                preference = readPreference();
                paint(preference, true, true);
            }
        });

        document.addEventListener('click', (event) => {
            if (event.target.closest('[data-mentora-theme-toggle]')) {
                toggle();
            }
        });
        document.addEventListener('DOMContentLoaded', () => labelToggles(resolve(preference), preference));

        paint(preference);

        return {
            get preference() { return preference; },
            get mode() { return resolve(preference); },
            resolve,
            paint,
            set,
            toggle,
            onChange: (listener) => listeners.push(listener)
        };
    })();
</script>
