// Busy-button + status-strip helpers shared by every action button.

'use strict';

/**
 * Wrap an async action so its button is disabled and spins while it runs.
 * Re-entry while the button is disabled is ignored. The button may be
 * null (programmatic runs) — the action then just runs.
 *
 * @param {HTMLButtonElement|null} btn
 * @param {(...args:any[]) => Promise<any>} fn
 * @param {{label?:string, onError?:(e:Error)=>void}} [opts]
 *   label   — temporary aria-label while busy;
 *   onError — handles a rejection (default: rethrow).
 */
export function withBusy(btn, fn, { label = '', onError = null } = {}) {
    return async (...args) => {
        if (btn?.disabled) return undefined;
        const prevLabel = btn && label ? btn.getAttribute('aria-label') : null;
        if (btn) {
            btn.disabled = true;
            btn.classList.add('is-busy');
            if (label) btn.setAttribute('aria-label', label);
        }
        try {
            return await fn(...args);
        } catch (e) {
            if (!onError) throw e;
            onError(e);
            return undefined;
        } finally {
            if (btn) {
                btn.disabled = false;
                btn.classList.remove('is-busy');
                if (label) {
                    if (prevLabel) btn.setAttribute('aria-label', prevLabel);
                    else btn.removeAttribute('aria-label');
                }
            }
        }
    };
}

/**
 * Status strip text + colour ('ok' → .is-ok, any other kind → .is-error).
 * @param {HTMLElement|null} el
 */
export function setStatus(el, msg, kind = '') {
    if (!el) return;
    el.textContent = msg || '';
    el.classList.remove('is-ok', 'is-error');
    if (kind) el.classList.add(kind === 'ok' ? 'is-ok' : 'is-error');
}
