// Row colours for the whole page, from ALL link kinds at once.
//
//   row-red    no link            row-gray   has a manual link
//   row-green  auto link          row-yellow auto link flagged for review
//
// A row can be linked by more than one kind of edge:
//   incoming bank row  — to a Poster check     (check links)
//                        or a Poster income    (income links)
//   Poster finance row — to an outgoing row    (mail links)
//                        or an incoming row    (income links)
// so every table is repainted from the union — a module that knows only
// its own links would paint a row red that another kind already covers.
// Mirrors src/Payday3/Domain/RowState on the server.

'use strict';

// Cache-bust cross-module imports — see comment in out/bootstrap.js.
const _v = new URL(import.meta.url).searchParams.get('v') || '';
const _qs = _v ? '?v=' + encodeURIComponent(_v) : '';
const { SEPAY_TBODY, MAIL_TBODY } = await import(new URL('./bankTable.js' + _qs, import.meta.url).href);

const STATES = ['row-red', 'row-green', 'row-yellow', 'row-gray'];

/** Colour for a row with these edges. */
export function classify(edges) {
    if (!edges || edges.length === 0) return 'row-red';
    let manual = false, yellow = false;
    for (const e of edges) {
        if (e.is_manual) manual = true;
        if (e.link_type === 'auto_yellow') yellow = true;
    }
    if (manual) return 'row-gray';
    return yellow ? 'row-yellow' : 'row-green';
}

/**
 * Group edges by one of their id fields, across several link lists.
 * @param {Array<[Array<object>, string]>} sources  [links, idField] pairs
 * @returns {Map<number, object[]>}
 */
export function edgesBy(sources) {
    const map = new Map();
    for (const [links, key] of sources) {
        for (const l of links || []) {
            const id = Number(l[key]);
            if (!map.has(id)) map.set(id, []);
            map.get(id).push(l);
        }
    }
    return map;
}

/**
 * Repaint every reconciliation table from the current link sets.
 * Hidden rows (row-hidden) keep their class — they are out of the game.
 *
 * @param {{checkLinks?:object[], mailLinks?:object[], incomeLinks?:object[]}} links
 */
export function paintRowStates({ checkLinks = [], mailLinks = [], incomeLinks = [] } = {}) {
    const paint = (selector, idOf, edges) => {
        document.querySelectorAll(selector).forEach((tr) => {
            if (tr.classList.contains('row-hidden')) return;
            tr.classList.remove(...STATES);
            tr.classList.add(classify(edges.get(idOf(tr))));
        });
    };
    paint(`${SEPAY_TBODY} tr.pd3-row`, (tr) => Number(tr.dataset.sepayId),
        edgesBy([[checkLinks, 'sepay_id'], [incomeLinks, 'sepay_id']]));
    paint('#pd3PosterTable tr.pd3-row', (tr) => Number(tr.dataset.posterId),
        edgesBy([[checkLinks, 'poster_transaction_id']]));
    paint(`${MAIL_TBODY} tr.pd3-row`, (tr) => Number(tr.dataset.mailUid),
        edgesBy([[mailLinks, 'mail_uid']]));
    paint('#pd3OutFinanceTable tr.pd3-row', (tr) => Number(tr.dataset.financeId),
        edgesBy([[mailLinks, 'finance_id'], [incomeLinks, 'finance_id']]));
}
