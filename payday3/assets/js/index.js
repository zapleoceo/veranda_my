// payday3 bootstrap. Reads server-rendered state from #pd3-bootstrap,
// then wires up each small UI module. No business logic lives here —
// every behaviour is one focused file in ./ui/.

'use strict';

import { State }          from './state.js';
import { initModeToggle } from './ui/modeToggle.js';
import { initSelection }  from './ui/selection.js';
import { initSort }       from './ui/sort.js';
import { initEyeToggles } from './ui/eyeToggles.js';
import { initHelpMode }   from './ui/helpMode.js';
import { initDateForm }   from './ui/dateForm.js';
import { refreshStats }   from './ui/stats.js';

const bootstrapEl = document.getElementById('pd3-bootstrap');
let raw = {};
if (bootstrapEl) {
    try { raw = JSON.parse(bootstrapEl.textContent || '{}'); }
    catch (e) { console.error('[payday3] bootstrap parse failed', e); }
}

const state = new State({
    range:     raw.range     || null,
    links:     raw.links     || [],
    csrf:      raw.csrf      || '',
    endpoints: raw.endpoints || {},
});
window.__pd3 = state;

initModeToggle();
initSelection();
initSort();
initEyeToggles();
initHelpMode();
initDateForm();
refreshStats();

console.info('[payday3] ready', {
    range: state.get('range'),
    links: state.get('links').length,
});
