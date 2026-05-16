// Compute "linked / unlinked" counters on the sepay pane footer from
// the row classes we already rendered server-side.
// Runs once on boot; Phase 3 will re-run it after any link mutation.

'use strict';

export function refreshStats() {
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
}
