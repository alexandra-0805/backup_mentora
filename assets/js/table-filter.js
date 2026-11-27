/*
 * Live table filters and paging (admin, agent and applicant workspaces).
 *
 *   <div class="table-toolbar" data-table-filter="people-table">
 *     <input type="search" data-filter="search">            matches any text in the row
 *     <select data-filter="status">...</select>               matches the row's data-status
 *   </div>
 *   <table id="people-table"> ... <tr data-status="active"> ...
 *   <span data-filter-count="people-table"></span>         "Showing N of M"
 *
 * Paging: every .admin-table, and every list of more than 10 .list-row / .notice-row / .screening-card siblings,
 * shows 10 at a time with Previous / Next under it. With a filter, the pages go through the matching rows only.
 * Add data-no-pager to a table (or a list's parent) to always show everything. Printing shows every row.
 */
(() => {
    const PAGE_SIZE = 10;
    const normalise = (value) => String(value || '').toLocaleLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');

    /**
     * Pages through `items` (rows or cards), PAGE_SIZE at a time, with a Previous / Next bar inserted after `anchor`.
     * setMatches(list) narrows it to the rows a filter kept (the rest stay hidden by the filter) and goes back to page 1.
     */
    const chevron = '<svg class="data-pager-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m9 6 6 6-6 6"/></svg>';

    /** alwaysShow: keep the bar even when everything fits on one page (tables); otherwise it appears past PAGE_SIZE. */
    const createPager = (items, anchor, scrollTarget, alwaysShow) => {
        let matches = items;
        let page = 0;

        const bar = document.createElement('div');
        bar.className = 'data-pager';
        bar.setAttribute('role', 'navigation');
        bar.setAttribute('aria-label', 'Pages');
        bar.innerHTML = '<button type="button" class="data-pager-btn data-pager-prev" data-pager-prev>'
            + chevron + '<span>Previous</span></button>'
            + '<span class="data-pager-info" aria-live="polite"></span>'
            + '<button type="button" class="data-pager-btn" data-pager-next>'
            + '<span>Next</span>' + chevron + '</button>';
        anchor.after(bar);
        const prev = bar.querySelector('[data-pager-prev]');
        const next = bar.querySelector('[data-pager-next]');
        const info = bar.querySelector('.data-pager-info');

        const render = () => {
            const pageCount = Math.max(1, Math.ceil(matches.length / PAGE_SIZE));
            page = Math.min(Math.max(page, 0), pageCount - 1);
            const start = page * PAGE_SIZE;
            const onPage = new Set(matches.slice(start, start + PAGE_SIZE));
            items.forEach((item) => item.classList.toggle('is-paged-out', !onPage.has(item)));
            bar.hidden = !alwaysShow && matches.length <= PAGE_SIZE;
            prev.disabled = page === 0;
            next.disabled = page >= pageCount - 1;
            info.textContent = matches.length === 0
                ? '0 of 0'
                : (start + 1) + '\u2013' + (start + onPage.size) + ' of ' + matches.length;
            info.title = 'Page ' + (page + 1) + ' of ' + pageCount;
        };

        const turn = (step) => {
            page += step;
            render();
            // Keep the top of the list in view so the new page reads from its first row.
            if (scrollTarget.getBoundingClientRect().top < 0) {
                scrollTarget.scrollIntoView({ block: 'start' });
            }
        };
        prev.addEventListener('click', () => turn(-1));
        next.addEventListener('click', () => turn(1));

        const pager = {
            items,
            setMatches(list) {
                matches = list;
                page = 0;
                render();
            },
            /** Go to the page holding `item` (e.g. the card a #link points to). */
            reveal(item) {
                const index = matches.indexOf(item);
                if (index >= 0) {
                    page = Math.floor(index / PAGE_SIZE);
                    render();
                }
            },
        };
        render();
        return pager;
    };

    const pagers = [];
    const tablePagers = new Map();
    // A lone row with one cell spanning the table is a "Nothing yet" message, not a record.
    const isPlaceholderRow = (row) => row.cells.length === 1 && row.cells[0].colSpan > 1
        && Array.from(row.parentElement.rows).filter((other) => !other.classList.contains('table-filter-empty')).length === 1;
    const isDataRow = (row) => !row.classList.contains('table-empty-row') && !row.classList.contains('table-filter-empty')
        && !isPlaceholderRow(row);

    // Tables.
    document.querySelectorAll('table.admin-table').forEach((table) => {
        const body = table.tBodies[0];
        if (!body || table.closest('[data-no-pager]')) {
            return;
        }
        const rows = Array.from(body.rows).filter(isDataRow);
        // Put the bar under the table's own scroll box when it has one, so it doesn't scroll sideways with the table.
        const wrapper = table.parentElement;
        const anchor = wrapper && wrapper.children.length === 1 && !wrapper.matches('.admin-panel, .pathway-card')
            && ['auto', 'scroll'].includes(getComputedStyle(wrapper).overflowX) ? wrapper : table;
        // Tables always show the bar, even when empty ("0 of 0"), so every list pages the same way.
        const pager = createPager(rows, anchor, table, true);
        pagers.push(pager);
        tablePagers.set(table, pager);
    });

    // Lists of rows or cards that sit side by side in one parent.
    const listItemSelector = '.list-row, .notice-row, .screening-card';
    const listParents = new Set();
    document.querySelectorAll(listItemSelector).forEach((item) => {
        if (item.parentElement) {
            listParents.add(item.parentElement);
        }
    });
    listParents.forEach((parent) => {
        if (parent.closest('[data-no-pager]')) {
            return;
        }
        const items = Array.from(parent.children).filter((child) => child.matches(listItemSelector));
        if (items.length > PAGE_SIZE) {
            pagers.push(createPager(items, items[items.length - 1], items[0]));
        }
    });

    // Filters.
    document.querySelectorAll('[data-table-filter]').forEach((toolbar) => {
        const table = document.getElementById(toolbar.dataset.tableFilter);
        const body = table ? table.tBodies[0] : null;
        if (!body) {
            return;
        }
        const rows = Array.from(body.rows).filter(isDataRow);
        const controls = Array.from(toolbar.querySelectorAll('[data-filter]'));
        const counters = document.querySelectorAll('[data-filter-count="' + toolbar.dataset.tableFilter + '"]');
        const columns = table.tHead ? table.tHead.rows[0].cells.length : 1;
        const pager = tablePagers.get(table);

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
            const kept = rows.filter((row) => {
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
                return visible;
            });
            const shown = kept.length;
            if (pager) {
                pager.setMatches(kept);
            }
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

    // A link to a row or card on another page (e.g. screening.php#r12) opens that page first.
    const revealHash = () => {
        let target = null;
        try {
            target = location.hash.length > 1 ? document.getElementById(decodeURIComponent(location.hash.slice(1))) : null;
        } catch (error) {
            return;
        }
        if (!target) {
            return;
        }
        pagers.forEach((pager) => {
            const item = pager.items.find((candidate) => candidate === target || candidate.contains(target));
            if (item) {
                pager.reveal(item);
                target.scrollIntoView({ block: 'start' });
            }
        });
    };
    window.addEventListener('hashchange', revealHash);
    revealHash();
})();
