/* Checkout sheet: contact + address form, debounced live delivery
 * quote, validation, submit, and the success screen with the
 * pre-filled bank QR. */

import { api } from '../api.js';
import { cartPayload, cartTotal, clearCart, fmtVnd, t } from '../state.js';
import { openSheet, closeSheet } from './menu.js';
import { getOrderComment } from './cart.js';
import { attachAutocomplete } from './places.js';
import { toast } from './toast.js';

const els = {};
let resolvedPoint = null;   // {lat,lng,address} from Places or /api/quote
let lastQuote = null;       // last server quote (display only — server re-quotes at submit)
let quoteTimer = null;
let quoteSeq = 0;

export function initCheckout() {
    els.sheet     = document.getElementById('ooCheckout');
    els.form      = document.getElementById('ooCheckoutForm');
    els.name      = document.getElementById('ooName');
    els.phone     = document.getElementById('ooPhone');
    els.address   = document.getElementById('ooAddress');
    els.apartment = document.getElementById('ooApartment');
    els.note      = document.getElementById('ooNote');
    els.quote     = document.getElementById('ooQuote');
    els.summary   = document.getElementById('ooSummary');
    els.submit    = document.getElementById('ooSubmit');
    els.error     = document.getElementById('ooFormError');
    els.success   = document.getElementById('ooSuccess');

    // Places autocomplete (graceful: plain input when no key).
    attachAutocomplete(els.address, (place) => {
        resolvedPoint = place;
        els.address.value = place.address;
        requestQuote(0);
    });

    // Typing invalidates the previously resolved pin → re-quote by text.
    els.address.addEventListener('input', () => {
        resolvedPoint = null;
        requestQuote(900);
    });

    els.form.addEventListener('submit', (e) => {
        e.preventDefault();
        submit();
    });

    document.getElementById('ooNewOrder').addEventListener('click', () => {
        els.success.hidden = true;
        document.body.style.overflow = '';
    });

    // restore the previous contact details — returning customers
    try {
        const saved = JSON.parse(localStorage.getItem('oo_contact') || 'null');
        if (saved) {
            els.name.value      = saved.name || '';
            els.phone.value     = saved.phone || '';
            els.address.value   = saved.address || '';
            els.apartment.value = saved.apartment || '';
            els.note.value      = saved.note || '';
        }
    } catch (_) { /* localStorage unavailable — fine */ }
}

export function openCheckout() {
    renderSummary();
    renderQuote();
    openSheet(els.sheet);
    if (els.address.value.trim() && !lastQuote) requestQuote(0);
}

// ─── Live quote ───────────────────────────────────────────────────
function requestQuote(delayMs) {
    clearTimeout(quoteTimer);
    const text = els.address.value.trim();
    if (!text && !resolvedPoint) { lastQuote = null; renderQuote(); return; }

    quoteTimer = setTimeout(async () => {
        const seq = ++quoteSeq;
        renderQuote('loading');
        try {
            const payload = { address: text };
            if (resolvedPoint) payload.point = { lat: resolvedPoint.lat, lng: resolvedPoint.lng };
            const r = await api.quote(payload);
            if (seq !== quoteSeq) return;            // a newer request superseded us
            lastQuote = r.quote;
            if (r.resolved && !resolvedPoint) resolvedPoint = r.resolved;
            renderQuote();
            renderSummary();
        } catch (e) {
            if (seq !== quoteSeq) return;
            lastQuote = null;
            renderQuote('error');
        }
    }, delayMs);
}

function renderQuote(stateOverride) {
    const q = els.quote;
    q.hidden = false;
    q.className = 'oo-quote';
    q.replaceChildren();

    if (stateOverride === 'loading') {
        q.classList.add('oo-quote--muted');
        q.textContent = t('quoteCalculating');
        return;
    }
    if (!els.address.value.trim() && !resolvedPoint) { q.hidden = true; return; }

    if (stateOverride === 'error' || !lastQuote) {
        q.classList.add('oo-quote--muted');
        q.textContent = t('quoteUnavailable');
        return;
    }

    if (!lastQuote.available) {
        const reason = lastQuote.reason || '';
        if (reason === 'out_of_zone') {
            q.classList.add('oo-quote--error');
            q.textContent = t('quoteOutOfZone', { km: window.__oo.cfg.max_radius_km });
        } else if (reason === 'geocode_failed') {
            q.classList.add('oo-quote--muted');
            q.textContent = t('quoteGeocodeFail');
        } else {
            q.classList.add('oo-quote--muted');
            q.textContent = t('quoteUnavailable');
        }
        return;
    }

    const row = document.createElement('div');
    row.className = 'oo-quote__row';
    const label = document.createElement('span');
    label.textContent = t('quoteRow', { provider: lastQuote.provider });
    const fee = document.createElement('span');
    fee.className = 'oo-quote__fee';
    fee.textContent = fmtVnd(lastQuote.fee_vnd);
    row.append(label, fee);
    q.appendChild(row);

    const metaParts = [];
    if (lastQuote.distance_km != null) metaParts.push(t('quoteDistanceTpl', { km: lastQuote.distance_km }));
    if (lastQuote.eta_minutes != null) metaParts.push(t('quoteEtaTpl', { min: lastQuote.eta_minutes }));
    if (metaParts.length) {
        const meta = document.createElement('div');
        meta.className = 'oo-quote__meta';
        meta.textContent = metaParts.join(' · ');
        q.appendChild(meta);
    }
}

function renderSummary() {
    const total = cartTotal();
    els.summary.replaceChildren();

    els.summary.appendChild(summaryRow(t('foodTotal'), fmtVnd(total)));
    if (lastQuote?.available) {
        els.summary.appendChild(summaryRow(t('deliveryRow'), fmtVnd(lastQuote.fee_vnd) + ' →🛵'));
    }
    const totalRow = summaryRow(t('total'), fmtVnd(total));
    totalRow.classList.add('oo-summary__row--total');
    els.summary.appendChild(totalRow);

    const min = window.__oo.cfg.min_order_vnd || 0;
    if (min > 0 && total < min) {
        const warn = document.createElement('div');
        warn.className = 'oo-quote--error oo-quote';
        warn.textContent = t('minOrderTpl', { sum: fmtVnd(min) });
        els.summary.appendChild(warn);
    }
}

function summaryRow(label, value) {
    const row = document.createElement('div');
    row.className = 'oo-summary__row';
    const l = document.createElement('span');
    l.textContent = label;
    const v = document.createElement('strong');
    v.textContent = value;
    row.append(l, v);
    return row;
}

// ─── Validation + submit ─────────────────────────────────────────
function validate() {
    let ok = true;
    const mark = (input, errId, bad) => {
        input.closest('.oo-field').classList.toggle('is-invalid', bad);
        document.getElementById(errId).hidden = !bad;
        if (bad) ok = false;
    };
    mark(els.name, 'ooNameErr', els.name.value.trim() === '');
    const digits = els.phone.value.replace(/\D+/g, '');
    mark(els.phone, 'ooPhoneErr', digits.length < 8 || digits.length > 15);
    mark(els.address, 'ooAddressErr', els.address.value.trim() === '' && !resolvedPoint);
    return ok;
}

async function submit() {
    els.error.hidden = true;
    if (!validate()) return;

    const min = window.__oo.cfg.min_order_vnd || 0;
    if (min > 0 && cartTotal() < min) {
        showError(t('minOrderTpl', { sum: fmtVnd(min) }));
        return;
    }

    const payload = {
        customer: {
            name:  els.name.value.trim(),
            phone: els.phone.value.trim(),
        },
        address: {
            address:   els.address.value.trim(),
            apartment: els.apartment.value.trim(),
            note:      els.note.value.trim(),
        },
        comment: getOrderComment(),
        items:   cartPayload(),
        website: document.getElementById('ooWebsite').value, // honeypot
    };
    if (resolvedPoint) payload.address.point = { lat: resolvedPoint.lat, lng: resolvedPoint.lng };

    els.submit.disabled = true;
    els.submit.classList.add('is-busy');
    try {
        const r = await api.createOrder(payload);
        persistContact();
        clearCart();
        closeSheet(els.sheet);
        showSuccess(r);
    } catch (e) {
        showError(errorMessage(e));
    } finally {
        els.submit.disabled = false;
        els.submit.classList.remove('is-busy');
    }
}

function persistContact() {
    try {
        localStorage.setItem('oo_contact', JSON.stringify({
            name:      els.name.value.trim(),
            phone:     els.phone.value.trim(),
            address:   els.address.value.trim(),
            apartment: els.apartment.value.trim(),
            note:      els.note.value.trim(),
        }));
    } catch (_) { /* private mode — fine */ }
}

function errorMessage(e) {
    if (e.status === 429) return t('throttled');
    switch (e.code) {
        case 'out_of_zone':      return t('quoteOutOfZone', { km: window.__oo.cfg.max_radius_km });
        case 'min_order':        return t('minOrderTpl', { sum: fmtVnd(window.__oo.cfg.min_order_vnd) });
        case 'customer_invalid': return t('phoneInvalid');
        case 'address_invalid':  return t('addressMissing');
        case 'cart_empty':       return t('cartInvalid');
        default:
            if ((e.code || '').startsWith('unknown_')) return t('cartInvalid');
            return t('submitError');
    }
}

function showError(msg) {
    els.error.textContent = msg;
    els.error.hidden = false;
    toast(msg);
}

// ─── Success + payment QR ─────────────────────────────────────────
function showSuccess(r) {
    document.getElementById('ooSuccessSub').textContent =
        t('orderAcceptedTpl', { id: r.order_id });

    const payBox = document.getElementById('ooPay');
    payBox.replaceChildren();

    if (r.payment && r.payment.qr_url) {
        const h = document.createElement('h3');
        h.textContent = t('payTitle');
        payBox.appendChild(h);

        const qrWrap = document.createElement('div');
        qrWrap.className = 'oo-pay__qrwrap';
        const img = document.createElement('img');
        img.src = r.payment.qr_url;
        img.alt = 'VietQR';
        qrWrap.appendChild(img);
        payBox.appendChild(qrWrap);

        const hint = document.createElement('p');
        hint.className = 'oo-pay__hint';
        hint.textContent = t('payInstruction');
        payBox.appendChild(hint);

        payBox.appendChild(payRow(t('payAmount'), fmtVnd(r.payment.amount_vnd), true));
        payBox.appendChild(payRow(t('payReference'), r.payment.reference, false, true));
        if (r.payment.account_name) payBox.appendChild(payRow(t('payAccountName'), r.payment.account_name));
        payBox.appendChild(payRow(t('payAccount'), r.payment.account));

        const note = document.createElement('p');
        note.className = 'oo-pay__hint';
        note.textContent = t('payPendingNote');
        payBox.appendChild(note);

        payBox.hidden = false;
    } else {
        const h = document.createElement('p');
        h.className = 'oo-pay__hint';
        h.textContent = t('payNotConfigured');
        payBox.appendChild(h);
        payBox.hidden = false;
    }

    const courier = [t('deliveryCourier')];
    if (r.dispatch && r.dispatch.tracking_id) courier.push(t('dispatchOk'));
    document.getElementById('ooSuccessCourier').textContent = courier.join(' ');

    els.success.hidden = false;
    els.success.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
    window.scrollTo(0, 0);
}

function payRow(label, value, strong = false, code = false) {
    const row = document.createElement('div');
    row.className = 'oo-pay__row';
    const l = document.createElement('span');
    l.textContent = label;
    let v;
    if (code) {
        v = document.createElement('code');
        v.textContent = value;
    } else if (strong) {
        v = document.createElement('b');
        v.textContent = value;
    } else {
        v = document.createElement('span');
        v.textContent = value;
    }
    row.append(l, v);
    return row;
}
