// Lite/Full toggle. The whole .pd3-mode-toggle area (Lite-label /
// switch / Full-label) is treated as one widget — clicking any of
// the three pieces flips the state. State persists in localStorage
// and is mirrored to body.pd3-mode-lite for CSS to pick up.
//
// Both the IN-mode and OUT-mode mid columns have their own toggles
// (#pd3ModeToggle and #pd3OutModeToggle) but they share the same
// body class — toggling either updates both.

'use strict';

const LS_KEY = 'pd3:mode';
const TOGGLE_IDS = ['pd3ModeToggle', 'pd3OutModeToggle'];

let _suspendChange = false;

function isLite() {
    return document.body.classList.contains('pd3-mode-lite');
}

function apply(lite) {
    document.body.classList.toggle('pd3-mode-lite', lite);
    _suspendChange = true;
    for (const id of TOGGLE_IDS) {
        const cb = document.getElementById(id);
        if (cb) cb.checked = !lite;   // checked = Full
    }
    _suspendChange = false;
    try { localStorage.setItem(LS_KEY, lite ? 'lite' : 'full'); } catch (_) {}
}

export function initModeToggle() {
    const stored = (() => {
        try { return localStorage.getItem(LS_KEY); } catch (_) { return null; }
    })();
    apply(stored === 'lite');

    // 1) Sync the hidden checkboxes themselves (the switch slider).
    for (const id of TOGGLE_IDS) {
        const cb = document.getElementById(id);
        if (!cb) continue;
        cb.addEventListener('change', () => {
            if (_suspendChange) return;
            apply(!cb.checked);    // unchecked = Lite
        });
    }

    // 2) Make the "Lite" / "Full" text labels click-targets too.
    //    payday2 users expect to click on the word "Lite" to switch
    //    to Lite mode, not just on the round switch knob.
    document.querySelectorAll('.pd3-mode-toggle__label').forEach((label) => {
        label.style.cursor = 'pointer';
        label.style.userSelect = 'none';
        label.addEventListener('click', () => {
            const wantsLite = label.textContent.trim().startsWith('L');
            apply(wantsLite);
        });
    });
}
