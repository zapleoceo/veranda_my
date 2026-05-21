// PosterBridge — thin wrapper around window.Poster injected by the
// POS container. Encapsulates the "wait for the SDK to land",
// "subscribe to events", "ask for the active user" surface so the
// rest of the widget doesn't have to know about polling or globals.
//
// Falls back to a no-op test mode when window.Poster never lands —
// the widget then exposes the manual buttons so a developer can
// still QA the backend from outside the POS.

'use strict';

export class PosterBridge {
    constructor(poster) {
        this._poster = poster;
    }

    /** Wait for window.Poster to be ready. Resolves with a PosterBridge or rejects on timeout. */
    static connect({ timeoutMs = 8000 } = {}) {
        return new Promise((resolve, reject) => {
            const t0 = Date.now();
            (function tick() {
                if (typeof window !== 'undefined' && window.Poster && typeof window.Poster.on === 'function') {
                    resolve(new PosterBridge(window.Poster));
                    return;
                }
                if (Date.now() - t0 > timeoutMs) {
                    reject(new Error('window.Poster not exposed within ' + timeoutMs + 'ms'));
                    return;
                }
                setTimeout(tick, 100);
            })();
        });
    }

    on(event, cb) {
        try { this._poster.on(event, cb); }
        catch (_) { /* unknown event — POS SDK rejects, ignore */ }
    }

    /** Resolves to a User object or `null` when no-one's logged in. */
    async getActiveUser() {
        try {
            const u = await Promise.resolve(this._poster.users?.getActiveUser?.());
            if (!u || u === false) return null;
            return u;
        } catch (_) { return null; }
    }
}
