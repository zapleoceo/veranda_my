// Column sort. Click a `.pd3-sortable` header → sort the table's rows by
// the `data-sort-key` lookup on each row's data-* attributes.
//
// Numeric vs string is auto-detected per key (sum/total/card/tips/num/ts → numeric).
// Toggles between asc → desc → none on repeated clicks.
//
// Every <tbody> is sorted on its own: the «Деньги» table keeps incoming
// rows above the «Расходы» divider and outgoing rows below it, whatever
// the column.

'use strict';

// One collator for every comparison (localeCompare(…, 'ru') builds one per call).
const COLLATOR = new Intl.Collator('ru');

const NUMERIC_KEYS = new Set(['sum', 'total', 'card', 'tips', 'num', 'ts', 'table']);

function compare(a, b, key) {
    const av = a.dataset[key];
    const bv = b.dataset[key];
    if (NUMERIC_KEYS.has(key)) {
        const an = Number(av) || 0;
        const bn = Number(bv) || 0;
        if (an < bn) return -1;
        if (an > bn) return 1;
        return 0;
    }
    return COLLATOR.compare(String(av ?? ''), String(bv ?? ''));
}

/**
 * Reorder one tbody's `tr.pd3-row`s. dir '' restores the document order
 * stamped on the first sort (data-pd3-orig-idx).
 * @param {{querySelectorAll:Function, appendChild:Function}} tbody
 * @param {string} key
 * @param {'asc'|'desc'|''} dir
 */
export function sortTbody(tbody, key, dir) {
    const rows = Array.from(tbody.querySelectorAll('tr.pd3-row'));
    if (!rows.length) return;
    rows.forEach((r, i) => { if (!r.dataset.pd3OrigIdx) r.dataset.pd3OrigIdx = String(i); });
    if (!dir) {
        rows.sort((a, b) => Number(a.dataset.pd3OrigIdx) - Number(b.dataset.pd3OrigIdx));
    } else {
        rows.sort((a, b) => {
            const c = compare(a, b, key);
            return dir === 'desc' ? -c : c;
        });
    }
    rows.forEach((r) => tbody.appendChild(r));
}

export function initSort() {
    document.querySelectorAll('.pd3-table').forEach((table) => {
        table.querySelectorAll('thead th.pd3-sortable').forEach((th) => {
            th.addEventListener('click', () => {
                const key = th.dataset.sortKey;
                if (!key) return;
                const currentDir = th.dataset.sortDir || '';
                const nextDir = currentDir === 'asc' ? 'desc' : currentDir === 'desc' ? '' : 'asc';

                // Reset other headers in the same table.
                table.querySelectorAll('thead th.pd3-sortable').forEach((other) => {
                    if (other !== th) other.removeAttribute('data-sort-dir');
                });
                if (nextDir) th.dataset.sortDir = nextDir;
                else th.removeAttribute('data-sort-dir');

                for (const tbody of table.tBodies) sortTbody(tbody, key, nextDir);
            });
        });
    });
}
