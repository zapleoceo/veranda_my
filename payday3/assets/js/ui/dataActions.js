// Sync / clear-day buttons. Each one POSTs to a Slim endpoint, shows
// a spinner on its button while the request is in flight, then asks
// the IN-mode loader to re-fetch the snapshot so the freshly-synced
// rows show up — no page reload, no flash, no scroll-reset.

'use strict';

import { api } from '../api.js';

function dateQuery(range) {
    const p = new URLSearchParams();
    if (range?.from) p.set('dateFrom', range.from);
    if (range?.to)   p.set('dateTo',   range.to);
    return p.toString();
}

function withBusy(btn, label, fn) {
    return async () => {
        if (btn.disabled) return;
        btn.disabled = true;
        const prev = btn.getAttribute('aria-label');
        btn.setAttribute('aria-label', label);
        btn.classList.add('is-busy');
        try {
            await fn();
        } catch (e) {
            console.error('[payday3]', e);
            alert(e?.message || 'Ошибка');
        } finally {
            btn.disabled = false;
            if (prev) btn.setAttribute('aria-label', prev);
            else btn.removeAttribute('aria-label');
            btn.classList.remove('is-busy');
        }
    };
}

export function initDataActions({ state, refresh }) {
    const range = () => state.get('range') || {};
    const qs    = () => dateQuery(range());
    const refreshAll = async () => {
        if (typeof refresh === 'function') await refresh();
    };

    const $sepaySync  = document.getElementById('pd3SepaySyncBtn');
    const $posterSync = document.getElementById('pd3PosterSyncBtn');
    const $clearDay   = document.getElementById('pd3ClearDayBtn');

    $sepaySync?.addEventListener('click', withBusy(
        $sepaySync, 'Loading sepay...',
        async () => {
            await api.post('/payday3/api/sepay/sync?' + qs());
            await refreshAll();
        },
    ));

    $posterSync?.addEventListener('click', withBusy(
        $posterSync, 'Loading poster...',
        async () => {
            await api.post('/payday3/api/poster/sync?' + qs());
            await refreshAll();
        },
    ));

    $clearDay?.addEventListener('click', withBusy(
        $clearDay, 'Resetting...',
        async () => {
            const r = range();
            const sameDay = r?.from === r?.to;
            const msg = sameDay
                ? `Soft-reset за ${r.from}?\n\nВсе записи Sepay и Poster за этот день будут помечены was_deleted=1. Следующая синхронизация их восстановит.`
                : `Soft-reset за период ${r?.from} — ${r?.to}?\n\nВсе записи Sepay и Poster в диапазоне будут помечены was_deleted=1. Следующая синхронизация их восстановит.`;
            if (!confirm(msg)) return;
            await api.post('/payday3/api/day/clear?' + qs());
            await refreshAll();
        },
    ));
}
