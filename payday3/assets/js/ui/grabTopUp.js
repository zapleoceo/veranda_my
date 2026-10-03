// «Пополнить Grab» row of the Финансовые транзакции card — pure view
// logic (no DOM), unit-tested in tests/js/payday3/grabTopUp.test.mjs.
//
// The server (GrabTopUpService) computes everything: surplus = saved
// Факт. «Вьет.» − live Poster balance, Vietnam/Tips reconciliation and
// the "GRAB already booked today" check. The client only mirrors the
// gate so the button can't be enabled by a partial payload; the server
// re-validates on create anyway.

'use strict';

import { fmtVnd } from './format.js';

const fmt = (n) => fmtVnd(n, { empty: '—' });

/** Enabled only when every server-side condition holds. */
export function grabCanCreate(p) {
    if (!p || p.can_create !== true) return false;
    const surplus = Number(p.surplus_vnd);
    return Number.isFinite(surplus) && surplus > 0
        && p.vietnam_ok === true && p.tips_ok === true
        && (!Array.isArray(p.found) || p.found.length === 0);
}

/**
 * @returns {{ total: string, disabled: boolean, text: string, showFound: boolean }}
 *   showFound → render p.found as the mini-table under `text`.
 */
export function grabRowState(p) {
    if (!p) return { total: '—', disabled: true, text: 'Нет данных.', showFound: false };
    const total = p.surplus_vnd === null || p.surplus_vnd === undefined ? '—' : fmt(p.surplus_vnd);
    const found = Array.isArray(p.found) ? p.found : [];
    const disabled = !grabCanCreate(p);

    if (found.length > 0) {
        const f = found[0];
        return { total, disabled: true, showFound: true,
            text: `Найдена: #${f.transaction_id} ${fmt(Math.abs(Number(f.sum_minor) || 0))}` };
    }
    switch (p.reason) {
        case 'ok':
            return { total, disabled, showFound: false, text: `Излишек ${fmt(p.surplus_vnd)} — можно пополнить` };
        case 'not_reconciled':
            return { total, disabled: true, showFound: false, text: 'Сначала сведите Vietnam и Tips' };
        case 'no_surplus':
            return { total, disabled: true, showFound: false, text: 'Излишка нет' };
        case 'error':
            return { total, disabled: true, showFound: false, text: 'Ошибка: ' + (p.error || p.message || 'Poster') };
        default:   // no_fact / no_poster / unknown → server text
            return { total, disabled: true, showFound: false, text: p.message || 'Нет данных.' };
    }
}
