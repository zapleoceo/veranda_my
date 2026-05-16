// payday3 bootstrap. Reads server-rendered state from #pd3-bootstrap,
// then wires up each small UI module. No business logic lives here —
// every behaviour is one focused file in ./ui/.

'use strict';

import { State }           from './state.js';
import { setCsrf }         from './api.js';
import { initModeToggle }  from './ui/modeToggle.js';
import { initSelection }   from './ui/selection.js';
import { initSort }        from './ui/sort.js';
import { initEyeToggles }  from './ui/eyeToggles.js';
import { initHelpMode }    from './ui/helpMode.js';
import { initDateForm }    from './ui/dateForm.js';
import { refreshStats }    from './ui/stats.js';
import { LineRenderer }    from './ui/lineRenderer.js';
import { initLinkActions } from './ui/linkActions.js';
import { initDataActions } from './ui/dataActions.js';
import { initModeTab }     from './ui/modeTab.js';
import { initModals }      from './ui/modals.js';

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
setCsrf(state.get('csrf'));
window.__pd3 = state;

initModeTab();
initModeToggle();
const selection = initSelection();
initSort();
initEyeToggles();
initHelpMode();
initDateForm();
initDataActions({ state });
initModals({ state });
refreshStats();

// Line renderer — bezier connectors between sepay/poster anchors.
const grid = document.querySelector('.pd3-graph__grid');
const renderer = grid ? new LineRenderer({
    container:          grid,
    layer:              document.getElementById('pd3LineLayer'),
    sepayScroll:        document.getElementById('pd3SepayScroll'),
    posterScroll:       document.getElementById('pd3PosterScroll'),
    sepayTbody:         document.querySelector('#pd3SepayTable tbody'),
    posterTbody:        document.querySelector('#pd3PosterTable tbody'),
    horizontalScroller: document.getElementById('pd3GraphRoot'),
    onUnlink: null,            // wired after linkActions returns
}) : null;

if (renderer) {
    const onUnlink = initLinkActions({ state, renderer, selection });
    renderer._onUnlink = onUnlink;   // late-bind the close-button handler
    renderer.setLinks(state.get('links'));
} else {
    console.warn('[payday3] grid not found, LineRenderer disabled');
}

console.info('[payday3] ready', {
    range: state.get('range'),
    links: state.get('links').length,
});
