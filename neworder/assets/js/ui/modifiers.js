// Modifier sheet — shown when the operator taps a product that has
// modifier groups (required single-pick) and/or optional add-on
// modifications. Builds the chosen-line shape and pushes it through
// state.addLine.
//
// Add button is disabled until every required group has a pick.

'use strict';

const _self = new URL(import.meta.url);
const _v    = _self.searchParams.get('v') || '';
const _qs   = _v ? '?v=' + encodeURIComponent(_v) : '';
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

let _activeProduct = null;
let _chosenModifierId = 0;
let _chosenAddOns = new Map(); // dish_modification_id → count

function recomputeTotal() {
    if (!_activeProduct) return;
    let base = _activeProduct.price || 0;
    if (_chosenModifierId) {
        for (const g of _activeProduct.modifier_groups || []) {
            const opt = (g.options || []).find((o) => o.id === _chosenModifierId);
            if (opt) { base = opt.price; break; }
        }
    }
    let addons = 0;
    for (const [id, count] of _chosenAddOns.entries()) {
        const ref = (_activeProduct.modifications || []).find((m) => m.id === id);
        if (ref) addons += (ref.price || 0) * count;
    }
    document.getElementById('noModifPrice').textContent = fmtVnd(base + addons);

    // Validate required groups.
    let ok = true;
    for (const g of _activeProduct.modifier_groups || []) {
        if (g.required && !(g.options || []).some((o) => o.id === _chosenModifierId)) ok = false;
    }
    document.getElementById('noModifAdd').disabled = !ok;
}

function render(product) {
    const root = document.getElementById('noModifBody');
    const title = document.getElementById('noModifTitle');
    title.textContent = product.name;

    const blocks = [];
    for (const g of (product.modifier_groups || [])) {
        const groupName = g.name || t('add');
        blocks.push(`
            <div class="no-modif__group" data-group-id="${g.id}">
                <h4>${esc(groupName)}${g.required ? '<span class="req">*</span>' : ''}</h4>
                ${(g.options || []).map((o) => `
                    <label class="no-modif__option" data-modifier-id="${o.id}">
                        <input type="radio" name="no-modif-${g.id}" value="${o.id}" hidden>
                        <span class="no-modif__option-name">${esc(o.name)}</span>
                        <span class="no-modif__option-price">${esc(fmtVnd(o.price))}</span>
                    </label>
                `).join('')}
            </div>`);
    }
    // Add-on modifications — grouped by group_name for readability.
    const addonGroups = new Map();
    for (const m of (product.modifications || [])) {
        const key = m.group_name || t('modifExtras');
        const arr = addonGroups.get(key) || [];
        arr.push(m);
        addonGroups.set(key, arr);
    }
    for (const [name, arr] of addonGroups.entries()) {
        blocks.push(`
            <div class="no-modif__group">
                <h4>${esc(name)}</h4>
                ${arr.map((m) => `
                    <div class="no-modif__addon-row" data-addon-id="${m.id}">
                        <span class="name">${esc(m.name)}</span>
                        <span class="price">+${esc(fmtVnd(m.price))}</span>
                        <div class="no-qty">
                            <button type="button" data-act="addon-dec" aria-label="Меньше">−</button>
                            <span class="no-qty__count" data-count>0</span>
                            <button type="button" data-act="addon-inc" aria-label="Больше">+</button>
                        </div>
                    </div>
                `).join('')}
            </div>`);
    }
    root.innerHTML = blocks.join('') || `<div class="no-empty">${esc(t('modifNoOptions'))}</div>`;
    recomputeTotal();
}

function open(product) {
    _activeProduct    = product;
    _chosenModifierId = 0;
    _chosenAddOns     = new Map();
    render(product);
    const sheet = document.getElementById('noModif');
    sheet.hidden = false;
    sheet.setAttribute('aria-hidden', 'false');
}
function close() {
    const sheet = document.getElementById('noModif');
    sheet.hidden = true;
    sheet.setAttribute('aria-hidden', 'true');
    _activeProduct = null;
}

export function initModifiers({ state }) {
    const sheet = document.getElementById('noModif');
    sheet.addEventListener('click', (e) => {
        if (e.target.closest('[data-no-close]')) { close(); return; }

        // Modifier option pick (single-choice radio).
        const opt = e.target.closest('.no-modif__option');
        if (opt) {
            _chosenModifierId = Number(opt.dataset.modifierId);
            sheet.querySelectorAll('.no-modif__option').forEach((el) => el.classList.remove('is-active'));
            opt.classList.add('is-active');
            recomputeTotal();
            return;
        }

        // Add-on +/−.
        const addonBtn = e.target.closest('[data-act^="addon-"]');
        if (addonBtn) {
            const row = addonBtn.closest('.no-modif__addon-row');
            const id  = Number(row.dataset.addonId);
            const cur = _chosenAddOns.get(id) || 0;
            const next = addonBtn.dataset.act === 'addon-inc' ? cur + 1 : Math.max(0, cur - 1);
            if (next === 0) _chosenAddOns.delete(id);
            else _chosenAddOns.set(id, next);
            row.querySelector('[data-count]').textContent = String(next);
            recomputeTotal();
        }
    });

    document.getElementById('noModifAdd').addEventListener('click', () => {
        if (!_activeProduct) return;
        const mods = [];
        for (const [id, count] of _chosenAddOns.entries()) mods.push({ id, count });
        state.addLine({
            product_id:     _activeProduct.id,
            count:          1,
            modificator_id: _chosenModifierId,
            modifications:  mods,
        });
        toast(_activeProduct.name + ' × 1');
        close();
    });

    return open;
}
