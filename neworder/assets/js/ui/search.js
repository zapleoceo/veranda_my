// Search wires the input to state.search; the menu module already
// subscribes to state changes and re-renders with the filter applied.

'use strict';

export function initSearch({ state }) {
    const $in = document.getElementById('noSearchInput');
    const $clear = document.getElementById('noSearchClear');
    if (!$in) return;

    const update = () => {
        const v = $in.value.trim();
        if ($clear) $clear.hidden = $in.value === '';
        state.setSearch(v);
    };
    $in.addEventListener('input', update);
    $in.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') { $in.value = ''; update(); }
    });
    $clear?.addEventListener('click', () => { $in.value = ''; update(); $in.focus(); });
}
