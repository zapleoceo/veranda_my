// IN / OUT tabs in the toolbar. Toggles a body class so the rest of
// the CSS can show/hide the right section. Persisted to localStorage.
//
// IN  → reconciliation between sepay (incoming bank) and Poster checks.
// OUT → reconciliation between BIDV outgoing mail and Poster finance
//       transactions. The OUT section isn't built yet — for now the tab
//       reveals a placeholder so the user can verify the toggle works.

'use strict';

const LS_KEY = 'pd3:mode-tab';

function apply(mode) {
    const isOut = mode === 'out';
    document.body.classList.toggle('pd3-mode-out', isOut);
    document.body.classList.toggle('pd3-mode-in', !isOut);
    document.querySelectorAll('.pd3-tab').forEach((tab) => {
        tab.classList.toggle('is-active', tab.dataset.tab === mode);
    });
    try { localStorage.setItem(LS_KEY, mode); } catch (_) {}
}

export function initModeTab() {
    const stored = (() => {
        try { return localStorage.getItem(LS_KEY); } catch (_) { return null; }
    })();
    apply(stored === 'out' ? 'out' : 'in');

    document.querySelectorAll('.pd3-tab').forEach((tab) => {
        tab.addEventListener('click', () => {
            const next = tab.dataset.tab === 'out' ? 'out' : 'in';
            apply(next);
        });
    });
}
