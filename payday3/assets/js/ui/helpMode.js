// ❓ button toggles `body.pd3-help-mode`. CSS then draws an outline
// around every `[data-help-abs]` element and shows the tooltip on
// hover. Pressing the button again leaves the mode.

'use strict';

export function initHelpMode() {
    const btn = document.getElementById('pd3HelpBtn');
    if (!btn) return;
    let on = false;
    btn.addEventListener('click', () => {
        on = !on;
        document.body.classList.toggle('pd3-help-mode', on);
        btn.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
}
