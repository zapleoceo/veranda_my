// ❓ button toggles `body.pd3-help-mode`. CSS draws a dashed outline
// around every `[data-help-abs]` element; this module also renders
// a single floating tooltip via `position: fixed` so the tip never
// gets clipped by an ancestor with `overflow: hidden` (the IN/OUT
// graph card is the worst offender).
//
// The tooltip element lives at the root of <body>, follows the
// hovered target on mouseenter, and flips above/below depending on
// where it fits in the viewport.

'use strict';

const TIP_GAP = 6;

function ensureTip() {
    let tip = document.getElementById('pd3HelpTip');
    if (tip) return tip;
    tip = document.createElement('div');
    tip.id = 'pd3HelpTip';
    tip.className = 'pd3-help-tip';
    tip.hidden = true;
    document.body.appendChild(tip);
    return tip;
}

function positionTip(tip, target) {
    const text = target.getAttribute('data-help-abs');
    if (!text) return;
    tip.textContent = text;
    tip.hidden = false;

    // First reveal lets us measure the rendered box.
    const r  = target.getBoundingClientRect();
    const tr = tip.getBoundingClientRect();
    const vw = window.innerWidth;
    const vh = window.innerHeight;

    // Default: above the element, centered on its X-midpoint.
    let top  = r.top - tr.height - TIP_GAP;
    let left = r.left + r.width / 2 - tr.width / 2;

    // Flip below when there isn't enough room above.
    if (top < 4) top = r.bottom + TIP_GAP;
    // Keep the tip inside the viewport horizontally.
    left = Math.max(4, Math.min(left, vw - tr.width - 4));
    // Clamp vertically too in case the element itself is partially
    // off-screen (a very narrow viewport, for instance).
    if (top + tr.height > vh - 4) top = vh - tr.height - 4;

    tip.style.top  = Math.round(top)  + 'px';
    tip.style.left = Math.round(left) + 'px';
}

export function initHelpMode() {
    const btn = document.getElementById('pd3HelpBtn');
    if (!btn) return;
    const tip = ensureTip();
    let on = false;

    btn.addEventListener('click', () => {
        on = !on;
        document.body.classList.toggle('pd3-help-mode', on);
        btn.setAttribute('aria-pressed', on ? 'true' : 'false');
        if (!on) tip.hidden = true;
    });

    // Use mouseover (bubbles) instead of mouseenter so a single
    // listener on the document picks up nested matches without us
    // re-binding after every DOM mutation (table re-renders etc.).
    document.addEventListener('mouseover', (e) => {
        if (!on) return;
        const t = e.target instanceof Element ? e.target.closest('[data-help-abs]') : null;
        if (!t) return;
        positionTip(tip, t);
    });
    document.addEventListener('mouseout', (e) => {
        if (!on) return;
        const t = e.target instanceof Element ? e.target.closest('[data-help-abs]') : null;
        if (!t) return;
        // Only hide when actually leaving the data-help-abs subtree.
        const r = e.relatedTarget;
        if (r instanceof Element && r.closest('[data-help-abs]') === t) return;
        tip.hidden = true;
    });
    // Reposition while help mode is on (scroll / resize) so tip
    // stays glued to the current target.
    window.addEventListener('scroll', () => {
        if (!on || tip.hidden) return;
        // The actual target lives in tip's dataset for cheap re-lookup.
        const t = tip._target;
        if (t && document.contains(t)) positionTip(tip, t);
        else tip.hidden = true;
    }, { passive: true });
}
