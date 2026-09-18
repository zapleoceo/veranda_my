// Shared formatting / escaping / query helpers for payday3 — the one
// copy every module uses (they used to carry 6–7 slightly different
// ones). Pure, no DOM, loads under node:test.

'use strict';

// One formatter for the whole page — Intl.NumberFormat is expensive to
// construct and fmtVnd() runs ~1k times per render.
const NF = new Intl.NumberFormat('en-US', { maximumFractionDigits: 0 });

/**
 * VND integer with space thousands: 1234567 → '1 234 567'.
 * @param {*} n
 * @param {{empty?:string}} [opts]  what to show for null / undefined / ''
 *   (default '0' — a missing number counts as zero in the tables).
 */
export function fmtVnd(n, { empty = '0' } = {}) {
    if (n === null || n === undefined || n === '') return empty;
    return NF.format(Math.round(Number(n) || 0)).replace(/,/g, ' ');
}

/** '1 234 567' / '-5 000 ₫' → number; no digits → null. */
export function parseVnd(s) {
    const t = String(s ?? '').replace(/[^\d-]/g, '');
    if (t === '' || t === '-') return null;
    const n = Number(t);
    return Number.isFinite(n) ? n : null;
}

const ESC = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
/** HTML-escape for text and attribute values. */
export const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ESC[c]);

/** 'dateFrom=…&dateTo=…' for a {from,to} range (either may be missing). */
export function rangeQuery(range) {
    const p = new URLSearchParams();
    if (range?.from) p.set('dateFrom', range.from);
    if (range?.to)   p.set('dateTo',   range.to);
    return p.toString();
}

/** Append the range query to a URL that may already carry a query. */
export function withRange(url, range) {
    const q = rangeQuery(range);
    if (!q) return url;
    return url + (url.includes('?') ? '&' : '?') + q;
}
