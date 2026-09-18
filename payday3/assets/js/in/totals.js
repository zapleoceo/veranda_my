// Top totals card (Sepay / Poster / VC / Δ) from row data.
//
// Mirrors src/Views/payday3/partials/totals.php exactly, so the card
// shows the same numbers after an AJAX refresh as on first paint:
//   * EVERY row counts — the 👁 toggles only hide rows from view, they
//     never change what the bank / Poster reported;
//   * Vietnam Company is bucketed by payment-method id (paymentMethods.js),
//     the same rule the server uses.
//
//     Sepay = Poster + VC  →  Δ = Sepay − Poster − VC   (0 = reconciled)
//
// Pure, no DOM — tested in tests/js/payday3/totals.test.mjs.

'use strict';

const _i = (await import(new URL('../ui/cacheBust.js' + new URL(import.meta.url).search, import.meta.url).href)).importer(import.meta.url);
const { isVietnam, methodOfData } = await _i('../ui/paymentMethods.js');

const sum = (rows, pick) => (rows || []).reduce((acc, r) => acc + (Number(pick(r)) || 0), 0);

/**
 * @param {{sepayOpen?:object[], sepayHidden?:object[], poster?:object[]}} data
 *   poster rows carry `total` = card + third + tip.
 * @returns {{sepay:number, poster:number, vietnam:number, diff:number}}
 */
export function topTotals({ sepayOpen = [], sepayHidden = [], poster = [] } = {}) {
    const sepay = sum(sepayOpen, (s) => s.amount) + sum(sepayHidden, (s) => s.amount);
    let posterTotal = 0;
    let vietnam = 0;
    for (const p of poster || []) {
        const t = Number(p.total) || 0;
        if (isVietnam(methodOfData(p))) vietnam += t;
        else                            posterTotal += t;
    }
    return { sepay, poster: posterTotal, vietnam, diff: sepay - posterTotal - vietnam };
}

/** CSS class of the Δ cell — same as totals.php. */
export const diffClass = (diff) => (diff === 0 ? 'ok' : (diff < 0 ? 'danger' : 'warn'));
