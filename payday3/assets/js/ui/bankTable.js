// «Деньги» — the one left-hand bank table (partials/bank_table.php).
//
// Incoming SePay rows and outgoing BIDV-mail rows live in the same
// <table>, in separate <tbody> blocks with a divider between them, so
// both blocks share the exact same column widths. Every module that
// renders, reads or observes those rows takes its selectors from here.

'use strict';

/** Cells per bank row (− | Content | Время | Сумма | + | ☐ | anchor). */
export const BANK_COLUMNS = 7;

export const BANK_TABLE   = '#pd3BankTable';
export const BANK_SCROLL  = '#pd3BankScroll';
/** Incoming (SePay) block — above the divider. */
export const SEPAY_TBODY  = '#pd3SepayTbody';
/** Outgoing (BIDV mail) block — below the divider. */
export const MAIL_TBODY   = '#pd3OutMailTbody';

/**
 * Chronological order (earliest first) for a block of rows carrying a
 * 'Y-m-d H:i:s' timestamp under `key`. Stable and non-mutating; rows
 * without a timestamp sink to the end of their block.
 *
 * @template T
 * @param {T[]} rows
 * @param {string} key
 * @returns {T[]}
 */
export function byTimeAsc(rows, key) {
    return rows
        .map((row, i) => ({ row, i, t: String(row?.[key] ?? '') }))
        .sort((a, b) => {
            if (a.t === b.t) return a.i - b.i;
            if (a.t === '') return 1;
            if (b.t === '') return -1;
            return a.t < b.t ? -1 : 1;
        })
        .map((x) => x.row);
}
