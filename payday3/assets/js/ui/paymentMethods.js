// Poster payment-method buckets for checks (Vietnam Company / Bybit).
//
// Same rule as the server (totals.php, poster_table.php,
// ReconciliationService): by poster_payment_method_id, NOT by the
// display name. Rows carry the id as `payment_method_id` (JSON) /
// `data-method-id` (DOM). Only a row with no id at all (markup from
// before the id was shipped) falls back to the name prefix.
//
// NB: these are payment-METHOD ids of checks. Settings `accounts.*`
// are Poster finance-ACCOUNT ids (a different id space) and don't apply
// to checks.

'use strict';

export const PAYMENT_METHOD = Object.freeze({ VIETNAM: 11, BYBIT: 12 });

const hasId = (id) => id !== undefined && id !== null && id !== '';

function matches(method, id, prefix) {
    if (hasId(method?.id)) return Number(method.id) === id;
    return String(method?.name ?? '').toLowerCase().startsWith(prefix);
}

/** @param {{id?:*, name?:string}} method */
export const isVietnam = (method) => matches(method, PAYMENT_METHOD.VIETNAM, 'vietnam');
/** @param {{id?:*, name?:string}} method */
export const isBybit   = (method) => matches(method, PAYMENT_METHOD.BYBIT, 'bybit');

/** Method of a check row from /api/data. */
export const methodOfData = (p) => ({
    id:   p?.payment_method_id ?? p?.poster_payment_method_id,
    name: p?.payment_method,
});

/** Method of a rendered `#pd3PosterTable tr`. */
export const methodOfRow = (tr) => ({ id: tr?.dataset?.methodId, name: tr?.dataset?.method });
