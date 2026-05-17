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
//     scrollers and the horizontal container scroller.
//   * Every redraw is rAF-batched.
//   * Anchors are read with getBoundingClientRect once per redraw —
//     not per link — so a hundred links don't trigger a hundred
//     layout passes.

'use strict';

const SVG_NS = 'http://www.w3.org/2000/svg';

const DEFAULT_COLOR_FOR = (link) => {
    if (link.is_manual)                 return '#9aa4b2';
    switch (link.link_type) {
        case 'auto_green':              return '#10b981';
        case 'auto_yellow':              return '#f59e0b';
        case 'auto_red':                  return '#ef4444';
        default:                          return '#9aa4b2';
    }
};

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
        linkKey        = null,           // (link) => unique string for close-button reuse
        colorFor       = DEFAULT_COLOR_FOR,
        onUnlink       = null,           // (link) => void — called by × button
        horizontalScroller = null,
        // Legacy aliases kept for the existing IN-mode bootstrap.
        sepayScroll = null, posterScroll = null, sepayTbody = null, posterTbody = null,
    }) {
        this._container = container;
        this._layer     = layer;
        this._leftScroll   = leftScroll  ?? sepayScroll;
        this._rightScroll  = rightScroll ?? posterScroll;
        this._leftTbody    = leftTbody   ?? sepayTbody;
        this._rightTbody   = rightTbody  ?? posterTbody;
        this._horizontalScroller = horizontalScroller || container;
        this._colorFor  = colorFor;
        this._onUnlink  = onUnlink;
        // Default selectors keep IN-mode backward compatibility.
        this._leftAnchorId  = leftAnchorId  || ((l) => 'pd3-sepay-anchor-'  + l.sepay_id);
        this._rightAnchorId = rightAnchorId || ((l) => 'pd3-poster-anchor-' + l.poster_transaction_id);
        this._linkKey       = linkKey       || ((l) => l.sepay_id + ':' + l.poster_transaction_id);

        this._links     = [];
        this._buttons   = new Map();      // key → close-button DOM node (reused across redraws)
        this._raf       = 0;
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

    /** Public API: tear everything down. */
    destroy() {
        if (this._destroyed) return;
        this._destroyed = true;
        if (this._raf) cancelAnimationFrame(this._raf);
        this._resizeObserver?.disconnect();
        this._mutationObserver?.disconnect();
        for (const { el, fn } of this._scrollListeners) {
            el.removeEventListener('scroll', fn);
        }
        this._scrollListeners = [];
        try { this._svg?.remove(); } catch (_) {}
        for (const btn of this._buttons.values()) btn.remove();
        this._buttons.clear();
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

        // Scroll on per-pane vertical scrollers and the horizontal container.
        const targets = new Set([this._horizontalScroller, this._leftScroll, this._rightScroll, window]);
        this._scrollListeners = [];
        for (const el of targets) {
            if (!el) continue;
            const fn = () => this._schedule();
            el.addEventListener('scroll', fn, { passive: true });
            this._scrollListeners.push({ el, fn });
        }
    }

    _schedule() {
        if (this._destroyed) return;
        if (this._raf) return;
        this._raf = requestAnimationFrame(() => {
            this._raf = 0;
            if (!this._destroyed) this._redraw();
        });
    }

    _redraw() {
        const rootRect = this._container.getBoundingClientRect();
        const w = this._container.scrollWidth  || rootRect.width;
        const h = this._container.scrollHeight || rootRect.height;
        this._svg.setAttribute('viewBox', `0 0 ${w} ${h}`);
        this._svg.style.width  = w + 'px';
        this._svg.style.height = h + 'px';

        // Clear paths but keep close-button nodes for reuse — we hide
        // them and re-show only the ones we actually re-render.
        while (this._group.firstChild) this._group.removeChild(this._group.firstChild);
        for (const btn of this._buttons.values()) btn.style.display = 'none';

        const leftClip  = this._scrollRect(this._leftScroll);
        const rightClip = this._scrollRect(this._rightScroll);
        const keep = new Set();

        for (const link of this._links) {
            const aEl = document.getElementById(this._leftAnchorId(link));
            const bEl = document.getElementById(this._rightAnchorId(link));
            if (!aEl || !bEl) continue;
            if (!isRendered(aEl) || !isRendered(bEl)) continue;

            const aRect = aEl.getBoundingClientRect();
            const bRect = bEl.getBoundingClientRect();
            if (leftClip  && !isInClipY(aRect, leftClip))  continue;
            if (rightClip && !isInClipY(bRect, rightClip)) continue;

            const a = pointOf(aRect, rootRect, this._container);
            const b = pointOf(bRect, rootRect, this._container);

            const color = this._colorFor(link);
            const d = bezierPath(a, b);

            // White halo outline for visibility on any background.
            this._group.appendChild(svgPath(d, 'rgba(255,255,255,0.65)', 4));
            this._group.appendChild(svgPath(d, color, 2));

            const key = this._linkKey(link);
            this._placeRemoveButton(link, a, b, key);
            keep.add(key);
        }

        // Drop close-buttons whose link disappeared.
        for (const [key, btn] of this._buttons) {
            if (!keep.has(key)) { btn.remove(); this._buttons.delete(key); }
        }
    }

    _placeRemoveButton(link, a, b, key) {
        let btn = this._buttons.get(key);
        if (!btn) {
            btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'pd3-link-remove';
            btn.title = 'Удалить связь';
            btn.textContent = '×';
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                // The handler receives the full link record — IN-mode
                // adapter unpacks {sepay_id, poster_transaction_id};
                // OUT-mode adapter unpacks {mail_uid, finance_id}.
                this._onUnlink?.(link);
            });
            this._layer.appendChild(btn);
            this._buttons.set(key, btn);
        }
        // The × sits near the END of the bezier (poster side), not at
        // the midpoint — that's how payday2 placed it. Clamping to
        // [0.92, 0.98] keeps it visibly attached to the line without
        // overlapping the anchor dot itself. Evaluated on the cubic
        // curve, not the chord, so it always lies ON the path.
        const len = Math.hypot(b.x - a.x, b.y - a.y) || 1;
        const t   = Math.min(0.98, Math.max(0.92, 1 - (10 / len)));
        const pt  = cubicPoint(a, b, t);
        btn.style.left = Math.round(pt.x - 8) + 'px';
        btn.style.top  = Math.round(pt.y - 8) + 'px';
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

function pointOf(anchorRect, rootRect, container) {
    const cx = anchorRect.left + anchorRect.width  / 2 - rootRect.left + container.scrollLeft;
    const cy = anchorRect.top  + anchorRect.height / 2 - rootRect.top  + container.scrollTop;
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

function svgPath(d, stroke, width) {
    const p = document.createElementNS(SVG_NS, 'path');
    p.setAttribute('d', d);
    p.setAttribute('fill', 'none');
    p.setAttribute('stroke', stroke);
    p.setAttribute('stroke-width', String(width));
    p.setAttribute('stroke-linecap', 'round');
    p.setAttribute('stroke-linejoin', 'round');
    return p;
}
