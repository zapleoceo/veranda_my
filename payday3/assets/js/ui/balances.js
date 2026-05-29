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

// Cache-bust cross-module imports — see comment in out/bootstrap.js.
const _v = new URL(import.meta.url).searchParams.get('v') || '';
const _qs = _v ? '?v=' + encodeURIComponent(_v) : '';
const { api } = await import(new URL('../api.js' + _qs, import.meta.url).href);

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

function renderAccountsList(accounts) {
    const wrap  = document.getElementById('pd3BalAccountsWrap');
    const tbody = document.getElementById('pd3BalAccountsTbody');
    if (!wrap || !tbody) return;
    if (!accounts || accounts.length === 0) {
        wrap.hidden = true;
        tbody.innerHTML = '';
        return;
    }
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    })[c]);
    tbody.innerHTML = accounts.map((a) => `<tr>
        <td class="right nowrap muted">${esc(a.account_id)}</td>
        <td class="nowrap">${esc(a.name)}</td>
        <td class="right nowrap">${esc(fmt(a.balance))}</td>
    </tr>`).join('');
    wrap.hidden = false;
}

let posterCache = { andrey: null, vietnam: null, cash: null, total: null, accounts: [] };
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
        renderAccountsList(posterCache.accounts || []);
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
            const v = data?.['bal_' + k] ?? null;
            if (input) input.value = fmt(v);
            // Sync lastSavedKeys so saveActualNow doesn't see the initial
            // undefined vs null as a "change" and insert a ghost null-row
            // before the user has touched anything (which would then mask
            // older real data via the latestFor DESC query).
            lastSavedKeys[k] = v;
        }
        // total is computed client-side; seed its sentinel too so a
        // beforeunload during page load can't write null for it.
        lastSavedKeys['total'] = data?.['bal_total'] ?? null;
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

let savingActual = false;
let lastSavedKeys = {};
let saveTimer = 0;

async function saveActualNow(state) {
    if (savingActual) return;
    savingActual = true;
    const date = state.get('range')?.to || new Date().toISOString().slice(0, 10);
    const body = { target_date: date };
    let changed = false;
    for (const k of ['andrey', 'vietnam', 'cash', 'total']) {
        const input = document.getElementById('pd3BalActual_' + k);
        const v = input ? parse(input.value) : null;
        body['bal_' + k] = v;
        if (lastSavedKeys[k] !== v) changed = true;
    }
    if (!changed) { savingActual = false; return; }
    setStatus('Сохраняю…');
    try {
        await api.post('/payday3/api/balances', body);
        for (const k of ['andrey', 'vietnam', 'cash', 'total']) lastSavedKeys[k] = body['bal_' + k];
        setStatus('Сохранено в ' + date, 'ok');
    } catch (e) {
        setStatus('Ошибка: ' + (e.message || 'error'), 'error');
    } finally {
        savingActual = false;
    }
}

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

        const plan = await api.post('/payday3/api/balances/sync/plan', { diff_vnd: diff });
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

let _h2cPromise = null;
function loadHtml2Canvas() {
    if (window.html2canvas) return Promise.resolve(window.html2canvas);
    if (_h2cPromise) return _h2cPromise;
    _h2cPromise = new Promise((resolve, reject) => {
        const s = document.createElement('script');
        s.src = 'https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js';
        s.crossOrigin = 'anonymous';
        s.onload  = () => resolve(window.html2canvas);
        s.onerror = () => {
            _h2cPromise = null;
            reject(new Error('html2canvas CDN заблокирован — проверь сеть/блокировщики'));
        };
        document.head.appendChild(s);
    });
    return _h2cPromise;
}

async function sendBalancesToTelegram(state) {
    const card = document.getElementById('pd3Balances');
    const btn  = document.getElementById('pd3BalancesTelegramBtn');
    if (!card || !btn || btn.disabled) return;
    btn.disabled = true;
    btn.classList.add('is-busy');
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
    } finally {
        btn.disabled = false;
        btn.classList.remove('is-busy');
    }
}

export function initBalances({ state }) {
    if (!document.getElementById('pd3Balances')) return;

    document.querySelectorAll('.pd3-bal-input').forEach((el) => {
        // Live diff while typing — cheap, no network.
        el.addEventListener('input', () => { refreshDiffs(posterCache); syncBtnRefresh(); });
        // Reformat + auto-save when the field loses focus.
        el.addEventListener('blur', () => {
            const v = parse(el.value);
            el.value = v === null ? '' : fmt(v);
            refreshDiffs(posterCache);
            syncBtnRefresh();
            saveActualNow(state);
        });
        // Enter commits without losing focus.
        el.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') { e.preventDefault(); el.blur(); }
        });
        // Debounced save while typing — handles paste/long edits.
        el.addEventListener('input', () => scheduleAutoSave(state));
    });

    document.getElementById('pd3BalancesReloadBtn')?.addEventListener('click', () => reloadPoster().then(syncBtnRefresh));
    document.getElementById('pd3BalancesTelegramBtn')?.addEventListener('click', () => sendBalancesToTelegram(state));
    document.getElementById('pd3BalancesUpldBtn')?.addEventListener('click', () => runUpld(state));

    // Re-save before the user navigates away — guarantees the last
    // edit makes it to the server even if they tab away in a hurry.
    window.addEventListener('beforeunload', () => {
        if (savingActual) return;
        try { saveActualNow(state); } catch (_) {}
    });

    // Fire Poster (slow Poster API) and Факт (fast DB query) in parallel.
    // Previously the chain was reloadPoster().then(loadActual) — if the
    // Poster API hangs (it has, repeatedly: nginx-side 60 s upstream
    // timeouts surface in the error log), the operator stared at empty
    // ФАКТ inputs for a minute even though the local row was already
    // persisted. allSettled so a Poster failure doesn't sink the Факт
    // load, and vice versa.
    Promise.allSettled([reloadPoster(), loadActual(state)]).finally(syncBtnRefresh);

    // Pre-warm html2canvas in the background so the very first
    // Telegram click doesn't feel sluggish while the CDN script loads.
    // Failures here are silent — the actual click will retry and
    // surface the error in the status strip.
    setTimeout(() => { loadHtml2Canvas().catch(() => {}); }, 1500);
}
