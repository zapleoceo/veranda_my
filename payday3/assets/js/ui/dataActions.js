// Sync / clear-day buttons. Each one POSTs to a Slim endpoint, shows
// a spinner on its button while the request is in flight, then asks
// the IN-mode loader to re-fetch the snapshot so the freshly-synced
// rows show up — no page reload, no flash, no scroll-reset.
//
// «Деньги» ↻ (#pd3SepaySyncBtn) is the ONE listener for that button: it
// syncs SePay + refreshes the incoming side AND reloads the outgoing
// (mail) block via `reloadOut`, under one busy spinner.

'use strict';

import { api }       from '../api.js';
import { withRange } from './format.js';
import { withBusy }  from './busy.js';

const alertError = (e) => {
    console.error('[payday3]', e);
    alert(e?.message || 'Ошибка');
};

/**
 * @param {{state:object, refresh:()=>Promise<void>, reloadOut?:()=>Promise<void>}} deps
 *   refresh   — re-fetch the incoming side (+ finance card) after a write;
 *   reloadOut — re-fetch the outgoing side (only on the operator's ↻).
 * @returns {{autoFill:()=>Promise<void>}}  first-paint SePay + Poster sync
 *   of an empty day — without an extra outgoing reload (it already loads).
 */
export function initDataActions({ state, refresh, reloadOut = null }) {
    const range = () => state.get('range') || {};
    const refreshAll = async () => {
        if (typeof refresh === 'function') await refresh();
    };

    const $sepaySync  = document.getElementById('pd3SepaySyncBtn');
    const $posterSync = document.getElementById('pd3PosterSyncBtn');
    const $clearDay   = document.getElementById('pd3ClearDayBtn');

    const syncSepay = async () => {
        await api.post(withRange('/payday3/api/sepay/sync', range()));
        await refreshAll();
    };
    const syncPoster = async () => {
        await api.post(withRange('/payday3/api/poster/sync', range()));
        await refreshAll();
    };

    const sepayBusy = (fn) => withBusy($sepaySync, fn, { label: 'Loading sepay...', onError: alertError });
    const posterRun = withBusy($posterSync, syncPoster, { label: 'Loading poster...', onError: alertError });

    $sepaySync?.addEventListener('click', sepayBusy(async () => {
        // The outgoing reload runs alongside the sync (independent data);
        // its own failures are reported by out/bootstrap.js.
        const out = reloadOut ? Promise.resolve(reloadOut()).catch(() => {}) : null;
        await syncSepay();
        await out;
    }));

    $posterSync?.addEventListener('click', posterRun);

    $clearDay?.addEventListener('click', withBusy($clearDay, async () => {
        const r = range();
        const sameDay = r?.from === r?.to;
        const msg = sameDay
            ? `Soft-reset за ${r.from}?\n\nВсе записи Sepay и Poster за этот день будут помечены was_deleted=1. Следующая синхронизация их восстановит.`
            : `Soft-reset за период ${r?.from} — ${r?.to}?\n\nВсе записи Sepay и Poster в диапазоне будут помечены was_deleted=1. Следующая синхронизация их восстановит.`;
        if (!confirm(msg)) return;
        await api.post(withRange('/payday3/api/day/clear', range()));
        await refreshAll();
    }, { label: 'Resetting...', onError: alertError }));

    return {
        autoFill: async () => {
            await Promise.all([sepayBusy(syncSepay)(), posterRun()]);
        },
    };
}
