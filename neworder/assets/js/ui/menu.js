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

// Manual expansion is independent of cart-driven DOM rerenders.
const expandedCategories = new Set();
const fold = (text) => String(text ?? '').toLowerCase().normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '').replace(/đ/g, 'd');

/** Highlight original text, escaping each segment before inserting markup. */
function highlight(text, words) {
    text = String(text ?? '');
    if (!words.length) return esc(text);
    const units = Array.from(text.matchAll(/[^\p{M}]\p{M}*|\p{M}+/gu));
    const offsets = [];
    let normalized = '';
    for (const unit of units) {
        const value = fold(unit[0]);
        for (let i = 0; i < value.length; i++) offsets.push([unit.index, unit.index + unit[0].length]);
        normalized += value;
    }
    const ranges = [];
    for (const word of words) {
        let start = normalized.indexOf(word);
        while (start !== -1) {
            ranges.push([offsets[start][0], offsets[start + word.length - 1][1]]);
            start = normalized.indexOf(word, start + word.length);
        }
    }
    ranges.sort((a, b) => a[0] - b[0]);
    const merged = [];
    for (const range of ranges) {
        const last = merged[merged.length - 1];
        if (last && range[0] <= last[1]) last[1] = Math.max(last[1], range[1]);
        else merged.push([...range]);
    }
    let end = 0;
    let html = '';
    for (const range of merged) {
        html += esc(text.slice(end, range[0])) + '<mark>' + esc(text.slice(range[0], range[1])) + '</mark>';
        end = range[1];
    }
    return html + esc(text.slice(end));
}

/** Render the whole menu given current state + filter. */
function render(state) {
    const root = document.getElementById('noMenu');
    if (!root) return;
    const products   = state.s.products;
    const categories = state.s.categories;
    const q = (state.s.search || '').trim();
    const words = fold(q).split(/\s+/).filter(Boolean);
    const status = document.getElementById('noSearchStatus');
    if (status) {
        status.hidden = !q;
        status.textContent = q ? t('searchResultsTpl', { n: 0 }) : '';
    }
    if (!products.length) {
        root.innerHTML = `<div class="no-empty">${esc(t('menuEmpty'))}</div>`;
        return;
    }

    const names = new Map(categories.map((c) => [c.id, c.name]));
    let resultCount = 0;
    const byCat = new Map();
    for (const p of products) {
        const searchable = fold(p.name + ' ' + (names.get(p.category_id) || t('categoryOther')));
        if (!words.every((word) => searchable.includes(word))) continue;
        resultCount++;
        const arr = byCat.get(p.category_id) || [];
        arr.push(p);
        byCat.set(p.category_id, arr);
    }
    if (status && q) status.textContent = t('searchResultsTpl', { n: resultCount });
    if (byCat.size === 0) {
        root.innerHTML = `<div class="no-empty">${esc(t('searchEmptyTpl', { q }))}</div>`;
        return;
    }

    // Numbered names first (1, 2, ... 24); keep the original order for ties
    // and unnumbered categories. Orphan products stay in the final "Прочее" bucket.
    const known = new Set(categories.map((c) => c.id));
    const categoryNumber = (name) => {
        const match = String(name ?? '').trim().match(/^\d+/);
        return match ? Number(match[0]) : Infinity;
    };
    const ordered = [...categories].sort((a, b) => {
        const first = categoryNumber(a.name);
        const second = categoryNumber(b.name);
        return first === second ? 0 : first - second;
    });
    let orphans = [];
    for (const [cid, list] of byCat.entries()) {
        if (!known.has(cid)) orphans = orphans.concat(list);
    }
    const inCart = new Set(state.cart.map((l) => l.product_id));

    const blocks = [];
    for (const c of ordered) {
        const list = byCat.get(c.id);
        if (!list || !list.length) continue;
        blocks.push(renderCategory('category-' + c.id, c.name, list, inCart, words));
    }
    if (orphans.length) blocks.push(renderCategory('other', t('categoryOther'), orphans, inCart, words));

    const focused = root.contains(document.activeElement) ? document.activeElement : null;
    const focusedId = focused?.id;
    const focusedProduct = focused?.dataset.productId;
    root.innerHTML = blocks.join('');
    if (focusedId) document.getElementById(focusedId)?.focus({ preventScroll: true });
    else if (focusedProduct) root.querySelector(`[data-product-id="${CSS.escape(focusedProduct)}"]`)?.focus({ preventScroll: true });
}

function renderCategory(key, name, list, inCart, words) {
    const open = words.length > 0 || expandedCategories.has(key);
    const id = 'no-category-' + encodeURIComponent(key);
    return `
        <section class="no-cat">
            <h2 class="no-cat__title">
                <button type="button" class="no-cat__toggle" id="${esc(id)}-toggle"
                    data-category-key="${esc(key)}" aria-expanded="${open}" aria-controls="${esc(id)}">
                    <span class="no-cat__name">${highlight(name, words)}</span>
                    <span class="no-cat__count">${list.length}</span>
                    <svg class="no-cat__chevron" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path d="m6 9 6 6 6-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </button>
            </h2>
            <div class="no-cat__grid" id="${esc(id)}" ${open ? '' : 'hidden'}>
                ${list.map((p) => renderItem(p, inCart.has(p.id), words)).join('')}
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
    let loaded = false;
    state.on(() => { if (loaded) render(state); });
    document.getElementById('noMenu')?.addEventListener('click', (event) => {
        const toggle = event.target.closest('.no-cat__toggle');
        if (!toggle) return;
        const open = toggle.getAttribute('aria-expanded') !== 'true';
        const key = toggle.dataset.categoryKey;
        if (!state.s.search.trim()) {
            if (open) expandedCategories.add(key);
            else expandedCategories.delete(key);
        }
        toggle.setAttribute('aria-expanded', String(open));
        document.getElementById(toggle.getAttribute('aria-controls')).hidden = !open;
    });

    async function refresh() {
        try {
            // Parallel fetch — menu is biggest, locations is small.
            const [menuRes, locRes] = await Promise.all([api.menu(), api.locations()]);
            loaded = true;
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
