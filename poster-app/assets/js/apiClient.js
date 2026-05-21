// ApiClient — tiny fetch wrapper for /poster-app/api/*. Owns the
// 12h bearer token returned by /login and re-attaches it to every
// subsequent request as `Authorization: Bearer <token>`.
//
// We deliberately don't persist the token: refresh-the-page = re-
// login through Poster.users.getActiveUser(). Iframe localStorage
// is treated as third-party in some browsers (blocked entirely in
// Safari), and the cost of re-login is one Poster API call.

'use strict';

export class ApiClient {
    constructor(base) {
        this._base  = base.replace(/\/$/, '');
        this._token = '';
    }

    setToken(t) { this._token = String(t || ''); }
    clearToken() { this._token = ''; }
    hasToken()  { return this._token !== ''; }

    async login(body) {
        const j = await this._post('/login', body);
        if (j && typeof j.token === 'string') this._token = j.token;
        return j;
    }

    shiftStart(body) { return this._post('/shift-start', body); }
    shiftEnd(body)   { return this._post('/shift-end',   body); }

    async _post(path, body) {
        const headers = {
            'Content-Type': 'application/json',
            'Accept':       'application/json',
        };
        if (this._token) headers['Authorization'] = 'Bearer ' + this._token;
        const res = await fetch(this._base + path, {
            method:  'POST',
            headers,
            body:    JSON.stringify(body || {}),
            credentials: 'omit',     // no cookies, token-based
        });
        let j = null;
        try { j = await res.json(); } catch (_) { /* allow non-json bodies */ }
        if (!res.ok || !j || j.ok !== true) {
            throw new Error(j?.error || ('HTTP ' + res.status));
        }
        return j;
    }
}
