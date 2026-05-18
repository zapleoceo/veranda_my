// IN-mode AJAX refresh. Fetches the full sepay+poster+links snapshot
// from /payday3/api/data and re-renders the tables, footers, and the
// link layer — no `window.location.reload()` after sync/clearDay.
//
// The returned `loadInData(opts)` function is what dataActions.js calls
// at the end of every mutation.

'use strict';

// Cache-bust cross-module imports — see comment in out/bootstrap.js.
const _v = new URL(import.meta.url).searchParams.get('v') || '';
const _qs = _v ? '?v=' + encodeURIComponent(_v) : '';
const { api }              = await import(new URL('../api.js'        + _qs, import.meta.url).href);
const { renderSepay,
        renderPoster,
        updateInFooters }  = await import(new URL('./renderTables.js' + _qs, import.meta.url).href);
const { refreshStats }     = await import(new URL('../ui/stats.js'   + _qs, import.meta.url).href);

function dateQuery(range) {
    const p = new URLSearchParams();
    if (range?.from) p.set('dateFrom', range.from);
    if (range?.to)   p.set('dateTo',   range.to);
    return p.toString();
}

let inFlight = null;

export function makeInLoader({ state, renderer }) {
    return async function loadInData() {
        // Coalesce overlapping calls — sync + clearDay can both fire.
        if (inFlight) return inFlight;
        const promise = (async () => {
            const qs = dateQuery(state.get('range') || {});
            const data = await api.get('/payday3/api/data' + (qs ? '?' + qs : ''));
            if (!data) return;
            const sepayOpen   = data.sepay        || [];
            const sepayHidden = data.sepayHidden  || [];
            const poster      = data.poster       || [];
            const links       = data.links        || [];

            renderSepay(sepayOpen, sepayHidden, links);
            renderPoster(poster, links);
            updateInFooters(sepayOpen, sepayHidden, poster);

            state.set('links', links);
            if (renderer) {
                renderer.setLinks(links);
            }
            refreshStats();
        })();
        inFlight = promise.finally(() => { inFlight = null; });
        return inFlight;
    };
}
