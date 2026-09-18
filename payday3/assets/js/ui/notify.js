// Non-blocking toast for background failures (a reload that failed but
// kept the previous rows on screen, etc.) — unlike alert() it neither
// blocks the page nor wipes anything.

'use strict';

/**
 * @param {string} msg
 * @param {'info'|'warn'|'error'} [kind]
 * @param {{timeout?:number}} [opts]
 */
export function notify(msg, kind = 'info', { timeout = 6000 } = {}) {
    let host = document.getElementById('pd3Toasts');
    if (!host) {
        host = document.createElement('div');
        host.id = 'pd3Toasts';
        host.className = 'pd3-toasts';
        host.setAttribute('role', 'status');
        host.setAttribute('aria-live', 'polite');
        document.body.appendChild(host);
    }
    const el = document.createElement('div');
    el.className = 'pd3-toast pd3-toast--' + kind;
    el.textContent = String(msg ?? '');
    host.appendChild(el);
    setTimeout(() => el.remove(), timeout);
    return el;
}
