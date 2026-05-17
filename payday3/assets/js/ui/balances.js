// Actual-balances card (Phase 8). Loads the most recent snapshot on
// boot, accepts user edits with thousand-separator-friendly parsing,
// posts a new snapshot on save. Append-only — every save creates a
// fresh history row.

'use strict';

import { api } from '../api.js';

const KEYS = ['bal_andrey', 'bal_vietnam', 'bal_cash', 'bal_total'];

const fmt = (n) => {
    if (n === null || n === undefined || n === '') return '';
    const v = Math.round(Number(n) || 0);
    try { return new Intl.NumberFormat('en-US', { maximumFractionDigits: 0 }).format(v).replace(/,/g, ' '); }
    catch (_) { return String(v).replace(/\B(?=(\d{3})+(?!\d))/g, ' '); }
};

// Strip every non-digit (spaces, narrow no-break spaces, commas) so
// the user can paste "1 234 567" and we still parse 1234567.
const parse = (s) => {
    const t = String(s ?? '').replace(/[^\d\-]/g, '');
    return t === '' ? null : Number(t);
};

function setStatus(msg, kind = '') {
    const el = document.getElementById('pd3BalancesStatus');
    if (!el) return;
    el.textContent = msg || '';
    el.classList.remove('is-ok', 'is-error');
    if (kind) el.classList.add(kind === 'ok' ? 'is-ok' : 'is-error');
}

async function load(state) {
    const date = state.get('range')?.to || new Date().toISOString().slice(0, 10);
    try {
        const data = await api.get('/payday3/api/balances?date=' + encodeURIComponent(date));
        for (const k of KEYS) {
            const input = document.querySelector(`[data-key="${k}"]`);
            if (input) input.value = fmt(data?.[k]);
        }
        if (data?.target_date) setStatus('Загружено: ' + data.target_date);
    } catch (e) {
        setStatus('Ошибка загрузки: ' + (e.message || 'request failed'), 'error');
    }
}

async function save(state) {
    const date = state.get('range')?.to || new Date().toISOString().slice(0, 10);
    const body = { target_date: date };
    for (const k of KEYS) {
        const input = document.querySelector(`[data-key="${k}"]`);
        body[k] = input ? parse(input.value) : null;
    }
    setStatus('Сохраняю…');
    try {
        await api.post('/payday3/api/balances', body);
        setStatus('Сохранено в ' + date, 'ok');
    } catch (e) {
        setStatus('Ошибка: ' + (e.message || 'request failed'), 'error');
    }
}

export function initBalances({ state }) {
    if (!document.getElementById('pd3Balances')) return;

    // Re-format on blur so "1234567" → "1 234 567".
    document.querySelectorAll('.pd3-bal-input').forEach((el) => {
        el.addEventListener('blur', () => {
            const v = parse(el.value);
            el.value = v === null ? '' : fmt(v);
        });
    });
    document.getElementById('pd3BalancesSaveBtn')?.addEventListener('click', () => save(state));
    document.getElementById('pd3BalancesReloadBtn')?.addEventListener('click', () => load(state));

    load(state);
}
