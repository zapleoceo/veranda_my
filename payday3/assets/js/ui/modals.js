// One generic modal host, many content blocks. Each toolbar button
// has `data-pd3-modal` pointing at the id of the modal to reveal;
// Esc, backdrop click, and × close it. No per-modal state machines.

'use strict';

import { api } from '../api.js';

const TRIGGERS = {
    pd3InfoBtn:         'pd3InfoModal',
    pd3SettingsBtn:     'pd3SettingsModal',
    pd3KashShiftBtn:    'pd3KashShiftModal',
    pd3SuppliesBtn:     'pd3SuppliesModal',
    pd3CheckFinderBtn:  'pd3CheckFinderModal',
};

let _host = null;
let _currentId = null;

function open(modalId) {
    if (!_host) return;
    document.querySelectorAll('.pd3-modal').forEach((m) => { m.hidden = true; });
    const target = document.getElementById(modalId);
    if (!target) return;
    target.hidden = false;
    _host.hidden = false;
    _host.removeAttribute('aria-hidden');
    _currentId = modalId;
    target.focus?.();
}

function close() {
    if (!_host) return;
    _host.hidden = true;
    _host.setAttribute('aria-hidden', 'true');
    _currentId = null;
}

function qs(range) {
    const p = new URLSearchParams();
    if (range?.from) p.set('dateFrom', range.from);
    if (range?.to)   p.set('dateTo',   range.to);
    return p.toString();
}

async function loadKashShift({ state }) {
    const body = document.getElementById('pd3KashShiftBody');
    if (!body) return;
    body.innerHTML = '<p class="pd3-modal__loading">Загрузка кассовых смен…</p>';
    try {
        const data = await api.get('/payday3/api/poster/cashshifts?' + qs(state.get('range')));
        body.innerHTML = renderKashShift(data.shifts || []);
    } catch (e) {
        body.innerHTML = `<p class="muted">Не удалось загрузить: ${escapeHtml(e.message || 'ошибка')}.</p>`;
    }
}

async function loadSupplies({ state }) {
    const body = document.getElementById('pd3SuppliesBody');
    if (!body) return;
    body.innerHTML = '<p class="pd3-modal__loading">Загрузка поставок…</p>';
    try {
        const data = await api.get('/payday3/api/poster/supplies?' + qs(state.get('range')));
        body.innerHTML = renderSupplies(data.supplies || [], data.accounts || []);
    } catch (e) {
        body.innerHTML = `<p class="muted">Не удалось загрузить: ${escapeHtml(e.message || 'ошибка')}.</p>`;
    }
}

function renderKashShift(shifts) {
    if (!shifts.length) return '<p class="muted">Смен за период не найдено.</p>';
    return `<table class="pd3-table" style="width:100%">
        <thead><tr><th>ID</th><th>Открыта</th><th>Закрыта</th><th class="right">Cash</th><th class="right">Card</th></tr></thead>
        <tbody>${shifts.map((s) => `<tr>
            <td>${escapeHtml(String(s.id || s.shift_id || ''))}</td>
            <td>${escapeHtml(String(s.date_start || s.opened || ''))}</td>
            <td>${escapeHtml(String(s.date_close || s.closed || ''))}</td>
            <td class="right">${escapeHtml(String(s.cash || s.payed_cash || ''))}</td>
            <td class="right">${escapeHtml(String(s.card || s.payed_card || ''))}</td>
        </tr>`).join('')}</tbody></table>`;
}

function renderSupplies(supplies, accounts) {
    if (!supplies.length) return '<p class="muted">Поставок за период не найдено.</p>';
    const accLabel = (id) => {
        const a = accounts.find((x) => Number(x.account_id) === Number(id));
        return a ? (a.account_name || a.title || ('#' + id)) : ('#' + id);
    };
    return `<table class="pd3-table" style="width:100%">
        <thead><tr><th>ID</th><th>Дата</th><th>Поставщик</th><th class="right">Сумма</th><th>Аккаунт</th></tr></thead>
        <tbody>${supplies.map((s) => `<tr>
            <td>${escapeHtml(String(s.supply_id || s.id || ''))}</td>
            <td>${escapeHtml(String(s.supply_date_start || s.date || ''))}</td>
            <td>${escapeHtml(String(s.supplier_name || s.supplier || ''))}</td>
            <td class="right">${escapeHtml(String(s.supply_sum || s.sum || ''))}</td>
            <td>${escapeHtml(accLabel(s.account_id))}</td>
        </tr>`).join('')}</tbody></table>`;
}

async function findCheck(query, { state }) {
    const out = document.getElementById('pd3CheckFinderResult');
    if (!out) return;
    const id = parseInt(String(query).replace(/\D+/g, ''), 10);
    if (!id || id <= 0) { out.textContent = 'Введи числовой transaction_id.'; return; }
    out.textContent = 'Ищу…';
    try {
        const q = qs(state.get('range'));
        const data = await api.get('/payday3/api/poster/checks/find?id=' + id + '&' + q);
        if (!data.found) { out.textContent = 'Чек не найден за выбранный период.'; return; }
        const t = data.transaction || {};
        out.innerHTML = `<div class="pd3-checkfinder-row">
            <strong>#${escapeHtml(String(t.receipt_number || t.transaction_id || id))}</strong>
            · ${escapeHtml(String(t.date_close || t.date || ''))}
            · ${escapeHtml(String(t.sum || t.payed_sum || ''))}
            <button type="button" class="pd3-btn pd3-checkfinder-remove" data-tx="${id}" style="margin-left:8px">Удалить</button>
        </div>`;
        out.querySelector('.pd3-checkfinder-remove')?.addEventListener('click', async (e) => {
            if (!confirm('Удалить чек #' + id + ' через Poster?')) return;
            const btn = e.currentTarget; btn.disabled = true;
            try {
                const r = await api.delete('/payday3/api/poster/checks/' + id);
                out.innerHTML = `<p class="muted">Удалён.${r.telegram_ok ? ' Уведомление отправлено в Telegram.' : ''}</p>`;
            } catch (err) {
                out.innerHTML = `<p class="muted">Ошибка удаления: ${escapeHtml(err.message || 'ошибка')}.</p>`;
            }
        });
    } catch (e) {
        out.textContent = 'Ошибка: ' + (e.message || 'request failed');
    }
}

function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, (c) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    })[c]);
}

export function initModals({ state }) {
    _host = document.getElementById('pd3ModalHost');
    if (!_host) return;

    // Triggers
    for (const [btnId, modalId] of Object.entries(TRIGGERS)) {
        const btn = document.getElementById(btnId);
        if (!btn) continue;
        btn.addEventListener('click', async () => {
            open(modalId);
            if (modalId === 'pd3KashShiftModal')  await loadKashShift({ state });
            if (modalId === 'pd3SuppliesModal')   await loadSupplies({ state });
        });
    }

    // Backdrop + close button + Esc
    _host.addEventListener('click', (e) => {
        if (e.target.hasAttribute?.('data-pd3-modal-close')) close();
    });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && _currentId) close();
    });

    // Check finder form
    const form = document.getElementById('pd3CheckFinderForm');
    form?.addEventListener('submit', (e) => {
        e.preventDefault();
        const q = document.getElementById('pd3CheckFinderInput')?.value?.trim();
        if (q) findCheck(q, { state });
    });
}
