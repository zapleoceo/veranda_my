// Open-checks inline banner inside the cart panel.
//
// On every table change we re-poll /open-checks. If there are any, the
// operator gets a radio choice right above the order comment:
//   ( ) Новый отдельный заказ
//   ( ) Добавить к чеку #X (480 000 ₫) · Pho × 2 · …
// Picking an existing check sets state.appendToTx; the submit path
// branches on that.

'use strict';

const _self = new URL(import.meta.url);
const _v    = _self.searchParams.get('v') || '';
const _qs   = _v ? '?v=' + encodeURIComponent(_v) : '';
const { api } = await import(new URL('../api.js' + _qs, import.meta.url).href);
const { t }   = await import(new URL('../i18n.js' + _qs, import.meta.url).href);

const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
})[c]);
const fmtVnd = (n) => {
    const v = Math.round(Number(n) || 0);
    try { return new Intl.NumberFormat('en-US', { maximumFractionDigits: 0 }).format(v).replace(/,/g, ' ') + ' ₫'; }
    catch (_) { return String(v) + ' ₫'; }
};

function render(state) {
    const wrap = document.getElementById('noOpenCheck');
    if (!wrap) return;
    const checks = state.s.openChecks || [];
    if (!state.s.tableId || !checks.length) {
        wrap.hidden = true;
        wrap.innerHTML = '';
        return;
    }
    wrap.hidden = false;
    const newSelected = state.s.appendToTx === 0;
    const headline = checks.length === 1
        ? t('openCheckOne')
        : t('openCheckMany', { n: checks.length });
    wrap.innerHTML = `
        <h4>${esc(headline)}</h4>
        <div class="no-radios">
            <label class="no-radio ${newSelected ? 'is-active' : ''}">
                <input type="radio" name="no-openchk" value="0" ${newSelected ? 'checked' : ''}>
                <div>
                    <div class="no-radio__title">${esc(t('newSeparateOrder'))}</div>
                </div>
            </label>
            ${checks.map((c) => `
                <label class="no-radio ${state.s.appendToTx === c.transaction_id ? 'is-active' : ''}">
                    <input type="radio" name="no-openchk" value="${c.transaction_id}" ${state.s.appendToTx === c.transaction_id ? 'checked' : ''}>
                    <div>
                        <div class="no-radio__title">${esc(t('addToCheckTpl', { id: c.transaction_id, sum: fmtVnd(c.sum) }))}</div>
                        ${c.items?.length ? `<div class="no-radio__sub">${esc(c.items.slice(0, 3).join(' · '))}${c.items.length > 3 ? '…' : ''}</div>` : ''}
                    </div>
                </label>
            `).join('')}
        </div>`;
}

export function initOpenChecks({ state }) {
    state.on(() => render(state));
    render(state);

    document.getElementById('noOpenCheck')?.addEventListener('change', (e) => {
        if (!(e.target instanceof HTMLInputElement)) return;
        if (e.target.name !== 'no-openchk') return;
        state.setAppendToTx(Number(e.target.value) || 0);
    });

    // Refresh fn called externally when table changes.
    let _seq = 0;
    return async function refresh() {
        const myCall = ++_seq;
        if (!state.s.spotId || !state.s.tableId) {
            state.setOpenChecks([]);
            return;
        }
        try {
            const r = await api.openChecks(state.s.spotId, state.s.tableId);
            if (myCall !== _seq) return;   // newer call superseded us
            state.setOpenChecks(r.checks || []);
        } catch (e) {
            console.error('[no:open-checks]', e);
            state.setOpenChecks([]);
        }
    };
}
