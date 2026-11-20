<?php
// Sun / moon button that switches between light and dark mode (see includes/theme_boot.php).
// Optional: $themeToggleClass adds classes (e.g. 'header-icon-link' in the workspace top bar).
// Animations (assets/css/theme.css): the moon rocks and its stars twinkle on hover, the sun's rays turn,
// and the new icon spins in when the mode is switched.
$themeToggleClass = trim('theme-toggle ' . ($themeToggleClass ?? ''));
?>
<button class="<?= htmlspecialchars($themeToggleClass, ENT_QUOTES, 'UTF-8') ?>"
    type="button"
    data-mentora-theme-toggle
    aria-label="Switch to dark mode"
    title="Switch to dark mode"
    aria-pressed="false">
    <svg class="theme-icon-moon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
        <path class="moon-body" d="M20 14.5A8 8 0 0 1 9.5 4a8 8 0 1 0 10.5 10.5Z"/>
        <path class="moon-star moon-star-a" d="M17 2.8v2.6M15.7 4.1h2.6"/>
        <path class="moon-star moon-star-b" d="M21 7.6v1.8M20.1 8.5h1.8"/>
    </svg>
    <svg class="theme-icon-sun" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
        <circle class="sun-core" cx="12" cy="12" r="4"/>
        <path class="sun-rays" d="M12 2.5v2M12 19.5v2M2.5 12h2M19.5 12h2M5.3 5.3l1.4 1.4M17.3 17.3l1.4 1.4M5.3 18.7l1.4-1.4M17.3 6.7l1.4-1.4"/>
    </svg>
</button>
<script>
    // Spin the new icon in after a click (only then, so nothing moves on page load).
    document.querySelectorAll('[data-mentora-theme-toggle]:not([data-spin-ready])').forEach((button) => {
        button.dataset.spinReady = '1';
        button.addEventListener('click', () => {
            button.classList.remove('is-switching');
            void button.offsetWidth; // restart the animation on repeated clicks
            button.classList.add('is-switching');
            setTimeout(() => button.classList.remove('is-switching'), 650);
        });
    });
</script>
<?php unset($themeToggleClass); ?>
