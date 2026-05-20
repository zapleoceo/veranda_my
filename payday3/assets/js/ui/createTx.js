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

// Cache-bust cross-module imports — see comment in out/bootstrap.js.
const _v = new URL(import.meta.url).searchParams.get('v') || '';
const _qs = _v ? '?v=' + encodeURIComponent(_v) : '';
const { api } = await import(new URL('../api.js' + _qs, import.meta.url).href);

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

/** Read the visible text of the currently-selected option in a <select>. */
function labelOf(sel) {
    if (!sel || sel.selectedIndex < 0) return '';
    const opt = sel.options[sel.selectedIndex];
    return opt ? String(opt.textContent || opt.text || '').trim() : '';
}

const TYPE_LABEL = { 1: 'Доход', 2: 'Расход', 3: 'Перевод' };

/**
 * Open the success-summary modal after a transaction was created.
 * Mirrors payday2's createTxSuccessModal flow — title names the
 * operation, body lists the key fields.
 */
function showSuccess({ type, amount, accFromLabel, accToLabel, catLabel, openModal }) {
    const wrap = document.getElementById('pd3CreateTxSuccessDetails');
    const titleEl = document.getElementById('pd3CreateTxSuccessTitle');
    if (!wrap || !titleEl) return;

    // Title — same pattern as payday2: name the kind of operation + account.
    let title = 'Транзакция создана';
    if      (type === 1 && accToLabel)   title = `Приход на счёт «${accToLabel}» создан`;
    else if (type === 2 && accFromLabel) title = `Расход со счёта «${accFromLabel}» создан`;
    else if (type === 3 && accFromLabel && accToLabel) title = `Перевод «${accFromLabel}» → «${accToLabel}» создан`;
    titleEl.textContent = title;

    const row = (label, value) => `
        <div class="pd3-tx-success__row">
            <div class="pd3-tx-success__label">${esc(label)}</div>
            <div class="pd3-tx-success__value">${value}</div>
        </div>`;

    const lines = [];
    lines.push(row('Тип', `<strong>${esc(TYPE_LABEL[type] || '—')}</strong>`));
    lines.push(row('Сумма', `<strong>${esc(fmtVndInt(amount))} ₫</strong>`));
    if (type === 1) lines.push(row('На счёт',  esc(accToLabel)));
    if (type === 2) lines.push(row('Со счёта', esc(accFromLabel)));
    if (type === 3) {
        lines.push(row('Со счёта', esc(accFromLabel)));
        lines.push(row('На счёт',  esc(accToLabel)));
    }
    if (catLabel) lines.push(row('Категория', esc(catLabel)));
    wrap.innerHTML = lines.join('');

    openModal?.('pd3CreateTxSuccessModal');
}

let _accountsMap = null;        // { <id>: <name> }
let _categoriesMap = null;      // { <id>: { name, parent_id } }
let _settings = null;

async function loadOptions() {
    // Poster accounts + categories rarely change at runtime, so we
    // only fetch them on first open. LocalSettings (especially the
    // category whitelist) *does* change whenever the operator hits
    // Save in ⚙ — refetch every open so the popup picks up new
    // entries without a hard page reload.
    const [acc, cat, set] = await Promise.all([
        _accountsMap   ? Promise.resolve(_accountsMap)
                       : api.get('/payday3/api/poster/finance/accounts').catch(() => ({})),
        _categoriesMap ? Promise.resolve(_categoriesMap)
                       : api.get('/payday3/api/poster/finance/categories').catch(() => ({})),
        api.get('/payday3/api/settings').catch(() => _settings),
    ]);
    _accountsMap   = acc && typeof acc === 'object' ? acc : {};
    _categoriesMap = cat && typeof cat === 'object' ? cat : {};
    _settings      = set || _settings || {};
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

export function initCreateTx({ state, host, openModal, closeModal, onCreated }) {
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
            const type   = Number(form.elements['type'].value) || 0;
            const date   = form.elements['date'].value;
            const time   = form.elements['time'].value || '00:00';
            const dt     = `${date} ${time.length === 5 ? time + ':00' : time}`;
            const amount = parseVndInt(amountEl.value);
            const accFromId = Number(form.elements['account_from'].value) || 0;
            const accToId   = Number(form.elements['account_to'].value)   || 0;
            const catId     = Number(form.elements['category_id'].value)  || 0;
            const body = {
                type, amount, date: dt,
                account_from: accFromId,
                account_to:   accToId,
                category_id:  catId,
                comment:      form.elements['comment'].value || '',
            };
            await api.post('/payday3/api/poster/finance/transactions', body);
            // Switch straight to the success modal — openModal hides all
            // other .pd3-modal panes before showing its target, so the
            // form vanishes in the same paint as the summary appears.
            // Mirrors payday2's createTxSuccessModal flow.
            showSuccess({
                type, amount,
                accFromLabel: labelOf(form.elements['account_from']),
                accToLabel:   labelOf(form.elements['account_to']),
                catLabel:     catId ? labelOf(form.elements['category_id']) : '',
                openModal,
            });
            // Clear status so a stale "Создаю…" doesn't appear on next open.
            status('');
            // Refresh the OUT-mode tables so the newly-created
            // transaction shows up immediately on the Poster side
            // (and auto-matchers can pick it up if it pairs with a
            // mail row). Fire-and-forget — the user is already looking
            // at the success modal, no need to block on the refetch.
            try { onCreated?.(); } catch (_) { /* swallow */ }
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
        // Default comment mirrors payday2 ("Created by <email>"); the
        // email is shipped from server via pd3-bootstrap.userEmail.
        const userEmail = (state?.get?.('userEmail')) || 'User';
        form.elements['comment'].value = 'Created by ' + userEmail;

        openModal?.('pd3CreateTxModal');

        await loadOptions();
        fillAccountSelect(form.elements['account_from']);
        fillAccountSelect(form.elements['account_to']);
        fillCategorySelect(form.elements['category_id']);

        // Pre-select the Andrey account on the From side — that's
        // the source for 99% of operator-created expenses. ID comes
        // from LocalSettings (the same place the rest of the app
        // reads it), with a sane fallback to account 1.
        const andreyId = String(_settings?.accounts?.andrey || 1);
        const hasOpt = (sel, v) => Array.from(sel?.options || []).some((o) => String(o.value) === String(v));
        if (hasOpt(form.elements['account_from'], andreyId)) {
            form.elements['account_from'].value = andreyId;
        }
        if (hasOpt(form.elements['account_to'], andreyId)) {
            form.elements['account_to'].value = andreyId;
        }

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
