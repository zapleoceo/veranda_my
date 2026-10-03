// «Финансовые транзакции» — are all three rows closed? Pure (no DOM),
// unit-tested in tests/js/payday3/financeClosed.test.mjs. Drives the
// red/green outline of the balances ✈ (Telegram screenshot) button.

'use strict';

/** Vietnam / Tips: nothing to transfer, or a Poster transfer of exactly the expected sum. */
export function transferRowClosed(p) {
    if (p?.error) return false;   // Poster failed — total 0 is not trustworthy
    const total = p?.total_vnd ?? null;
    if (total === null) return false;
    if (total <= 0) return true;
    return (Array.isArray(p.found) ? p.found : [])
        .some((f) => Math.abs(Number(f.sum_minor) || 0) === total);
}

/** Grab: top-up already booked today, or no surplus to top up. */
export function grabRowClosed(p) {
    if (!p) return false;
    if (Array.isArray(p.found) && p.found.length > 0) return true;
    return p.reason === 'no_surplus';
}

export function financeAllClosed(data) {
    return transferRowClosed(data?.vietnam)
        && transferRowClosed(data?.tips)
        && grabRowClosed(data?.grab);
}
