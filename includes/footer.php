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

    <div class="footer-meta">
        <!-- Social links (a <ul>, not a <div>, so the footer's ".footer div" column rule doesn't apply) -->
        <ul class="footer-social" aria-label="Mentora on social media">
            <li>
                <a class="footer-social-link is-linkedin"
                    href="https://www.linkedin.com/company/mentora-ph/home/"
                    target="_blank" rel="noopener noreferrer"
                    aria-label="Mentora on LinkedIn (opens in a new tab)">
                    <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433c-1.144 0-2.063-.926-2.063-2.065 0-1.138.92-2.063 2.063-2.063 1.14 0 2.064.925 2.064 2.063 0 1.139-.925 2.065-2.064 2.065zm1.782 13.019H3.555V9h3.564v11.452z"/></svg>
                </a>
            </li>
            <li>
                <a class="footer-social-link is-facebook"
                    href="https://www.facebook.com/people/Mentora-PH/61590997361336/"
                    target="_blank" rel="noopener noreferrer"
                    aria-label="Mentora on Facebook (opens in a new tab)">
                    <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M9.101 23.691v-7.98H6.627v-3.667h2.474v-1.58c0-4.085 1.848-5.978 5.858-5.978.401 0 .955.042 1.468.103a8.68 8.68 0 0 1 1.141.195v3.325a8.623 8.623 0 0 0-.653-.036 26.805 26.805 0 0 0-.733-.009c-.707 0-1.259.096-1.675.309a1.686 1.686 0 0 0-.679.622c-.258.42-.374.995-.374 1.752v1.297h3.919l-.386 2.103-.287 1.564h-3.246v8.245C19.396 23.238 24 18.179 24 12.044c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.628 3.874 10.35 9.101 11.647Z"/></svg>
                </a>
            </li>
            <li>
                <a class="footer-social-link is-instagram"
                    href="https://www.instagram.com/mentora.ph/"
                    target="_blank" rel="noopener noreferrer"
                    aria-label="Mentora on Instagram (opens in a new tab)">
                    <svg class="is-stroke" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4"/><circle class="dot" cx="17.5" cy="6.5" r="1.1"/></svg>
                </a>
            </li>
            <li>
                <a class="footer-social-link is-threads"
                    href="https://www.threads.com/@mentora.ph"
                    target="_blank" rel="noopener noreferrer"
                    aria-label="Mentora on Threads (opens in a new tab)">
                    <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12.186 24h-.007c-3.581-.024-6.334-1.205-8.184-3.509C2.35 18.44 1.5 15.586 1.472 12.01v-.017c.03-3.579.879-6.43 2.525-8.482C5.845 1.205 8.6.024 12.18 0h.014c2.746.02 5.043.725 6.826 2.098 1.677 1.29 2.858 3.13 3.509 5.467l-2.04.569c-1.104-3.96-3.898-5.984-8.304-6.015-2.91.022-5.11.936-6.54 2.717C4.307 6.504 3.616 8.914 3.589 12c.027 3.086.718 5.496 2.057 7.164 1.43 1.783 3.631 2.698 6.54 2.717 2.623-.02 4.358-.631 5.8-2.045 1.647-1.613 1.618-3.593 1.09-4.798-.31-.71-.873-1.3-1.634-1.75-.192 1.352-.622 2.446-1.284 3.272-.886 1.102-2.14 1.704-3.73 1.79-1.202.065-2.361-.218-3.259-.801-1.063-.689-1.685-1.74-1.752-2.964-.065-1.19.408-2.285 1.33-3.082.88-.76 2.119-1.207 3.583-1.291a13.853 13.853 0 0 1 3.02.142c-.126-.742-.375-1.332-.75-1.757-.513-.586-1.308-.883-2.359-.89h-.029c-.844 0-1.992.232-2.721 1.32L7.734 7.847c.98-1.454 2.568-2.256 4.478-2.256h.044c3.194.02 5.097 1.975 5.287 5.388.108.046.216.094.321.142 1.49.7 2.58 1.761 3.154 3.07.797 1.82.871 4.79-1.548 7.158-1.85 1.81-4.094 2.628-7.277 2.65Zm1.003-11.69c-.242 0-.487.007-.739.021-1.836.103-2.98.946-2.916 2.143.067 1.256 1.452 1.839 2.784 1.767 1.224-.065 2.818-.543 3.086-3.71a10.5 10.5 0 0 0-2.215-.221z"/></svg>
                </a>
            </li>
        </ul>

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