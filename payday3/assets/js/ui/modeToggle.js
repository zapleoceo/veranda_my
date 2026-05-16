// Lite/Full toggle. Toggling the checkbox flips `body.pd3-mode-lite`
// (default = Full to match payday2's default). Persists to localStorage
// so the choice survives reload.

'use strict';

const LS_KEY = 'pd3:mode';

export function initModeToggle(root = document) {
    const cb = root.getElementById ? root.getElementById('pd3ModeToggle') : document.getElementById('pd3ModeToggle');
    if (!cb) return;
    const apply = (lite) => {
        document.body.classList.toggle('pd3-mode-lite', lite);
        cb.checked = !lite;             // checked = Full
        try { localStorage.setItem(LS_KEY, lite ? 'lite' : 'full'); } catch (_) {}
    };
    const stored = (() => { try { return localStorage.getItem(LS_KEY); } catch (_) { return null; } })();
    apply(stored === 'lite');
    cb.addEventListener('change', () => apply(!cb.checked));
}
