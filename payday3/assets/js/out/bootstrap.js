// Outgoing side: BIDV mail rows (lower block of «Деньги») ↔ Poster
// finance transactions (lower right table). Owns:
//   * data fetch — /out/mail (live IMAP), /out/finance (Poster API),
//     /out/links (DB), fanned out in parallel on page load;
//   * rendering of the mail block and the finance table;
//   * the outgoing LineRenderer (its own SVG layer in the shared grid);
//   * outgoing link mutations as an adapter — autoLink / manualLink /
//     clearLinks — driven by the page-wide link panel (ui/linkPanel.js);
//   * per-row mail hide (−) and the shared «show hidden» eye.
// Row selection lives in ui/selection.js for the whole page.

'use strict';

// nginx serves /payday3/assets/js/* with a 4-hour cache and ignores
// our Cache-Control headers, so bare static imports like
//   import { renderOutMail } from './renderTables.js';
// land on a stale URL after every deploy. Cascade index.js's ?v=
// query string through dynamic imports so every cross-module URL
// changes on each commit.
const _v = new URL(import.meta.url).searchParams.get('v') || '';
const _qs = _v ? '?v=' + encodeURIComponent(_v) : '';
const { api }                 = await import(new URL('../api.js'              + _qs, import.meta.url).href);
const { LineRenderer }        = await import(new URL('../ui/lineRenderer.js'  + _qs, import.meta.url).href);
const { renderOutMail,
        renderOutFinance,
        updateOutFooter }     = await import(new URL('./renderTables.js'      + _qs, import.meta.url).href);
const { BANK_COLUMNS, BANK_SCROLL,
        BANK_TABLE, MAIL_TBODY } = await import(new URL('../ui/bankTable.js'  + _qs, import.meta.url).href);

function rangeQs(state) {
    const r = state.get('range') || {};
    const p = new URLSearchParams();
    if (r.from) p.set('dateFrom', r.from);
    if (r.to)   p.set('dateTo',   r.to);
    return p.toString();
}

/**
 * @param {{state:object, onChanged?:()=>void}} deps
 *   onChanged — after every render of the outgoing rows (load or link
 *   mutation), so the page can reset the selection and re-apply eye
 *   toggles without this module knowing about them.
 */
export function initOutMode({ state, onChanged }) {
    let renderer       = null;
    let mailRows       = [];
    let finRows        = [];
    let links          = [];
    let showHiddenMail = false;   // shared «show hidden» eye (#pd3SepayHiddenToggle)

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
                    apply(await api.delete(`/payday3/api/out/links/${link.mail_uid}/${link.finance_id}?${rangeQs(state)}`));
                } catch (e) { console.error('[payday3-out]', e); alert(e.message); }
            },
        });
    }

    function render() {
        renderOutMail(mailRows, links, { showHidden: showHiddenMail });
        renderOutFinance(finRows, links);
        buildRenderer();
        renderer.setLinks(links);
        onChanged?.();
    }

    /** Apply a link-mutation response (it carries the fresh link set). */
    function apply(result) {
        links = Array.isArray(result?.links) ? result.links : links;
        render();
        return result;
    }

    // Flood protection: while a fetch is in flight every reload button
    // is disabled and shows the .is-busy spinner; re-entry is dropped
    // (the in-flight request already brings the freshest data).
    let loading = false;
    async function load() {
        if (loading) return;
        loading = true;
        const reloadButtons = [document.getElementById('pd3OutFinanceReloadBtn')].filter(Boolean);
        reloadButtons.forEach((b) => { b.disabled = true; b.classList.add('is-busy'); });
        try {
            // Fan out — IMAP, Poster API and the DB link query each hit
            // a dedicated endpoint; AuthMiddleware releases the session
            // lock for /payday3/* so they really run concurrently.
            const qs = rangeQs(state);
            const [mailRes, finRes, linkRes] = await Promise.allSettled([
                api.get(`/payday3/api/out/mail?include_hidden=${showHiddenMail ? '1' : '0'}&${qs}`),
                api.get(`/payday3/api/out/finance?${qs}`),
                api.get(`/payday3/api/out/links?${qs}`),
            ]);
            // Partial failures don't sink the render — e.g. IMAP can flake
            // but the operator still sees Poster txs and existing links.
            mailRows = mailRes.status === 'fulfilled' ? (mailRes.value?.mail    || []) : [];
            finRows  = finRes .status === 'fulfilled' ? (finRes .value?.finance || []) : [];
            links    = linkRes.status === 'fulfilled' ? (linkRes.value?.links   || []) : [];
            render();
            updateOutFooter(mailRows, finRows);
            for (const [name, p] of [['mail', mailRes], ['finance', finRes], ['links', linkRes]]) {
                if (p.status === 'rejected') console.error('[payday3-out] /' + name, p.reason);
            }
        } catch (e) {
            const tb = document.querySelector(MAIL_TBODY);
            if (tb) tb.innerHTML = `<tr class="pd3-empty"><td colspan="${BANK_COLUMNS}">Не удалось загрузить: ${e.message || 'ошибка'}</td></tr>`;
            console.error('[payday3-out]', e);
        } finally {
            loading = false;
            reloadButtons.forEach((b) => { b.disabled = false; b.classList.remove('is-busy'); });
        }
    }

    // Both reload buttons refetch the outgoing side: the finance pane's ↻
    // and the «Деньги» ↻ (which also syncs SePay — see ui/dataActions.js).
    document.getElementById('pd3OutFinanceReloadBtn')?.addEventListener('click', () => load());
    document.getElementById('pd3SepaySyncBtn')?.addEventListener('click', () => load());

    // Per-row hide (−) on a mail row.
    document.body.addEventListener('click', async (e) => {
        const t = e.target.closest?.('.pd3-out-mail-hide');
        if (!t) return;
        const uid = Number(t.dataset.mailUid);
        if (!uid) return;
        const cmt = prompt('Комментарий к скрытию (необязательно):', '') ?? '';
        try {
            await api.post('/payday3/api/out/mail/hide?' + rangeQs(state), { mailUid: uid, comment: cmt });
            await load();   // the hidden row drops out unless «show hidden» is on
        } catch (err) { alert(err.message); }
    });

    // Shared «show hidden» eye of the «Деньги» pane: SePay rows are
    // toggled in place by ui/eyeToggles.js; hidden mail rows are only
    // returned by the server on request, so refetch with the flag.
    document.getElementById('pd3SepayHiddenToggle')?.addEventListener('click', () => {
        showHiddenMail = !showHiddenMail;
        load().catch((err) => alert(err.message));
    });

    // First paint: IN rows are server-rendered; the outgoing side is live
    // (IMAP ≈ 2 s). setTimeout(0) lets IN's own requests go out first.
    setTimeout(() => { load(); }, 0);

    return {
        reload: () => load(),
        autoLink:   async () => apply(await api.post('/payday3/api/out/links/auto?' + rangeQs(state))),
        manualLink: async (mailUids, financeIds) =>
            apply(await api.post('/payday3/api/out/links/manual?' + rangeQs(state), { mailUids, financeIds })),
        clearLinks: async () => apply(await api.post('/payday3/api/out/links/clear?' + rangeQs(state))),
    };
}
