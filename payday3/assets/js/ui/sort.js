// Column sort. Click a `.pd3-sortable` header → sort its tbody by the
// `data-sort-key` lookup on each row's data-* attributes.
//
// Numeric vs string is auto-detected per key (sum/total/card/tips/num/ts → numeric).
// Toggles between asc → desc → none on repeated clicks.

'use strict';

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
    return String(av ?? '').localeCompare(String(bv ?? ''), 'ru');
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

                const tbody = table.tBodies[0];
                if (!tbody) return;
                const rows = Array.from(tbody.querySelectorAll('tr.pd3-row'));

                if (!nextDir) {
                    // Restore document order: just re-append by the original index
                    // stamp we add the first time we touch a row.
                    rows.sort((a, b) => Number(a.dataset.pd3OrigIdx || 0) - Number(b.dataset.pd3OrigIdx || 0));
                } else {
                    rows.forEach((r, i) => { if (!r.dataset.pd3OrigIdx) r.dataset.pd3OrigIdx = String(i); });
                    rows.sort((a, b) => {
                        const c = compare(a, b, key);
                        return nextDir === 'desc' ? -c : c;
                    });
                }
                rows.forEach((r) => tbody.appendChild(r));
            });
        });
    });
}
