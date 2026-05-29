// Submit handler — picks the right endpoint based on state.appendToTx,
// validates pre-flight, toggles the spinner, drives the success screen.

'use strict';

const _self = new URL(import.meta.url);
const _v    = _self.searchParams.get('v') || '';
const _qs   = _v ? '?v=' + encodeURIComponent(_v) : '';
const { api }   = await import(new URL('../api.js'  + _qs, import.meta.url).href);
const { toast } = await import(new URL('./toast.js' + _qs, import.meta.url).href);
const { t }     = await import(new URL('../i18n.js' + _qs, import.meta.url).href);

function showSuccess(text) {
    const $s   = document.getElementById('noSuccess');
    const $sub = document.getElementById('noSuccessSub');
    $sub.textContent = text || '';
    $s.hidden = false;
    $s.setAttribute('aria-hidden', 'false');
}
function hideSuccess() {
    const $s = document.getElementById('noSuccess');
    $s.hidden = true;
    $s.setAttribute('aria-hidden', 'true');
}

function closeCart() {
    const $cart = document.getElementById('noCart');
    $cart.hidden = true;
    $cart.setAttribute('aria-hidden', 'true');
}

export function initSubmit({ state, openCart, refreshOpenChecks }) {
    document.getElementById('noNewOrderBtn')?.addEventListener('click', async () => {
        hideSuccess();
        // Allow another order on the same table — only clear the cart,
        // keep spot/hall/table so the operator stays in context. Then
        // refresh open-checks so the next order sees fresh state
        // (otherwise stale data could hide a freshly-created check
        // we just opened ourselves a moment ago).
        state.clearCart();
        try { await refreshOpenChecks?.(); } catch (_) {}
    });

    return async function submit() {
        // Pre-flight.
        if (!state.cart.length) {
            toast(t('cartEmpty'), { error: true });
            return;
        }
        if (!state.s.tableId) {
            toast(t('selectTable'), { error: true });
            // Open the location picker for them.
            document.getElementById('noLocationBtn')?.click();
            return;
        }
        // Open-check guard. If the selected table has any open checks
        // and the operator hasn't explicitly picked a radio (new vs
        // append), refuse to submit until they do — otherwise it's
        // too easy to accidentally create a second check on a busy
        // table because the cart was never opened.
        if (state.s.openChecks.length > 0 && !state.s.openCheckChoiceMade) {
            toast(t('openCheckPickFirst'), { error: true });
            openCart?.();   // force the banner into view
            return;
        }

        const btn = document.getElementById('noSubmitBtn');
        const errBox = document.getElementById('noCartError');
        errBox.hidden = true;
        errBox.textContent = '';
        btn.classList.add('is-busy');
        btn.disabled = true;

        const payload = {
            spot_id:  state.s.spotId,
            table_id: state.s.tableId,
            comment:  state.s.comment,
            items:    state.cart,
        };

        try {
            let result, message;
            if (state.s.appendToTx > 0) {
                result = await api.appendOrder({
                    ...payload,
                    spot_tablet_id: state.s.spotTabletId,
                    transaction_id: state.s.appendToTx,
                });
                message = t('orderAppendedTpl', { n: result.added, id: state.s.appendToTx });
            } else {
                result = await api.createOrder(payload);
                message = t('orderAcceptedTpl', { id: result.order_id });
            }
            // Clear cart but keep the location — next order on the same
            // table starts in one tap. Then refresh open-checks so a
            // brand-new check we just created (or a check that just
            // got appended to) is visible in the banner on next open.
            state.clearCart();
            closeCart();
            showSuccess(message);
            try { await refreshOpenChecks?.(); } catch (_) {}
        } catch (e) {
            errBox.textContent = e.message || t('errorGeneric');
            errBox.hidden = false;
            toast(e.message || t('errorGeneric'), { error: true });
        } finally {
            btn.classList.remove('is-busy');
            btn.disabled = false;
        }
    };
}
