// Renders the sepay + poster IN-mode tbodies from the snapshot returned
// by GET /payday3/api/data. Mirrors out/renderTables.js: pure functions,
// pass rows + links in, no fetching. Keeps the markup byte-for-byte
// equivalent to the server-side partial so all existing selectors (eye
// toggles, sort, selection, line renderer) keep working.

'use strict';

const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
})[c]);

const fmt = (n) => {
    const v = Math.round(Number(n) || 0);
    try { return new Intl.NumberFormat('en-US', { maximumFractionDigits: 0 }).format(v).replace(/,/g, ' '); }
    catch (_) { return String(v).replace(/\B(?=(\d{3})+(?!\d))/g, ' '); }
};

function rowState(edges) {
    if (!edges || edges.length === 0) return 'row-red';
    let manual = false, yellow = false;
    for (const e of edges) {
        if (e.is_manual)                 manual = true;
        if (e.link_type === 'auto_yellow') yellow = true;
    }
    if (manual) return 'row-gray';
    return yellow ? 'row-yellow' : 'row-green';
}

function sepayRow(s, cls) {
    const content = s.content ?? '';
    const time    = s.time ?? '';
    const amount  = Number(s.amount) || 0;
    return `<tr id="pd3-sepay-${s.id}" class="pd3-row ${cls}"
        data-sepay-id="${s.id}"
        data-ts="${esc(s.transaction_date)}"
        data-sum="${amount}"
        data-content="${esc(String(content).toLowerCase())}">
        <td class="pd3-col pd3-col--hide">
            <button type="button" class="pd3-row-hide" data-sepay-id="${s.id}" title="Скрыть/восстановить">−</button>
        </td>
        <td class="pd3-col pd3-col--content">${esc(content)}</td>
        <td class="pd3-col pd3-col--time nowrap">${esc(time)}</td>
        <td class="pd3-col pd3-col--sum nowrap right">${esc(s.amount_fmt ?? fmt(amount))}</td>
        <td class="pd3-col pd3-col--cb">
            <input type="checkbox" class="pd3-cb pd3-cb--sepay" data-sepay-id="${s.id}" data-sum="${amount}">
        </td>
        <td class="pd3-col pd3-col--anchor"><span class="pd3-anchor" id="pd3-sepay-anchor-${s.id}"></span></td>
    </tr>`;
}

function posterRow(p, cls) {
    const total  = Number(p.total) || 0;
    const num    = p.receipt_number !== '' ? p.receipt_number : String(p.transaction_id);
    const method = p.payment_method ?? '—';
    const methodLite = p.payment_method_lite ?? method;
    return `<tr id="pd3-poster-${p.transaction_id}" class="pd3-row ${cls}"
        data-poster-id="${p.transaction_id}"
        data-num="${esc(num)}"
        data-ts="${esc(p.date_close)}"
        data-card="${Number(p.payed_card) || 0}"
        data-tips="${Number(p.tip_sum) || 0}"
        data-total="${total}"
        data-method="${esc(method)}"
        data-waiter="${esc(p.waiter_name ?? '')}"
        data-table="${p.table_id ?? 0}">
        <td class="pd3-col pd3-col--lead">
            <div class="pd3-lead">
                <span class="pd3-anchor" id="pd3-poster-anchor-${p.transaction_id}"></span>
                <input type="checkbox" class="pd3-cb pd3-cb--poster" data-poster-id="${p.transaction_id}" data-sum="${total}">
            </div>
        </td>
        <td class="pd3-col pd3-col--num    nowrap">${esc(num)}</td>
        <td class="pd3-col pd3-col--time   nowrap">${esc(p.time ?? '')}</td>
        <td class="pd3-col pd3-col--card   nowrap right">${esc(p.payed_card_fmt ?? fmt(p.payed_card))}</td>
        <td class="pd3-col pd3-col--tips   nowrap right">${esc(p.tip_sum_fmt ?? fmt(p.tip_sum))}</td>
        <td class="pd3-col pd3-col--total  nowrap right"><strong>${esc(p.total_fmt ?? fmt(total))}</strong></td>
        <td class="pd3-col pd3-col--method">
            <span class="pm-full">${esc(method)}</span>
            <span class="pm-lite" aria-hidden="true">${esc(methodLite)}</span>
        </td>
        <td class="pd3-col pd3-col--waiter">${esc(p.waiter_name ?? '')}</td>
        <td class="pd3-col pd3-col--table  nowrap">${p.table_id ? esc(p.table_id) : '—'}</td>
    </tr>`;
}

export function renderSepay(open, hidden, links) {
    const tbody = document.querySelector('#pd3SepayTable tbody');
    if (!tbody) return;
    const bySepay = new Map();
    for (const l of links) {
        if (!bySepay.has(l.sepay_id)) bySepay.set(l.sepay_id, []);
        bySepay.get(l.sepay_id).push(l);
    }
    if (open.length === 0 && hidden.length === 0) {
        tbody.innerHTML = '<tr class="pd3-empty"><td colspan="6">Нет банковских транзакций за период.</td></tr>';
        return;
    }
    const parts = [];
    for (const s of open)   parts.push(sepayRow(s, rowState(bySepay.get(s.id))));
    for (const s of hidden) parts.push(sepayRow(s, 'row-hidden is-hidden'));
    tbody.innerHTML = parts.join('');
}

export function renderPoster(rows, links) {
    const tbody = document.querySelector('#pd3PosterTable tbody');
    if (!tbody) return;
    const byPoster = new Map();
    for (const l of links) {
        if (!byPoster.has(l.poster_transaction_id)) byPoster.set(l.poster_transaction_id, []);
        byPoster.get(l.poster_transaction_id).push(l);
    }
    if (rows.length === 0) {
        tbody.innerHTML = '<tr class="pd3-empty"><td colspan="9">Нет чеков Poster за период.</td></tr>';
        return;
    }
    tbody.innerHTML = rows.map((p) => posterRow(p, rowState(byPoster.get(p.transaction_id)))).join('');
}

export function updateInFooters(sepayOpen, sepayHidden, posterRows) {
    const sepayTotal = sepayOpen.reduce((acc, s) => acc + (Number(s.amount) || 0), 0);
    const allSepay = sepayTotal + sepayHidden.reduce((acc, s) => acc + (Number(s.amount) || 0), 0);

    const $st = document.getElementById('pd3SepayTotal');
    if ($st) $st.textContent = fmt(sepayTotal);

    // Poster footer (Итого / Tips / связи / несвязи / BB / VC)
    // is recomputed from the live DOM so it stays consistent with
    // server-side render, JS-side re-render, and post-link-mutation
    // state without us having to thread row data through every call site.
    recomputePosterFooter();

    // Top totals card (Sepay / Poster / VC / Δ).
    //
    // Poster value mirrors the pane-footer "Итого" — non-Vietnam checks
    // including tips. VC is the Vietnam Company bucket (cash collected
    // by the company, NOT routed through the bank). Sepay is all bank
    // deposits, so the reconciliation identity is:
    //     Sepay = Poster + VC  →  Δ = Sepay − Poster − VC
    // Δ is zero when the day reconciles perfectly.
    const posterTotal  = readPosterFooterValue('pd3PosterTotal');
    const vietnamTotal = readPosterFooterValue('pd3PosterVietnam');
    const totals = document.querySelector('.pd3-totals');
    if (totals) {
        const cells = totals.querySelectorAll('strong');
        if (cells.length >= 4) {
            cells[0].textContent = fmt(allSepay);
            cells[1].textContent = fmt(posterTotal);
            cells[2].textContent = fmt(vietnamTotal);
            const diff = allSepay - posterTotal - vietnamTotal;
            cells[3].textContent = fmt(diff);
            const diffWrap = cells[3].parentElement;
            if (diffWrap) {
                diffWrap.classList.remove('ok', 'warn', 'danger');
                diffWrap.classList.add(diff === 0 ? 'ok' : (diff < 0 ? 'danger' : 'warn'));
            }
        }
    }
}

/**
 * Walks every visible Poster row and bucket-sums into the six
 * spans in the pane footer. Reads everything from data-* attributes
 * + row state classes so it works after server-side render,
 * after a JS re-render, and after a link/unlink mutation.
 *
 *   Итого   sum(card+third+tip)   EXCLUDING Vietnam Company
 *   Tips    sum(tip)               on LINKED non-Vietnam rows
 *   связи   sum(card+third+tip)   on LINKED non-Vietnam rows
 *   несвязи sum(card+third+tip)   on UNLINKED non-Vietnam rows
 *   BB      sum(card+third+tip)   for Bybit method
 *   VC      sum(card+third+tip)   for Vietnam Company method
 *
 * "Linked" = any of row-green / row-yellow / row-gray (auto / yellow
 * / manual). row-red is unlinked. row-hidden rows are skipped.
 *
 * `data-total` already encodes card+third+tip (server- and JS-render
 * both mirror payday2's "Card+Tips" column convention), so we read it
 * directly instead of summing data-total+data-tips.
 */
export function recomputePosterFooter() {
    const rows = document.querySelectorAll('#pd3PosterTable tr.pd3-row');
    let total = 0, bb = 0, vc = 0, linked = 0, unlinked = 0, tipsLinked = 0;
    for (const tr of rows) {
        const sum  = Number(tr.dataset.total) || 0;
        const tip  = Number(tr.dataset.tips) || 0;
        const pm   = String(tr.dataset.method || '').toLowerCase();

        // BB / VC are visibility-independent — they're "this is what
        // Poster reported for the period". Toggling the 👁 hides
        // Vietnam rows from the operator's view but doesn't change
        // the underlying number.
        if (pm.startsWith('vietnam')) { vc += sum; continue; }   // VC excluded from Итого
        if (pm.startsWith('bybit'))   { bb += sum; }

        // Итого / Tips / связи / несвязи respect the visibility
        // toggles (hidden rows aren't part of the live total).
        const hidden = tr.classList.contains('row-hidden') || tr.classList.contains('is-hidden');
        if (hidden) continue;

        total += sum;
        const isLinked = tr.classList.contains('row-green')
            || tr.classList.contains('row-yellow')
            || tr.classList.contains('row-gray');
        if (isLinked) { linked += sum; tipsLinked += tip; }
        else          { unlinked += sum; }
    }
    const set = (id, v) => { const el = document.getElementById(id); if (el) el.textContent = fmt(v); };
    set('pd3PosterTotal',       total);
    set('pd3PosterTipsLinked',  tipsLinked);
    set('pd3PosterLinked',      linked);
    set('pd3PosterUnlinked',    unlinked);
    set('pd3PosterBybit',       bb);
    set('pd3PosterVietnam',     vc);
}

// Read a numeric span from the Poster footer (used by the top totals
// card so it doesn't have to duplicate the bucket logic).
function readPosterFooterValue(id) {
    const el = document.getElementById(id);
    if (!el) return 0;
    const cleaned = String(el.textContent || '').replace(/[^\d\-]/g, '');
    return Number(cleaned) || 0;
}
