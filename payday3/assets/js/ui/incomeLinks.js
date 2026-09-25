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

import { api }          from '../api.js';
import { LineRenderer } from './lineRenderer.js';
import { BANK_SCROLL, BANK_TABLE } from './bankTable.js';
import { withRange }    from './format.js';
import { notify }       from './notify.js';

/**
 * @param {{state:object, onChanged?:()=>void}} deps
 * @returns {{reload:Function, autoLink:Function, manualLink:Function, clearLinks:Function}|null}
 */
export function initIncomeLinks({ state, onChanged }) {
    const grid = document.querySelector('#pd3GraphRoot .pd3-graph__grid');
    if (!grid) return null;
    const base = '/payday3/api/income-links';
    const url  = (path) => withRange(base + path, state.get('range'));

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
                apply(await api.delete(url(`/${Number(link.sepay_id)}/${Number(link.finance_id)}`)));
            } catch (e) { console.error('[payday3-income]', e); alert(e.message); }
        },
    });

    const load = async () => {
        try { apply(await api.get(url(''))); }
        catch (e) {
            console.error('[payday3-income]', e);
            notify('Связи «приход ↔ транзакция» не загрузились: ' + (e.message || 'ошибка'), 'warn');
        }
    };
    load();

    return {
        reload:     load,
        autoLink:   async () => apply(await api.post(url('/auto'))),
        manualLink: async (sepayIds, financeIds) =>
            apply(await api.post(url('/manual'), { sepayIds, financeIds })),
        clearLinks: async () => apply(await api.post(url('/clear'))),
    };
}
