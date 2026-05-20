// Tiny non-blocking toast. One instance, replaces text on each call.

'use strict';

const EL = () => document.getElementById('noToast');
let _timer = 0;

export function toast(msg, opts = {}) {
    const el = EL();
    if (!el) return;
    el.textContent = String(msg || '');
    el.classList.toggle('is-error', !!opts.error);
    el.hidden = false;
    clearTimeout(_timer);
    _timer = setTimeout(() => { el.hidden = true; }, opts.error ? 3000 : 1600);
}
