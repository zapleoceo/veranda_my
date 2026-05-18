// Renders mail / finance tbodies from server data. Pure functions —
// no fetching, no state. The bootstrap module fetches and passes the
// rows in.

'use strict';

const fmt = (n) => {
    const v = Math.round(Number(n) || 0);
    try { return new Intl.NumberFormat('en-US', { maximumFractionDigits: 0 }).format(v).replace(/,/g, ' '); }
    catch (_) { return String(v).replace(/\B(?=(\d{3})+(?!\d))/g, ' '); }
};

const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
})[c]);

function rowState(edges) {
    if (!edges || edges.length === 0) return 'row-red';
    let manual = false, yellow = false;
    for (const e of edges) {
        if (e.is_manual)                 manual = true;
        if (e.link_type === 'auto_yellow') yellow = true;
    }
    if (manual) return 'row-gray';
    return yellow ? 'row-yellow' : 'row-green';
}

export function renderOutMail(rows, links) {
    const byMail = new Map();
    for (const l of links) {
        if (!byMail.has(l.mail_uid)) byMail.set(l.mail_uid, []);
        byMail.get(l.mail_uid).push(l);
    }
    const tbody = document.querySelector('#pd3OutMailTable tbody');
    if (!tbody) return;
    if (!rows.length) {
        tbody.innerHTML = '<tr class="pd3-empty"><td colspan="6">Писем за период не найдено.</td></tr>';
        return;
    }
    tbody.innerHTML = rows.map((r) => {
        const state = r.is_hidden ? 'row-hidden' : rowState(byMail.get(r.mail_uid));
        const time  = (r.date || '').slice(11, 19);
        const cls   = ['pd3-row', state, r.is_hidden ? 'is-hidden' : ''].filter(Boolean).join(' ');
        return `<tr id="pd3-out-mail-${r.mail_uid}" class="${cls}"
                    data-mail-uid="${r.mail_uid}"
                    data-ts="${esc(r.date)}"
                    data-sum="${r.amount}"
                    data-content="${esc((r.content || '').toLowerCase())}">
            <td class="pd3-col pd3-col--hide">
                <button type="button" class="pd3-row-hide pd3-out-mail-hide" data-mail-uid="${r.mail_uid}" title="Скрыть/восстановить">−</button>
            </td>
            <td class="pd3-col pd3-col--content">${esc(r.content)}</td>
            <td class="pd3-col pd3-col--time nowrap">${esc(time)}</td>
            <td class="pd3-col pd3-col--sum  nowrap right">
                <button type="button" class="pd3-out-mail-create"
                        title="Создать транзакцию в Poster на эту сумму"
                        data-mail-uid="${r.mail_uid}"
                        data-amount="${r.amount}"
                        data-date="${esc(r.date)}">+</button>
                ${esc(r.amount_fmt)}
            </td>
            <td class="pd3-col pd3-col--cb">
                <input type="checkbox" class="pd3-cb pd3-cb--out-mail" data-mail-uid="${r.mail_uid}" data-sum="${r.amount}">
            </td>
            <td class="pd3-col pd3-col--anchor">
                <span class="pd3-anchor" id="pd3-out-mail-anchor-${r.mail_uid}"></span>
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
        tbody.innerHTML = '<tr class="pd3-empty"><td colspan="7">Транзакций за период не найдено.</td></tr>';
        return;
    }
    tbody.innerHTML = rows.map((r) => {
        const state = rowState(byFin.get(r.transaction_id));
        return `<tr id="pd3-out-finance-${r.transaction_id}" class="pd3-row ${state}"
                    data-finance-id="${r.transaction_id}"
                    data-ts="${esc(r.date)}"
                    data-amount="${r.amount}">
            <td class="pd3-col pd3-col--lead">
                <div class="pd3-lead">
                    <span class="pd3-anchor" id="pd3-out-finance-anchor-${r.transaction_id}"></span>
                    <input type="checkbox" class="pd3-cb pd3-cb--out-finance" data-finance-id="${r.transaction_id}" data-sum="${r.amount}">
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
