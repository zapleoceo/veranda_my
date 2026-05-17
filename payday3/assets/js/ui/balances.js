// Итоговый баланс card. Three sources of truth:
//   Poster   — GET /payday3/api/poster/balances (live snapshot)
//   Факт.    — operator-entered, persisted to payday_actual_balances
//   Разница  — Факт − Poster, computed client-side, coloured
//
// Both fetches use the in-flight disabled-button pattern + .is-busy
// spinner; the user can't double-fire either.

'use strict';

import { api } from '../api.js';

const KEYS = ['andrey', 'vietnam', 'cash', 'total'];

const fmt = (n) => {
    if (n === null || n === undefined || n === '') return '';
    const v = Math.round(Number(n) || 0);
    try { return new Intl.NumberFormat('en-US', { maximumFractionDigits: 0 }).format(v).replace(/,/g, ' '); }
    catch (_) { return String(v).replace(/\B(?=(\d{3})+(?!\d))/g, ' '); }
};

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

function refreshDiffs(posterMap) {
    let actualTotal = 0;
    for (const k of ['andrey', 'vietnam', 'cash']) {
        const input  = document.getElementById('pd3BalActual_' + k);
        const diffEl = document.getElementById('pd3BalDiff_'   + k);
        const v = parse(input?.value);
        if (v !== null) actualTotal += v;
        if (!diffEl) continue;
        const reported = posterMap?.[k] ?? null;
        if (v === null || reported === null) {
            diffEl.textContent = '—';
            diffEl.classList.remove('is-ok', 'is-error');
            continue;
        }
        const diff = v - reported;
        diffEl.textContent = fmt(diff);
        diffEl.classList.toggle('is-ok',    diff === 0);
        diffEl.classList.toggle('is-error', diff !== 0);
    }
    const tInput = document.getElementById('pd3BalActual_total');
    if (tInput) tInput.value = fmt(actualTotal);
    const tDiff  = document.getElementById('pd3BalDiff_total');
    if (tDiff && posterMap?.total !== null && posterMap?.total !== undefined) {
        const diff = actualTotal - posterMap.total;
        tDiff.textContent = fmt(diff);
        tDiff.classList.toggle('is-ok',    diff === 0);
        tDiff.classList.toggle('is-error', diff !== 0);
    }
}

let posterCache = { andrey: null, vietnam: null, cash: null, total: null };
let reloadInFlight = false;

async function reloadPoster() {
    if (reloadInFlight) return;
    reloadInFlight = true;
    const btn = document.getElementById('pd3BalancesReloadBtn');
    btn?.classList.add('is-busy');
    if (btn) btn.disabled = true;
    try {
        const data = await api.get('/payday3/api/poster/balances');
        posterCache = data || posterCache;
        for (const k of KEYS) {
            const el = document.getElementById('pd3BalPoster_' + k);
            if (!el) continue;
            const v = posterCache[k];
            el.textContent = v === null || v === undefined ? '—' : fmt(v);
        }
        refreshDiffs(posterCache);
    } catch (e) {
        setStatus('Poster balances: ' + (e.message || 'error'), 'error');
    } finally {
        reloadInFlight = false;
        btn?.classList.remove('is-busy');
        if (btn) btn.disabled = false;
    }
}

async function loadActual(state) {
    const date = state.get('range')?.to || new Date().toISOString().slice(0, 10);
    try {
        const data = await api.get('/payday3/api/balances?date=' + encodeURIComponent(date));
        for (const k of ['andrey', 'vietnam', 'cash']) {
            const input = document.getElementById('pd3BalActual_' + k);
            if (input) input.value = fmt(data?.['bal_' + k]);
        }
        refreshDiffs(posterCache);
    } catch (e) {
        setStatus('Факт: ' + (e.message || 'error'), 'error');
    }
}

let saving = false;
async function save(state) {
    if (saving) return;
    saving = true;
    const btn = document.getElementById('pd3BalancesSaveBtn');
    if (btn) btn.disabled = true;
    setStatus('Сохраняю…');
    const date = state.get('range')?.to || new Date().toISOString().slice(0, 10);
    const body = { target_date: date };
    for (const k of ['andrey', 'vietnam', 'cash', 'total']) {
        const input = document.getElementById('pd3BalActual_' + k);
        body['bal_' + k] = input ? parse(input.value) : null;
    }
    try {
        await api.post('/payday3/api/balances', body);
        setStatus('Сохранено в ' + date, 'ok');
    } catch (e) {
        setStatus('Ошибка: ' + (e.message || 'error'), 'error');
    } finally {
        saving = false;
        if (btn) btn.disabled = false;
    }
}

export function initBalances({ state }) {
    if (!document.getElementById('pd3Balances')) return;

    document.querySelectorAll('.pd3-bal-input').forEach((el) => {
        el.addEventListener('blur', () => {
            const v = parse(el.value);
            el.value = v === null ? '' : fmt(v);
            refreshDiffs(posterCache);
        });
        el.addEventListener('input', () => refreshDiffs(posterCache));
    });

    document.getElementById('pd3BalancesSaveBtn')?.addEventListener('click', () => save(state));
    document.getElementById('pd3BalancesReloadBtn')?.addEventListener('click', () => reloadPoster());

    reloadPoster().then(() => loadActual(state));
}
