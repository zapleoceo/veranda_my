// Incoming bank row (SePay, upper block of «Деньги») ↔ Poster finance
// INCOME (lower right table) — money that reached the bank without a
// sales check, e.g. «компенсация от игровой за соц. страхование».
// Table sepay_finance_links, /payday3/api/income-links/*.
//
// Adapter with the same shape as the other two sides
//   { autoLink, manualLink(sepayIds, financeIds), clearLinks }
// plus its own LineRenderer on #pd3IncomeLineLayer. The fresh link set
// goes to `state` ('incomeLinks'); onChanged() repaints the page.

'use strict';

// Cache-bust cross-module imports — see comment in out/bootstrap.js.
const _v = new URL(import.meta.url).searchParams.get('v') || '';
const _qs = _v ? '?v=' + encodeURIComponent(_v) : '';
const { api }          = await import(new URL('../api.js'       + _qs, import.meta.url).href);
const { LineRenderer } = await import(new URL('./lineRenderer.js' + _qs, import.meta.url).href);
const { BANK_SCROLL, BANK_TABLE } = await import(new URL('./bankTable.js' + _qs, import.meta.url).href);

function rangeQs(state) {
    const r = state.get('range') || {};
    const p = new URLSearchParams();
    if (r.from) p.set('dateFrom', r.from);
    if (r.to)   p.set('dateTo',   r.to);
    return p.toString();
}

/**
 * @param {{state:object, onChanged?:()=>void}} deps
 * @returns {{reload:Function, autoLink:Function, manualLink:Function, clearLinks:Function}|null}
 */
export function initIncomeLinks({ state, onChanged }) {
    const grid = document.querySelector('#pd3GraphRoot .pd3-graph__grid');
    if (!grid) return null;
    const base = '/payday3/api/income-links';

    const apply = (result) => {
        const links = Array.isArray(result?.links) ? result.links : [];
        state.set('incomeLinks', links);
        renderer.setLinks(links);
        onChanged?.();
        return result;
    };

    const renderer = new LineRenderer({
        container:          grid,
        layer:              document.getElementById('pd3IncomeLineLayer'),
        leftScroll:         document.querySelector(BANK_SCROLL),
        rightScroll:        document.getElementById('pd3OutFinanceScroll'),
        // Whole bank table / right column: rows above move these anchors too.
        leftTbody:          document.querySelector(BANK_TABLE),
        rightTbody:         document.getElementById('pd3RightColumn'),
        horizontalScroller: document.getElementById('pd3GraphRoot'),
        leftAnchorId:       (l) => 'pd3-sepay-anchor-'       + l.sepay_id,
        rightAnchorId:      (l) => 'pd3-out-finance-anchor-' + l.finance_id,
        linkKey:            (l) => 'income:' + l.sepay_id + ':' + l.finance_id,
        onUnlink: async (link) => {
            try {
                apply(await api.delete(`${base}/${Number(link.sepay_id)}/${Number(link.finance_id)}?${rangeQs(state)}`));
            } catch (e) { console.error('[payday3-income]', e); alert(e.message); }
        },
    });

    const load = async () => {
        try { apply(await api.get(`${base}?${rangeQs(state)}`)); }
        catch (e) { console.error('[payday3-income]', e); }
    };
    load();

    return {
        reload:     load,
        autoLink:   async () => apply(await api.post(`${base}/auto?${rangeQs(state)}`)),
        manualLink: async (sepayIds, financeIds) =>
            apply(await api.post(`${base}/manual?${rangeQs(state)}`, { sepayIds, financeIds })),
        clearLinks: async () => apply(await api.post(`${base}/clear?${rangeQs(state)}`)),
    };
}
