/* /onlineorder bootstrap: fetch the live Poster menu, wire up the
 * modules, handle the generic sheet-close clicks. */

import { api } from './api.js';
import { setMenu, t } from './state.js';
import { initMenu, renderAll, closeSheet } from './ui/menu.js';
import { initCart } from './ui/cart.js';
import { initCheckout, openCheckout } from './ui/checkout.js';
import { toast } from './ui/toast.js';

function wireSheetClosers() {
    document.querySelectorAll('[data-oo-close]').forEach((el) => {
        el.addEventListener('click', () => {
            const sheet = el.closest('.oo-sheet');
            if (sheet) closeSheet(sheet);
        });
    });
    document.addEventListener('keydown', (e) => {
        if (e.key !== 'Escape') return;
        document.querySelectorAll('.oo-sheet:not([hidden])').forEach((s) => closeSheet(s));
    });
}

async function boot() {
    initMenu();
    initCart(openCheckout);
    initCheckout();
    wireSheetClosers();

    try {
        const data = await api.getMenu();
        setMenu({ categories: data.categories || [], products: data.products || [] });
        renderAll();
    } catch (e) {
        console.error('[onlineorder] menu load failed', e);
        const menu = document.getElementById('ooMenu');
        menu.replaceChildren();
        const err = document.createElement('div');
        err.className = 'oo-empty';
        err.textContent = t('menuLoadError');
        menu.appendChild(err);
        toast(t('menuLoadError'));
    }
}

boot();
