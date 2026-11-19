<?php
/**
 * Slide-down dialogs for every page (included once, from the page footers). Styles: assets/css/dialog.css
 *
 * 1. Bootstrap-style markup works as-is:
 *      <button data-bs-toggle="dialog" data-bs-target="#myDialog">Open</button>
 *      <dialog class="dialog dialog-slide-down" id="myDialog"> ... data-bs-dismiss="dialog" ... </dialog>
 *
 * 2. Confirm before a change: add data-confirm to a link, a button or a form's submit button.
 *      data-confirm="Message"              (required) text in the dialog
 *      data-confirm-title="Save changes?"  (optional) heading, default "Confirm changes"
 *      data-confirm-action="Save"          (optional) confirm button label, default: the button's own text
 *      data-confirm-tone="danger"          (optional) red confirm button for destructive actions
 *    Forms are checked first (required fields etc.), then the dialog opens, then the form submits.
 *
 * 3. From JavaScript: const ok = await window.MentoraConfirm({ title, message, items, confirmLabel, cancelLabel, tone });
 */
if (!empty($mentoraConfirmDialogIncluded)) {
    return;
}
$mentoraConfirmDialogIncluded = true;
?>
<dialog class="dialog dialog-slide-down" id="mentoraConfirmDialog" aria-labelledby="mentoraConfirmTitle" aria-describedby="mentoraConfirmText">
    <div class="dialog-header">
        <h1 class="dialog-title" id="mentoraConfirmTitle">Confirm changes</h1>
        <button type="button" class="btn-close" data-bs-dismiss="dialog" aria-label="Close"></button>
    </div>
    <div class="dialog-body">
        <p id="mentoraConfirmText"></p>
        <ul class="dialog-list" hidden></ul>
    </div>
    <div class="dialog-footer">
        <button type="button" class="btn-solid theme-secondary" data-bs-dismiss="dialog" data-dialog-cancel>Cancel</button>
        <button type="button" class="btn-solid theme-primary" data-dialog-confirm>Confirm</button>
    </div>
</dialog>
<script>
    (() => {
        const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');

        const closeTimers = new WeakMap();
        const closeHooks = new WeakMap(); // runs the moment a dialog starts closing

        const openDialog = (dialog) => {
            if (!dialog || typeof dialog.showModal !== 'function') {
                return;
            }
            // Reopened during the slide-up: cancel the pending close and stay open.
            clearTimeout(closeTimers.get(dialog));
            dialog.classList.remove('is-closing');
            if (!dialog.open) {
                dialog.showModal();
            }
        };

        // Close with the slide-up animation (skipped for reduced motion).
        const closeDialog = (dialog) => {
            if (!dialog || !dialog.open || dialog.classList.contains('is-closing')) {
                return;
            }
            const hook = closeHooks.get(dialog);
            if (hook) {
                hook();
            }
            if (reducedMotion.matches || !dialog.classList.contains('dialog-slide-down')) {
                dialog.close();
                return;
            }
            dialog.classList.add('is-closing');
            closeTimers.set(dialog, setTimeout(() => {
                dialog.classList.remove('is-closing');
                dialog.close();
            }, 180));
        };

        // Bootstrap-style open / dismiss, plus closing by clicking the dimmed backdrop.
        document.addEventListener('click', (event) => {
            const opener = event.target.closest('[data-bs-toggle="dialog"]');
            if (opener) {
                event.preventDefault();
                openDialog(document.querySelector(opener.dataset.bsTarget || opener.getAttribute('href')));
                return;
            }
            const dismiss = event.target.closest('[data-bs-dismiss="dialog"]');
            if (dismiss) {
                closeDialog(dismiss.closest('dialog'));
                return;
            }
            if (event.target instanceof HTMLDialogElement && event.target.classList.contains('dialog')) {
                closeDialog(event.target);
            }
        });

        // Escape key: animate the close too.
        document.addEventListener('cancel', (event) => {
            if (event.target instanceof HTMLDialogElement && event.target.classList.contains('dialog')) {
                event.preventDefault();
                closeDialog(event.target);
            }
        }, true);

        // ---- Confirmation dialog ----
        const dialog = document.getElementById('mentoraConfirmDialog');
        const title = dialog.querySelector('.dialog-title');
        const text = dialog.querySelector('#mentoraConfirmText');
        const list = dialog.querySelector('.dialog-list');
        const confirmButton = dialog.querySelector('[data-dialog-confirm]');
        const cancelButton = dialog.querySelector('[data-dialog-cancel]');
        let resolveAnswer = null;

        // Give the answer exactly once, as soon as the person chooses (not when the animation ends).
        const settle = (value) => {
            const resolve = resolveAnswer;
            resolveAnswer = null;
            if (resolve) {
                resolve(value);
            }
        };
        closeHooks.set(dialog, () => settle(false)); // Cancel, ×, Esc or backdrop = "no"

        window.MentoraConfirm = (options = {}) => new Promise((resolve) => {
            if (typeof dialog.showModal !== 'function') {
                resolve(window.confirm([options.title, options.message].filter(Boolean).join('\n\n')));
                return;
            }
            settle(false); // a new question replaces any unanswered one
            title.textContent = options.title || 'Confirm changes';
            text.textContent = options.message || '';
            text.hidden = !options.message;
            list.replaceChildren(...(options.items || []).map((item) => {
                const li = document.createElement('li');
                li.textContent = item;
                return li;
            }));
            list.hidden = !(options.items && options.items.length);
            confirmButton.textContent = options.confirmLabel || 'Confirm';
            confirmButton.className = 'btn-solid ' + (options.tone === 'danger' ? 'theme-danger' : 'theme-primary');
            cancelButton.textContent = options.cancelLabel || 'Cancel';
            resolveAnswer = resolve;
            openDialog(dialog);
            // Destructive actions start on Cancel; everything else on the confirm button.
            (options.tone === 'danger' ? cancelButton : confirmButton).focus();
        });

        confirmButton.addEventListener('click', () => {
            settle(true);
            closeDialog(dialog);
        });

        // Closed some other way (e.g. by script): treat as "no". The browser can deliver this event
        // late, after the next question is already showing, so ignore it while the dialog is open.
        dialog.addEventListener('close', () => {
            if (!dialog.open) {
                settle(false);
            }
        });

        // Anything with data-confirm asks first (runs before the element's own click handlers).
        document.addEventListener('click', async (event) => {
            const trigger = event.target.closest('[data-confirm]');
            if (!trigger || trigger.closest('dialog')) {
                return;
            }
            if (trigger.dataset.confirmBypass === '1') {
                delete trigger.dataset.confirmBypass;
                return;
            }
            const form = trigger.type === 'submit' ? trigger.form : null;
            if (form && !form.noValidate && !form.checkValidity()) {
                return; // let the browser point out missing or invalid fields first
            }
            event.preventDefault();
            event.stopImmediatePropagation();

            const confirmed = await window.MentoraConfirm({
                title: trigger.dataset.confirmTitle,
                message: trigger.dataset.confirm,
                confirmLabel: trigger.dataset.confirmAction || trigger.textContent.trim(),
                cancelLabel: trigger.dataset.confirmCancel,
                tone: trigger.dataset.confirmTone
            });
            if (!confirmed) {
                return;
            }
            if (form) {
                if (typeof form.requestSubmit === 'function') {
                    form.requestSubmit(trigger);
                } else {
                    form.submit();
                }
                return;
            }
            if (trigger.tagName === 'A' && trigger.getAttribute('href')) {
                if (trigger.target === '_blank') {
                    window.open(trigger.href, '_blank', 'noopener');
                } else {
                    window.location.href = trigger.href;
                }
                return;
            }
            trigger.dataset.confirmBypass = '1';
            trigger.click();
        }, true);
    })();
</script>
