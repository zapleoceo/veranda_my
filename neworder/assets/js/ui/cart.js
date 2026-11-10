// Cart sheet — list of lines + footer (comment + total + submit).
//
// Lines combine on add-to-cart (state side); here we only render the
// current snapshot and expose qty +/− / delete / per-item comment.

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

// Lines whose comment box the operator opened (UI-only state).
const commentOpen = new WeakSet();

function renderBar(state) {
    const bar   = document.getElementById('noCartBar');
    const count = document.getElementById('noCartBarCount');
    const sum   = document.getElementById('noCartBarSum');
    if (!bar || !count || !sum) return;
    const n = state.cartCount;
    bar.hidden = n <= 0;
    count.textContent = String(n);
    sum.textContent   = fmtVnd(state.cartTotal);
}

function lineSubtitle(state, line) {
    const p = state.findProduct(line.product_id);
    if (!p) return '';
    const parts = [];
    if (line.modificator_id) {
        for (const g of (p.modifier_groups || [])) {
            const opt = (g.options || []).find((o) => o.id === Number(line.modificator_id));
            if (opt) { parts.push(opt.name); break; }
        }
    }
    for (const m of (line.modifications || [])) {
        const ref = (p.modifications || []).find((x) => x.id === Number(m.id));
        if (ref) parts.push((m.count > 1 ? (m.count + '× ') : '') + ref.name);
    }
    return parts.join(' · ');
}

function renderItems(state) {
    const root = document.getElementById('noCartItems');
    const totalEl = document.getElementById('noCartTotal');
    const submitBtn = document.getElementById('noSubmitBtn');
    if (!root || !totalEl) return;

    if (!state.cart.length) {
        root.innerHTML = `<div class="no-empty" style="padding:24px 0">${esc(t('cartEmpty'))}</div>`;
        totalEl.textContent = '0 ₫';
        submitBtn.disabled = true;
        return;
    }

    const lines = state.cart.map((line, idx) => {
        const product = state.findProduct(line.product_id);
        const name = product ? product.name : ('Товар #' + line.product_id);
        const sub  = lineSubtitle(state, line);
        const linePrice = state._linePrice(line);
        const lineTotal = linePrice * (Number(line.count) || 0);
        // The comment box is shown when the line has a note or the operator
        // opened it. The open state lives here (not in the line), so a
        // toggle never writes junk into the order payload.
        const showComment = (line.comment || '').trim() !== '' || commentOpen.has(line);
        return `
            <div class="no-cart__item" data-idx="${idx}">
                <div>
                    <div class="no-cart__item-title">${esc(name)}</div>
                    ${sub ? `<div class="no-cart__item-sub">${esc(sub)}</div>` : ''}
                </div>
                <div class="no-cart__item-price">${esc(fmtVnd(lineTotal))}</div>
                <div class="no-cart__item-actions">
                    <div class="no-qty">
                        <button type="button" data-act="dec" aria-label="${esc(t('qtyLess'))}">−</button>
                        <span class="no-qty__count">${Number(line.count)}</span>
                        <button type="button" data-act="inc" aria-label="${esc(t('qtyMore'))}">+</button>
                    </div>
                    <button type="button" class="no-cart__item-cmt-btn" data-act="cmt" title="${esc(t('itemComment'))}" aria-pressed="${showComment}">
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>
                    </button>
                </div>
                ${showComment ? `<textarea class="no-cart__item-comment" rows="1" data-act="cmt-input" placeholder="${esc(t('itemCommentPh'))}">${esc(line.comment)}</textarea>` : ''}
            </div>`;
    }).join('');
    root.innerHTML = lines;
    totalEl.textContent = fmtVnd(state.cartTotal);
    submitBtn.disabled  = false;
}

function open(state) {
    const $cart = document.getElementById('noCart');
    $cart.hidden = false;
    $cart.setAttribute('aria-hidden', 'false');
    // Sync comment textarea to state value.
    const cmt = document.getElementById('noOrderComment');
    if (cmt) cmt.value = state.s.comment || '';
}
function close() {
    const $cart = document.getElementById('noCart');
    $cart.hidden = true;
    $cart.setAttribute('aria-hidden', 'true');
}

export function initCart({ state, onSubmit }) {
    state.on(() => { renderBar(state); renderItems(state); });
    renderBar(state);
    renderItems(state);

    // Close handlers — any [data-no-close] inside .no-cart.
    document.getElementById('noCart').addEventListener('click', (e) => {
        if (e.target.closest('[data-no-close]')) close();
    });

    // Delegated line actions.
    document.getElementById('noCartItems').addEventListener('click', (e) => {
        const row = e.target.closest('.no-cart__item');
        if (!row) return;
        const idx = Number(row.dataset.idx);
        const act = e.target.closest('button')?.dataset.act;
        if (!act) return;
        const line = state.cart[idx];
        if (!line) return;
        // "−" on the last piece removes the line, so there is no separate
        // delete button.
        if (act === 'inc') state.setLineCount(idx, (Number(line.count) || 0) + 1);
        else if (act === 'dec') state.setLineCount(idx, (Number(line.count) || 0) - 1);
        else if (act === 'cmt') {
            if (commentOpen.has(line) || (line.comment || '').trim() !== '') {
                // Closing the box also drops the note.
                commentOpen.delete(line);
                state.setLineComment(idx, '');
            } else {
                commentOpen.add(line);
                state._emit?.();   // re-render to show the empty textarea
            }
        }
    });
    document.getElementById('noCartItems').addEventListener('input', (e) => {
        const t = e.target;
        if (!(t instanceof HTMLTextAreaElement)) return;
        if (t.dataset.act !== 'cmt-input') return;
        const idx = Number(t.closest('.no-cart__item')?.dataset.idx ?? -1);
        if (idx >= 0) {
            // Keep the box open while it is being edited, even if it is cleared.
            if (state.cart[idx]) commentOpen.add(state.cart[idx]);
            state.patchLineComment(idx, t.value);
        }
    });

    // Order-level comment textarea.
    document.getElementById('noOrderComment')?.addEventListener('input', (e) => {
        state.setComment(e.target.value);
    });

    // Submit forward to bootstrap-provided handler.
    document.getElementById('noSubmitBtn')?.addEventListener('click', async () => {
        if (!state.cart.length) {
            toast(t('cartEmpty'), { error: true });
            return;
        }
        try { await onSubmit?.(); } catch (e) { toast(e.message || t('errorGeneric'), { error: true }); }
    });

    // Public open hook for the cart bar.
    return () => open(state);
}
