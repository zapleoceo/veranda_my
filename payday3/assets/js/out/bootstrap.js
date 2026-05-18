// OUT-mode bootstrap. Lazy: stays inert until the user clicks the
// OUT tab the first time. After that it owns:
//   * data fetch from /payday3/api/out/data
//   * tbody rendering for mail + finance tables
//   * a second LineRenderer instance with OUT anchor-id factories
//   * mid-col buttons (auto / manual / clear / per-link × unlink / hide)
//   * row selection sums
//   * Lite/Full toggle for the OUT panes (shares body.pd3-mode-lite)

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

const fmt = (n) => {
    const v = Math.round(Number(n) || 0);
    try { return new Intl.NumberFormat('en-US', { maximumFractionDigits: 0 }).format(v).replace(/,/g, ' '); }
    catch (_) { return String(v).replace(/\B(?=(\d{3})+(?!\d))/g, ' '); }
};

function rangeQs(state) {
    const r = state.get('range') || {};
    const p = new URLSearchParams();
    if (r.from) p.set('dateFrom', r.from);
    if (r.to)   p.set('dateTo',   r.to);
    return p.toString();
}

export function initOutMode({ state }) {
    let loaded   = false;
    let renderer = null;
    let mailRows = [];
    let finRows  = [];
    let links    = [];
    const selMail = new Set();
    const selFin  = new Set();

    const grid = document.querySelector('.pd3-section--out .pd3-graph__grid');
    if (!grid) return;

    function buildRenderer() {
        if (renderer) return;
        renderer = new LineRenderer({
            container:          grid,
            layer:              document.getElementById('pd3OutLineLayer'),
            leftScroll:         document.getElementById('pd3OutMailScroll'),
            rightScroll:        document.getElementById('pd3OutFinanceScroll'),
            leftTbody:          document.querySelector('#pd3OutMailTable tbody'),
            rightTbody:         document.querySelector('#pd3OutFinanceTable tbody'),
            horizontalScroller: document.getElementById('pd3OutGraphRoot'),
            leftAnchorId:       (l) => 'pd3-out-mail-anchor-'    + l.mail_uid,
            rightAnchorId:      (l) => 'pd3-out-finance-anchor-' + l.finance_id,
            linkKey:            (l) => l.mail_uid + ':' + l.finance_id,
            onUnlink: async (link) => {
                try {
                    const r = await api.delete(`/payday3/api/out/links/${link.mail_uid}/${link.finance_id}?${rangeQs(state)}`);
                    afterMutation(r);
                } catch (e) { console.error('[payday3-out]', e); alert(e.message); }
            },
        });
    }

    function recomputeSelection() {
        let mailSum = 0, finSum = 0;
        for (const cb of document.querySelectorAll('.pd3-cb--out-mail:checked')) {
            mailSum += Number(cb.dataset.sum) || 0;
        }
        for (const cb of document.querySelectorAll('.pd3-cb--out-finance:checked')) {
            finSum += Math.abs(Number(cb.dataset.sum) || 0);
        }
        const $m = document.getElementById('pd3OutSelMailSum');
        const $f = document.getElementById('pd3OutSelFinanceSum');
        const $d = document.getElementById('pd3OutSelDiff');
        const $i = document.getElementById('pd3OutSelMatch');
        if ($m) $m.textContent = fmt(mailSum);
        if ($f) $f.textContent = fmt(finSum);
        const diff = mailSum - finSum;
        if ($d) $d.textContent = fmt(diff);
        if ($i) {
            if (!selMail.size && !selFin.size)               { $i.dataset.state = 'empty'; $i.textContent = '·'; }
            else if (diff === 0 && selMail.size && selFin.size) { $i.dataset.state = 'ok';    $i.textContent = '✅'; }
            else if (selMail.size && selFin.size)              { $i.dataset.state = Math.abs(diff) > 1000 ? 'err' : 'warn'; $i.textContent = '⚠'; }
            else                                                 { $i.dataset.state = 'warn';  $i.textContent = '∙'; }
        }
        const $make  = document.getElementById('pd3OutLinkMakeBtn');
        if ($make) $make.toggleAttribute('disabled', !(selMail.size > 0 && selFin.size > 0));
    }

    function afterMutation(result) {
        links = Array.isArray(result?.links) ? result.links : links;
        // Re-render tbodies so row colours follow the new link set,
        // then ask the renderer to redraw connectors.
        renderOutMail(mailRows, links);
        renderOutFinance(finRows, links);
        selMail.clear();
        selFin.clear();
        recomputeSelection();
        renderer?.setLinks(links);
    }

    // Flood protection: while a fetch is in flight, every reload
    // button is disabled and shows the .is-busy spinner. A re-entry
    // attempt aborts (no queue — the user gets the freshest data
    // from whichever click wins).
    let loading = false;
    async function load() {
        if (loading) return;
        loading = true;
        const reloadButtons = [
            document.getElementById('pd3OutMailReloadBtn'),
            document.getElementById('pd3OutFinanceReloadBtn'),
        ].filter(Boolean);
        reloadButtons.forEach((b) => { b.disabled = true; b.classList.add('is-busy'); });
        try {
            const data = await api.get('/payday3/api/out/data?' + rangeQs(state));
            mailRows = data.mail    || [];
            finRows  = data.finance || [];
            links    = data.links   || [];
            renderOutMail(mailRows, links);
            renderOutFinance(finRows, links);
            updateOutFooter(mailRows, finRows);
            buildRenderer();
            renderer.setLinks(links);
            selMail.clear();
            selFin.clear();
            recomputeSelection();
            loaded = true;
        } catch (e) {
            const tb = document.querySelector('#pd3OutMailTable tbody');
            if (tb) tb.innerHTML = `<tr class="pd3-empty"><td colspan="6">Не удалось загрузить: ${e.message || 'ошибка'}</td></tr>`;
            console.error('[payday3-out]', e);
        } finally {
            loading = false;
            reloadButtons.forEach((b) => { b.disabled = false; b.classList.remove('is-busy'); });
        }
    }

    // Lazy: first time OUT tab becomes active.
    const watchActivation = () => {
        if (document.body.classList.contains('pd3-mode-out') && !loaded) load();
    };
    watchActivation();
    document.querySelectorAll('.pd3-tab').forEach((t) => t.addEventListener('click', () => setTimeout(watchActivation, 0)));

    // Selection toggle
    document.body.addEventListener('change', (e) => {
        const t = e.target;
        if (!(t instanceof HTMLInputElement)) return;
        if (t.classList.contains('pd3-cb--out-mail')) {
            const uid = Number(t.dataset.mailUid);
            t.checked ? selMail.add(uid) : selMail.delete(uid);
            recomputeSelection();
        } else if (t.classList.contains('pd3-cb--out-finance')) {
            const fid = Number(t.dataset.financeId);
            t.checked ? selFin.add(fid) : selFin.delete(fid);
            recomputeSelection();
        }
    });

    // Reload buttons just re-call load — mail and finance share a fetch.
    document.getElementById('pd3OutMailReloadBtn')?.addEventListener('click', () => load());
    document.getElementById('pd3OutFinanceReloadBtn')?.addEventListener('click', () => load());

    // Mid-col actions
    document.getElementById('pd3OutLinkAutoBtn')?.addEventListener('click', async (e) => {
        const btn = e.currentTarget;
        if (btn.disabled) return; btn.disabled = true;
        try {
            const r = await api.post('/payday3/api/out/links/auto?' + rangeQs(state));
            afterMutation(r);
        } catch (err) { alert(err.message); } finally { btn.disabled = false; }
    });
    document.getElementById('pd3OutLinkMakeBtn')?.addEventListener('click', async (e) => {
        const btn = e.currentTarget;
        if (btn.disabled) return; btn.disabled = true;
        try {
            const r = await api.post('/payday3/api/out/links/manual?' + rangeQs(state), {
                mailUids:   [...selMail],
                financeIds: [...selFin],
            });
            afterMutation(r);
        } catch (err) { alert(err.message); } finally { btn.disabled = false; }
    });
    document.getElementById('pd3OutLinkClearBtn')?.addEventListener('click', async (e) => {
        const r0 = state.get('range') || {};
        const period = r0.from === r0.to ? r0.from : `${r0.from} — ${r0.to}`;
        if (!confirm(`Снять ВСЕ OUT-связи за период ${period}?`)) return;
        const btn = e.currentTarget; btn.disabled = true;
        try {
            const r = await api.post('/payday3/api/out/links/clear?' + rangeQs(state));
            afterMutation(r);
        } catch (err) { alert(err.message); } finally { btn.disabled = false; }
    });

    // Hide mail row from its hide-button.
    document.body.addEventListener('click', async (e) => {
        const t = e.target.closest?.('.pd3-out-mail-hide');
        if (!t) return;
        const uid = Number(t.dataset.mailUid);
        if (!uid) return;
        const cmt = prompt('Комментарий к скрытию (необязательно):', '') ?? '';
        try {
            await api.post('/payday3/api/out/mail/hide?' + rangeQs(state), { mailUid: uid, comment: cmt });
            await load();   // refresh mail list (the hidden row drops out unless eye is on)
        } catch (err) { alert(err.message); }
    });

    // Hide-linked eye toggle in mid-col.
    const $hideLinked = document.getElementById('pd3OutHideLinkedBtn');
    if ($hideLinked) {
        let pressed = false;
        $hideLinked.addEventListener('click', () => {
            pressed = !pressed;
            $hideLinked.setAttribute('aria-pressed', pressed ? 'true' : 'false');
            document.querySelectorAll('#pd3OutMailTable .pd3-row.row-green, #pd3OutMailTable .pd3-row.row-yellow, #pd3OutMailTable .pd3-row.row-gray, #pd3OutFinanceTable .pd3-row.row-green, #pd3OutFinanceTable .pd3-row.row-yellow, #pd3OutFinanceTable .pd3-row.row-gray')
                .forEach((r) => r.classList.toggle('is-hidden', pressed));
            renderer?.redraw();
        });
    }

    // Hidden-mail eye toggle on the mail card — reloads with include_hidden.
    let showHiddenMail = false;
    document.getElementById('pd3OutMailHiddenToggle')?.addEventListener('click', async (e) => {
        showHiddenMail = !showHiddenMail;
        e.currentTarget.setAttribute('aria-pressed', showHiddenMail ? 'true' : 'false');
        try {
            const data = await api.get('/payday3/api/out/data?include_hidden=' + (showHiddenMail ? '1' : '0') + '&' + rangeQs(state));
            mailRows = data.mail    || [];
            finRows  = data.finance || [];
            links    = data.links   || [];
            renderOutMail(mailRows, links);
            renderOutFinance(finRows, links);
            updateOutFooter(mailRows, finRows);
            renderer?.setLinks(links);
            recomputeSelection();
        } catch (err) { alert(err.message); }
    });

    // Public handle so other modules (createTx after a successful
    // finance.createTransactions) can ask OUT to refetch without
    // re-implementing the load logic. Returns the load() promise so
    // callers can `await` it.
    return {
        reload: () => load(),
    };
}
