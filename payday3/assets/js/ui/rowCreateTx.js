// «+» on an unlinked bank row → Poster finance-transaction modal.
//
// Single source of truth for the button, shared by both tabs:
//   OUT — BIDV mail rows (money left the bank)    → EXPENSE
//   IN  — SePay rows    (money arrived to the bank) → INCOME
//
// The button is rendered on every row; CSS shows it only on unlinked
// (row-red) rows. The server-side SePay partial
// (src/Views/payday3/partials/sepay_table.php) must emit the same
// markup — tests on both sides pin the shared contract.
//
// Pure functions, no DOM access at module level, so the module loads
// under node:test.

'use strict';

/** UI transaction types — the modal's <select name="type"> values. */
export const TX_TYPE = Object.freeze({ INCOME: 1, EXPENSE: 2, TRANSFER: 3 });

/** Delegated-click selector for the button. */
export const CREATE_TX_SELECTOR = '.pd3-row-create';

const TITLES = {
    [TX_TYPE.INCOME]:  'Создать приход в Poster на эту сумму',
    [TX_TYPE.EXPENSE]: 'Создать расход в Poster на эту сумму',
};

const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
})[c]);

/**
 * @param {{amount:number|string, date:string, type:number}} p
 *   amount — VND integer; date — 'Y-m-d H:i:s' of the bank row.
 */
export function createTxButtonHtml({ amount, date, type }) {
    const t = Number(type) === TX_TYPE.INCOME ? TX_TYPE.INCOME : TX_TYPE.EXPENSE;
    return `<button type="button" class="pd3-row-create"`
        + ` title="${esc(TITLES[t])}"`
        + ` data-tx-type="${t}"`
        + ` data-amount="${esc(Math.round(Number(amount) || 0))}"`
        + ` data-date="${esc(date)}">+</button>`;
}

/**
 * Read the modal pre-fill from a clicked button.
 * A missing/unknown type falls back to EXPENSE — the historical OUT
 * behaviour — so a stale cached markup never opens as income.
 *
 * @param {{dataset?: Record<string,string>}} el
 * @returns {{amount:number, date:string, type:number}}
 */
export function readCreateTxTrigger(el) {
    const d = el?.dataset ?? {};
    const type = Number(d.txType) === TX_TYPE.INCOME ? TX_TYPE.INCOME : TX_TYPE.EXPENSE;
    return {
        amount: Math.round(Number(d.amount) || 0),
        date:   String(d.date ?? ''),
        type,
    };
}

/**
 * Split a bank-row timestamp into the modal's date + time inputs.
 * Falls back to `now` when the row has no parseable timestamp.
 *
 * @param {string} dateIso  'Y-m-d H:i:s' | 'Y-m-dTH:i' | 'Y-m-d' | ''
 * @param {Date}   now
 * @returns {{date:string, time:string}}  'Y-m-d' and 'H:i'
 */
export function splitDateTime(dateIso, now = new Date()) {
    const pad = (n) => String(n).padStart(2, '0');
    let date = `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
    let time = `${pad(now.getHours())}:${pad(now.getMinutes())}`;
    const m = String(dateIso || '').match(/^(\d{4}-\d{2}-\d{2})(?:[ T](\d{2}:\d{2}))?/);
    if (m) {
        date = m[1];
        if (m[2]) time = m[2];
    }
    return { date, time };
}
