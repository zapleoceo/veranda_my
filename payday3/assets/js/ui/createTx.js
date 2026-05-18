// "+" popup on each OUT-mail row → create a Poster finance
// transaction. Direct port of payday2's #createTxModal flow,
// trimmed to the essentials we actually use:
//
//   - Date / Time / Type
//   - Account-from (expense / transfer) and Account-to (income / transfer)
//   - Amount (VND integer)
//   - Category (filtered by LocalSettings.allowed_categories + renamed
//     via custom_category_names, identical to payday2)
//   - Comment
//
// Loads accounts + categories on first open and reuses the cache.

'use strict';

import { api } from '../api.js';

const fmtVndInt = (n) => {
    const v = Math.round(Number(n) || 0);
    if (!Number.isFinite(v) || v <= 0) return '';
    try { return new Intl.NumberFormat('en-US', { maximumFractionDigits: 0 }).format(v).replace(/,/g, ' '); }
    catch (_) { return String(v).replace(/\B(?=(\d{3})+(?!\d))/g, ' '); }
};
const parseVndInt = (raw) => {
    const cleaned = String(raw ?? '').replaceAll(' ', '').replaceAll(' ', '').replace(/[^\d-]/g, '');
    const n = parseInt(cleaned, 10);
    return Number.isFinite(n) ? n : 0;
};
const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
})[c]);

let _accountsMap = null;        // { <id>: <name> }
let _categoriesMap = null;      // { <id>: { name, parent_id } }
let _settings = null;

async function loadOptionsOnce() {
    const needAcc = !_accountsMap;
    const needCat = !_categoriesMap;
    const needSet = !_settings;
    const [acc, cat, set] = await Promise.all([
        needAcc ? api.get('/payday3/api/poster/finance/accounts').catch(() => ({}))   : Promise.resolve(_accountsMap),
        needCat ? api.get('/payday3/api/poster/finance/categories').catch(() => ({})) : Promise.resolve(_categoriesMap),
        needSet ? api.get('/payday3/api/settings').catch(() => null)                  : Promise.resolve(_settings),
    ]);
    if (needAcc) _accountsMap   = acc && typeof acc === 'object' ? acc : {};
    if (needCat) _categoriesMap = cat && typeof cat === 'object' ? cat : {};
    if (needSet) _settings      = set || {};
}

function fillAccountSelect(sel) {
    if (!sel) return;
    sel.innerHTML = '<option value="">Выберите счёт…</option>';
    for (const [id, name] of Object.entries(_accountsMap || {})) {
        const opt = document.createElement('option');
        opt.value = id;
        opt.textContent = String(name);
        sel.appendChild(opt);
    }
}

function fillCategorySelect(sel) {
    if (!sel) return;
    sel.innerHTML = '<option value="">Без категории</option>';
    const allowed = new Set((_settings?.allowed_categories || []).map(Number));
    const custom  = _settings?.custom_category_names || {};

    // Same tree walk as the settings modal — depth-indented options.
    const byId  = {};
    const roots = [];
    for (const [idStr, data] of Object.entries(_categoriesMap || {})) {
        const id = Number(idStr);
        byId[id] = { id, name: String(data?.name || ''), parent_id: Number(data?.parent_id || 0), children: [] };
    }
    for (const id in byId) {
        const n = byId[id];
        if (n.parent_id && byId[n.parent_id]) byId[n.parent_id].children.push(n);
        else                                   roots.push(n);
    }
    const walk = (node, depth) => {
        if (allowed.has(node.id)) {
            const label = custom[node.id] || custom[String(node.id)] || node.name;
            const opt = document.createElement('option');
            opt.value = String(node.id);
            opt.textContent = '— '.repeat(depth) + label;
            sel.appendChild(opt);
        }
        for (const c of node.children) walk(c, depth + 1);
    };
    for (const r of roots) walk(r, 0);

    // Safety net: any allowed id not seen via the tree (orphans) still
    // appears as a flat option so the operator's whitelist is respected.
    const seen = new Set(Array.from(sel.options).map((o) => Number(o.value)));
    for (const id of allowed) {
        if (!seen.has(id) && byId[id]) {
            const opt = document.createElement('option');
            opt.value = String(id);
            opt.textContent = custom[id] || custom[String(id)] || byId[id].name || ('#' + id);
            sel.appendChild(opt);
        }
    }
}

function applyTypeVisibility(form) {
    const type = Number(form.elements['type'].value || 0);
    const fromWrap = document.getElementById('pd3CreateTxAccFromWrap');
    const toWrap   = document.getElementById('pd3CreateTxAccToWrap');
    // Income (1)   → only To
    // Expense (2)  → only From
    // Transfer (3) → both
    if (fromWrap) fromWrap.hidden = (type === 1);
    if (toWrap)   toWrap.hidden   = (type === 2);
}

function status(text, kind = '') {
    const el = document.getElementById('pd3CreateTxStatus');
    if (!el) return;
    el.textContent = text || '';
    el.classList.remove('is-ok', 'is-error');
    if (kind) el.classList.add(kind === 'ok' ? 'is-ok' : 'is-error');
}

export function initCreateTx({ host, openModal, closeModal }) {
    const form = document.getElementById('pd3CreateTxForm');
    if (!form) return { open: () => {} };

    form.elements['type'].addEventListener('change', () => applyTypeVisibility(form));

    // VND-only sanitisation while typing.
    const amountEl = form.elements['amount'];
    amountEl.addEventListener('input', () => { amountEl.value = fmtVndInt(parseVndInt(amountEl.value)); });

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        status('Создаю…');
        const submitBtn = form.querySelector('button[type=submit]');
        if (submitBtn) submitBtn.disabled = true;
        try {
            const date   = form.elements['date'].value;
            const time   = form.elements['time'].value || '00:00';
            const dt     = `${date} ${time.length === 5 ? time + ':00' : time}`;
            const body = {
                type:         Number(form.elements['type'].value) || 0,
                amount:       parseVndInt(amountEl.value),
                date:         dt,
                account_from: Number(form.elements['account_from'].value) || 0,
                account_to:   Number(form.elements['account_to'].value)   || 0,
                category_id:  Number(form.elements['category_id'].value)  || 0,
                comment:      form.elements['comment'].value || '',
            };
            await api.post('/payday3/api/poster/finance/transactions', body);
            status('Создана в Poster', 'ok');
            setTimeout(() => closeModal?.('pd3CreateTxModal'), 700);
        } catch (err) {
            status(err.message || 'Ошибка', 'error');
        } finally {
            if (submitBtn) submitBtn.disabled = false;
        }
    });

    /**
     * Open the modal pre-filled from an OUT-mail row's data-* values.
     *   amount = number, VND
     *   dateIso = 'Y-m-d H:i:s' or 'Y-m-d' (mail row's data-ts)
     */
    async function open(amount, dateIso) {
        status('');
        // Parse "YYYY-MM-DD HH:MM:SS" into the date + time inputs;
        // fall back to "now" when the row didn't have a timestamp.
        const now = new Date();
        const pad = (n) => String(n).padStart(2, '0');
        let datePart = `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
        let timePart = `${pad(now.getHours())}:${pad(now.getMinutes())}`;
        const m = String(dateIso || '').match(/^(\d{4}-\d{2}-\d{2})(?:[ T](\d{2}:\d{2}))?/);
        if (m) {
            datePart = m[1];
            if (m[2]) timePart = m[2];
        }
        form.elements['date'].value   = datePart;
        form.elements['time'].value   = timePart;
        form.elements['type'].value   = '2';                     // Expense (matches payday2 default)
        form.elements['amount'].value = fmtVndInt(Number(amount) || 0);
        form.elements['comment'].value = '';

        openModal?.('pd3CreateTxModal');

        await loadOptionsOnce();
        fillAccountSelect(form.elements['account_from']);
        fillAccountSelect(form.elements['account_to']);
        fillCategorySelect(form.elements['category_id']);
        applyTypeVisibility(form);
    }

    // Delegated click handler on the OUT-mail tbody — works across
    // re-renders without re-binding.
    document.addEventListener('click', (e) => {
        const btn = e.target.closest?.('.pd3-out-mail-create');
        if (!btn) return;
        e.preventDefault();
        e.stopPropagation();
        const amount = btn.dataset.amount;
        const date   = btn.dataset.date;
        open(amount, date);
    });

    return { open };
}
