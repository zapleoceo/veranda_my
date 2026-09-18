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
    { initSelection, SIDE_KINDS },
    { initSort },
    { initEyeToggles },
    { initHelpMode },
    { initDateForm },
    { refreshStats },
    { LineRenderer },
    { createInLinks },
    { initIncomeLinks },
    { paintRowStates },
    { initLinkPanel },
    { initDataActions },
    { BANK_TABLE, BANK_SCROLL, SEPAY_TBODY },
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
    _i('./ui/incomeLinks.js'),
    _i('./ui/rowStates.js'),
    _i('./ui/linkPanel.js'),
    _i('./ui/dataActions.js'),
    _i('./ui/bankTable.js'),
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

initModeToggle();
const selection = initSelection();
initSort();
const eyes = initEyeToggles();
initHelpMode();
initDateForm();
initModals({ state });

// Row colours come from ALL link kinds at once (a SePay row is linked by
// a check OR a Poster income; a finance row by an expense OR an income),
// so after any change every table is repainted from the union, then the
// footers and eye toggles follow.
const repaint = () => {
    paintRowStates({
        checkLinks:  state.get('links')       || [],
        mailLinks:   state.get('outLinks')    || [],
        incomeLinks: state.get('incomeLinks') || [],
    });
    refreshStats();
    eyes.reapply();
};

// After a side re-renders its rows its old ticks are gone. Each side
// resets only its own selection buckets (SIDE_KINDS), then repaint.
const afterSideRender = (side) => () => {
    selection.reset(SIDE_KINDS[side]);
    repaint();
};

// Incoming connectors — SePay rows ↔ Poster checks. Observes the WHOLE
// «Деньги» table and the WHOLE right column: the other side's rows
// shift these anchors too (e.g. the checks pane growing moves nothing
// here, but a new SePay row moves every outgoing row below it).
const grid = document.querySelector('#pd3GraphRoot .pd3-graph__grid');
const renderer = grid ? new LineRenderer({
    container:          grid,
    layer:              document.getElementById('pd3LineLayer'),
    leftScroll:         document.querySelector(BANK_SCROLL),
    rightScroll:        document.getElementById('pd3PosterScroll'),
    leftTbody:          document.querySelector(BANK_TABLE),
    rightTbody:         document.getElementById('pd3RightColumn'),
    horizontalScroller: document.getElementById('pd3GraphRoot'),
    onUnlink: null,            // wired below, once the adapter exists
}) : null;
if (!renderer) console.warn('[payday3] grid not found, LineRenderer disabled');

const inLinks = createInLinks({ state, renderer, onChanged: afterSideRender('in') });
if (renderer) {
    renderer._onUnlink = inLinks.onUnlink;   // late-bind the × button handler
    renderer.setLinks(state.get('links'));
}

// Outgoing side — BIDV mail ↔ Poster finance. Loads itself right away
// (live IMAP + Poster), draws its own connectors on the same grid.
const outMode = initOutMode({ state, onChanged: afterSideRender('out') });

// Incoming rows ↔ Poster finance incomes (money without a check) — its
// own connectors on #pd3IncomeLineLayer; loads its links right away.
const incomeLinks = initIncomeLinks({ state, onChanged: repaint });

// Balances BEFORE createTx: the «+» popups refresh the Poster column
// after finance.createTransactions succeeds.
const balances = initBalances({ state });
// Import the modal host helpers AFTER initModals so initCreateTx can
// route open/close through the same code path as the toolbar buttons.
const { modalHost } = await _i('./ui/modals.js');
initCreateTx({
    state,
    host:       modalHost,
    openModal:  modalHost.open,
    closeModal: modalHost.close,
    onCreated:  () => {
        outMode?.reload();
        balances.reload();
    },
});

// One link panel for both sides (🧩 / 🎯 / ⛓️‍💥 in the mid column).
initLinkPanel({ state, inLinks, incomeLinks, outLinks: outMode, selection });
refreshStats();

// Font-scale widget — single «Aa» button that cycles 1 / 1.2 / 1.5×.
// Both LineRenderer instances redraw on the 'pd3:font-scale-changed'
// window event.
initFontScale();

// AJAX refresh of the incoming side — replaces window.location.reload().
const loadInData = makeInLoader({ state, renderer, onRendered: afterSideRender('in') });
const finance    = initFinanceTransfers({ state });
initDataActions({
    state,
    refresh: async () => {
        await loadInData();
        finance.reload();
    },
});
// Per-row hide/restore of incoming rows — reuses loadInData so the eye
// toggle picks up the change without a full page reload.
initSepayHide({ reload: loadInData });

// First-paint auto-fill: incoming rows and checks are server-rendered
// from the DB. If both came back empty (a fresh day), fire SePay + Poster
// sync — the buttons own the busy spinner + refresh flow, and no-op
// while busy. The outgoing side needs no kick: it always loads live.
(function autoFillTables() {
    const inEmpty =
        document.querySelector(`${SEPAY_TBODY} .pd3-empty`) &&
        document.querySelector('#pd3PosterTable .pd3-empty');
    if (inEmpty) {
        document.getElementById('pd3SepaySyncBtn')?.click();
        document.getElementById('pd3PosterSyncBtn')?.click();
    }
})();

console.info('[payday3] ready', {
    v:     _v || '(none)',
    range: state.get('range'),
    links: state.get('links').length,
});
