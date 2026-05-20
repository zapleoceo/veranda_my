// Font-scale toolbar widget.
//
// One button («Aa») that cycles the page's `zoom` factor through
// 1× → 1.2× → 1.5× → 1×. We use the CSS `zoom` property (not
// transform:scale) so the browser reflows the entire layout —
// typography, paddings, modal sizes, border radii — at the chosen
// scale. The SVG line overlay compensates for the zoom in its own
// pixel math (see LineRenderer) because CSS values inside a zoomed
// scope are re-multiplied by the zoom factor on paint.
//
// The chosen factor is persisted in localStorage and applied via a
// class on <html> before first paint (see content.php inline script)
// so there's no default-then-jump flash on reload.

'use strict';

const KEY    = 'pd3.fontScale';
const ORDER  = ['1', '1.2', '1.5'];
const VALID  = new Set(ORDER);
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

function paintButton(scale) {
    const btn = document.getElementById('pd3FontScaleBtn');
    if (!btn) return;
    btn.classList.toggle('is-active', scale !== '1');
    btn.setAttribute('data-scale-label', scale + '×');
    btn.title = scale === '1'
        ? 'Размер шрифта: обычный (клик — крупнее)'
        : ('Размер шрифта: ' + scale + '× (клик — следующий)');
}

function nextScale(current) {
    const i = ORDER.indexOf(current);
    return ORDER[(i + 1) % ORDER.length];
}

export function initFontScale() {
    let scale = readSavedScale();
    applyScale(scale);
    paintButton(scale);

    // Notify every LineRenderer (IN-mode + OUT-mode) to redraw after
    // layout has reflowed under the new zoom. Two rAFs: the first
    // lets the browser apply the zoom and recompute BCRs; the second
    // is when we actually want the redraw.
    const announceChange = () => {
        requestAnimationFrame(() => requestAnimationFrame(() => {
            window.dispatchEvent(new Event('pd3:font-scale-changed'));
        }));
    };

    // Announce the initial scale too — IN-mode renderer is built BEFORE
    // initFontScale runs, so it took its first measurements at whatever
    // class state happened to be on <html> at the time. A redraw now
    // settles it.
    announceChange();

    const btn = document.getElementById('pd3FontScaleBtn');
    if (!btn) return;

    btn.addEventListener('click', () => {
        scale = nextScale(scale);
        applyScale(scale);
        paintButton(scale);
        persistScale(scale);
        announceChange();
    });
}
