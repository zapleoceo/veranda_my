// Modal host — one overlay (#pd3ModalHost), many `.pd3-modal` panes.
// Esc, backdrop click and × ([data-pd3-modal-close]) close it.
//
// Each feature module registers its pane once:
//   register('pd3SuppliesModal', { trigger: 'pd3SuppliesBtn', onOpen: () => load() });
// A click on the trigger opens the pane, then runs its onOpen — the host
// never branches on modal ids.

'use strict';

let _host = null;
let _currentId = null;
let _lastFocus = null;
const _panes = new Map();   // modalId → { onOpen }

function open(modalId) {
    if (!_host) return;
    const target = document.getElementById(modalId);
    if (!target) return;
    document.querySelectorAll('.pd3-modal').forEach((m) => { m.hidden = true; });
    if (!_currentId) _lastFocus = document.activeElement;
    target.hidden = false;
    _host.hidden = false;
    _host.removeAttribute('aria-hidden');
    _currentId = modalId;
    // Focus the dialog itself so Esc / Tab start inside it.
    if (!target.hasAttribute('tabindex')) target.setAttribute('tabindex', '-1');
    target.focus?.();
}

function close() {
    if (!_host) return;
    _host.hidden = true;
    _host.setAttribute('aria-hidden', 'true');
    _currentId = null;
    // Give focus back to whatever opened the modal.
    _lastFocus?.focus?.();
    _lastFocus = null;
}

/**
 * @param {string} modalId
 * @param {{trigger?:string, onOpen?:()=>unknown}} [opts]
 *   trigger — id of the toolbar button that opens this pane.
 */
function register(modalId, { trigger = '', onOpen = null } = {}) {
    _panes.set(modalId, { onOpen });
    const btn = trigger ? document.getElementById(trigger) : null;
    btn?.addEventListener('click', async () => {
        open(modalId);
        await _panes.get(modalId)?.onOpen?.();
    });
}

/** Bind the overlay once. @returns {boolean} whether the host exists. */
function init() {
    _host = document.getElementById('pd3ModalHost');
    if (!_host || _host.dataset.wired === '1') return !!_host;
    _host.dataset.wired = '1';
    _host.addEventListener('click', (e) => {
        if (e.target.hasAttribute?.('data-pd3-modal-close')) close();
    });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && _currentId) close();
    });
    return true;
}

// Public so non-modal modules (createTx, …) can open/close arbitrary
// panes through the same code path as the toolbar buttons.
export const modalHost = {
    init,
    register,
    open:  (id) => open(id),
    close: ()   => close(),
};
