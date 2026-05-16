// Tiny REST client. Centralises CSRF, error shape, and Content-Type
// so individual ui/* modules stay one-screen long.

'use strict';

const JSON_HEADERS = {
    'Accept':       'application/json',
    'Content-Type': 'application/json',
};

let _csrf = '';
export function setCsrf(token) { _csrf = String(token || ''); }

async function request(method, url, body) {
    const init = { method, headers: { ...JSON_HEADERS } };
    if (_csrf) init.headers['X-CSRF-Token'] = _csrf;
    if (body !== undefined) init.body = JSON.stringify(body);
    const res = await fetch(url, init);
    let payload = null;
    try { payload = await res.json(); } catch (_) { /* non-JSON */ }
    if (!res.ok || !payload || payload.ok === false) {
        const msg = payload?.error || `HTTP ${res.status}`;
        const err = new Error(msg);
        err.status  = res.status;
        err.payload = payload;
        throw err;
    }
    return payload.data ?? null;
}

export const api = {
    get:    (url)        => request('GET',    url),
    post:   (url, body)  => request('POST',   url, body ?? {}),
    delete: (url)        => request('DELETE', url),
};
