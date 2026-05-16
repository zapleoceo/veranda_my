// Eye toggles (👁).
//
//   #pd3SepayHiddenToggle  — show/hide hidden sepay rows (CSS class `is-hidden` on .row-hidden)
//   #pd3HideLinkedBtn      — show/hide already-linked rows (.row-green/.row-yellow/.row-gray)
//   #pd3VietnamToggle      — show/hide poster rows where payment method begins with "Vietnam"
//
// Each toggle is a self-contained controller; they don't share state.

'use strict';

function makeToggle(btnId, getRows, initialPressed = false) {
    const btn = document.getElementById(btnId);
    if (!btn) return;
    let pressed = initialPressed;
    const apply = () => {
        btn.setAttribute('aria-pressed', pressed ? 'true' : 'false');
        getRows().forEach((row) => row.classList.toggle('is-hidden', pressed));
    };
    apply();
    btn.addEventListener('click', () => { pressed = !pressed; apply(); });
}

export function initEyeToggles() {
    // Hidden sepay rows — start hidden (matches payday2 default).
    makeToggle(
        'pd3SepayHiddenToggle',
        () => document.querySelectorAll('#pd3SepayTable tr.row-hidden'),
        true,
    );

    // Already-linked rows — start visible. When pressed, hide linked.
    makeToggle(
        'pd3HideLinkedBtn',
        () => document.querySelectorAll(
            '.pd3-row.row-green, .pd3-row.row-yellow, .pd3-row.row-gray'
        ),
        false,
    );

    // Vietnam Company poster rows — start hidden (matches payday2).
    makeToggle(
        'pd3VietnamToggle',
        () => Array.from(document.querySelectorAll('#pd3PosterTable tr.pd3-row'))
            .filter((r) => (r.dataset.method || '').toLowerCase().startsWith('vietnam')),
        true,
    );
}
