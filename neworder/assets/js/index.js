// /neworder bootstrap.
//
// Wires the small focused modules together — there's no monolith
// here, every concern lives in ./ui/<name>.js. Cross-module imports
// inherit the cache-bust query string from this file's own URL.

'use strict';

const _self = new URL(import.meta.url);
const _v    = _self.searchParams.get('v') || '';
const _qs   = _v ? '?v=' + encodeURIComponent(_v) : '';
const _i    = (p) => import(new URL(p + _qs, import.meta.url).href);

const [
    { State },
    { api, csrfToken },
    { initMenu },
    { initSearch },
    { initCart },
    { initModifiers },
    { initLocationPicker },
    { initOpenChecks },
    { initSubmit },
    { toast },
    { t },
] = await Promise.all([
    _i('./state.js'),
    _i('./api.js'),
    _i('./ui/menu.js'),
    _i('./ui/search.js'),
    _i('./ui/cart.js'),
    _i('./ui/modifiers.js'),
    _i('./ui/locationPicker.js'),
    _i('./ui/openChecks.js'),
    _i('./ui/submit.js'),
    _i('./ui/toast.js'),
    _i('./i18n.js'),
]);

// Single source of truth for the running state — held in memory + mirrored
// to localStorage for the cart, location, comment so a refresh keeps the
// operator's progress.
const state = new State();
state.restore();
window.__no = state;   // dev-tools peek hook; remove later

// Modules wire themselves to DOM nodes by id — they receive `state` and
// each other's public hooks (open/close/refresh) as needed.
const refreshMenu       = await initMenu({ state });
initSearch({ state });
const openCart          = initCart({ state, onSubmit: () => submit() });
const openModif         = initModifiers({ state });
// refreshOpenChecks must be defined BEFORE locationPicker (which captures it)
// — without this order JS still works at runtime because the arrow runs after
// init completes, but the dependency direction is clearer this way.
const refreshOpenChecks = initOpenChecks({ state });
const openLoc           = initLocationPicker({ state, onChange: () => refreshOpenChecks() });
const submit            = initSubmit({ state, openCart });

// Cart bar opens the sheet; the sheet has its own close handlers.
document.getElementById('noCartBar')?.addEventListener('click', () => openCart());
document.getElementById('noLocationBtn')?.addEventListener('click', () => openLoc());

// Manual refresh of the menu (top-right icon).
document.getElementById('noMenuRefreshBtn')?.addEventListener('click', () => {
    refreshMenu();
    toast(t('menuRefreshing'));
});

// Hook item-click globally (event delegation) — modifier sheet decides
// whether to open or add straight to the cart.
document.getElementById('noMenu').addEventListener('click', (e) => {
    const btn = e.target.closest('.no-item');
    if (!btn) return;
    const id = Number(btn.dataset.productId);
    const product = state.findProduct(id);
    if (!product) return;
    const needsPicker =
        (product.modifier_groups && product.modifier_groups.length > 0) ||
        (product.modifications   && product.modifications.length   > 0);
    if (needsPicker) {
        openModif(product);
    } else {
        state.addLine({ product_id: id, count: 1 });
        toast(product.name + ' × 1');
    }
});

console.info('[neworder] ready', { v: _v || '(none)', csrf: csrfToken().slice(0, 6) + '…' });
