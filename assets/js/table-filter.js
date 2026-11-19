/*
 * Live table filters (admin, agent and applicant workspaces).
 *
 *   <div class="table-toolbar" data-table-filter="people-table">
 *     <input type="search" data-filter="search">            matches any text in the row
 *     <select data-filter="status">…</select>               matches the row's data-status
 *   </div>
 *   <table id="people-table"> … <tr data-status="active"> …
 *   <span data-filter-count="people-table"></span>         "Showing N of M"
 */
(() => {
    const normalise = (value) => String(value || '').toLocaleLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');

    document.querySelectorAll('[data-table-filter]').forEach((toolbar) => {
        const table = document.getElementById(toolbar.dataset.tableFilter);
        const body = table ? table.tBodies[0] : null;
        if (!body) {
            return;
        }
        const rows = Array.from(body.rows).filter((row) => !row.classList.contains('table-empty-row'));
        const controls = Array.from(toolbar.querySelectorAll('[data-filter]'));
        const counters = document.querySelectorAll('[data-filter-count="' + toolbar.dataset.tableFilter + '"]');
        const columns = table.tHead ? table.tHead.rows[0].cells.length : 1;

        let emptyRow = body.querySelector('.table-filter-empty');
        if (!emptyRow) {
            emptyRow = body.insertRow();
            emptyRow.className = 'table-filter-empty';
            const cell = emptyRow.insertCell();
            cell.colSpan = columns;
            cell.textContent = 'No records match your search.';
        }
        emptyRow.hidden = true;

        const apply = () => {
            let shown = 0;
            rows.forEach((row) => {
                const visible = controls.every((control) => {
                    const wanted = normalise(control.value).trim();
                    if (wanted === '' || wanted === 'all') {
                        return true;
                    }
                    if (control.dataset.filter === 'search') {
                        return normalise(row.textContent).includes(wanted);
                    }
                    return normalise(row.dataset[control.dataset.filter]) === wanted;
                });
                row.hidden = !visible;
                shown += visible ? 1 : 0;
            });
            emptyRow.hidden = shown > 0 || rows.length === 0;
            counters.forEach((counter) => {
                counter.textContent = shown === rows.length ? String(rows.length) : shown + ' of ' + rows.length;
            });
        };

        controls.forEach((control) => {
            control.addEventListener(control.tagName === 'SELECT' ? 'change' : 'input', apply);
        });
        apply();
    });
})();
