<section class="theme-settings-card">
    <div>
        <h2>Appearance</h2>
        <p>Choose the display mode for your Mentora workspace.</p>
    </div>
    <button class="theme-toggle-button" type="button" data-theme-toggle aria-pressed="false">Dark mode</button>
</section>
<script>
    (() => {
        const buttons = document.querySelectorAll('[data-theme-toggle]');
        const applyTheme = (theme) => {
            const isDark = theme === 'dark';
            document.body.classList.toggle('workspace-dark-mode', isDark);
            buttons.forEach((button) => {
                button.textContent = isDark ? 'Light mode' : 'Dark mode';
                button.setAttribute('aria-pressed', String(isDark));
            });
        };

        applyTheme(window.localStorage.getItem('mentora-workspace-theme') || 'light');
        buttons.forEach((button) => {
            button.addEventListener('click', () => {
                const nextTheme = document.body.classList.contains('workspace-dark-mode') ? 'light' : 'dark';
                window.localStorage.setItem('mentora-workspace-theme', nextTheme);
                applyTheme(nextTheme);
            });
        });
    })();
</script>
