// payday3 bootstrap. Reads server-rendered state from #pd3-bootstrap,
// then wires up each small UI module. No business logic lives here —
// every behaviour is one focused file in ./ui/.
//
// Cache-busting strategy: plain static imports, no query strings in the
// specifiers. content.php emits an import map right before this module's
// <script> that maps every /payday3/assets/js/**.js URL to the same URL
// with ?v=<filemtime> (src/Payday3/Http/ModuleImportMap.php), so each
// module is re-fetched exactly when its own file changes. (The previous
// top-level-await dynamic-import cascade hung iOS WebKit — do not revive.)

'use strict';

import { State } from './state.js';
import { setCsrf } from './api.js';
import { initModeToggle } from './ui/modeToggle.js';
import { initSelection, SIDE_KINDS } from './ui/selection.js';
import { initSort } from './ui/sort.js';
import { initEyeToggles } from './ui/eyeToggles.js';
import { initHelpMode } from './ui/helpMode.js';
import { initDateForm } from './ui/dateForm.js';
import { refreshStats } from './ui/stats.js';
import { LineRenderer } from './ui/lineRenderer.js';
import { createInLinks } from './ui/linkActions.js';
import { initIncomeLinks } from './ui/incomeLinks.js';
import { paintRowStates } from './ui/rowStates.js';
import { initLinkPanel } from './ui/linkPanel.js';
import { initDataActions } from './ui/dataActions.js';
import { BANK_TABLE, BANK_SCROLL, SEPAY_TBODY } from './ui/bankTable.js';
import { initModals, modalHost } from './ui/modals.js';
import { initOutMode } from './out/bootstrap.js';
import { initBalances } from './ui/balances.js';
import { makeInLoader, initSepayHide } from './in/bootstrap.js';
import { initFinanceTransfers } from './ui/financeTransfers.js';
import { initCreateTx } from './ui/createTx.js';
import { initFontScale } from './ui/fontScale.js';

const _v = new URL(import.meta.url).searchParams.get('v') || '';

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
// Before any module can send a request: every POST/DELETE under
// /payday3/api must carry X-CSRF-Token (api.js adds it).
setCsrf(state.get('csrf'));

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
// eye toggles, then the footers — computed ONCE, from the final classes.
const repaint = () => {
    paintRowStates({
        checkLinks:  state.get('links')       || [],
        mailLinks:   state.get('outLinks')    || [],
        incomeLinks: state.get('incomeLinks') || [],
    });
    eyes.reapply();
    refreshStats();
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
    leftAnchorId:       (l) => 'pd3-sepay-anchor-'  + l.sepay_id,
    rightAnchorId:      (l) => 'pd3-poster-anchor-' + l.poster_transaction_id,
    linkKey:            (l) => l.sepay_id + ':' + l.poster_transaction_id,
}) : null;
if (!renderer) console.warn('[payday3] grid not found, LineRenderer disabled');

const inLinks = createInLinks({ state, renderer, onChanged: afterSideRender('in') });
if (renderer) {
    renderer.setOnUnlink(inLinks.onUnlink);   // late-bind the × button handler
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
// Same modal host as the toolbar buttons (set up by initModals above).
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
const dataActions = initDataActions({
    state,
    refresh: async () => {
        await loadInData();
        finance.reload();
    },
    // «Деньги» ↻ also reloads the outgoing block — one listener, one spinner.
    reloadOut: outMode ? () => outMode.reload() : null,
});
// Per-row hide/restore of incoming rows — reuses loadInData so the eye
// toggle picks up the change without a full page reload.
initSepayHide({ reload: loadInData });

// First-paint auto-fill: incoming rows and checks are server-rendered
// from the DB. If both came back empty (a fresh day), sync SePay + Poster
// (with the buttons' spinners). The outgoing side needs no kick — it
// already loads live — so this does NOT reload it a second time.
(function autoFillTables() {
    const inEmpty =
        document.querySelector(`${SEPAY_TBODY} .pd3-empty`) &&
        document.querySelector('#pd3PosterTable .pd3-empty');
    if (inEmpty) dataActions.autoFill();
})();

console.info('[payday3] ready', {
    v:     _v || '(none)',
    range: state.get('range'),
    links: state.get('links').length,
});
