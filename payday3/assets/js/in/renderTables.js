// Renders the sepay + poster IN-mode tbodies from the snapshot returned
// by GET /payday3/api/data. Mirrors out/renderTables.js: pure functions,
// pass rows + links in, no fetching. Keeps the markup byte-for-byte
// equivalent to the server-side partial so all existing selectors (eye
// toggles, sort, selection, line renderer) keep working.

'use strict';

import { createTxButtonHtml, TX_TYPE } from '../ui/rowCreateTx.js';
import { BANK_COLUMNS, SEPAY_TBODY }   from '../ui/bankTable.js';
import { classify as rowState }          from '../ui/rowStates.js';
import { esc, fmtVnd as fmt }            from '../ui/format.js';
import { isVietnam, isBybit, methodOfRow } from '../ui/paymentMethods.js';
import { topTotals, diffClass }        from './totals.js';

/** Cells per Poster check row — see posterRow() / poster_table.php. */
export const POSTER_COLUMNS = 9;

function sepayRow(s, cls) {
    const content = s.content ?? '';
    const time    = s.time ?? '';
    const amount  = Number(s.amount) || 0;
    const id      = esc(s.id);
    return `<tr id="pd3-sepay-${id}" class="pd3-row ${cls}"
        data-sepay-id="${id}"
        data-ts="${esc(s.transaction_date)}"
        data-sum="${amount}"
        data-content="${esc(String(content).toLowerCase())}">
        <td class="pd3-col pd3-col--hide">
            <button type="button" class="pd3-row-hide" data-sepay-id="${id}" title="Скрыть/восстановить">−</button>
        </td>
        <td class="pd3-col pd3-col--content">${esc(content)}</td>
        <td class="pd3-col pd3-col--time nowrap">${esc(time)}</td>
        <td class="pd3-col pd3-col--sum nowrap right">${esc(s.amount_fmt ?? fmt(amount))}</td>
        <td class="pd3-col pd3-col--create">${createTxButtonHtml({ amount, date: s.transaction_date, type: TX_TYPE.INCOME })}</td>
        <td class="pd3-col pd3-col--cb">
            <input type="checkbox" class="pd3-cb pd3-cb--sepay" data-sepay-id="${id}" data-sum="${amount}">
        </td>
        <td class="pd3-col pd3-col--anchor"><span class="pd3-anchor" id="pd3-sepay-anchor-${id}"></span></td>
    </tr>`;
}

function posterRow(p, cls) {
    const total  = Number(p.total) || 0;
    const num    = p.receipt_number !== '' ? p.receipt_number : String(p.transaction_id);
    const method = p.payment_method ?? '—';
    const methodLite = p.payment_method_lite ?? method;
    const methodId = p.payment_method_id ?? p.poster_payment_method_id ?? '';
    const id     = esc(p.transaction_id);
    return `<tr id="pd3-poster-${id}" class="pd3-row ${cls}"
        data-poster-id="${id}"
        data-num="${esc(num)}"
        data-ts="${esc(p.date_close)}"
        data-card="${Number(p.payed_card) || 0}"
        data-tips="${Number(p.tip_sum) || 0}"
        data-total="${total}"
        data-method="${esc(method)}"
        data-method-id="${esc(methodId)}"
        data-waiter="${esc(p.waiter_name ?? '')}"
        data-table="${esc(p.table_id ?? 0)}">
        <td class="pd3-col pd3-col--lead">
            <div class="pd3-lead">
                <span class="pd3-anchor" id="pd3-poster-anchor-${id}"></span>
                <input type="checkbox" class="pd3-cb pd3-cb--poster" data-poster-id="${id}" data-sum="${total}">
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
    const tbody = document.querySelector(SEPAY_TBODY);
    if (!tbody) return;
    const bySepay = new Map();
    for (const l of links) {
        if (!bySepay.has(l.sepay_id)) bySepay.set(l.sepay_id, []);
        bySepay.get(l.sepay_id).push(l);
    }
    if (open.length === 0 && hidden.length === 0) {
        tbody.innerHTML = `<tr class="pd3-empty"><td colspan="${BANK_COLUMNS}">Нет поступлений за период.</td></tr>`;
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
        tbody.innerHTML = `<tr class="pd3-empty"><td colspan="${POSTER_COLUMNS}">Нет чеков Poster за период.</td></tr>`;
        return;
    }
    tbody.innerHTML = rows.map((p) => posterRow(p, rowState(byPoster.get(p.transaction_id)))).join('');
}

/**
 * Sepay footer sum + the top totals card, from row data (see totals.js).
 * The Poster pane footer depends on live row classes (linked / hidden),
 * so the page repaint recomputes it once via recomputePosterFooter().
 */
export function updateInFooters(sepayOpen, sepayHidden, posterRows) {
    const t = topTotals({ sepayOpen, sepayHidden, poster: posterRows });

    const $st = document.getElementById('pd3SepayTotal');
    if ($st) $st.textContent = fmt(sepayOpen.reduce((acc, s) => acc + (Number(s.amount) || 0), 0));

    const totals = document.querySelector('.pd3-totals');
    const cells = totals ? totals.querySelectorAll('strong') : [];
    if (cells.length >= 4) {
        cells[0].textContent = fmt(t.sepay);
        cells[1].textContent = fmt(t.poster);
        cells[2].textContent = fmt(t.vietnam);
        cells[3].textContent = fmt(t.diff);
        const diffWrap = cells[3].parentElement;
        if (diffWrap) {
            diffWrap.classList.remove('ok', 'warn', 'danger');
            diffWrap.classList.add(diffClass(t.diff));
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
 *
 * VC / BB are bucketed by payment-method id (data-method-id), the
 * server's rule — see paymentMethods.js.
 */
export function recomputePosterFooter() {
    const rows = document.querySelectorAll('#pd3PosterTable tr.pd3-row');
    let total = 0, bb = 0, vc = 0, linked = 0, unlinked = 0, tipsLinked = 0;
    for (const tr of rows) {
        const sum  = Number(tr.dataset.total) || 0;
        const tip  = Number(tr.dataset.tips) || 0;
        const method = methodOfRow(tr);

        // BB / VC are visibility-independent — they're "this is what
        // Poster reported for the period". Toggling the 👁 hides
        // Vietnam rows from the operator's view but doesn't change
        // the underlying number.
        if (isVietnam(method)) { vc += sum; continue; }   // VC excluded from Итого
        if (isBybit(method))   { bb += sum; }

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
