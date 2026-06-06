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

/**
 * Wires the SePay per-row hide/restore button (`.pd3-row-hide` in
 * #pd3SepayTable). Delegated on document.body so it works for both
 * server-rendered and JS-rendered rows. After every mutation we call
 * `reload()` so the row drops from the "open" list (or reappears under
 * the eye-toggle) without a full page reload.
 *
 * Restore path: rows decorated with `.row-hidden` (via listHiddenInRange
 * + eye-toggle) call the same endpoint with hidden=false, which deletes
 * the sepay_hidden row.
 */
export function initSepayHide({ reload }) {
    document.body.addEventListener('click', async (e) => {
        const btn = e.target.closest?.('#pd3SepayTable .pd3-row-hide');
        if (!btn) return;
        const id = Number(btn.dataset.sepayId);
        if (!id) return;
        const tr = btn.closest('tr');
        const isRestore = tr?.classList.contains('row-hidden') === true;
        // Pre-disable to swallow double-clicks during the round-trip.
        btn.disabled = true;
        try {
            await api.post('/payday3/api/sepay/hide', {
                sepayId: id,
                hidden:  !isRestore,
            });
            if (typeof reload === 'function') await reload();
        } catch (err) {
            alert(err.message || 'Не удалось скрыть/восстановить.');
            btn.disabled = false;
        }
    });
}
