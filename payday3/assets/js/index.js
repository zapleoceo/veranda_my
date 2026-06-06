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
    { makeInLoader, initSepayHide },
    { initFinanceTransfers },
    { initCreateTx },
    { initFontScale },
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
    _i('./ui/fontScale.js'),
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
    userEmail: raw.userEmail || '',
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
// initOutMode FIRST so we can pass its reload() into createTx —
// the "+" popup uses it to refresh the OUT-mode tables after
// finance.createTransactions succeeds.
const outMode = initOutMode({ state }) || {};
// Import the modal host helpers AFTER initModals so initCreateTx can
// route open/close through the same code path as the toolbar buttons.
const { modalHost } = await _i('./ui/modals.js');
initCreateTx({
    state,
    host:       modalHost,
    openModal:  modalHost.open,
    closeModal: modalHost.close,
    onCreated:  outMode.reload,
});
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

// Font-scale widget — single «Aa» button that cycles 1 / 1.2 / 1.5×.
// Renderer redraws happen via a window event ('pd3:font-scale-changed'),
// so IN-mode and OUT-mode LineRenderer instances both pick it up
// regardless of which one is currently visible.
initFontScale();

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
// Per-row hide/restore — direct port of payday2's ?ajax=sepay_hide.
// Reuses loadInData so the eye-toggle picks up the change without a
// full page reload.
initSepayHide({ reload: loadInData });

// First-paint auto-fill. When the operator opens the page (or picks a
// fresh date) we don't want either tab to greet them with empty tables.
//
//   IN  — server-rendered from DB. If both tables came back empty, fire
//         Sepay + Poster sync in parallel. The sync buttons already own
//         the busy spinner + refresh-on-success flow, so we just simulate
//         the clicks. Buttons no-op while busy, so this is safe even if
//         the operator races us with a manual click.
//
//   OUT — always lives off live IMAP + Poster API (never cached), so it
//         can't be "pre-rendered". out/bootstrap loads on tab activation
//         by default. We additionally kick off that load right now, in
//         the background, so when the operator clicks the OUT tab the
//         data is already there. Delayed by a beat so IN sync gets the
//         browser's HTTP slot first.
(function autoFillTables() {
    const inEmpty =
        document.querySelector('#pd3SepayTable .pd3-empty') &&
        document.querySelector('#pd3PosterTable .pd3-empty');
    if (inEmpty) {
        document.getElementById('pd3SepaySyncBtn')?.click();
        document.getElementById('pd3PosterSyncBtn')?.click();
    }
    // Pre-warm OUT. setTimeout(0) yields to the event loop so the IN
    // sync clicks above start their fetches first; the IMAP call inside
    // OUT can be slow (~2 s) and we don't want it competing for the
    // browser's first paint.
    if (typeof outMode?.reload === 'function') {
        setTimeout(() => { outMode.reload(); }, 0);
    }
})();

console.info('[payday3] ready', {
    v:     _v || '(none)',
    range: state.get('range'),
    links: state.get('links').length,
});
