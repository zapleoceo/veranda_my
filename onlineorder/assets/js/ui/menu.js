/* Menu rendering: category chips, product grid, search filter and the
 * modifier bottom-sheet for dishes with variants/add-ons. */

import { state, subscribe, addToCart, productQty, fmtVnd, t } from '../state.js';

const els = {};
let activeCat = 0;       // 0 = all
let query = '';

export function initMenu() {
    els.menu   = document.getElementById('ooMenu');
    els.cats   = document.getElementById('ooCats');
    els.search = document.getElementById('ooSearch');
    els.clear  = document.getElementById('ooSearchClear');

    els.search.addEventListener('input', () => {
        query = els.search.value.trim().toLowerCase();
        els.clear.hidden = query === '';
        renderMenu();
    });
    els.clear.addEventListener('click', () => {
        els.search.value = '';
        query = '';
        els.clear.hidden = true;
        renderMenu();
    });

    // Re-render qty badges when the cart changes.
    subscribe(() => updateBadges());
}

export function renderAll() {
    renderChips();
    renderMenu();
}

// ─── Category chips ───────────────────────────────────────────────
function visibleCategories() {
    const used = new Set(state.menu.products.map((p) => p.category_id));
    return state.menu.categories.filter((c) => used.has(c.id));
}

function renderChips() {
    const cats = visibleCategories();
    els.cats.replaceChildren();
    const all = chip(t('cart') === 'Cart' ? 'All' : '•••', 0); // tiny: first chip = all
    all.textContent = window.__oo.lang === 'ru' ? 'Все' : (window.__oo.lang === 'vi' ? 'Tất cả' : 'All');
    els.cats.appendChild(all);
    cats.forEach((c) => els.cats.appendChild(chip(c.name, c.id)));
    highlightChip();
}

function chip(name, id) {
    const b = document.createElement('button');
    b.type = 'button';
    b.className = 'oo-cat-chip';
    b.dataset.cat = String(id);
    b.textContent = name;
    b.addEventListener('click', () => {
        activeCat = id;
        highlightChip();
        renderMenu();
        els.menu.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
    return b;
}

function highlightChip() {
    els.cats.querySelectorAll('.oo-cat-chip').forEach((b) => {
        b.classList.toggle('is-active', Number(b.dataset.cat) === activeCat);
    });
}

// ─── Product grid ─────────────────────────────────────────────────
function filtered() {
    let items = state.menu.products;
    if (activeCat) items = items.filter((p) => p.category_id === activeCat);
    if (query) items = items.filter((p) => p.name.toLowerCase().includes(query));
    return items;
}

function renderMenu() {
    const items = filtered();
    els.menu.replaceChildren();

    if (!state.menu.products.length) {
        els.menu.appendChild(emptyNode(t('menuEmpty')));
        return;
    }
    if (!items.length) {
        els.menu.appendChild(emptyNode(t('searchEmptyTpl', { q: els.search.value.trim() })));
        return;
    }

    const catName = new Map(state.menu.categories.map((c) => [c.id, c.name]));
    const byCat = new Map();
    items.forEach((p) => {
        if (!byCat.has(p.category_id)) byCat.set(p.category_id, []);
        byCat.get(p.category_id).push(p);
    });

    byCat.forEach((products, catId) => {
        const h = document.createElement('h3');
        h.className = 'oo-cat-title';
        h.textContent = catName.get(catId) || t('categoryOther');
        els.menu.appendChild(h);

        const grid = document.createElement('div');
        grid.className = 'oo-grid';
        products.forEach((p) => grid.appendChild(card(p)));
        els.menu.appendChild(grid);
    });
}

function emptyNode(text) {
    const d = document.createElement('div');
    d.className = 'oo-empty';
    d.textContent = text;
    return d;
}

function card(p) {
    const el = document.createElement('article');
    el.className = 'oo-card';
    el.dataset.pid = String(p.id);

    if (p.photo) {
        const img = document.createElement('img');
        img.className = 'oo-card__photo';
        img.loading = 'lazy';
        img.alt = p.name;
        img.src = p.photo;
        img.onerror = () => img.replaceWith(photoStub());
        el.appendChild(img);
    } else {
        el.appendChild(photoStub());
    }

    const body = document.createElement('div');
    body.className = 'oo-card__body';

    const name = document.createElement('p');
    name.className = 'oo-card__name';
    name.textContent = p.name;
    body.appendChild(name);

    const row = document.createElement('div');
    row.className = 'oo-card__row';

    const hasVariants = (p.modifier_groups || []).length > 0;
    const price = document.createElement('span');
    price.className = 'oo-card__price';
    price.textContent = (hasVariants ? t('priceFrom') + ' ' : '') + fmtVnd(cardPrice(p));
    row.appendChild(price);

    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'oo-add';
    btn.setAttribute('aria-label', t('add'));
    btn.textContent = '+';
    btn.addEventListener('click', () => onAdd(p));
    row.appendChild(btn);

    body.appendChild(row);
    el.appendChild(body);
    return el;
}

function photoStub() {
    const d = document.createElement('div');
    d.className = 'oo-card__photo oo-card__photo--empty';
    d.textContent = 'V';
    return d;
}

function cardPrice(p) {
    const opts = (p.modifier_groups || []).flatMap((g) => g.options || []);
    if (opts.length) return Math.min(...opts.map((o) => o.price));
    return p.price;
}

function updateBadges() {
    els.menu.querySelectorAll('.oo-card').forEach((card) => {
        const btn = card.querySelector('.oo-add');
        const qty = productQty(Number(card.dataset.pid));
        if (qty > 0) {
            btn.classList.add('oo-add--qty');
            btn.textContent = '+' + qty;
        } else {
            btn.classList.remove('oo-add--qty');
            btn.textContent = '+';
        }
    });
}

// ─── Add flow / modifier sheet ────────────────────────────────────
function onAdd(p) {
    const needsSheet = (p.modifier_groups || []).length > 0 || (p.modifications || []).length > 0;
    if (!needsSheet) {
        addToCart(p);
        return;
    }
    openModifSheet(p);
}

function openModifSheet(p) {
    const sheet = document.getElementById('ooModifSheet');
    const title = document.getElementById('ooModifTitle');
    const body  = document.getElementById('ooModifBody');
    const price = document.getElementById('ooModifPrice');
    const add   = document.getElementById('ooModifAdd');

    title.textContent = p.name;
    body.replaceChildren();

    // Variant groups → radio per group (first option pre-selected).
    (p.modifier_groups || []).forEach((g, gi) => {
        const wrap = document.createElement('div');
        wrap.className = 'oo-modif-group';
        const h = document.createElement('h4');
        h.textContent = g.name || '—';
        wrap.appendChild(h);
        (g.options || []).forEach((o, oi) => {
            wrap.appendChild(optionRow('radio', `g${gi}`, o, oi === 0));
        });
        body.appendChild(wrap);
    });

    // Add-ons → checkboxes.
    if ((p.modifications || []).length) {
        const wrap = document.createElement('div');
        wrap.className = 'oo-modif-group';
        const h = document.createElement('h4');
        h.textContent = t('modifExtras');
        wrap.appendChild(h);
        p.modifications.forEach((m) => wrap.appendChild(optionRow('checkbox', 'addons', m, false)));
        body.appendChild(wrap);
    }

    const recalc = () => { price.textContent = fmtVnd(selectionPrice(p, body)); };
    body.addEventListener('change', (e) => {
        const label = e.target.closest('.oo-opt');
        if (label && e.target.type === 'checkbox') label.classList.toggle('is-on', e.target.checked);
        if (e.target.type === 'radio') {
            body.querySelectorAll(`input[name="${e.target.name}"]`).forEach((r) =>
                r.closest('.oo-opt').classList.toggle('is-on', r.checked));
        }
        recalc();
    });
    recalc();

    add.onclick = () => {
        const { modificator, addons } = readSelection(p, body);
        addToCart(p, modificator, addons);
        closeSheet(sheet);
    };

    openSheet(sheet);
}

function optionRow(type, name, item, checked) {
    const label = document.createElement('label');
    label.className = 'oo-opt' + (checked ? ' is-on' : '');
    const input = document.createElement('input');
    input.type = type;
    input.name = name;
    input.value = String(item.id);
    input.checked = checked;
    const span = document.createElement('span');
    span.className = 'oo-opt__name';
    span.textContent = item.name;
    const price = document.createElement('span');
    price.className = 'oo-opt__price';
    price.textContent = (type === 'checkbox' ? '+' : '') + fmtVnd(item.price);
    label.append(input, span, price);
    return label;
}

function readSelection(p, body) {
    let modificator = null;
    (p.modifier_groups || []).forEach((g, gi) => {
        const picked = body.querySelector(`input[name="g${gi}"]:checked`);
        if (!picked) return;
        const opt = (g.options || []).find((o) => o.id === Number(picked.value));
        if (opt) modificator = opt; // Poster: one modificator_id per line
    });
    const addons = [];
    body.querySelectorAll('input[name="addons"]:checked').forEach((cb) => {
        const m = (p.modifications || []).find((x) => x.id === Number(cb.value));
        if (m) addons.push({ id: m.id, name: m.name, price: m.price, count: 1 });
    });
    return { modificator, addons };
}

function selectionPrice(p, body) {
    const { modificator, addons } = readSelection(p, body);
    let unit = modificator ? modificator.price : p.price;
    addons.forEach((m) => { unit += m.price * m.count; });
    return unit;
}

// ─── Sheet helpers (shared) ───────────────────────────────────────
export function openSheet(el) {
    el.hidden = false;
    el.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
}

export function closeSheet(el) {
    el.hidden = true;
    el.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
}
