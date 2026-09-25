// Incoming-side links: SePay rows ↔ Poster checks (table
// check_payment_links, /payday3/api/links/*).
//
// Adapter only — no buttons are bound here. The page-wide link panel
// (ui/linkPanel.js) drives this, the income↔finance adapter
// (ui/incomeLinks.js) and the outgoing adapter (out/bootstrap.js) from
// one set of mid-column buttons. After every mutation the fresh link set
// goes to `state` ('links') and onChanged() lets the page repaint every
// table from ALL link kinds (ui/rowStates.js) and redraw the connectors.

'use strict';

import { api }       from '../api.js';
import { withRange } from './format.js';

/**
 * @param {{state:object, renderer:object|null, onChanged?:(links:array)=>void}} deps
 *   onChanged — called after every applied mutation (row repaint,
 *   selection reset) so this adapter stays unaware of those modules.
 */
export function createInLinks({ state, renderer, onChanged }) {
    const url = (path) => withRange(path, state.get('range'));

    const apply = (result) => {
        const links = Array.isArray(result?.links) ? result.links : [];
        state.set('links', links);
        renderer?.setLinks(links);
        onChanged?.(links);
        return result;
    };

    return {
        autoLink:   async () => apply(await api.post(url('/payday3/api/links/auto'))),
        manualLink: async (sepayIds, posterIds) =>
            apply(await api.post(url('/payday3/api/links/manual'), { sepayIds, posterIds })),
        clearLinks: async () => apply(await api.post(url('/payday3/api/links/clear'))),
        /** Per-link unlink (LineRenderer × button). */
        onUnlink: async (link) => {
            const sid = Number(link?.sepay_id);
            const pid = Number(link?.poster_transaction_id);
            if (!sid || !pid) return;
            try {
                apply(await api.delete(url(`/payday3/api/links/${sid}/${pid}`)));
            } catch (e) {
                console.error('[payday3]', e);
                alert(e.message);
            }
        },
    };
}
