// Menu renderer — categories + product cards.
//
// Categories with no products in the current filter are hidden so the
// scroll stays focused. Items that already sit in the cart get an
// `is-in-cart` accent so the operator sees what's already added.
//
// Loading + error states are part of this module — the parent bootstrap
// only triggers a refresh.

'use strict';

const _self = new URL(import.meta.url);
const _v    = _self.searchParams.get('v') || '';
const _qs   = _v ? '?v=' + encodeURIComponent(_v) : '';
const { api }   = await import(new URL('../api.js'  + _qs, import.meta.url).href);
const { toast } = await import(new URL('./toast.js' + _qs, import.meta.url).href);
const { t }     = await import(new URL('../i18n.js' + _qs, import.meta.url).href);

const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
})[c]);

const fmtVnd = (n) => {
    const v = Math.round(Number(n) || 0);
    try { return new Intl.NumberFormat('en-US', { maximumFractionDigits: 0 }).format(v).replace(/,/g, ' ') + ' ₫'; }
    catch (_) { return String(v) + ' ₫'; }
};

/** Highlight matched chunks of `text` for the active search query. */
function highlight(text, q) {
    if (!q) return esc(text);
    const safe = esc(text);
    try {
        const re = new RegExp('(' + q.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'ig');
        return safe.replace(re, '<mark>$1</mark>');
    } catch (_) { return safe; }
}

/** Render the whole menu given current state + filter. */
function render(state) {
    const root = document.getElementById('noMenu');
    if (!root) return;
    const products   = state.s.products;
    const categories = state.s.categories;
    if (!products.length) {
        root.innerHTML = `<div class="no-empty">${esc(t('menuEmpty'))}</div>`;
        return;
    }

    const q = (state.s.search || '').trim().toLowerCase();
    const byCat = new Map();
    for (const p of products) {
        if (q && !p.name.toLowerCase().includes(q)) continue;
        const arr = byCat.get(p.category_id) || [];
        arr.push(p);
        byCat.set(p.category_id, arr);
    }
    if (byCat.size === 0) {
        root.innerHTML = `<div class="no-empty">${esc(t('searchEmptyTpl', { q }))}</div>`;
        return;
    }

    // Preserve Poster's category order; orphan products (category_id missing
    // from the tree) get appended into an "Прочее" bucket.
    const known = new Set(categories.map((c) => c.id));
    const ordered = [...categories];
    let orphans = [];
    for (const [cid, list] of byCat.entries()) {
        if (!known.has(cid)) orphans = orphans.concat(list);
    }
    const inCart = new Set(state.cart.map((l) => l.product_id));

    const blocks = [];
    for (const c of ordered) {
        const list = byCat.get(c.id);
        if (!list || !list.length) continue;
        blocks.push(renderCategory(c.name, list, inCart, q));
    }
    if (orphans.length) blocks.push(renderCategory(t('categoryOther'), orphans, inCart, q));

    root.innerHTML = blocks.join('');
}

function renderCategory(name, list, inCart, q) {
    return `
        <section class="no-cat">
            <h3 class="no-cat__title">${esc(name)}</h3>
            <div class="no-cat__grid">
                ${list.map((p) => renderItem(p, inCart.has(p.id), q)).join('')}
            </div>
        </section>`;
}

function renderItem(p, isInCart, q) {
    const hasOptions =
        (p.modifier_groups && p.modifier_groups.length) ||
        (p.modifications   && p.modifications.length);
    const priceLabel = hasOptions ? t('priceFrom') + ' ' + fmtVnd(p.price) : fmtVnd(p.price);
    return `
        <button type="button" class="no-item ${isInCart ? 'is-in-cart' : ''}" data-product-id="${p.id}">
            <div class="no-item__name">${highlight(p.name, q)}</div>
            <div class="no-item__price">${esc(priceLabel)}</div>
        </button>`;
}

/** Public: fetches menu + locations, then renders. Returns a refresh fn. */
export async function initMenu({ state }) {
    state.on(() => render(state));

    async function refresh() {
        try {
            // Parallel fetch — menu is biggest, locations is small.
            const [menuRes, locRes] = await Promise.all([api.menu(), api.locations()]);
            state.setMenu(menuRes.categories || [], menuRes.products || []);
            state.setLocations(locRes.spots || [], locRes.halls || [], locRes.tables || []);
        } catch (e) {
            const root = document.getElementById('noMenu');
            if (root) root.innerHTML = `<div class="no-error">${esc(e.message || t('menuLoadError'))}</div>`;
            toast(e.message || t('menuLoadError'), { error: true });
        }
    }
    await refresh();
    return refresh;
}
