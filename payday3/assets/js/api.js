// Tiny REST client. Centralises CSRF, error shape, and Content-Type
// so individual ui/* modules stay one-screen long.

'use strict';

const JSON_HEADERS = {
    'Accept':       'application/json',
    'Content-Type': 'application/json',
};

// The server requires X-CSRF-Token on every POST/DELETE under
// /payday3/api. index.js calls setCsrf() with the token from
// #pd3-bootstrap before any module can send a request; every mutating
// request in payday3 goes through this client (no raw fetch elsewhere).
let _csrf = '';
export function setCsrf(token) {
    _csrf = String(token || '');
    if (!_csrf) console.warn('[api] empty CSRF token — mutating requests will be rejected');
}

/**
 * @param {string} method
 * @param {string} url
 * @param {*} [body]
 * @param {{keepalive?:boolean}} [opts]  keepalive — survive page unload
 *   (beforeunload saves; unlike sendBeacon it can carry the CSRF header).
 */
async function request(method, url, body, { keepalive = false } = {}) {
    const init = { method, headers: { ...JSON_HEADERS } };
    if (_csrf) init.headers['X-CSRF-Token'] = _csrf;
    if (body !== undefined) init.body = JSON.stringify(body);
    if (keepalive) init.keepalive = true;
    const res = await fetch(url, init);

    // Auth expired / never had a session: AuthMiddleware returns 401
    // JSON for AJAX callers (instead of 302 redirect). Send the
    // operator to /login and bring them back to whatever page they
    // were on. Throw afterwards so .then() chains halt cleanly.
    if (res.status === 401) {
        let loginUrl = '/login';
        try {
            const p = await res.clone().json();
            if (p && typeof p.login_url === 'string') loginUrl = p.login_url;
        } catch (_) { /* not JSON, keep default */ }
        const next = encodeURIComponent(location.pathname + location.search);
        const sep  = loginUrl.includes('?') ? '&' : '?';
        location.href = loginUrl + sep + 'next=' + next;
        throw new Error('Auth required');
    }

    // Always read the body — re-use the cloned response so we can
    // inspect raw text when JSON parsing fails.
    const rawText = await res.clone().text();
    let payload = null;
    try { payload = JSON.parse(rawText); } catch (_) { /* non-JSON */ }
    if (!res.ok || !payload || payload.ok === false) {
        const msg = (payload && typeof payload.error === 'string' && payload.error)
            ? payload.error
            : `HTTP ${res.status}` + (rawText ? ' — ' + rawText.slice(0, 200) : '');
        const err = new Error(msg);
        err.status  = res.status;
        err.payload = payload;
        err.raw     = rawText;
        console.error('[api]', method, url, '→', res.status, payload ?? rawText);
        throw err;
    }
    return payload.data ?? null;
}

export const api = {
    get:    (url)        => request('GET',    url),
    post:   (url, body, opts) => request('POST', url, body ?? {}, opts),
    delete: (url)        => request('DELETE', url),
};
