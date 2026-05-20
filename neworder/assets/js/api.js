// HTTP wrappers for /neworder endpoints. GETs are bare; POSTs carry
// the CSRF token read from the <meta name="csrf-token"> tag and include
// credentials so the same-origin session is in scope.

'use strict';

const BASE = '/neworder/api';

export function csrfToken() {
    return document.querySelector('meta[name=csrf-token]')?.content || '';
}

async function request(method, path, body) {
    const headers = { 'Accept': 'application/json' };
    let init = {
        method,
        headers,
        credentials: 'same-origin',
    };
    if (body !== undefined) {
        headers['Content-Type'] = 'application/json';
        headers['X-Csrf-Token'] = csrfToken();
        init.body = JSON.stringify(body);
    }
    const r = await fetch(BASE + path, init);
    let j = null;
    try { j = await r.json(); } catch (_) { /* non-json body, fall through */ }
    if (!r.ok || !j || j.ok !== true) {
        const msg = j?.error || (`HTTP ${r.status}`);
        throw new Error(msg);
    }
    return j;
}

export const api = {
    menu:        ()                       => request('GET',  '/menu'),
    locations:   ()                       => request('GET',  '/locations'),
    openChecks:  (spotId, tableId)        => request('GET',  `/open-checks?spot_id=${encodeURIComponent(spotId)}&table_id=${encodeURIComponent(tableId)}`),
    createOrder: (payload)                => request('POST', '/orders',        payload),
    appendOrder: (payload)                => request('POST', '/orders/append', payload),
};
