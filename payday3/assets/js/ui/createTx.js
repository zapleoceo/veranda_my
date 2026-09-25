// "+" popup on unlinked bank rows → create a Poster finance
// transaction: OUT mail rows open it as an expense, IN SePay rows as
// an income (the button + its data live in ./rowCreateTx.js); the
// toolbar «+ транзакция» opens it blank on the selected day.
// Direct port of payday2's #createTxModal flow, trimmed to the
// essentials we actually use:
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
import { TX_TYPE, CREATE_TX_SELECTOR, readCreateTxTrigger, splitDateTime } from './rowCreateTx.js';
import { esc, fmtVnd, parseVnd } from './format.js';
import { setStatus }  from './busy.js';
import { buildCategoryTree, walkCategories, customName } from './categoryTree.js';

// The amount input shows positive VND only; empty for 0 / negative.
const fmtVndInt   = (n) => { const v = Math.round(Number(n) || 0); return v > 0 ? fmtVnd(v) : ''; };
const parseVndInt = (raw) => parseVnd(raw) ?? 0;

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
    // A failed fetch is NOT cached (retried on the next open) and is
    // reported in the status strip instead of leaving an empty list.
    const failed = [];
    const orNull = (label) => (e) => { failed.push(`${label}: ${e?.message || 'ошибка'}`); return null; };
    const [acc, cat, set] = await Promise.all([
        _accountsMap   ?? api.get('/payday3/api/poster/finance/accounts').catch(orNull('счета')),
        _categoriesMap ?? api.get('/payday3/api/poster/finance/categories').catch(orNull('категории')),
        api.get('/payday3/api/settings').catch(orNull('настройки')),
    ]);
    const asMap = (v) => (v && typeof v === 'object' ? v : null);
    _accountsMap   = asMap(acc);
    _categoriesMap = asMap(cat);
    _settings      = set || _settings || {};
    if (failed.length) status('Не загрузились ' + failed.join('; '), 'error');
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
    // Empty-value placeholder forces a real choice — submit handler
    // refuses to POST if catId === 0. Matches the modal's `required`
    // attribute and the inline validation in the submit listener.
    sel.innerHTML = '<option value="">Выберите категорию…</option>';
    const allowed = new Set((_settings?.allowed_categories || []).map(Number));
    const custom  = _settings?.custom_category_names || {};

    // Same tree walk as the settings modal — depth-indented options.
    const { roots, byId } = buildCategoryTree(_categoriesMap);
    walkCategories(roots, (node, depth) => {
        if (!allowed.has(node.id)) return;
        const opt = document.createElement('option');
        opt.value = String(node.id);
        opt.textContent = '— '.repeat(depth) + (customName(custom, node.id) || node.name);
        sel.appendChild(opt);
    });

    // Safety net: any allowed id not seen via the tree (orphans) still
    // appears as a flat option so the operator's whitelist is respected.
    const seen = new Set(Array.from(sel.options).map((o) => Number(o.value)));
    for (const id of allowed) {
        if (!seen.has(id) && byId[id]) {
            const opt = document.createElement('option');
            opt.value = String(id);
            opt.textContent = customName(custom, id) || byId[id].name || ('#' + id);
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

const status = (text, kind = '') => setStatus(document.getElementById('pd3CreateTxStatus'), text, kind);

export function initCreateTx({ state, host, openModal, closeModal, onCreated }) {
    const form = document.getElementById('pd3CreateTxForm');
    if (!form) return { open: () => {} };

    form.elements['type'].addEventListener('change', () => applyTypeVisibility(form));

    // VND-only sanitisation while typing.
    const amountEl = form.elements['amount'];
    amountEl.addEventListener('input', () => { amountEl.value = fmtVndInt(parseVndInt(amountEl.value)); });

    form.addEventListener('submit', async (e) => {
        e.preventDefault();

        // Parse values up-front so we can validate without doing the
        // work twice. Reads are cheap; bail early on bad input.
        const type   = Number(form.elements['type'].value) || 0;
        const date   = form.elements['date'].value;
        const time   = form.elements['time'].value || '00:00';
        const dt     = `${date} ${time.length === 5 ? time + ':00' : time}`;
        const amount = parseVndInt(amountEl.value);
        const accFromId = Number(form.elements['account_from'].value) || 0;
        const accToId   = Number(form.elements['account_to'].value)   || 0;
        const catId     = Number(form.elements['category_id'].value)  || 0;

        // Client-side validation — surfaces problems instantly without
        // a round-trip to Poster (which would just reject with a vague
        // 400). All checks set status to red and focus the offending
        // field so the next click goes straight to it.
        const catEl = form.elements['category_id'];
        if (!catId) {
            status('Выберите категорию', 'error');
            catEl?.focus?.();
            return;
        }

        status('Создаю…');
        const submitBtn = form.querySelector('button[type=submit]');
        if (submitBtn) submitBtn.disabled = true;
        try {
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
            // Refresh what the new transaction changes: the OUT finance
            // table (an expense shows up there and becomes linkable) and
            // the Poster balances. An IN income is intentionally not
            // linked to anything — IN links bank rows to sales checks.
            // Fire-and-forget — the user is already looking at the
            // success modal, no need to block on the refetch.
            Promise.resolve().then(() => onCreated?.())
                .catch((e) => console.error('[payday3] refresh after create failed', e));
        } catch (err) {
            status(err.message || 'Ошибка', 'error');
        } finally {
            if (submitBtn) submitBtn.disabled = false;
        }
    });

    /**
     * Open the modal pre-filled from a bank row's "+" button.
     *   amount  = number, VND
     *   dateIso = 'Y-m-d H:i:s' or 'Y-m-d' (bank row timestamp)
     *   type    = TX_TYPE.EXPENSE (OUT mail) | TX_TYPE.INCOME (IN SePay)
     */
    async function open(amount, dateIso, type = TX_TYPE.EXPENSE) {
        status('');
        // Row timestamp → date + time inputs ("now" when missing).
        const { date, time } = splitDateTime(dateIso);
        form.elements['date'].value   = date;
        form.elements['time'].value   = time;
        form.elements['type'].value   = String(type);
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

    // Delegated click handler for the "+" on any bank row (OUT mail
    // and IN SePay) — works across re-renders without re-binding.
    document.addEventListener('click', (e) => {
        const btn = e.target.closest?.(CREATE_TX_SELECTOR);
        if (!btn) return;
        e.preventDefault();
        e.stopPropagation();
        const { amount, date, type } = readCreateTxTrigger(btn);
        open(amount, date, type);
    });

    // Toolbar «+ транзакция»: blank transaction on the selected day (time =
    // now); the operator picks type, account, amount and category.
    document.getElementById('pd3CreateTxBtn')?.addEventListener('click', () => {
        open(0, state?.get?.('range')?.from || '');
    });

    return { open };
}
