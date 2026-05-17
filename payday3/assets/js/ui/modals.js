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

// Cache of the recent-checks list per modal open. Loaded once when
// the modal opens; the search input is a CLIENT-SIDE filter over
// this list. Pressing «Искать по id» falls back to /checks/find for
// rows older than the loaded page window.
let checksCache = [];

function renderChecksTable(rows) {
    if (!rows.length) return '<p class="muted">Чеков за период не найдено.</p>';
    const fmt = (n) => {
        const v = Math.round(Number(n) || 0);
        try { return new Intl.NumberFormat('en-US', { maximumFractionDigits: 0 }).format(v).replace(/,/g, ' '); }
        catch (_) { return String(v); }
    };
    return `<table class="pd3-table pd3-checkfinder-table">
        <thead><tr><th>№</th><th>Дата</th><th class="right">Сумма</th><th>Стол</th><th>Официант</th><th></th></tr></thead>
        <tbody>${rows.map((r) => `<tr data-tx="${r.transaction_id}">
            <td><strong>${escapeHtml(String(r.receipt_number || r.transaction_id))}</strong></td>
            <td class="nowrap">${escapeHtml(String(r.date_close || ''))}</td>
            <td class="right nowrap">${escapeHtml(fmt(r.sum))}</td>
            <td>${escapeHtml(String(r.table_id || '—'))}</td>
            <td>${escapeHtml(String(r.waiter_name || ''))}</td>
            <td><button type="button" class="pd3-btn pd3-checkfinder-remove" data-tx="${r.transaction_id}">Удалить</button></td>
        </tr>`).join('')}</tbody></table>`;
}

function wireRemoveButtons() {
    const out = document.getElementById('pd3CheckFinderResult');
    if (!out) return;
    out.querySelectorAll('.pd3-checkfinder-remove').forEach((btn) => {
        btn.addEventListener('click', async () => {
            const id = Number(btn.dataset.tx);
            if (!id) return;
            if (!confirm('Удалить чек #' + id + ' через Poster?')) return;
            btn.disabled = true;
            try {
                const r = await api.delete('/payday3/api/poster/checks/' + id);
                btn.closest('tr')?.remove();
                if (r.telegram_ok) console.info('[payday3] telegram audit sent for', id);
            } catch (err) {
                btn.disabled = false;
                alert('Ошибка удаления: ' + (err.message || 'request failed'));
            }
        });
    });
}

async function loadChecksList({ state }) {
    const out = document.getElementById('pd3CheckFinderResult');
    if (!out) return;
    out.innerHTML = '<p class="pd3-modal__loading">Загрузка списка чеков…</p>';
    try {
        const data = await api.get('/payday3/api/poster/checks?' + qs(state.get('range')));
        checksCache = data.checks || [];
        out.innerHTML = renderChecksTable(checksCache);
        wireRemoveButtons();
    } catch (e) {
        out.innerHTML = `<p class="muted">Не удалось загрузить: ${escapeHtml(e.message || 'ошибка')}.</p>`;
    }
}

function filterChecksList(needle) {
    const out = document.getElementById('pd3CheckFinderResult');
    if (!out) return;
    const n = String(needle || '').toLowerCase().trim();
    if (n === '') { out.innerHTML = renderChecksTable(checksCache); wireRemoveButtons(); return; }
    const filtered = checksCache.filter((r) =>
        String(r.transaction_id).includes(n)
        || String(r.receipt_number).includes(n)
        || String(r.waiter_name || '').toLowerCase().includes(n)
        || String(r.table_id || '').includes(n)
    );
    out.innerHTML = renderChecksTable(filtered);
    wireRemoveButtons();
}

async function findCheckById(idStr, { state }) {
    const out = document.getElementById('pd3CheckFinderResult');
    if (!out) return;
    const id = parseInt(String(idStr).replace(/\D+/g, ''), 10);
    if (!id || id <= 0) { filterChecksList(idStr); return; }
    out.innerHTML = '<p class="pd3-modal__loading">Ищу…</p>';
    try {
        const q = qs(state.get('range'));
        const data = await api.get('/payday3/api/poster/checks/find?id=' + id + '&' + q);
        if (!data.found) { out.innerHTML = '<p class="muted">Чек не найден за выбранный период.</p>'; return; }
        out.innerHTML = renderChecksTable([{
            transaction_id: id,
            receipt_number: data.transaction?.receipt_number || id,
            date_close:     data.transaction?.date_close     || '',
            sum:            data.transaction?.sum            || data.transaction?.payed_sum || 0,
            table_id:       data.transaction?.table_id       || '',
            waiter_name:    data.transaction?.waiter_name    || data.transaction?.name || '',
        }]);
        wireRemoveButtons();
    } catch (e) {
        out.innerHTML = `<p class="muted">Ошибка: ${escapeHtml(e.message || 'request failed')}</p>`;
    }
}

function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, (c) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    })[c]);
}

async function loadSettings() {
    const form = document.getElementById('pd3SettingsForm');
    if (!form) return;
    const status = document.getElementById('pd3SettingsStatus');
    status && (status.textContent = '');
    try {
        const data = await api.get('/payday3/api/settings');
        form.elements['telegram_chat_id'].value           = data.telegram_chat_id || '';
        form.elements['telegram_message_thread_id'].value = data.telegram_message_thread_id || '';
        form.elements['service_user_id'].value            = data.service_user_id || '';
        const acc = data.accounts || {};
        form.elements['accounts[andrey]'].value  = acc.andrey  || '';
        form.elements['accounts[tips]'].value    = acc.tips    || '';
        form.elements['accounts[vietnam]'].value = acc.vietnam || '';
        form.elements['balance_sinc_account_id'].value = data.balance_sinc_account_id || '';
    } catch (e) {
        status && (status.textContent = 'Ошибка загрузки: ' + (e.message || 'error'));
    }
}

async function saveSettings(event) {
    event.preventDefault();
    const form = event.currentTarget;
    const status = document.getElementById('pd3SettingsStatus');
    status && (status.textContent = 'Сохраняю…');
    const fd = new FormData(form);
    const body = {
        telegram_chat_id:           fd.get('telegram_chat_id'),
        telegram_message_thread_id: fd.get('telegram_message_thread_id'),
        service_user_id:            Number(fd.get('service_user_id')) || 0,
        accounts: {
            andrey:  Number(fd.get('accounts[andrey]'))  || 0,
            tips:    Number(fd.get('accounts[tips]'))    || 0,
            vietnam: Number(fd.get('accounts[vietnam]')) || 0,
        },
        balance_sinc_account_id: Number(fd.get('balance_sinc_account_id')) || 0,
    };
    try {
        await api.post('/payday3/api/settings', body);
        status && (status.textContent = 'Сохранено.');
        status?.classList.add('is-ok');
        status?.classList.remove('is-error');
    } catch (e) {
        status && (status.textContent = 'Ошибка: ' + (e.message || 'error'));
        status?.classList.add('is-error');
        status?.classList.remove('is-ok');
    }
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
            if (modalId === 'pd3KashShiftModal')   await loadKashShift({ state });
            if (modalId === 'pd3SuppliesModal')    await loadSupplies({ state });
            if (modalId === 'pd3SettingsModal')    await loadSettings();
            if (modalId === 'pd3CheckFinderModal') await loadChecksList({ state });
        });
    }

    document.getElementById('pd3SettingsForm')?.addEventListener('submit', saveSettings);

    // Check-finder: live filter while typing, exact-id search on submit.
    const finderInput = document.getElementById('pd3CheckFinderInput');
    finderInput?.addEventListener('input', () => filterChecksList(finderInput.value));

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
        if (q) findCheckById(q, { state });
    });
}
