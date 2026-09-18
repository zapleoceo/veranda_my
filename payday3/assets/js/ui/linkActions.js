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

// Cache-bust cross-module imports — see comment in out/bootstrap.js.
const _v = new URL(import.meta.url).searchParams.get('v') || '';
const _qs = _v ? '?v=' + encodeURIComponent(_v) : '';
const { api } = await import(new URL('../api.js' + _qs, import.meta.url).href);

function dateQuery(range) {
    const p = new URLSearchParams();
    if (range?.from) p.set('dateFrom', range.from);
    if (range?.to)   p.set('dateTo',   range.to);
    return p.toString();
}

/**
 * @param {{state:object, renderer:object|null, onChanged?:(links:array)=>void}} deps
 *   onChanged — called after every applied mutation (row repaint,
 *   selection reset) so this adapter stays unaware of those modules.
 */
export function createInLinks({ state, renderer, onChanged }) {
    const qs = () => dateQuery(state.get('range') || {});

    const apply = (result) => {
        const links = Array.isArray(result?.links) ? result.links : [];
        state.set('links', links);
        renderer?.setLinks(links);
        onChanged?.(links);
        return result;
    };

    return {
        autoLink:   async () => apply(await api.post('/payday3/api/links/auto?' + qs())),
        manualLink: async (sepayIds, posterIds) =>
            apply(await api.post('/payday3/api/links/manual?' + qs(), { sepayIds, posterIds })),
        clearLinks: async () => apply(await api.post('/payday3/api/links/clear?' + qs())),
        /** Per-link unlink (LineRenderer × button). */
        onUnlink: async (link) => {
            const sid = Number(link?.sepay_id);
            const pid = Number(link?.poster_transaction_id);
            if (!sid || !pid) return;
            try {
                apply(await api.delete(`/payday3/api/links/${sid}/${pid}?${qs()}`));
            } catch (e) {
                console.error('[payday3]', e);
                alert(e.message);
            }
        },
    };
}
