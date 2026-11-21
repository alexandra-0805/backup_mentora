<?php
// Path back to the app root ("" or "../"). includes/header.php sets these; the same rule is repeated here so
// the footer also works (and is understood by the editor) on its own.
$inAccount ??= str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/account/');
$root ??= (str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/auth/') || $inAccount) ? '../' : '';
?>
<?php if (empty($hideFooter)): // a page can set $hideFooter = true (Messages does) to go without the footer ?>
<footer class="footer">
    <div>
        <a class="brand footer-logo-link" href="<?= $root ?>index.php" aria-label="Mentora home">
            <img
                class="footer-logo"
                src="<?= $root ?>assets/images/mentora-logo.png"
                alt="">
        </a>
        <div class="footer-brand-text">
            <a class="footer-brand-name hover-lift-text" href="<?= $root ?>index.php">Mentora</a>
            <small class="hover-lift-text">Practical support. Proven guidance.</small>
        </div>
    </div>

    <div>
        <small class="hover-lift-text">© <?= date('Y') ?> Mentora. All rights reserved.</small>
    </div>
</footer>
<?php endif; ?>

<script src="<?= $root ?>assets/js/forms.js?v=3"></script>
<script>
    // Show / hide password: adds an eye button inside every password field on the page.
    (() => {
        const eye = `<svg class="icon-show"
            viewBox="0 0 24 24"
            aria-hidden="true"
            focusable="false"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>`;
        const eyeOff = `<svg class="icon-hide"
            viewBox="0 0 24 24"
            aria-hidden="true"
            focusable="false"><path d="M3 3l18 18"/><path d="M10.6 5.1A10.4 10.4 0 0 1 12 5c6.4 0 10 7 10 7a17.6 17.6 0 0 1-3.2 4.1M6.6 6.6C3.9 8.3 2 12 2 12s3.6 7 10 7a9.7 9.7 0 0 0 5.4-1.6"/><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/></svg>`;

        document.querySelectorAll('input[type="password"]').forEach((input) => {
            const wrap = document.createElement('div');
            wrap.className = 'password-field';
            input.parentNode.insertBefore(wrap, input);
            wrap.appendChild(input);

            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'password-toggle';
            button.setAttribute('aria-controls', input.id);
            button.innerHTML = eye + eyeOff;
            wrap.appendChild(button);

            const set = (visible) => {
                input.type = visible ? 'text' : 'password';
                button.classList.toggle('is-visible', visible);
                button.setAttribute('aria-pressed', String(visible));
                button.setAttribute('aria-label', visible ? 'Hide password' : 'Show password');
            };
            set(false);
            button.addEventListener('click', () => {
                set(input.type === 'password');
                input.focus();
            });
            // Hide again before sending, so browsers and password managers treat it as a password.
            input.form?.addEventListener('submit', () => set(false));
        });
    })();
</script>

<?php require __DIR__ . '/confirm_dialog.php'; ?>
<?php if ($inAccount) { require __DIR__ . '/presence_heartbeat.php'; } // signed-in pages such as Messages ?>

</body>

</html>