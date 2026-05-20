// Font-scale toolbar widget.
//
// Three buttons («а» / «А» / «А») cycle the page's `zoom` factor. We
// use the CSS `zoom` property (not transform:scale) so the browser
// reflows the entire layout — typography, paddings, modal sizes,
// border radii, the SVG link overlay — all scale uniformly. Every
// layout-affecting value in payday3.css already goes through the
// --pd3-* design tokens, but `zoom` is even cheaper: one knob,
// pixel-correct positioning, no token churn.
//
// The chosen factor is persisted in localStorage and re-applied on
// page load (in lockstep with .pd3-page[data-scale] and body class
// modifiers so #pd3ModalHost — which lives outside .pd3-page —
// scales too).

'use strict';

const KEY    = 'pd3.fontScale';
const VALID  = new Set(['1', '1.2', '1.5']);
const CLASS_PREFIX = 'pd3-scale-';

function readSavedScale() {
    try {
        const v = localStorage.getItem(KEY);
        return VALID.has(v) ? v : '1';
    } catch (_) { return '1'; }
}

function persistScale(scale) {
    try { localStorage.setItem(KEY, scale); } catch (_) { /* private mode etc. — ignore */ }
}

/** Apply the scale via a class on <html>. The matching CSS rule
 *  zooms both .pd3-page and #pd3ModalHost (which lives outside
 *  .pd3-page). The inline script in content.php uses the same
 *  convention so the persisted choice is honoured before paint. */
function applyScale(scale) {
    const html = document.documentElement;
    for (const cls of [...html.classList]) {
        if (cls.startsWith(CLASS_PREFIX)) html.classList.remove(cls);
    }
    if (scale !== '1') {
        html.classList.add(CLASS_PREFIX + scale.replace('.', '-'));
    }
}

function paintActive(scale) {
    document.querySelectorAll('.pd3-fontscale__btn').forEach((btn) => {
        btn.classList.toggle('is-active', btn.dataset.scale === scale);
    });
}

export function initFontScale({ renderer } = {}) {
    // Apply persisted choice on init.
    const initial = readSavedScale();
    applyScale(initial);
    paintActive(initial);

    document.querySelectorAll('.pd3-fontscale__btn').forEach((btn) => {
        btn.addEventListener('click', () => {
            const next = btn.dataset.scale || '1';
            if (!VALID.has(next)) return;
            applyScale(next);
            paintActive(next);
            persistScale(next);
            // The SVG line overlay is anchored to row positions via
            // getBoundingClientRect(); under CSS `zoom` those rects
            // ARE returned in scaled coords (modern browsers), so a
            // bare redraw after the layout settles is enough.
            if (renderer && typeof renderer.redraw === 'function') {
                requestAnimationFrame(() => renderer.redraw());
            }
        });
    });
}
