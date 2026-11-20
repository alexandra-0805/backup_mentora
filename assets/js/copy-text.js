/*
 * "Copy link" buttons (workspace pages): <button type="button" data-copy-text="https://…">Copy link</button>
 * Copies the text, then shows "Copied ✓" for two seconds. Falls back to a hidden text field when the
 * Clipboard API isn't available (e.g. the site is opened over plain http from another computer).
 */
(() => {
    const fallbackCopy = (text) => {
        const field = document.createElement('textarea');
        field.value = text;
        field.setAttribute('readonly', '');
        field.style.position = 'fixed';
        field.style.opacity = '0';
        document.body.append(field);
        field.select();
        let ok = false;
        try {
            ok = document.execCommand('copy');
        } catch (error) {
            ok = false;
        }
        field.remove();
        return ok;
    };

    document.addEventListener('click', async (event) => {
        const button = event.target.closest('[data-copy-text]');
        if (!button) {
            return;
        }
        const text = button.dataset.copyText;
        let ok = false;
        try {
            await navigator.clipboard.writeText(text);
            ok = true;
        } catch (error) {
            ok = fallbackCopy(text);
        }
        const label = button.dataset.copyLabel || button.textContent;
        button.dataset.copyLabel = label;
        button.textContent = ok ? 'Copied ✓' : 'Press Ctrl+C';
        if (!ok) {
            window.prompt('Copy this link:', text);
        }
        setTimeout(() => { button.textContent = label; }, 2000);
    });
})();
