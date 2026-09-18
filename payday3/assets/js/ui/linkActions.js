// Incoming-side links: SePay rows ↔ Poster checks (table
// check_payment_links, /payday3/api/links/*).
//
// Adapter only — no buttons are bound here. The page-wide link panel
// (ui/linkPanel.js) drives both this and the outgoing adapter
// (out/bootstrap.js) from one set of mid-column buttons. After every
// successful call the row classes are recomputed from the fresh link
// set and the LineRenderer redraws.

'use strict';

// Cache-bust cross-module imports — see comment in out/bootstrap.js.
const _v = new URL(import.meta.url).searchParams.get('v') || '';
const _qs = _v ? '?v=' + encodeURIComponent(_v) : '';
const { api }           = await import(new URL('../api.js'       + _qs, import.meta.url).href);
const { refreshStats }  = await import(new URL('./stats.js'      + _qs, import.meta.url).href);
const { SEPAY_TBODY }   = await import(new URL('./bankTable.js'  + _qs, import.meta.url).href);

function dateQuery(range) {
    const p = new URLSearchParams();
    if (range?.from) p.set('dateFrom', range.from);
    if (range?.to)   p.set('dateTo',   range.to);
    return p.toString();
}

/** Mirrors src/Payday3/Domain/RowState::classify on the client. */
function classify(edges) {
    if (!edges || edges.length === 0) return 'row-red';
    let manual = false, yellow = false;
    for (const e of edges) {
        if (e.is_manual) manual = true;
        if (e.link_type === 'auto_yellow') yellow = true;
    }
    if (manual) return 'row-gray';
    return yellow ? 'row-yellow' : 'row-green';
}

/** Recompute row CSS classes from the fresh link list. */
function reclassifyRows(links) {
    const bySepay  = new Map();
    const byPoster = new Map();
    for (const l of links) {
        if (!bySepay.has(l.sepay_id))                 bySepay.set(l.sepay_id, []);
        if (!byPoster.has(l.poster_transaction_id))   byPoster.set(l.poster_transaction_id, []);
        bySepay.get(l.sepay_id).push(l);
        byPoster.get(l.poster_transaction_id).push(l);
    }
    const STATES = ['row-red', 'row-green', 'row-yellow', 'row-gray'];
    document.querySelectorAll(`${SEPAY_TBODY} tr.pd3-row`).forEach((tr) => {
        if (tr.classList.contains('row-hidden')) return;  // hidden rows keep their class
        tr.classList.remove(...STATES);
        tr.classList.add(classify(bySepay.get(Number(tr.dataset.sepayId))));
    });
    document.querySelectorAll('#pd3PosterTable tr.pd3-row').forEach((tr) => {
        tr.classList.remove(...STATES);
        tr.classList.add(classify(byPoster.get(Number(tr.dataset.posterId))));
    });
}

/**
 * @param {{state:object, renderer:object|null, onChanged?:(links:array)=>void}} deps
 *   onChanged — called after every applied mutation (selection reset,
 *   eye-toggle re-apply) so this adapter stays unaware of those modules.
 */
export function createInLinks({ state, renderer, onChanged }) {
    const qs = () => dateQuery(state.get('range') || {});

    const apply = (result) => {
        const links = Array.isArray(result?.links) ? result.links : [];
        state.set('links', links);
        reclassifyRows(links);
        refreshStats();
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
