/* Cart bar + cart bottom-sheet. */

import { state, subscribe, changeQty, cartCount, cartTotal, fmtVnd, t } from '../state.js';
import { openSheet, closeSheet } from './menu.js';

const els = {};

export function initCart(onCheckout) {
    els.bar      = document.getElementById('ooCartBar');
    els.count    = document.getElementById('ooCartCount');
    els.sum      = document.getElementById('ooCartSum');
    els.sheet    = document.getElementById('ooCartSheet');
    els.items    = document.getElementById('ooCartItems');
    els.total    = document.getElementById('ooCartTotal');
    els.checkout = document.getElementById('ooToCheckout');

    els.bar.addEventListener('click', () => {
        renderItems();
        openSheet(els.sheet);
    });
    els.checkout.addEventListener('click', () => {
        closeSheet(els.sheet);
        onCheckout();
    });

    subscribe(render);
    render();
}

function render() {
    const n = cartCount();
    els.bar.hidden = n === 0;
    els.count.textContent = String(n);
    els.sum.textContent = fmtVnd(cartTotal());
    els.total.textContent = fmtVnd(cartTotal());
    els.checkout.disabled = n === 0;

    if (!els.sheet.hidden) renderItems();
    if (n === 0 && !els.sheet.hidden) closeSheet(els.sheet);
}

function renderItems() {
    els.items.replaceChildren();
    if (!state.cart.size) {
        const d = document.createElement('div');
        d.className = 'oo-empty';
        d.textContent = t('cartEmpty');
        els.items.appendChild(d);
        return;
    }
    state.cart.forEach((line, key) => els.items.appendChild(row(line, key)));
}

function row(line, key) {
    const el = document.createElement('div');
    el.className = 'oo-line';

    const name = document.createElement('div');
    name.className = 'oo-line__name';
    name.textContent = line.product.name;
    el.appendChild(name);

    const price = document.createElement('div');
    price.className = 'oo-line__price';
    price.textContent = fmtVnd(line.unitPrice * line.count);
    el.appendChild(price);

    const mods = [];
    if (line.modificatorName) mods.push(line.modificatorName);
    line.addons.forEach((m) => mods.push('+' + m.name));
    if (mods.length) {
        const m = document.createElement('div');
        m.className = 'oo-line__mods';
        m.textContent = mods.join(' · ');
        el.appendChild(m);
    }

    const qty = document.createElement('div');
    qty.className = 'oo-line__qty';
    qty.append(
        qbtn('−', () => changeQty(key, -1)),
        countNode(line.count),
        qbtn('+', () => changeQty(key, +1)),
    );
    el.appendChild(qty);

    return el;
}

function qbtn(sign, onClick) {
    const b = document.createElement('button');
    b.type = 'button';
    b.className = 'oo-qbtn';
    b.textContent = sign;
    b.addEventListener('click', onClick);
    return b;
}

function countNode(n) {
    const s = document.createElement('span');
    s.className = 'oo-line__count';
    s.textContent = String(n);
    return s;
}

export function getOrderComment() {
    return document.getElementById('ooOrderComment')?.value?.trim() || '';
}
