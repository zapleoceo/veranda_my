/* Cart store — the single source of truth the menu, cart sheet and
 * checkout all subscribe to. A cart key is product id + the exact
 * modifier signature, so the same dish with different options lives
 * on separate lines. */

const listeners = new Set();

export const state = {
    menu: { categories: [], products: [] },
    /** Map<key, {product, count, modificatorId, modificatorName, addons:[{id,name,price,count}], unitPrice, label}> */
    cart: new Map(),
};

export function subscribe(fn) {
    listeners.add(fn);
    return () => listeners.delete(fn);
}

function emit() {
    listeners.forEach((fn) => fn(state));
}

export function setMenu(menu) {
    state.menu = menu;
    emit();
}

export function productById(id) {
    return state.menu.products.find((p) => p.id === id) || null;
}

function keyFor(productId, modificatorId, addons) {
    const a = (addons || []).map((m) => `${m.id}x${m.count}`).sort().join(',');
    return `${productId}|${modificatorId || 0}|${a}`;
}

/** Unit price: a chosen variant REPLACES the base price, add-ons ADD. */
function unitPrice(product, modificator, addons) {
    let unit = modificator ? modificator.price : product.price;
    for (const m of addons || []) unit += m.price * m.count;
    return unit;
}

function labelFor(product, modificator, addons) {
    let label = product.name;
    if (modificator) label += ` (${modificator.name})`;
    for (const m of addons || []) label += ` +${m.name}`;
    return label;
}

export function addToCart(product, modificator = null, addons = []) {
    const key = keyFor(product.id, modificator?.id, addons);
    const line = state.cart.get(key);
    if (line) {
        line.count += 1;
    } else {
        state.cart.set(key, {
            product,
            count: 1,
            modificatorId: modificator?.id || 0,
            modificatorName: modificator?.name || '',
            addons: addons || [],
            unitPrice: unitPrice(product, modificator, addons),
            label: labelFor(product, modificator, addons),
        });
    }
    emit();
}

export function changeQty(key, delta) {
    const line = state.cart.get(key);
    if (!line) return;
    line.count += delta;
    if (line.count <= 0) state.cart.delete(key);
    emit();
}

export function clearCart() {
    state.cart.clear();
    emit();
}

export function cartCount() {
    let n = 0;
    state.cart.forEach((l) => { n += l.count; });
    return n;
}

export function cartTotal() {
    let sum = 0;
    state.cart.forEach((l) => { sum += l.unitPrice * l.count; });
    return sum;
}

/** Count of a product across all its modifier variants (menu badge). */
export function productQty(productId) {
    let n = 0;
    state.cart.forEach((l) => { if (l.product.id === productId) n += l.count; });
    return n;
}

/** The wire shape OrderCreateAction expects. */
export function cartPayload() {
    const items = [];
    state.cart.forEach((l) => {
        const row = { product_id: l.product.id, count: l.count, label: l.label };
        if (l.modificatorId > 0) row.modificator_id = l.modificatorId;
        if (l.addons.length) row.modifications = l.addons.map((m) => ({ id: m.id, count: m.count }));
        items.push(row);
    });
    return items;
}

export function fmtVnd(n) {
    return new Intl.NumberFormat('vi-VN').format(Math.round(n)) + ' ₫';
}

/** i18n helper: t('quoteOutOfZone', {km: 15}) */
export function t(key, vars = {}) {
    let s = (window.__oo?.i18n || {})[key] ?? key;
    for (const [k, v] of Object.entries(vars)) s = s.replaceAll(`{${k}}`, String(v));
    return s;
}
