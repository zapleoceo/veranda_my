// Итоговый баланс card. Three columns of truth:
//   Poster   — GET /payday3/api/poster/balances (live snapshot)
//   Факт.    — operator-entered, persisted to payday_actual_balances
//              AUTOMATICALLY when the input loses focus (no Save btn)
//   Δ        — Факт − Poster, computed client-side, coloured
//
// Header buttons:
//   ↻  reload Poster balances + accounts list
//   ✈  send html2canvas screenshot of the card to Telegram
//
// Below the 4-row grid we render every Poster account with its
// balance — populated from the same /poster/balances payload.

'use strict';

import { api }                  from '../api.js';
import { esc, fmtVnd, parseVnd as parse } from './format.js';
import { coalesce }             from './coalesce.js';
import { withBusy, setStatus as setStatusOf } from './busy.js';
import { splitDateTime }        from './rowCreateTx.js';
import { loadHtml2Canvas }      from './html2canvasLoader.js';

// Rows of the card. Факт. Total = sum of ROW_KEYS; Poster Total = every
// Poster account — so a Poster account without a row skews Δ Total
// (see renderUnmapped).
const ROW_KEYS = ['andrey', 'vietnam', 'cash', 'stash'];
const KEYS     = [...ROW_KEYS, 'total'];

// Missing values render as '' in this card (empty input / no Δ).
const fmt = (n) => fmtVnd(n, { empty: '' });

const setStatus = (msg, kind = '') => setStatusOf(document.getElementById('pd3BalancesStatus'), msg, kind);

/** Day the Факт. values belong to: range.to, else today in LOCAL time. */
const targetDate = (state) => state.get('range')?.to || splitDateTime('').date;

// Colour the Δ cell:
//   ≥ 0  → green (Факт covers Poster; surplus is fine)
//   < 0  → red   (Факт is short of Poster; needs attention)
// A separate helper keeps the logic in one place — the Total row
// applies the same rule.
function paintDiff(el, diff) {
    el.classList.remove('is-ok', 'is-error');
    el.classList.add(diff < 0 ? 'is-error' : 'is-ok');
}

function refreshDiffs(posterMap) {
    let actualTotal = 0;
    for (const k of ROW_KEYS) {
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
        paintDiff(diffEl, diff);
    }
    const tInput = document.getElementById('pd3BalActual_total');
    if (tInput) tInput.value = fmt(actualTotal);
    const tDiff  = document.getElementById('pd3BalDiff_total');
    if (tDiff && posterMap?.total !== null && posterMap?.total !== undefined) {
        const diff = actualTotal - posterMap.total;
        tDiff.textContent = fmt(diff);
        paintDiff(tDiff, diff);
    }
}

// Warn about Poster accounts that are counted in Poster Total but have
// no row of their own — otherwise the only symptom is a red Δ Total.
function renderUnmapped(unmapped) {
    const el = document.getElementById('pd3BalUnmapped');
    if (!el) return;
    const list = Array.isArray(unmapped) ? unmapped : [];
    if (list.length === 0) { el.hidden = true; el.textContent = ''; return; }
    const parts = list.map((a) => `#${a.account_id} ${a.name || ''} (${fmt(a.balance)})`);
    el.textContent = '⚠ Не учтены в строках, но входят в Poster Total: ' + parts.join(', ')
        + '. Без своей строки Δ Total уйдёт в минус.';
    el.hidden = false;
}

function renderAccountsList(accounts) {
    const wrap  = document.getElementById('pd3BalAccountsWrap');
    const tbody = document.getElementById('pd3BalAccountsTbody');
    if (!wrap || !tbody) return;
    if (!accounts || accounts.length === 0) {
        wrap.hidden = true;
        tbody.innerHTML = '';
        return;
    }
    tbody.innerHTML = accounts.map((a) => `<tr>
        <td class="right nowrap muted">${esc(a.account_id)}</td>
        <td class="nowrap">${esc(a.name)}</td>
        <td class="right nowrap">${esc(fmt(a.balance))}</td>
    </tr>`).join('');
    wrap.hidden = false;
}

let posterCache = { andrey: null, vietnam: null, cash: null, stash: null, total: null, accounts: [], unmapped: [] };

// Latest-wins: a ↻ during a load (or a post-create refresh) gets a
// fresh read after it instead of being dropped.
const reloadPoster = coalesce(withBusy(document.getElementById('pd3BalancesReloadBtn'), async () => {
    try {
        const data = await api.get('/payday3/api/poster/balances');
        posterCache = data || posterCache;
        for (const k of KEYS) {
            const el = document.getElementById('pd3BalPoster_' + k);
            if (!el) continue;
            const v = posterCache[k];
            el.textContent = v === null || v === undefined ? '—' : fmt(v);
        }
        renderAccountsList(posterCache.accounts || []);
        renderUnmapped(posterCache.unmapped);
        refreshDiffs(posterCache);
    } catch (e) {
        setStatus('Poster balances: ' + (e.message || 'error'), 'error');
    }
}));

async function loadActual(state) {
    try {
        const data = await api.get('/payday3/api/balances?date=' + encodeURIComponent(targetDate(state)));
        for (const k of ROW_KEYS) {
            const input = document.getElementById('pd3BalActual_' + k);
            const v = data?.['bal_' + k] ?? null;
            if (input) input.value = fmt(v);
            // Sync the sentinels so a save doesn't see the initial
            // undefined vs null as a "change" and insert a ghost null-row
            // before the user has touched anything (which would then mask
            // older real data via the latestFor DESC query).
            lastSavedKeys[k] = v;
        }
        // total is computed client-side; seed its sentinel too so a
        // beforeunload during page load can't write null for it.
        lastSavedKeys['total'] = data?.['bal_total'] ?? null;
        lastSentKeys = { ...lastSavedKeys };
        refreshDiffs(posterCache);
    } catch (e) {
        setStatus('Факт: ' + (e.message || 'error'), 'error');
    }
}

// ─── Auto-save plumbing ────────────────────────────────────────
//
// We persist whenever a value actually changed AND the user has
// committed it (blur, Enter, or 600 ms after the last keystroke).
// Repeated blurs with no change are ignored — no UI flicker.
//
// Saves are latest-wins (ui/coalesce.js): an edit committed while a
// save is in flight queues ONE more save that re-reads the inputs when
// it starts — nothing is dropped, and `await saveActualNow()` resolves
// only after the values on screen reached the server.

let lastSavedKeys = {};   // what the server confirmed
let lastSentKeys  = {};   // what is on its way (≥ lastSavedKeys)
let saveTimer = 0;

/** Current inputs → POST body + whether it differs from what was sent. */
function collectSave(state) {
    const date = targetDate(state);
    const body = { target_date: date };
    let changed = false;
    for (const k of KEYS) {
        const input = document.getElementById('pd3BalActual_' + k);
        const v = input ? parse(input.value) : null;
        body['bal_' + k] = v;
        if (lastSentKeys[k] !== v) changed = true;
    }
    return { date, body, changed };
}

const keysOf = (body) => Object.fromEntries(KEYS.map((k) => [k, body['bal_' + k]]));

const saveActualNow = coalesce(async (state) => {
    const { date, body, changed } = collectSave(state);
    if (!changed) return;
    lastSentKeys = keysOf(body);
    setStatus('Сохраняю…');
    try {
        await api.post('/payday3/api/balances', body);
        lastSavedKeys = keysOf(body);
        setStatus('Сохранено в ' + date, 'ok');
    } catch (e) {
        lastSentKeys = { ...lastSavedKeys };   // retry on the next commit
        setStatus('Ошибка: ' + (e.message || 'error'), 'error');
    }
});

function scheduleAutoSave(state, delay = 600) {
    clearTimeout(saveTimer);
    saveTimer = setTimeout(() => saveActualNow(state), delay);
}

// ─── UPLD — Poster correction transaction ─────────────────────
//
// Computes Факт.(Andrey) − Poster(Andrey+Tips) in VND, asks the
// server for a plan (returns a nonce + preview), confirms with the
// operator via a native dialog, then commits.

function syncBtnRefresh() {
    const btn = document.getElementById('pd3BalancesUpldBtn');
    if (!btn) return;
    const factual = parse(document.getElementById('pd3BalActual_andrey')?.value);
    const poster  = posterCache?.andrey;
    const ready   = factual !== null && poster !== null && poster !== undefined && factual !== poster;
    btn.disabled = !ready;
    if (ready) {
        const diff = factual - poster;
        btn.title = (diff > 0 ? 'Начислить ' : 'Списать ') + fmt(Math.abs(diff)) + ' (Факт. − Poster по Андрею)';
    } else if (factual === null) {
        btn.title = 'Заполни Факт. по Андрею';
    } else if (poster === null || poster === undefined) {
        btn.title = 'Нет баланса Poster по Андрею — нажми ↻';
    } else {
        btn.title = 'Разница = 0';
    }
}

let upldInFlight = false;
async function runUpld(state) {
    if (upldInFlight) return;
    const factual = parse(document.getElementById('pd3BalActual_andrey')?.value);
    const poster  = posterCache?.andrey;
    if (factual === null)        { alert('Заполни Факт. по Андрею'); return; }
    if (poster === null || poster === undefined) {
        alert('Нет баланса Poster по Андрею — нажми ↻');
        return;
    }
    const diff = factual - poster;
    if (diff === 0) { alert('Разница = 0'); return; }

    upldInFlight = true;
    const btn = document.getElementById('pd3BalancesUpldBtn');
    if (btn) { btn.disabled = true; btn.classList.add('is-busy'); }
    setStatus('Готовлю план…');
    try {
        // Make sure the latest Факт. value is on the server before
        // we use it as the source of truth.
        await saveActualNow(state);

        const plan = await api.post('/payday3/api/balances/sync/plan', { diff_vnd: diff, target_date: targetDate(state) });
        if (!plan?.nonce || !plan.plan) throw new Error('Plan empty');
        const p = plan.plan;
        const action = p.type === 1 ? 'Начислить' : 'Списать';
        const accLabel = p.account_name
            ? `счёт ${p.account_id} (${p.account_name})`
            : `счёт ${p.account_id}`;
        const ok = confirm(`${action} ${fmt(p.amount_vnd)} на ${accLabel}?\n\nКомментарий: ${p.comment}`);
        if (!ok) { setStatus('Отменено'); return; }

        setStatus('Создаю транзакцию в Poster…');
        const res = await api.post('/payday3/api/balances/sync/commit', { nonce: plan.nonce });
        setStatus(res?.already ? 'Уже была создана сегодня' : 'Транзакция создана в Poster', 'ok');
        await reloadPoster();   // pick up the new balance immediately
    } catch (e) {
        setStatus('UPLD: ' + (e.message || 'error'), 'error');
    } finally {
        upldInFlight = false;
        if (btn) { btn.classList.remove('is-busy'); syncBtnRefresh(); }
    }
}

// ─── Telegram screenshot ───────────────────────────────────────

async function sendBalancesToTelegram(state) {
    const card = document.getElementById('pd3Balances');
    if (!card) return;
    setStatus('Готовлю снимок…');
    try {
        // Make sure any pending blur-save is committed first.
        await saveActualNow(state);

        const html2canvas = await loadHtml2Canvas();

        // Swap inputs → divs so html2canvas doesn't mis-baseline text.
        const inputs = Array.from(card.querySelectorAll('input.pd3-bal-input'));
        const swaps = inputs.map((inp) => {
            const fake = document.createElement('div');
            fake.className = 'pd3-bal-input pd3-bal-input--ghost';
            fake.textContent = inp.value;
            inp.parentNode.insertBefore(fake, inp);
            inp.style.display = 'none';
            return { inp, fake };
        });
        try {
            const canvas = await html2canvas(card, {
                // scale: 1.5 keeps the screenshot crisp on retina
                // displays without blowing up the base64 payload — a
                // 2× scale was producing ~2 MB requests that
                // pressured PHP-FPM memory on the origin.
                scale: 1.5,
                useCORS: true,
                backgroundColor: getComputedStyle(document.body).backgroundColor || '#0f172a',
            });
            // JPEG at q≈0.92 is ~4× smaller than PNG for the kind of
            // anti-aliased text we have here; visually
            // indistinguishable for the Telegram preview.
            const dataUrl = canvas.toDataURL('image/jpeg', 0.92);
            setStatus('Отправка…');
            await api.post('/payday3/api/balances/telegram', { image: dataUrl });
            setStatus('Отправлено в Telegram', 'ok');
        } finally {
            for (const { inp, fake } of swaps) {
                fake.remove();
                inp.style.display = '';
            }
        }
    } catch (e) {
        setStatus('Telegram: ' + (e.message || 'error'), 'error');
    }
}

/**
 * @returns {{reload: () => Promise<void>}} — re-reads Poster balances;
 *   used after a "+" transaction is created so the Poster column moves.
 */
export function initBalances({ state }) {
    const reload = () => reloadPoster().then(syncBtnRefresh);
    if (!document.getElementById('pd3Balances')) return { reload: async () => {} };

    document.querySelectorAll('.pd3-bal-input').forEach((el) => {
        // Live diff while typing — cheap, no network.
        el.addEventListener('input', () => { refreshDiffs(posterCache); syncBtnRefresh(); });
        // Reformat + auto-save when the field loses focus.
        el.addEventListener('blur', () => {
            const v = parse(el.value);
            el.value = v === null ? '' : fmt(v);
            refreshDiffs(posterCache);
            syncBtnRefresh();
            clearTimeout(saveTimer);
            saveActualNow(state);
        });
        // Enter commits without losing focus.
        el.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') { e.preventDefault(); el.blur(); }
        });
        // Debounced save while typing — handles paste/long edits.
        el.addEventListener('input', () => scheduleAutoSave(state));
    });

    document.getElementById('pd3BalancesReloadBtn')?.addEventListener('click', reload);
    const tgBtn = document.getElementById('pd3BalancesTelegramBtn');
    tgBtn?.addEventListener('click', withBusy(tgBtn, () => sendBalancesToTelegram(state)));
    document.getElementById('pd3BalancesUpldBtn')?.addEventListener('click', () => runUpld(state));

    // Last edit before the user navigates away. An ordinary fetch is
    // cancelled on unload; keepalive lets it finish, and unlike
    // sendBeacon it still carries the X-CSRF-Token header (api.js).
    window.addEventListener('beforeunload', () => {
        const { body, changed } = collectSave(state);
        if (!changed) return;
        lastSentKeys = keysOf(body);
        api.post('/payday3/api/balances', body, { keepalive: true }).catch(() => {});
    });

    // Fire Poster (slow Poster API) and Факт (fast DB query) in parallel.
    // Previously the chain was reloadPoster().then(loadActual) — if the
    // Poster API hangs (it has, repeatedly: nginx-side 60 s upstream
    // timeouts surface in the error log), the operator stared at empty
    // ФАКТ inputs for a minute even though the local row was already
    // persisted. allSettled so a Poster failure doesn't sink the Факт
    // load, and vice versa.
    Promise.allSettled([reloadPoster(), loadActual(state)]).finally(syncBtnRefresh);

    // html2canvas is loaded lazily on the first ✈ click (html2canvasLoader.js).

    return { reload };
}
