// «Кассовые смены» modal — shifts of the period; a click on a shift
// lazily loads its transactions.

'use strict';

import { api }            from '../../api.js';
import { esc, fmtVnd, withRange } from '../format.js';

const fmt = (n) => fmtVnd(n, { empty: '' });

const TYPE_LABEL = { 1: 'Открытие', 2: 'Доход', 3: 'Расход', 4: 'Инкассация', 5: 'Закрытие' };
const TYPE_CLASS = { 1: 'is-open', 2: 'is-in', 3: 'is-out', 4: 'is-out', 5: 'is-close' };

const typeLabel = (type) => TYPE_LABEL[Number(type)] ?? String(type ?? '');

/** Poster timestamp (unix s / ms, or a string) → 'dd.mm HH:MM'. */
export function fmtShiftTs(raw) {
    if (!raw) return '';
    let ts = Number(raw);
    if (isNaN(ts) || ts <= 0) return String(raw);
    if (String(Math.floor(ts)).length === 10) ts *= 1000;
    const d = new Date(ts);
    if (isNaN(d.getTime())) return String(raw);
    const p = (n) => String(n).padStart(2, '0');
    return `${p(d.getDate())}.${p(d.getMonth() + 1)} ${p(d.getHours())}:${p(d.getMinutes())}`;
}

function renderShifts(shifts) {
    if (!shifts.length) return '<p class="muted">Смен за период не найдено.</p>';
    return `<table class="pd3-table pd3-kashshift">
        <thead><tr>
            <th>ID</th>
            <th>Открыта</th>
            <th>Закрыта</th>
            <th class="right">Старт</th>
        </tr></thead>
        <tbody>${shifts.map((s) => {
            const sid   = String(s.cash_shift_id ?? s.shift_id ?? s.id ?? '');
            const start = fmtShiftTs(s.date_start ?? s.opened);
            const end   = fmtShiftTs(s.date_end   ?? s.date_close ?? s.closed);
            const amt   = fmt(s.amount_start ?? s.start_amount ?? '');
            return `<tr class="pd3-kashshift__row" data-shift-id="${esc(sid)}">
                <td><strong>${esc(sid)}</strong></td>
                <td class="nowrap muted">${esc(start)}</td>
                <td class="nowrap muted">${esc(end)}</td>
                <td class="right nowrap">${esc(amt)}</td>
            </tr>
            <tr class="pd3-kashshift__details" data-shift-details="${esc(sid)}" hidden>
                <td colspan="4">
                    <div class="pd3-kashshift__details-inner" data-loaded="0">
                        <span class="muted">Загрузка транзакций…</span>
                    </div>
                </td>
            </tr>`;
        }).join('')}</tbody></table>`;
}

function renderTxns(txns) {
    if (!txns || txns.length === 0) {
        return '<div class="muted pd3-kashshift__empty">Нет транзакций в этой смене</div>';
    }
    return `<table class="pd3-table pd3-kashshift__txns">
        <thead><tr>
            <th>Дата</th><th>Тип</th>
            <th class="right">Сумма</th><th>Комментарий</th>
        </tr></thead>
        <tbody>${txns.map((tx) => {
            const type = Number(tx.type ?? 0);
            // Income shows positive, expense/collection negative —
            // mirrors payday2's signed display so a glance at the
            // amount tells you the direction of money flow.
            const rawAmt = tx.tr_amount ?? tx.amount ?? tx.sum ?? 0;
            const signed = (type === 3 || type === 4) ? -Math.abs(rawAmt) : rawAmt;
            return `<tr>
                <td class="nowrap muted">${esc(fmtShiftTs(tx.time ?? tx.date ?? tx.ts))}</td>
                <td class="nowrap pd3-kashshift__type ${TYPE_CLASS[type] || ''}">${esc(typeLabel(type))}</td>
                <td class="right nowrap"><strong>${esc(fmt(signed))}</strong></td>
                <td class="pd3-kashshift__comment">${esc(String(tx.comment ?? ''))}</td>
            </tr>`;
        }).join('')}</tbody></table>`;
}

function wireClicks(body) {
    if (body.dataset.wired === '1') return;
    body.dataset.wired = '1';

    body.addEventListener('click', async (e) => {
        const row = e.target.closest?.('.pd3-kashshift__row');
        if (!row) return;
        const sid = row.dataset.shiftId;
        if (!sid) return;
        const det = body.querySelector(`[data-shift-details="${CSS.escape(sid)}"]`);
        if (!det) return;
        // Lazy-fetch the txn list on the first open; cache thereafter.
        const inner = det.querySelector('.pd3-kashshift__details-inner');
        det.hidden = !det.hidden;
        row.classList.toggle('pd3-kashshift__row--open', !det.hidden);
        if (det.hidden) return;
        if (inner.dataset.loaded === '1') return;
        try {
            const data = await api.get('/payday3/api/poster/cashshifts/' + encodeURIComponent(sid));
            const txns = Array.isArray(data) ? data : (data?.transactions || data?.data || []);
            inner.innerHTML = renderTxns(txns);
            inner.dataset.loaded = '1';
        } catch (err) {
            inner.innerHTML = `<div class="muted">Не удалось загрузить: ${esc(err.message || 'ошибка')}</div>`;
        }
    });
}

async function load(state) {
    const body = document.getElementById('pd3KashShiftBody');
    if (!body) return;
    body.innerHTML = '<p class="pd3-modal__loading">Загрузка кассовых смен…</p>';
    try {
        const data = await api.get(withRange('/payday3/api/poster/cashshifts', state.get('range')));
        body.innerHTML = renderShifts(data.shifts || data || []);
        wireClicks(body);
    } catch (e) {
        body.innerHTML = `<p class="muted">Не удалось загрузить: ${esc(e.message || 'ошибка')}.</p>`;
    }
}

export function initKashShift({ host, state }) {
    host.register('pd3KashShiftModal', { trigger: 'pd3KashShiftBtn', onOpen: () => load(state) });
}
