/*
 * One dialog for adding and editing a record (Clients, Tasks & deliverables, …).
 *
 *   <button data-record-open="#client-dialog">+ Add client</button>                         empty form
 *   <button data-record-open="#client-dialog" data-record='{"id":3,"name":"Arkiste"}'
 *           data-record-title="Edit Arkiste">Edit</button>                                     form filled in
 *
 *   <dialog id="client-dialog" data-add-title="Add a client" data-add-save="Add client"
 *           data-edit-save="Save changes">
 *     <form> … <h1 data-record-heading></h1> … <button data-record-save></button>
 *     [data-record-only="add"] / [data-record-only="edit"] show only when adding / editing
 *
 * Fields are filled by name (form.elements[name]); the dialog itself opens and closes like the
 * other dialogs (includes/confirm_dialog.php handles data-bs-dismiss).
 */
(() => {
    document.addEventListener('click', (event) => {
        const opener = event.target.closest('[data-record-open]');
        if (!opener) {
            return;
        }
        const dialog = document.querySelector(opener.dataset.recordOpen);
        const form = dialog ? dialog.querySelector('form') : null;
        if (!form) {
            return;
        }
        event.preventDefault();
        let record = null;
        try {
            record = opener.dataset.record ? JSON.parse(opener.dataset.record) : null;
        } catch (error) {
            record = null;
        }

        form.reset();
        // Hidden fields keep what they were last set to, so clear them for a new record.
        form.querySelectorAll('input[type="hidden"][data-record-clear]').forEach((field) => { field.value = ''; });
        Object.entries(record || {}).forEach(([name, value]) => {
            const field = form.elements.namedItem(name);
            if (field && 'value' in field) {
                field.value = value === null || value === undefined ? '' : String(value);
            }
        });

        const heading = dialog.querySelector('[data-record-heading]');
        if (heading) {
            heading.textContent = record ? (opener.dataset.recordTitle || dialog.dataset.editTitle || 'Edit') : (dialog.dataset.addTitle || 'Add');
        }
        const save = dialog.querySelector('[data-record-save]');
        if (save) {
            save.textContent = record ? (dialog.dataset.editSave || 'Save changes') : (dialog.dataset.addSave || 'Add');
        }
        dialog.querySelectorAll('[data-record-only="add"]').forEach((element) => { element.hidden = Boolean(record); });
        dialog.querySelectorAll('[data-record-only="edit"]').forEach((element) => { element.hidden = !record; });

        if (typeof dialog.showModal === 'function' && !dialog.open) {
            dialog.showModal();
        }
        setTimeout(() => {
            const first = form.querySelector('input:not([type="hidden"]):not([disabled]), select:not([disabled]), textarea');
            if (first) {
                first.focus();
            }
        }, 60);
    });
})();
