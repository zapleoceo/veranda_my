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

async function loadKashShift({ state }) {
    const body = document.getElementById('pd3KashShiftBody');
    if (!body) return;
    body.innerHTML = '<p class="pd3-modal__loading">Загрузка кассовых смен…</p>';
    try {
        const r = state.get('range') || {};
        const data = await api.get('/payday2/?ajax=kashshift&dateFrom=' + encodeURIComponent(r.from) + '&dateTo=' + encodeURIComponent(r.to));
        body.innerHTML = renderKashShift(data);
    } catch (e) {
        body.innerHTML = `<p class="muted">Не удалось загрузить: ${escapeHtml(e.message || 'ошибка')}.</p>
                          <p class="muted">Воспользуйся <a class="pd3-link" href="/payday2" target="_blank">payday2</a> пока модуль не перенесён в payday3.</p>`;
    }
}

async function loadSupplies({ state }) {
    const body = document.getElementById('pd3SuppliesBody');
    if (!body) return;
    body.innerHTML = '<p class="pd3-modal__loading">Загрузка поставок…</p>';
    try {
        const r = state.get('range') || {};
        const data = await api.get('/payday2/?ajax=supplies&dateFrom=' + encodeURIComponent(r.from) + '&dateTo=' + encodeURIComponent(r.to));
        body.innerHTML = renderSupplies(data);
    } catch (e) {
        body.innerHTML = `<p class="muted">Не удалось загрузить: ${escapeHtml(e.message || 'ошибка')}.</p>
                          <p class="muted">Воспользуйся <a class="pd3-link" href="/payday2" target="_blank">payday2</a> пока модуль не перенесён в payday3.</p>`;
    }
}

function renderKashShift(data) {
    if (!data || (!Array.isArray(data) && !data.shifts)) {
        return '<p class="muted">Пусто.</p>';
    }
    const shifts = Array.isArray(data) ? data : (data.shifts || []);
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

function renderSupplies(data) {
    const list = Array.isArray(data) ? data : (data?.supplies || []);
    if (!list.length) return '<p class="muted">Поставок за период не найдено.</p>';
    return `<table class="pd3-table" style="width:100%">
        <thead><tr><th>ID</th><th>Дата</th><th>Поставщик</th><th class="right">Сумма</th></tr></thead>
        <tbody>${list.map((s) => `<tr>
            <td>${escapeHtml(String(s.id || s.supply_id || ''))}</td>
            <td>${escapeHtml(String(s.date || s.supply_date || ''))}</td>
            <td>${escapeHtml(String(s.supplier || s.supplier_name || ''))}</td>
            <td class="right">${escapeHtml(String(s.sum || s.supply_sum || ''))}</td>
        </tr>`).join('')}</tbody></table>`;
}

async function findCheck(query) {
    const out = document.getElementById('pd3CheckFinderResult');
    if (!out) return;
    out.textContent = 'Ищу…';
    try {
        const data = await api.get('/payday2/?ajax=poster_check_find&query=' + encodeURIComponent(query));
        if (!data || !data.checks?.length) {
            out.textContent = 'Ничего не найдено.';
            return;
        }
        out.innerHTML = data.checks.map((c) => `
            <div class="pd3-checkfinder-row">
                <strong>#${escapeHtml(String(c.receipt_number || c.transaction_id))}</strong>
                · ${escapeHtml(String(c.date_close || ''))}
                · ${escapeHtml(String(c.sum || c.payed_sum || ''))}
            </div>`).join('');
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
        if (q) findCheck(q);
    });
}
