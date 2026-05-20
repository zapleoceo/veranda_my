// Submit handler — picks the right endpoint based on state.appendToTx,
// validates pre-flight, toggles the spinner, drives the success screen.

'use strict';

const _self = new URL(import.meta.url);
const _v    = _self.searchParams.get('v') || '';
const _qs   = _v ? '?v=' + encodeURIComponent(_v) : '';
const { api }   = await import(new URL('../api.js' + _qs, import.meta.url).href);
const { toast } = await import(new URL('./toast.js' + _qs, import.meta.url).href);

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

export function initSubmit({ state, openCart }) {
    document.getElementById('noNewOrderBtn')?.addEventListener('click', () => {
        hideSuccess();
        // Allow another order on the same table — only clear the cart,
        // keep spot/hall/table so the operator stays in context.
        state.clearCart();
    });

    return async function submit() {
        // Pre-flight.
        if (!state.cart.length) {
            toast('Корзина пуста', { error: true });
            return;
        }
        if (!state.s.tableId) {
            toast('Выберите стол', { error: true });
            // Open the location picker for them.
            document.getElementById('noLocationBtn')?.click();
            return;
        }
        // We used to bail here when state.s.spotTabletId was 0, but
        // spots.getSpot doesn't actually expose spot_tablet_id — the
        // backend now falls back to a configured default so we just
        // forward whatever value we have (0 ⇒ backend default).

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
                message = `Добавлено ${result.added} поз. к чеку #${state.s.appendToTx}`;
            } else {
                result = await api.createOrder(payload);
                message = `Заказ #${result.order_id} принят в Poster`;
            }
            // Clear cart but keep the location — next order on the same
            // table starts in one tap.
            state.clearCart();
            closeCart();
            showSuccess(message);
        } catch (e) {
            errBox.textContent = e.message || 'Ошибка';
            errBox.hidden = false;
            toast(e.message || 'Ошибка', { error: true });
        } finally {
            btn.classList.remove('is-busy');
            btn.disabled = false;
        }
    };
}
