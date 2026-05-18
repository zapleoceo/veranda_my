// payday3 bootstrap. Reads server-rendered state from #pd3-bootstrap,
// then wires up each small UI module. No business logic lives here —
// every behaviour is one focused file in ./ui/.
//
// Cache-busting strategy: the <script> tag in content.php is loaded as
//   index.js?v=<filemtime>
// We forward that `v=` query string to every submodule import via
// dynamic `import()` calls below. That way a single mtime bump on
// index.js invalidates every cached module in one shot — no need to
// version each path separately.

'use strict';

const _selfUrl = new URL(import.meta.url);
const _v = _selfUrl.searchParams.get('v') || '';
const _qs = _v ? '?v=' + encodeURIComponent(_v) : '';
const _i = (p) => import(new URL(p + _qs, import.meta.url).href);

const [
    { State },
    { setCsrf },
    { initModeToggle },
    { initSelection },
    { initSort },
    { initEyeToggles },
    { initHelpMode },
    { initDateForm },
    { refreshStats },
    { LineRenderer },
    { initLinkActions },
    { initDataActions },
    { initModeTab },
    { initModals },
    { initOutMode },
    { initBalances },
    { makeInLoader },
    { initFinanceTransfers },
    { initCreateTx },
] = await Promise.all([
    _i('./state.js'),
    _i('./api.js'),
    _i('./ui/modeToggle.js'),
    _i('./ui/selection.js'),
    _i('./ui/sort.js'),
    _i('./ui/eyeToggles.js'),
    _i('./ui/helpMode.js'),
    _i('./ui/dateForm.js'),
    _i('./ui/stats.js'),
    _i('./ui/lineRenderer.js'),
    _i('./ui/linkActions.js'),
    _i('./ui/dataActions.js'),
    _i('./ui/modeTab.js'),
    _i('./ui/modals.js'),
    _i('./out/bootstrap.js'),
    _i('./ui/balances.js'),
    _i('./in/bootstrap.js'),
    _i('./ui/financeTransfers.js'),
    _i('./ui/createTx.js'),
]);

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
initModals({ state });
// Import the modal host helpers AFTER initModals so initCreateTx can
// route open/close through the same code path as the toolbar buttons.
const { modalHost } = await _i('./ui/modals.js');
initCreateTx({
    host:       modalHost,
    openModal:  modalHost.open,
    closeModal: modalHost.close,
});
initOutMode({ state });
initBalances({ state });
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

// AJAX IN-mode refresh — replaces window.location.reload() after sync.
const loadInData = makeInLoader({ state, renderer });
const finance    = initFinanceTransfers({ state });
initDataActions({
    state,
    refresh: async () => {
        await loadInData();
        finance.reload();
    },
});

console.info('[payday3] ready', {
    v:     _v || '(none)',
    range: state.get('range'),
    links: state.get('links').length,
});
