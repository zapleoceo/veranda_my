// Outgoing side: BIDV mail rows (lower block of «Деньги») ↔ Poster
// finance transactions (lower right table). Owns:
//   * data fetch — /out/mail (live IMAP), /out/finance (Poster API),
//     /out/links (DB), fanned out in parallel on page load;
//   * rendering of the mail block and the finance table;
//   * the outgoing LineRenderer (its own SVG layer in the shared grid);
//   * outgoing link mutations as an adapter — autoLink / manualLink /
//     clearLinks — driven by the page-wide link panel (ui/linkPanel.js);
//   * per-row mail hide (−) and refetching hidden mail for the shared
//     «show hidden» eye (its state is owned by ui/eyeToggles.js).
// Row selection lives in ui/selection.js for the whole page.
//
// Who reloads this side: the finance pane's ↻, the «Деньги» ↻ (via
// ui/dataActions.js → reload()), the 👁, a mail hide and a created
// transaction. Loads are latest-wins (ui/coalesce.js).

'use strict';

// nginx serves /payday3/assets/js/* with a 4-hour cache and ignores
// our Cache-Control headers; the import map emitted by content.php
// (src/Payday3/Http/ModuleImportMap.php) versions every module URL.
import { api }                 from '../api.js';
import { LineRenderer }        from '../ui/lineRenderer.js';
import { renderOutMail,
        renderOutFinance,
        updateOutFooter }     from './renderTables.js';
import { BANK_COLUMNS, BANK_SCROLL,
        BANK_TABLE, MAIL_TBODY } from '../ui/bankTable.js';
import { esc, withRange }      from '../ui/format.js';
import { coalesce }            from '../ui/coalesce.js';
import { withBusy }            from '../ui/busy.js';
import { notify }              from '../ui/notify.js';
import { showHiddenFrom }      from '../ui/eyeToggles.js';

/**
 * Merge an allSettled fan-out into the current rows: a rejected part
 * KEEPS the previous rows (a 429 / IMAP flake must not blank the table).
 * Pure — tested in tests/js/payday3/outMerge.test.mjs.
 *
 * @param {{mail:object[], finance:object[], links:object[]}} prev
 * @param {{mail:PromiseSettledResult, finance:PromiseSettledResult, links:PromiseSettledResult}} res
 * @returns {{mail:object[], finance:object[], links:object[], failed:string[]}}
 */
export function mergeOutResults(prev, res) {
    const pick = (name, field) => {
        const r = res[name];
        return r.status === 'fulfilled' ? (r.value?.[field] || []) : prev[name];
    };
    return {
        mail:    pick('mail',    'mail'),
        finance: pick('finance', 'finance'),
        links:   pick('links',   'links'),
        failed:  ['mail', 'finance', 'links'].filter((n) => res[n].status === 'rejected'),
    };
}

const PART_LABEL = { mail: 'письма BIDV', finance: 'транзакции Poster', links: 'связи' };

/**
 * @param {{state:object, onChanged?:()=>void}} deps
 *   onChanged — after every render of the outgoing rows (load or link
 *   mutation), so the page can reset the selection and repaint row
 *   colours / eye toggles without this module knowing about them.
 */
export function initOutMode({ state, onChanged }) {
    let renderer = null;
    let rows     = { mail: [], finance: [], links: [] };
    let loadedOnce = false;
    const eye = document.getElementById('pd3SepayHiddenToggle');
    const range = () => state.get('range');

    const grid = document.querySelector('#pd3GraphRoot .pd3-graph__grid');
    if (!grid) return null;

    function buildRenderer() {
        if (renderer) return;
        renderer = new LineRenderer({
            container:          grid,
            layer:              document.getElementById('pd3OutLineLayer'),
            leftScroll:         document.querySelector(BANK_SCROLL),
            rightScroll:        document.getElementById('pd3OutFinanceScroll'),
            // Observe the WHOLE bank table and right column: the incoming
            // block above / the checks pane above move these anchors too.
            leftTbody:          document.querySelector(BANK_TABLE),
            rightTbody:         document.getElementById('pd3RightColumn'),
            horizontalScroller: document.getElementById('pd3GraphRoot'),
            leftAnchorId:       (l) => 'pd3-out-mail-anchor-'    + l.mail_uid,
            rightAnchorId:      (l) => 'pd3-out-finance-anchor-' + l.finance_id,
            linkKey:            (l) => l.mail_uid + ':' + l.finance_id,
            onUnlink: async (link) => {
                try {
                    apply(await api.delete(withRange(
                        `/payday3/api/out/links/${Number(link.mail_uid)}/${Number(link.finance_id)}`, range())));
                } catch (e) { console.error('[payday3-out]', e); alert(e.message); }
            },
        });
    }

    /** Publish the link set: connectors + page repaint (row classes). */
    function publishLinks() {
        buildRenderer();
        renderer.setLinks(rows.links);
        // Finance rows can also be linked to incoming rows (ui/incomeLinks.js):
        // publish our set so the page repaints from the union of both.
        state.set('outLinks', rows.links);
        onChanged?.();
    }

    /** Full render — only after a load (new rows). */
    function render() {
        renderOutMail(rows.mail, rows.links, { showHidden: showHiddenFrom(eye) });
        renderOutFinance(rows.finance, rows.links);
        updateOutFooter(rows.mail, rows.finance);
        publishLinks();
    }

    /**
     * Apply a link-mutation response (it carries the fresh link set).
     * The rows didn't change, so no innerHTML rebuild — the page repaint
     * recolours the rows in place (ui/rowStates.js).
     */
    function apply(result) {
        if (Array.isArray(result?.links)) rows.links = result.links;
        publishLinks();
        return result;
    }

    const reloadBtn = document.getElementById('pd3OutFinanceReloadBtn');
    const load = coalesce(withBusy(reloadBtn, async () => {
        // Fan out — IMAP, Poster API and the DB link query each hit
        // a dedicated endpoint; AuthMiddleware releases the session
        // lock for /payday3/* so they really run concurrently.
        const includeHidden = showHiddenFrom(eye) ? '1' : '0';
        const [mail, finance, links] = await Promise.allSettled([
            api.get(withRange(`/payday3/api/out/mail?include_hidden=${includeHidden}`, range())),
            api.get(withRange('/payday3/api/out/finance', range())),
            api.get(withRange('/payday3/api/out/links',   range())),
        ]);
        // Partial failures don't sink the render — e.g. IMAP can flake
        // (or answer 429) but the operator keeps the rows already on
        // screen and still sees fresh Poster txs and links.
        const merged = mergeOutResults(rows, { mail, finance, links });
        rows = { mail: merged.mail, finance: merged.finance, links: merged.links };
        const errors = { mail, finance, links };
        for (const name of merged.failed) console.error('[payday3-out] /' + name, errors[name].reason);

        if (merged.failed.includes('mail') && !loadedOnce) {
            // Nothing to keep yet — say why the block is empty.
            renderOutFinance(rows.finance, rows.links);
            updateOutFooter(rows.mail, rows.finance);
            publishLinks();
            const tb = document.querySelector(MAIL_TBODY);
            if (tb) tb.innerHTML = `<tr class="pd3-empty"><td colspan="${BANK_COLUMNS}">Не удалось загрузить: ${esc(mail.reason?.message || 'ошибка')}</td></tr>`;
        } else {
            render();
        }
        if (!merged.failed.includes('mail')) loadedOnce = true;
        if (merged.failed.length) {
            const what = merged.failed.map((n) => PART_LABEL[n]).join(', ');
            const tooMany = merged.failed.some((n) => errors[n].reason?.status === 429);
            notify(`Расходы: не обновились ${what}${tooMany ? ' (слишком часто — подожди пару секунд)' : ''}. Показаны прежние данные.`, 'warn');
        }
    }, { onError: (e) => { console.error('[payday3-out]', e); notify('Расходы: ' + (e.message || 'ошибка'), 'error'); } }));

    reloadBtn?.addEventListener('click', () => load());

    // Per-row hide (−) on a mail row.
    document.body.addEventListener('click', async (e) => {
        const t = e.target.closest?.('.pd3-out-mail-hide');
        if (!t) return;
        const uid = Number(t.dataset.mailUid);
        if (!uid) return;
        const cmt = prompt('Комментарий к скрытию (необязательно):', '') ?? '';
        try {
            await api.post(withRange('/payday3/api/out/mail/hide', range()), { mailUid: uid, comment: cmt });
            await load();   // the hidden row drops out unless «show hidden» is on
        } catch (err) { alert(err.message); }
    });

    // Shared «show hidden» eye of the «Деньги» pane: SePay rows are
    // toggled in place by ui/eyeToggles.js (whose click listener runs
    // first and flips aria-pressed); hidden mail rows are only returned
    // by the server on request, so refetch — the load reads the eye's
    // state when it runs, so a click during a load is never lost.
    eye?.addEventListener('click', () => { load(); });

    // First paint: IN rows are server-rendered; the outgoing side is live
    // (IMAP ≈ 2 s). setTimeout(0) lets IN's own requests go out first.
    setTimeout(() => { load(); }, 0);

    return {
        reload: () => load(),
        autoLink:   async () => apply(await api.post(withRange('/payday3/api/out/links/auto', range()))),
        manualLink: async (mailUids, financeIds) =>
            apply(await api.post(withRange('/payday3/api/out/links/manual', range()), { mailUids, financeIds })),
        clearLinks: async () => apply(await api.post(withRange('/payday3/api/out/links/clear', range()))),
    };
}
