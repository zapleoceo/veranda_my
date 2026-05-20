// Recompute pane footers (Sepay link counts + the full Poster
// summary) from the DOM after any render or link mutation.

'use strict';

// Cache-bust cross-module imports — see comment in out/bootstrap.js.
const _v = new URL(import.meta.url).searchParams.get('v') || '';
const _qs = _v ? '?v=' + encodeURIComponent(_v) : '';
const { recomputePosterFooter } = await import(new URL('../in/renderTables.js' + _qs, import.meta.url).href);

export function refreshStats() {
    // Sepay footer — linked / unlinked counts. Sum is server-rendered
    // in the partial; we just refresh the link counters.
    const rows = document.querySelectorAll('#pd3SepayTable tr.pd3-row');
    let linked = 0;
    let unlinked = 0;
    rows.forEach((r) => {
        if (r.classList.contains('row-hidden')) return;
        if (r.classList.contains('row-red')) unlinked++;
        else linked++;
    });
    const $linked   = document.getElementById('pd3SepayLinked');
    const $unlinked = document.getElementById('pd3SepayUnlinked');
    if ($linked)   $linked.textContent   = String(linked);
    if ($unlinked) $unlinked.textContent = String(unlinked);

    // Poster footer — Итого / Tips / связи / несвязи / BB / VC. All
    // bucket sums are walked off the DOM so this works after the
    // server-rendered first paint AND after every later
    // re-render/mutation.
    recomputePosterFooter();
}
