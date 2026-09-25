// «Поиск чека» modal — the period's checks (client-side filter + sort),
// exact search by id for older checks, per-check products, delete.

'use strict';

import { api }            from '../../api.js';
import { esc, fmtVnd, withRange } from '../format.js';

const fmt = (n) => fmtVnd(n, { empty: '' });

const PAY_TYPE = { 0: 'без оплаты', 1: 'наличные', 2: 'безнал', 3: 'смешанная' };
const STATUS   = { 1: 'открыт', 2: 'закрыт', 3: 'удалён' };
const payTypeLabel = (v) => { const n = Number(v || 0) || 0; return PAY_TYPE[n] ?? String(n); };
const statusLabel  = (v) => STATUS[Number(v || 0) || 0] ?? '';

// ─── pure: sort + filter (tested in tests/js/payday3/checkFinder.test.mjs)

function sortValue(r, col) {
    switch (col) {
        case 'num':        return Number(r.receipt_number || r.transaction_id || 0);
        case 'date_close': return String(r.date_close || '');
        case 'sum':        return Number(r.sum || 0);
        case 'payed_sum':  return Number(r.payed_sum || 0);
        case 'table':      return String(r.table_title || r.table_id || '').toLowerCase();
        case 'status':     return Number(r.status || 0);
        case 'pay_type':   return Number(r.pay_type || 0);
        default:           return 0;
    }
}

/** Sorted copy of `rows` by `col`. */
export function sortChecks(rows, col, asc) {
    return [...rows].sort((a, b) => {
        const va = sortValue(a, col);
        const vb = sortValue(b, col);
        const d = (typeof va === 'number' && typeof vb === 'number')
            ? (va - vb)
            : (va < vb ? -1 : va > vb ? 1 : 0);
        return asc ? d : -d;
    });
}

/** Checks matching the needle by id / receipt № / waiter / table. */
export function filterChecks(rows, needle) {
    const n = String(needle || '').toLowerCase().trim();
    if (n === '') return rows;
    return rows.filter((r) =>
        String(r.transaction_id).includes(n)
        || String(r.receipt_number).includes(n)
        || String(r.waiter_name || '').toLowerCase().includes(n)
        || String(r.table_title || '').toLowerCase().includes(n)
        || String(r.table_id || '').includes(n)
    );
}

// ─── rendering

function renderProducts(products) {
    if (!products || products.length === 0) {
        return '<div class="muted pd3-checkfinder__empty">Нет продуктов</div>';
    }
    // Σ over the per-product totals so the operator can sanity-check
    // that the products add up to the check's payed_sum / sum.
    let sumTotal = 0;
    let sumQty   = 0;
    for (const p of products) {
        sumTotal += Number(p.total) || 0;
        sumQty   += Number(p.qty)   || 0;
    }
    return `<table class="pd3-table pd3-checkfinder__products">
        <thead><tr>
            <th>Название</th>
            <th class="right">Цена</th>
            <th class="right">Кол-во</th>
            <th class="right">Сумма продажи</th>
        </tr></thead>
        <tbody>${products.map((p) => `<tr>
            <td>${esc(p.name || '')}</td>
            <td class="right nowrap">${esc(fmt(p.unit_price))}</td>
            <td class="right nowrap">${esc(p.qty ?? '')}</td>
            <td class="right nowrap"><strong>${esc(fmt(p.total))}</strong></td>
        </tr>`).join('')}</tbody>
        <tfoot><tr class="pd3-checkfinder__products-total">
            <td class="muted">Σ по позициям</td>
            <td></td>
            <td class="right nowrap muted">${esc(sumQty)}</td>
            <td class="right nowrap"><strong>${esc(fmt(sumTotal))}</strong></td>
        </tr></tfoot>
    </table>`;
}

function renderSummary(r) {
    // One-line header above the products table: shows the same
    // money figures the parent row carries, but in a wider context
    // where the operator can spot a discount (sum > payed_sum).
    const disc = (Number(r.sum) || 0) - (Number(r.payed_sum) || 0);
    const discTxt = disc > 0 ? `, скидка ${fmt(disc)}` : '';
    return `<div class="pd3-checkfinder__summary muted">
        <span>${esc(r.date_close || '—')}</span>
        <span>Сумма чека: <strong>${esc(fmt(r.sum))}</strong></span>
        <span>Оплачено: <strong>${esc(fmt(r.payed_sum))}</strong>${esc(discTxt)}</span>
    </div>`;
}

// Sort state — survives filter / search re-renders within the page.
// Default: newest first.
const sort = { col: 'date_close', asc: false };
const arrow = (col) => (sort.col !== col ? '' : (sort.asc ? ' ▲' : ' ▼'));

function renderTable(rows) {
    if (!rows.length) return '<p class="muted">Чеков за период не найдено.</p>';
    const th = (col, title, extra = '') =>
        `<th class="pd3-checkfinder__sort ${extra}" data-sort="${col}">${esc(title)}${esc(arrow(col))}</th>`;
    return `<table class="pd3-table pd3-checkfinder-table">
        <thead><tr>
            ${th('num',        '№')}
            ${th('date_close', 'Дата')}
            ${th('sum',        'Сумма',    'right')}
            ${th('payed_sum',  'Оплачено', 'right')}
            ${th('table',      'Стол')}
            ${th('status',     'Статус')}
            ${th('pay_type',   'Оплата')}
            <th></th>
        </tr></thead>
        <tbody>${sortChecks(rows, sort.col, sort.asc).map((r) => {
            const id     = Number(r.transaction_id) || 0;
            const status = Number(r.status) || 0;
            const cls    = ['pd3-checkfinder__row',
                            status === 3 ? 'pd3-checkfinder__row--deleted' : '',
                            status === 1 ? 'pd3-checkfinder__row--open'    : ''].filter(Boolean).join(' ');
            const payCol = status === 2 ? payTypeLabel(r.pay_type) : '';
            return `<tr class="${cls}" data-tx="${id}">
                <td><strong>${esc(r.receipt_number || id)}</strong></td>
                <td class="nowrap muted">${esc(r.date_close || '')}</td>
                <td class="right nowrap">${esc(fmt(r.sum))}</td>
                <td class="right nowrap">${esc(fmt(r.payed_sum))}</td>
                <td class="nowrap">${esc(r.table_title || r.table_id || '—')}</td>
                <td class="muted">${esc(statusLabel(status))}</td>
                <td class="muted">${esc(payCol)}</td>
                <td class="right">
                    <button type="button" class="pd3-btn pd3-btn--sm pd3-checkfinder-remove" data-tx="${id}">Удалить</button>
                </td>
            </tr>
            <tr class="pd3-checkfinder__details" data-tx-details="${id}" hidden>
                <td colspan="8">
                    <div class="pd3-checkfinder__details-inner">
                        ${renderSummary(r)}
                        ${renderProducts(r.products || [])}
                    </div>
                </td>
            </tr>`;
        }).join('')}</tbody></table>`;
}

// ─── controller

const $out   = () => document.getElementById('pd3CheckFinderResult');
const $input = () => document.getElementById('pd3CheckFinderInput');

// The period's checks, loaded once per modal open; the search input
// filters this list client-side.
let checks = [];

function show(rows) {
    const out = $out();
    if (!out) return;
    out.innerHTML = renderTable(rows);
    wireRows(out);
}

const showFiltered = () => show(filterChecks(checks, $input()?.value || ''));

function wireRows(out) {
    if (out.dataset.wired === '1') return;
    out.dataset.wired = '1';

    // One delegated listener: sort header, row toggle, Delete button.
    out.addEventListener('click', async (e) => {
        const sortTh = e.target.closest?.('.pd3-checkfinder__sort');
        if (sortTh) {
            const col = sortTh.dataset.sort;
            if (col) {
                if (sort.col === col) sort.asc = !sort.asc;
                else { sort.col = col; sort.asc = true; }
                showFiltered();
            }
            return;
        }

        const delBtn = e.target.closest?.('.pd3-checkfinder-remove');
        if (delBtn) {
            e.stopPropagation();
            const id = Number(delBtn.dataset.tx);
            if (!id) return;
            if (!confirm('Удалить чек #' + id + ' через Poster?')) return;
            delBtn.disabled = true;
            try {
                const r = await api.delete('/payday3/api/poster/checks/' + id);
                // Remove BOTH the row and its details twin.
                delBtn.closest('tr')?.remove();
                out.querySelector(`[data-tx-details="${id}"]`)?.remove();
                checks = checks.filter((c) => Number(c.transaction_id) !== id);
                if (r?.telegram_ok) console.info('[payday3] telegram audit sent for', id);
            } catch (err) {
                delBtn.disabled = false;
                alert('Ошибка удаления: ' + (err.message || 'request failed'));
            }
            return;
        }

        const row = e.target.closest?.('.pd3-checkfinder__row');
        if (!row) return;
        const det = out.querySelector(`[data-tx-details="${row.dataset.tx}"]`);
        if (!det) return;
        det.hidden = !det.hidden;
        row.classList.toggle('pd3-checkfinder__row--open-detail', !det.hidden);
    });
}

async function loadList(state) {
    const out = $out();
    if (!out) return;
    out.innerHTML = '<p class="pd3-modal__loading">Загрузка списка чеков…</p>';
    try {
        const data = await api.get(withRange('/payday3/api/poster/checks', state.get('range')));
        checks = data.checks || [];
        show(checks);
    } catch (e) {
        out.innerHTML = `<p class="muted">Не удалось загрузить: ${esc(e.message || 'ошибка')}.</p>`;
    }
}

async function findById(idStr, state) {
    const out = $out();
    if (!out) return;
    const id = parseInt(String(idStr).replace(/\D+/g, ''), 10);
    if (!id || id <= 0) { showFiltered(); return; }
    out.innerHTML = '<p class="pd3-modal__loading">Ищу…</p>';
    try {
        const data = await api.get(withRange('/payday3/api/poster/checks/find?id=' + id, state.get('range')));
        if (!data.found) { out.innerHTML = '<p class="muted">Чек не найден за выбранный период.</p>'; return; }
        const t = data.transaction || {};
        show([{
            transaction_id: id,
            receipt_number: t.receipt_number || id,
            date_close:     t.date_close     || '',
            sum:            t.sum            || t.payed_sum || 0,
            table_id:       t.table_id       || '',
            waiter_name:    t.waiter_name    || t.name || '',
        }]);
    } catch (e) {
        out.innerHTML = `<p class="muted">Ошибка: ${esc(e.message || 'request failed')}</p>`;
    }
}

export function initCheckFinder({ host, state }) {
    host.register('pd3CheckFinderModal', { trigger: 'pd3CheckFinderBtn', onOpen: () => loadList(state) });

    // Live filter while typing, exact-id search on submit.
    $input()?.addEventListener('input', showFiltered);
    document.getElementById('pd3CheckFinderForm')?.addEventListener('submit', (e) => {
        e.preventDefault();
        const q = $input()?.value?.trim();
        if (q) findById(q, state);
    });
}
