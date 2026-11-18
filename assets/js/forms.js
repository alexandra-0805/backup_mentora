/**
 * Live form checks for forms marked <form data-validate> (sign-up, reset password).
 * Each input says what to check with data-check="name | email | password | match | agree"
 * and has a message slot <small class="field-error" id="<name>-error">.
 *
 * The same rules are enforced on the server (includes/auth_check.php: mentora_password_checks,
 * mentora_name_error, mentora_email_error), so keep the two in step.
 */
(() => {
    const PASSWORD_RULES = [
        (v) => v.length >= 8 && v.length <= 64,
        (v) => /[A-Z]/.test(v),
        (v) => /[a-z]/.test(v),
        (v) => /\d/.test(v),
        (v) => /[^A-Za-z0-9\s]/.test(v),
        (v) => v !== '' && !/\s/.test(v),
    ];
    const NAME = /^\p{L}[\p{L}\p{M} .'’-]*$/u;
    const EMAIL = /^[^\s@]+@[A-Za-z0-9-]+(\.[A-Za-z0-9-]+)*\.[A-Za-z]{2,}$/;
    const capitalise = (text) => text.charAt(0).toUpperCase() + text.slice(1);

    // Returns an error message, or '' when the field is fine.
    const checks = {
        name(input) {
            const value = input.value.trim().replace(/\s+/g, ' ');
            const label = input.dataset.label || 'name';
            if (value === '') return `Please enter your ${label}.`;
            if (value.length < 2 || value.length > 80) return `${capitalise(label)} must be 2 to 80 characters.`;
            if (!NAME.test(value)) return `${capitalise(label)} can only contain letters, spaces, hyphens (-), apostrophes (') and periods.`;
            return '';
        },
        email(input) {
            const value = input.value.trim();
            if (value === '') return 'Please enter your email address.';
            if (value.length > 190 || !EMAIL.test(value)) return 'Please enter a valid email address, e.g. name@gmail.com.';
            return '';
        },
        password(input) {
            if (input.value === '') return 'Please create a password.';
            return PASSWORD_RULES.every((rule) => rule(input.value)) ? '' : 'Your password doesn’t meet all the requirements above yet.';
        },
        match(input) {
            const original = input.form.querySelector(`[name="${input.dataset.match}"]`);
            if (input.value === '') return 'Please enter your password again.';
            return original && input.value !== original.value ? 'Passwords do not match.' : '';
        },
        agree(input) {
            return input.checked ? '' : 'Please accept the Data Privacy Notice to create an account.';
        },
    };

    document.querySelectorAll('form[data-validate]').forEach((form) => {
        form.noValidate = true;   // we show our own messages instead of the browser's bubbles
        const fields = [...form.querySelectorAll('[data-check]')];
        const touched = new Set(fields.filter((input) => input.getAttribute('aria-invalid') === 'true'));

        const slot = (input) => document.getElementById(`${input.name}-error`);

        const validate = (input) => {
            const message = checks[input.dataset.check]?.(input) ?? '';
            const show = touched.has(input);
            const box = slot(input);
            if (box) box.textContent = show ? message : '';
            input.setAttribute('aria-invalid', show && message ? 'true' : 'false');
            input.classList.toggle('is-valid', !message && input.type !== 'checkbox' && input.value !== '');
            return message === '';
        };

        // Password checklist: ticks off each rule as you type.
        const updateRules = (input) => {
            const list = document.getElementById('password-rules');
            if (!list) return;
            list.querySelectorAll('[data-rule]').forEach((item) => {
                const ok = PASSWORD_RULES[Number(item.dataset.rule)](input.value);
                item.classList.toggle('is-met', ok);
            });
        };

        fields.forEach((input) => {
            const live = input.dataset.check === 'match' || input.dataset.check === 'password';
            input.addEventListener(input.type === 'checkbox' ? 'change' : 'input', () => {
                if (input.dataset.check === 'password') {
                    updateRules(input);
                    // Re-check the confirm field as the original changes.
                    form.querySelectorAll(`[data-match="${input.name}"]`).forEach((confirm) => {
                        if (confirm.value !== '') { touched.add(confirm); validate(confirm); }
                    });
                }
                // Confirm password is checked as you type; others once you've left the field.
                if (live && input.value !== '') touched.add(input);
                if (input.type === 'checkbox') touched.add(input);
                validate(input);
            });
            input.addEventListener('blur', () => {
                if (input.value !== '' || touched.has(input)) { touched.add(input); validate(input); }
            });
            if (input.dataset.check === 'password') updateRules(input);
            if (touched.has(input)) validate(input);
        });

        form.addEventListener('submit', (event) => {
            fields.forEach((input) => touched.add(input));
            // Check every field (not just up to the first problem) so all messages show at once.
            const firstBad = fields.filter((input) => !validate(input))[0];
            if (firstBad) {
                event.preventDefault();
                event.stopImmediatePropagation();
                firstBad.focus();
            }
        });
    });

    // Copy a form's chosen pathway into another form (the separate Continue with Google form on sign-up).
    document.querySelectorAll('input[data-pathway-from]').forEach((hidden) => {
        const source = document.getElementById(hidden.dataset.pathwayFrom);
        if (!source) return;
        const sync = () => {
            const chosen = source.querySelector('input[name="pathway"]:checked');
            if (chosen) hidden.value = chosen.value;
        };
        source.addEventListener('change', sync);
        sync();
    });
})();
