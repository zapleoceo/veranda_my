// payday3 bootstrap. Reads server-rendered state from #pd3-bootstrap
// and wires up the line renderer. Phase 2 ships static rendering only;
// Phase 3 adds LineRenderer + REST refresh.

'use strict';

const bootstrapEl = document.getElementById('pd3-bootstrap');
if (bootstrapEl) {
    try {
        const state = JSON.parse(bootstrapEl.textContent || '{}');
        window.__pd3 = Object.freeze({
            range:     state.range     || null,
            links:     state.links     || [],
            csrf:      state.csrf      || '',
            endpoints: state.endpoints || {},
        });
        // Phase 3 will replace this with a real LineRenderer instance.
        console.info('[payday3] bootstrap loaded', {
            range: window.__pd3.range,
            links: window.__pd3.links.length,
        });
    } catch (e) {
        console.error('[payday3] bootstrap parse failed', e);
    }
}
