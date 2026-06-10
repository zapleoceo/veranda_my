/* HTTP layer for /onlineorder — one fetch wrapper, CSRF on every
 * mutation, uniform {ok,...}/{ok:false,error} handling. */

const CSRF = document.querySelector('meta[name="csrf-token"]')?.content || window.__oo?.csrf || '';

async function request(url, options = {}) {
    const res = await fetch(url, {
        credentials: 'same-origin',
        ...options,
        headers: {
            'Accept': 'application/json',
            ...(options.body ? { 'Content-Type': 'application/json' } : {}),
            ...(options.method && options.method !== 'GET' ? { 'X-Csrf-Token': CSRF } : {}),
            ...(options.headers || {}),
        },
    });
    let json = null;
    try { json = await res.json(); } catch (_) { /* non-JSON → handled below */ }
    if (!res.ok || !json || json.ok === false) {
        const err = new Error(json?.error || `HTTP ${res.status}`);
        err.status = res.status;
        err.code = json?.error || null;
        throw err;
    }
    return json;
}

export const api = {
    /** → {categories:[], products:[]} */
    getMenu: () => request('/onlineorder/api/menu'),

    /** → {quote:{...}, resolved:{lat,lng,address}|null} */
    quote: (payload) => request('/onlineorder/api/quote', {
        method: 'POST',
        body: JSON.stringify(payload),
    }),

    /** → {order_id, total_vnd, quote, payment, dispatch} */
    createOrder: (payload) => request('/onlineorder/api/orders', {
        method: 'POST',
        body: JSON.stringify(payload),
    }),
};
