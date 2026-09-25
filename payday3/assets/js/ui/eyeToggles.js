// Eye toggles (👁).
//
//   #pd3SepayHiddenToggle  — show/hide hidden incoming rows (CSS class `is-hidden` on .row-hidden).
//                            Its aria-pressed is the ONE source of truth for «show hidden»:
//                            out/bootstrap.js reads it (showHiddenFrom) to refetch hidden mail.
//   #pd3HideLinkedBtn      — show/hide already-linked rows in every table (.row-green/.row-yellow/.row-gray)
//   #pd3VietnamToggle      — show/hide Vietnam Company poster rows (by payment-method id)
//
// Each toggle is a self-contained controller; they don't share state.
// initEyeToggles() returns reapply(): tables re-rendered from scratch
// lose the `is-hidden` flags, so the owner re-applies the current
// toggle states. reapply() does NOT recompute the Poster footer — the
// page repaint does that once, after every toggle is applied.

'use strict';

import { recomputePosterFooter }  from '../in/renderTables.js';
import { SEPAY_TBODY }            from './bankTable.js';
import { isVietnam, methodOfRow } from './paymentMethods.js';

/** «Show hidden rows» is on when the eye is NOT pressed (pressed = hide). */
export const showHiddenFrom = (btn) => btn?.getAttribute('aria-pressed') === 'false';

function makeToggle(btnId, getRows, initialPressed = false) {
    const btn = document.getElementById(btnId);
    if (!btn) return null;
    let pressed = initialPressed;
    const apply = () => {
        btn.setAttribute('aria-pressed', pressed ? 'true' : 'false');
        // toggle(…, force) is a no-op for rows already in that state —
        // no attribute mutation, no renderer wake-up.
        getRows().forEach((row) => row.classList.toggle('is-hidden', pressed));
    };
    apply();
    btn.addEventListener('click', () => {
        pressed = !pressed;
        apply();
        // Hiding rows changes Итого / связи / несвязи — refresh.
        recomputePosterFooter();
    });
    return apply;
}

export function initEyeToggles() {
    const appliers = [
        // Hidden incoming rows — start hidden (matches payday2 default).
        makeToggle(
            'pd3SepayHiddenToggle',
            () => document.querySelectorAll(`${SEPAY_TBODY} tr.row-hidden`),
            true,
        ),
        // Already-linked rows in every table — start visible.
        makeToggle(
            'pd3HideLinkedBtn',
            () => document.querySelectorAll(
                '.pd3-row.row-green, .pd3-row.row-yellow, .pd3-row.row-gray'
            ),
            false,
        ),
        // Vietnam Company poster rows — start hidden (matches payday2).
        makeToggle(
            'pd3VietnamToggle',
            () => Array.from(document.querySelectorAll('#pd3PosterTable tr.pd3-row'))
                .filter((r) => isVietnam(methodOfRow(r))),
            true,
        ),
    ].filter(Boolean);

    recomputePosterFooter();
    return { reapply: () => appliers.forEach((apply) => apply()) };
}
