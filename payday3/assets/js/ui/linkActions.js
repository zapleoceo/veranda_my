// Wire mid-col buttons to backend mutations. After every successful
// call the row classes are recomputed from the fresh link set and
// LineRenderer is asked to redraw.

'use strict';

import { api } from '../api.js';

function dateQuery(range) {
    const p = new URLSearchParams();
    if (range?.from) p.set('dateFrom', range.from);
    if (range?.to)   p.set('dateTo',   range.to);
    return p.toString();
}

/**
 * Recompute row CSS classes from the fresh link list.
 * Mirrors src/Payday3/Domain/RowState::classify on the client.
 */
function reclassifyRows(links) {
    const bySepay  = new Map();
    const byPoster = new Map();
    for (const l of links) {
        if (!bySepay.has(l.sepay_id))                 bySepay.set(l.sepay_id, []);
        if (!byPoster.has(l.poster_transaction_id))   byPoster.set(l.poster_transaction_id, []);
        bySepay.get(l.sepay_id).push(l);
        byPoster.get(l.poster_transaction_id).push(l);
    }
    const classify = (edges) => {
        if (!edges || edges.length === 0) return 'row-red';
        let manual = false, yellow = false;
        for (const e of edges) {
            if (e.is_manual) manual = true;
            if (e.link_type === 'auto_yellow') yellow = true;
        }
        if (manual) return 'row-gray';
        return yellow ? 'row-yellow' : 'row-green';
    };

    document.querySelectorAll('#pd3SepayTable tr.pd3-row').forEach((tr) => {
        if (tr.classList.contains('row-hidden')) return;  // hidden rows keep their class
        const sid = Number(tr.dataset.sepayId);
        const next = classify(bySepay.get(sid));
        ['row-red','row-green','row-yellow','row-gray'].forEach((c) => tr.classList.remove(c));
        tr.classList.add(next);
    });
    document.querySelectorAll('#pd3PosterTable tr.pd3-row').forEach((tr) => {
        const pid = Number(tr.dataset.posterId);
        const next = classify(byPoster.get(pid));
        ['row-red','row-green','row-yellow','row-gray'].forEach((c) => tr.classList.remove(c));
        tr.classList.add(next);
    });
}

function refreshFooterStats() {
    const rows = document.querySelectorAll('#pd3SepayTable tr.pd3-row');
    let linked = 0, unlinked = 0;
    rows.forEach((r) => {
        if (r.classList.contains('row-hidden')) return;
        if (r.classList.contains('row-red')) unlinked++;
        else linked++;
    });
    const $l = document.getElementById('pd3SepayLinked');
    const $u = document.getElementById('pd3SepayUnlinked');
    if ($l) $l.textContent = String(linked);
    if ($u) $u.textContent = String(unlinked);
}

function uncheckAll(selection) {
    document.querySelectorAll('.pd3-cb').forEach((cb) => { cb.checked = false; });
    // Programmatic `.checked = false` doesn't fire a change event, so
    // we manually reset the selection state and force a recompute.
    selection.sepayIds.clear();
    selection.posterIds.clear();
    selection.recompute();
}

function flash(msg, isErr = false) {
    // Non-blocking toast. Replace with a real component later.
    if (isErr) console.error('[payday3]', msg); else console.info('[payday3]', msg);
}

export function initLinkActions({ state, renderer, selection }) {
    const range = state.get('range') || {};
    const qs    = () => dateQuery(state.get('range') || {});

    const after = (result) => {
        const links = Array.isArray(result?.links) ? result.links : [];
        state.set('links', links);
        reclassifyRows(links);
        refreshFooterStats();
        renderer.setLinks(links);
        uncheckAll(selection);
    };

    const $auto   = document.getElementById('pd3LinkAutoBtn');
    const $make   = document.getElementById('pd3LinkMakeBtn');
    const $clear  = document.getElementById('pd3LinkClearBtn');

    $auto?.addEventListener('click', async () => {
        if ($auto.disabled) return;
        $auto.disabled = true;
        try {
            const r = await api.post('/payday3/api/links/auto?' + qs());
            after(r);
            flash(`Авто-связи: добавлено ${r.added}, всего ${r.total}`);
        } catch (e) {
            flash(e.message, true);
        } finally {
            $auto.disabled = false;
        }
    });

    $make?.addEventListener('click', async () => {
        if ($make.disabled) return;
        const sepayIds  = [...selection.sepayIds];
        const posterIds = [...selection.posterIds];
        if (!sepayIds.length || !posterIds.length) return;
        $make.disabled = true;
        try {
            const r = await api.post('/payday3/api/links/manual?' + qs(), { sepayIds, posterIds });
            after(r);
            flash(`Ручные связи: добавлено ${r.added}`);
        } catch (e) {
            flash(e.message, true);
        } finally {
            $make.disabled = false;
        }
    });

    $clear?.addEventListener('click', async () => {
        if ($clear.disabled) return;
        const r = state.get('range') || {};
        const period = r.from === r.to ? r.from : `${r.from} — ${r.to}`;
        if (!confirm(`Снять ВСЕ связи Sepay↔Poster за период ${period}?\n\nЭто удалит и авто-, и ручные связи в выбранном диапазоне дат. Селект чекбоксов не учитывается.`)) return;
        $clear.disabled = true;
        try {
            const result = await api.post('/payday3/api/links/clear?' + qs());
            after(result);
            flash(`Связи очищены (${result.removed ?? 0})`);
        } catch (e) {
            flash(e.message, true);
        } finally {
            $clear.disabled = false;
        }
    });

    // Per-link unlink hook (called from LineRenderer's × button).
    // Receives the full link record so OUT-mode can reuse the same hook
    // shape with different id fields.
    return async function onUnlink(link) {
        const sid = Number(link?.sepay_id);
        const pid = Number(link?.poster_transaction_id);
        if (!sid || !pid) return;
        try {
            const r = await api.delete(`/payday3/api/links/${sid}/${pid}?${qs()}`);
            after(r);
        } catch (e) {
            flash(e.message, true);
        }
    };
}
