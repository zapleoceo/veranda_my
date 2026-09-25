// Renders mail / finance tbodies from server data. Pure functions —
// no fetching, no state. The bootstrap module fetches and passes the
// rows in.

'use strict';

import { createTxButtonHtml, TX_TYPE } from '../ui/rowCreateTx.js';
import { BANK_COLUMNS, MAIL_TBODY, byTimeAsc } from '../ui/bankTable.js';
import { classify as rowState }  from '../ui/rowStates.js';
import { esc, fmtVnd as fmt }    from '../ui/format.js';

/** Cells per finance row — see renderOutFinance(). */
export const FINANCE_COLUMNS = 7;

export function renderOutMail(rows, links, { showHidden = false } = {}) {
    const byMail = new Map();
    for (const l of links) {
        if (!byMail.has(l.mail_uid)) byMail.set(l.mail_uid, []);
        byMail.get(l.mail_uid).push(l);
    }
    const tbody = document.querySelector(MAIL_TBODY);
    if (!tbody) return;
    if (!rows.length) {
        tbody.innerHTML = `<tr class="pd3-empty"><td colspan="${BANK_COLUMNS}">Расходов за период нет.</td></tr>`;
        return;
    }
    // IMAP returns newest first; the «Деньги» table reads top-down in
    // time (earliest expense first, right under the divider).
    tbody.innerHTML = byTimeAsc(rows, 'date').map((r) => {
        const state = r.is_hidden ? 'row-hidden' : rowState(byMail.get(r.mail_uid));
        const time  = (r.date || '').slice(11, 19);
        const cls   = ['pd3-row', state, r.is_hidden && !showHidden ? 'is-hidden' : ''].filter(Boolean).join(' ');
        const uid   = esc(r.mail_uid);
        return `<tr id="pd3-out-mail-${uid}" class="${cls}"
                    data-mail-uid="${uid}"
                    data-ts="${esc(r.date)}"
                    data-sum="${esc(r.amount)}"
                    data-content="${esc((r.content || '').toLowerCase())}">
            <td class="pd3-col pd3-col--hide">
                <button type="button" class="pd3-row-hide pd3-out-mail-hide" data-mail-uid="${uid}" title="Скрыть/восстановить">−</button>
            </td>
            <td class="pd3-col pd3-col--content">${esc(r.content)}</td>
            <td class="pd3-col pd3-col--time nowrap">${esc(time)}</td>
            <td class="pd3-col pd3-col--sum  nowrap right">${esc(r.amount_fmt)}</td>
            <td class="pd3-col pd3-col--create">${createTxButtonHtml({ amount: r.amount, date: r.date, type: TX_TYPE.EXPENSE })}</td>
            <td class="pd3-col pd3-col--cb">
                <input type="checkbox" class="pd3-cb pd3-cb--out-mail" data-mail-uid="${uid}" data-sum="${esc(r.amount)}">
            </td>
            <td class="pd3-col pd3-col--anchor">
                <span class="pd3-anchor" id="pd3-out-mail-anchor-${uid}"></span>
            </td>
        </tr>`;
    }).join('');
}

export function renderOutFinance(rows, links) {
    const byFin = new Map();
    for (const l of links) {
        if (!byFin.has(l.finance_id)) byFin.set(l.finance_id, []);
        byFin.get(l.finance_id).push(l);
    }
    const tbody = document.querySelector('#pd3OutFinanceTable tbody');
    if (!tbody) return;
    if (!rows.length) {
        tbody.innerHTML = `<tr class="pd3-empty"><td colspan="${FINANCE_COLUMNS}">Транзакций за период не найдено.</td></tr>`;
        return;
    }
    tbody.innerHTML = rows.map((r) => {
        const state = rowState(byFin.get(r.transaction_id));
        const id    = esc(r.transaction_id);
        return `<tr id="pd3-out-finance-${id}" class="pd3-row ${state}"
                    data-finance-id="${id}"
                    data-ts="${esc(r.date)}"
                    data-amount="${esc(r.amount)}">
            <td class="pd3-col pd3-col--lead">
                <div class="pd3-lead">
                    <span class="pd3-anchor" id="pd3-out-finance-anchor-${id}"></span>
                    <input type="checkbox" class="pd3-cb pd3-cb--out-finance" data-finance-id="${id}" data-sum="${esc(r.amount)}">
                </div>
            </td>
            <td class="pd3-col pd3-col--time   nowrap">${esc(r.date)}</td>
            <td class="pd3-col pd3-col--method">${esc(r.user_id || '')}</td>
            <td class="pd3-col pd3-col--method">${esc(r.category_id || '')}</td>
            <td class="pd3-col pd3-col--total  nowrap right"><strong>${esc(r.amount_fmt)}</strong></td>
            <td class="pd3-col pd3-col--card   nowrap right">${fmt(r.balance)}</td>
            <td class="pd3-col pd3-col--content">${esc(r.comment)}</td>
        </tr>`;
    }).join('');
}

export function updateOutFooter(mailRows, financeRows) {
    let mailTotal = 0;
    for (const r of mailRows) mailTotal += Number(r.amount) || 0;
    let finTotal = 0;
    for (const r of financeRows) finTotal += Math.abs(Number(r.amount) || 0);

    const $mt = document.getElementById('pd3OutMailTotal');
    const $mc = document.getElementById('pd3OutMailCount');
    const $ft = document.getElementById('pd3OutFinanceTotal');
    const $fc = document.getElementById('pd3OutFinanceCount');
    if ($mt) $mt.textContent = fmt(mailTotal);
    if ($mc) $mc.textContent = String(mailRows.length);
    if ($ft) $ft.textContent = fmt(finTotal);
    if ($fc) $fc.textContent = String(financeRows.length);
}
