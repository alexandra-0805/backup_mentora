<?php
// Sun / moon button that switches between light and dark mode (see includes/theme_boot.php).
// Optional: $themeToggleClass adds classes (e.g. 'header-icon-link' in the workspace top bar).
$themeToggleClass = trim('theme-toggle ' . ($themeToggleClass ?? ''));
?>
<button class="<?= htmlspecialchars($themeToggleClass, ENT_QUOTES, 'UTF-8') ?>" type="button" data-mentora-theme-toggle aria-label="Switch to dark mode" title="Switch to dark mode" aria-pressed="false">
    <svg class="theme-icon-moon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M20 14.5A8 8 0 0 1 9.5 4a8 8 0 1 0 10.5 10.5Z"/></svg>
    <svg class="theme-icon-sun" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="4"/><path d="M12 2.5v2M12 19.5v2M2.5 12h2M19.5 12h2M5.3 5.3l1.4 1.4M17.3 17.3l1.4 1.4M5.3 18.7l1.4-1.4M17.3 6.7l1.4-1.4"/></svg>
</button>
<?php unset($themeToggleClass); ?>
