// IN-mode AJAX refresh. Fetches the full sepay+poster+links snapshot
// from /payday3/api/data and re-renders the tables, footers, and the
// link layer — no `window.location.reload()` after sync/clearDay.
//
// The returned `loadInData(opts)` function is what dataActions.js calls
// at the end of every mutation.

'use strict';

const _i = (await import(new URL('../ui/cacheBust.js' + new URL(import.meta.url).search, import.meta.url).href)).importer(import.meta.url);
const { api }              = await _i('../api.js');
const { renderSepay,
        renderPoster,
        updateInFooters }  = await _i('./renderTables.js');
const { SEPAY_TBODY }      = await _i('../ui/bankTable.js');
const { withRange }        = await _i('../ui/format.js');
const { coalesce }         = await _i('../ui/coalesce.js');

/**
 * @param {{state:object, renderer:object|null, onRendered?:()=>void}} deps
 *   onRendered — after the incoming rows and checks are re-rendered
 *   (selection reset, row colours, footers, eye toggles) — owned by index.js.
 * @returns {() => Promise<void>}  latest-wins: a call made while a load is
 *   in flight gets a fresh load that starts after it (SePay ↻ + Poster ↻
 *   fired together must both see their own writes).
 */
export function makeInLoader({ state, renderer, onRendered }) {
    return coalesce(async () => {
        const data = await api.get(withRange('/payday3/api/data', state.get('range')));
        if (!data) return;
        const sepayOpen   = data.sepay        || [];
        const sepayHidden = data.sepayHidden  || [];
        const poster      = data.poster       || [];
        const links       = data.links        || [];

        renderSepay(sepayOpen, sepayHidden, links);
        renderPoster(poster, links);
        updateInFooters(sepayOpen, sepayHidden, poster);

        state.set('links', links);
        renderer?.setLinks(links);
        onRendered?.();
    });
}

/**
 * Wires the SePay per-row hide/restore button (`.pd3-row-hide` in the
 * incoming block of «Деньги»). Delegated on document.body so it works for both
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
        const btn = e.target.closest?.(`${SEPAY_TBODY} .pd3-row-hide`);
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
