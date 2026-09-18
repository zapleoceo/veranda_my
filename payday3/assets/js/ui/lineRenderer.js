// LineRenderer — bezier connectors between sepay and poster anchors.
//
// Replaces payday2.js's drawLines() + duplicated out-renderer (~400 lines)
// with one class. Key differences:
//
//   * SVG lives inside the scrollable grid (.pd3-graph__grid) so it
//     scrolls horizontally with the tables automatically.
//   * ResizeObserver fires the redraw on container resize.
//   * MutationObserver fires it when rows are sorted/hidden/added.
//   * Scroll listeners (passive) cover the two per-pane vertical
//     scrollers and the horizontal container scroller; window scroll
//     and the font-scale event are ONE listener shared by all renderers.
//   * Redraws of ALL renderers share one rAF (frames scheduler below)
//     and run in two phases: every renderer reads its rects first,
//     then every renderer writes paths / × buttons — no read after a
//     write inside a frame, so no forced synchronous layout per link.
//   * SVG paths and × buttons are kept per link key and updated in
//     place, not recreated on every scroll frame.

'use strict';

const SVG_NS = 'http://www.w3.org/2000/svg';

const DEFAULT_COLOR_FOR = (link) => {
    if (link.is_manual)                 return '#9aa4b2';
    switch (link.link_type) {
        case 'auto_green':              return '#10b981';
        case 'auto_yellow':             return '#f59e0b';
        case 'auto_red':                return '#ef4444';
        default:                        return '#9aa4b2';
    }
};

/**
 * One rAF for any number of renderers. Each flush runs phase 1
 * (`_measure()`, reads only) for every dirty renderer, then phase 2
 * (`_apply(plan)`, writes only). Exported for tests.
 *
 * @param {(cb:()=>void)=>any} [raf]
 */
export function createFrameScheduler(raf = (cb) => requestAnimationFrame(cb)) {
    const dirty = new Set();
    let pending = false;
    const flush = () => {
        pending = false;
        const batch = [...dirty];
        dirty.clear();
        const plans = batch.map((r) => r._measure());
        batch.forEach((r, i) => { if (plans[i]) r._apply(plans[i]); });
    };
    return {
        schedule(r) {
            dirty.add(r);
            if (!pending) { pending = true; raf(flush); }
        },
        cancel(r) { dirty.delete(r); },
    };
}

const frames = createFrameScheduler();

// Page-wide triggers shared by every live renderer (window scroll, the
// «Aa» font-scale event) — bound once, not once per renderer.
const live = new Set();
let windowBound = false;
function bindWindowOnce() {
    if (windowBound || typeof window === 'undefined') return;
    windowBound = true;
    const all = () => live.forEach((r) => r._schedule());
    window.addEventListener('scroll', all, { passive: true });
    // ResizeObserver doesn't reliably fire under CSS `zoom`, so
    // fontScale.js dispatches an explicit event.
    window.addEventListener('pd3:font-scale-changed', all);
}

export class LineRenderer {
    constructor({
        container,                       // grid wrapper that hosts the SVG layer
        layer,                           // empty <div> inside container; SVG mounts here
        leftScroll,                      // left pane's vertical scroll viewport
        rightScroll,                     // right pane's vertical scroll viewport
        leftTbody,                       // left tbody (for MutationObserver)
        rightTbody,                      // right tbody
        leftAnchorId   = null,           // (link) => element id of the left anchor
        rightAnchorId  = null,           // (link) => element id of the right anchor
        linkKey        = null,           // (link) => unique string for path / button reuse
        colorFor       = DEFAULT_COLOR_FOR,
        onUnlink       = null,           // (link) => void — called by × button
        horizontalScroller = null,
    }) {
        this._container = container;
        this._layer     = layer;
        this._leftScroll   = leftScroll;
        this._rightScroll  = rightScroll;
        this._leftTbody    = leftTbody;
        this._rightTbody   = rightTbody;
        this._horizontalScroller = horizontalScroller || container;
        this._colorFor  = colorFor;
        this._onUnlink  = onUnlink;
        // Defaults: incoming SePay row ↔ Poster check.
        this._leftAnchorId  = leftAnchorId  || ((l) => 'pd3-sepay-anchor-'  + l.sepay_id);
        this._rightAnchorId = rightAnchorId || ((l) => 'pd3-poster-anchor-' + l.poster_transaction_id);
        this._linkKey       = linkKey       || ((l) => l.sepay_id + ':' + l.poster_transaction_id);

        this._links     = [];
        this._buttons   = new Map();      // key → close-button DOM node (reused across redraws)
        this._paths     = new Map();      // key → [halo, line] SVG paths (reused across redraws)
        this._destroyed = false;

        this._mountSvg();
        this._mountObservers();
    }

    /** Public API: replace the rendered set. */
    setLinks(links) {
        this._links = Array.isArray(links) ? links : [];
        this._schedule();
    }

    /** Public API: force a redraw without changing the link set. */
    redraw() { this._schedule(); }

    /** Public API: (re)bind the × button handler — `(link) => void`. */
    setOnUnlink(fn) { this._onUnlink = typeof fn === 'function' ? fn : null; }

    /** Public API: tear everything down. */
    destroy() {
        if (this._destroyed) return;
        this._destroyed = true;
        frames.cancel(this);
        live.delete(this);
        this._resizeObserver?.disconnect();
        this._mutationObserver?.disconnect();
        for (const { el, fn } of this._scrollListeners) {
            el.removeEventListener('scroll', fn);
        }
        this._scrollListeners = [];
        try { this._svg?.remove(); } catch (_) {}
        for (const btn of this._buttons.values()) btn.remove();
        this._buttons.clear();
        this._paths.clear();
    }

    // ─── internals ─────────────────────────────────────────────────────

    _mountSvg() {
        const svg = document.createElementNS(SVG_NS, 'svg');
        svg.style.position = 'absolute';
        svg.style.inset    = '0';
        svg.style.width    = '100%';
        svg.style.height   = '100%';
        svg.style.display       = 'block';
        svg.style.pointerEvents = 'none';
        svg.setAttribute('preserveAspectRatio', 'none');
        const group = document.createElementNS(SVG_NS, 'g');
        svg.appendChild(group);
        this._layer.appendChild(svg);
        this._svg   = svg;
        this._group = group;
    }

    _mountObservers() {
        const trigger = () => this._schedule();

        // Layout resizes (window resize, sidebar collapse, etc.)
        if (typeof ResizeObserver === 'function') {
            this._resizeObserver = new ResizeObserver(trigger);
            this._resizeObserver.observe(this._container);
            if (this._leftScroll)  this._resizeObserver.observe(this._leftScroll);
            if (this._rightScroll) this._resizeObserver.observe(this._rightScroll);
        }

        // Row additions / removals / class changes (sort, hide, lite-mode column toggle).
        if (typeof MutationObserver === 'function') {
            this._mutationObserver = new MutationObserver(trigger);
            const opts = { childList: true, subtree: true, attributes: true,
                           attributeFilter: ['class', 'style'] };
            if (this._leftTbody)  this._mutationObserver.observe(this._leftTbody,  opts);
            if (this._rightTbody) this._mutationObserver.observe(this._rightTbody, opts);
        }

        // Scroll on per-pane vertical scrollers and the horizontal container
        // (window scroll + font-scale are shared — bindWindowOnce()).
        const targets = new Set([this._horizontalScroller, this._leftScroll, this._rightScroll]);
        this._scrollListeners = [];
        for (const el of targets) {
            if (!el) continue;
            el.addEventListener('scroll', trigger, { passive: true });
            this._scrollListeners.push({ el, fn: trigger });
        }

        live.add(this);
        bindWindowOnce();
    }

    _schedule() {
        if (this._destroyed) return;
        frames.schedule(this);
    }

    /**
     * Read the active CSS-zoom factor on .pd3-page.
     *
     * Under Chromium's `zoom`, both `getBoundingClientRect().width` and
     * `offsetWidth` return already-scaled values, so a vis/layout ratio
     * doesn't reveal the factor. The reliable source of truth is the
     * class on <html> that fontScale.js sets — pd3-scale-1-2 / -1-5.
     * Falls back to 1 (no zoom) when no class is present.
     */
    _getScale() {
        const cls = document.documentElement.className || '';
        if (cls.indexOf('pd3-scale-1-5') !== -1) return 1.5;
        if (cls.indexOf('pd3-scale-1-2') !== -1) return 1.2;
        return 1;
    }

    /**
     * Phase 1 — reads only (rects, scroll offsets). Returns the plan
     * `_apply()` writes, or null when destroyed.
     */
    _measure() {
        if (this._destroyed) return null;
        const scale = this._getScale();
        const rootRect = this._container.getBoundingClientRect();
        // BCR returns visual (post-zoom) pixels — same coord system every
        // anchor BCR below is read in, so path coordinates lie in visual
        // px and the viewBox uses that same unit.
        const w = rootRect.width  || this._container.scrollWidth;
        const h = rootRect.height || this._container.scrollHeight;
        const scroll = { left: this._container.scrollLeft, top: this._container.scrollTop };

        const leftClip  = this._scrollRect(this._leftScroll);
        const rightClip = this._scrollRect(this._rightScroll);
        const items = [];
        for (const link of this._links) {
            const aEl = document.getElementById(this._leftAnchorId(link));
            const bEl = document.getElementById(this._rightAnchorId(link));
            if (!aEl || !bEl) continue;
            if (!isRendered(aEl) || !isRendered(bEl)) continue;

            const aRect = aEl.getBoundingClientRect();
            const bRect = bEl.getBoundingClientRect();
            if (leftClip  && !isInClipY(aRect, leftClip))  continue;
            if (rightClip && !isInClipY(bRect, rightClip)) continue;

            items.push({
                key:   this._linkKey(link),
                link,
                a:     pointOf(aRect, rootRect, scroll),
                b:     pointOf(bRect, rootRect, scroll),
                color: this._colorFor(link),
            });
        }
        return { w, h, scale, items };
    }

    /** Phase 2 — writes only: viewBox, paths (reused per key), × buttons. */
    _apply({ w, h, scale, items }) {
        if (this._destroyed) return;
        setAttr(this._svg, 'viewBox', `0 0 ${w} ${h}`);
        // The SVG element lives INSIDE the zoomed .pd3-page, so any CSS
        // pixel value we set on it is multiplied by `scale` on paint.
        // Dividing the visual dimensions by `scale` cancels that out and
        // the element ends up exactly covering the container visually.
        this._svg.style.width  = (w / scale) + 'px';
        this._svg.style.height = (h / scale) + 'px';

        const drawn = new Set();
        for (const { key, link, a, b, color } of items) {
            if (drawn.has(key)) continue;
            drawn.add(key);
            const d = bezierPath(a, b);
            let pair = this._paths.get(key);
            if (!pair) {
                // White halo outline for visibility on any background.
                pair = [svgPath('rgba(255,255,255,0.65)', 4), svgPath(color, 2)];
                this._group.appendChild(pair[0]);
                this._group.appendChild(pair[1]);
                this._paths.set(key, pair);
            }
            setAttr(pair[0], 'd', d);
            setAttr(pair[1], 'd', d);
            setAttr(pair[1], 'stroke', color);
            this._placeRemoveButton(link, a, b, key, scale);
        }

        // Paths of links that are gone or scrolled out of view.
        for (const [key, pair] of this._paths) {
            if (drawn.has(key)) continue;
            pair[0].remove();
            pair[1].remove();
            this._paths.delete(key);
        }
        // × buttons: hide while scrolled out, drop once the link is gone.
        const current = new Set(this._links.map((l) => this._linkKey(l)));
        for (const [key, btn] of this._buttons) {
            if (drawn.has(key)) continue;
            if (current.has(key)) { if (btn.style.display !== 'none') btn.style.display = 'none'; }
            else { btn.remove(); this._buttons.delete(key); }
        }
    }

    _placeRemoveButton(link, a, b, key, scale) {
        let btn = this._buttons.get(key);
        if (!btn) {
            btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'pd3-link-remove';
            btn.title = 'Удалить связь';
            btn.textContent = '×';
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                // The handler receives the full (latest) link record —
                // IN-mode adapter unpacks {sepay_id, poster_transaction_id};
                // OUT-mode adapter unpacks {mail_uid, finance_id}.
                this._onUnlink?.(btn._link);
            });
            this._layer.appendChild(btn);
            this._buttons.set(key, btn);
        }
        btn._link = link;
        // The × sits near the END of the bezier (poster side), not at
        // the midpoint — that's how payday2 placed it. Clamping to
        // [0.92, 0.98] keeps it visibly attached to the line without
        // overlapping the anchor dot itself. Evaluated on the cubic
        // curve, not the chord, so it always lies ON the path.
        const len = Math.hypot(b.x - a.x, b.y - a.y) || 1;
        const t   = Math.min(0.98, Math.max(0.92, 1 - (10 / len)));
        const pt  = cubicPoint(a, b, t);
        // pt is in visual (post-zoom) px because a/b were derived from
        // BCR readings. The button is a real DOM node inside the zoomed
        // .pd3-page, so the CSS px we set here are re-multiplied by the
        // active scale on paint — divide by it to keep the button on
        // the line.
        btn.style.left = Math.round((pt.x - 8) / scale) + 'px';
        btn.style.top  = Math.round((pt.y - 8) / scale) + 'px';
        btn.style.display = 'flex';
    }

    _scrollRect(scroll) {
        if (!scroll) return null;
        return scroll.getBoundingClientRect();
    }
}

// ─── pure helpers ─────────────────────────────────────────────────────

function isRendered(el) {
    return el.getClientRects().length > 0;
}

function isInClipY(rect, clip) {
    return rect.bottom >= clip.top && rect.top <= clip.bottom;
}

function pointOf(anchorRect, rootRect, scroll) {
    const cx = anchorRect.left + anchorRect.width  / 2 - rootRect.left + scroll.left;
    const cy = anchorRect.top  + anchorRect.height / 2 - rootRect.top  + scroll.top;
    // Snap to half-pixel so 1px lines render crisply.
    return { x: Math.round(cx) + 0.5, y: Math.round(cy) + 0.5 };
}

function bezierPath(a, b) {
    const { c1, c2 } = bezierControls(a, b);
    return `M ${a.x} ${a.y} C ${c1.x} ${c1.y}, ${c2.x} ${c2.y}, ${b.x} ${b.y}`;
}

/** Control points for the cubic. Kept in one place so cubicPoint() and
 *  bezierPath() never diverge — the same curve underlies the SVG path
 *  and the × button placement. */
function bezierControls(a, b) {
    const dx  = b.x - a.x;
    const cdx = Math.min(140, Math.max(40, Math.abs(dx) * 0.35));
    return {
        c1: { x: a.x + cdx, y: a.y },
        c2: { x: b.x - cdx, y: b.y },
    };
}

/** Cubic Bezier B(t) for our P1=a, P2=c1, P3=c2, P4=b setup. */
function cubicPoint(a, b, t) {
    const { c1, c2 } = bezierControls(a, b);
    const u = 1 - t;
    const uu = u * u, tt = t * t;
    const w1 = uu * u, w2 = 3 * uu * t, w3 = 3 * u * tt, w4 = tt * t;
    return {
        x: w1 * a.x + w2 * c1.x + w3 * c2.x + w4 * b.x,
        y: w1 * a.y + w2 * c1.y + w3 * c2.y + w4 * b.y,
    };
}

/** Skip identical writes — cheaper than letting the SVG re-parse `d`. */
function setAttr(el, name, value) {
    if (el.getAttribute(name) !== value) el.setAttribute(name, value);
}

function svgPath(stroke, width) {
    const p = document.createElementNS(SVG_NS, 'path');
    p.setAttribute('fill', 'none');
    p.setAttribute('stroke', stroke);
    p.setAttribute('stroke-width', String(width));
    p.setAttribute('stroke-linecap', 'round');
    p.setAttribute('stroke-linejoin', 'round');
    return p;
}
