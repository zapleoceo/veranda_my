// /poster-app/assets/js/widget.js
//
// POS-side widget that:
//   1. Waits for Poster's POS SDK to expose `window.Poster`
//   2. On userLogin / shiftOpen events — POSTs to our backend so we
//      learn the PIN (user.posPass) and start a work shift
//   3. On shiftClose / manual "End shift" — POSTs to close the shift
//
// Stateless 12h HMAC token comes back from /login and is stored in
// memory only. NO cookies, NO localStorage — the widget runs in a
// third-party iframe where both are flaky and cookies would need
// SameSite=None which we don't want to flip for the rest of the site.

'use strict';

import { PosterBridge } from './bridge.js';
import { ApiClient }    from './apiClient.js';
import { ShiftUi }      from './ui.js';

const cfg = window.__pa || {};
const ui  = new ShiftUi(document);
const api = new ApiClient(cfg.apiBase || '/poster-app/api');

ui.log('info', 'Widget loaded, ожидаю POS SDK…');

const bridge = await PosterBridge.connect({ timeoutMs: 8000 }).catch((err) => {
    ui.log('error', 'POS SDK не загрузился: ' + err.message);
    ui.allowManual({ open: false, close: false });   // nothing we can do without SDK
    return null;
});

if (bridge) {
    ui.log('ok', 'POS SDK подключён');
    await handshake(bridge);
    subscribeEvents(bridge);
    wireManualButtons(bridge);
}

/**
 * On widget mount: ask the POS who's currently logged in. If anyone
 * is — we already have all the info we need to learn their PIN +
 * open a shift. Acts as a recovery step in case userLogin fired
 * before our widget loaded.
 */
async function handshake(bridge) {
    try {
        const user = await bridge.getActiveUser();
        if (!user) {
            ui.setUser(null);
            ui.setShift(null);
            ui.allowManual({ open: false, close: false });
            ui.log('info', 'В POS никто не залогинен');
            return;
        }
        await loginToBackend(user);
    } catch (err) {
        ui.log('error', 'Handshake failed: ' + err.message);
    }
}

function subscribeEvents(bridge) {
    bridge.on('userLogin', async ({ user }) => {
        ui.log('info', 'Login: ' + (user?.name || user?.id));
        await loginToBackend(user);
    });
    bridge.on('userLogout', () => {
        ui.log('info', 'Logout');
        api.clearToken();
        ui.setUser(null);
        ui.setShift(null);
        ui.allowManual({ open: false, close: false });
    });
    bridge.on('shiftOpen', async ({ shift }) => {
        ui.log('info', 'POS shiftOpen #' + shift?.id);
        try {
            const r = await api.shiftStart({ poster_shift_id: shift?.id ?? null });
            ui.setShift(r.shift);
            ui.log('ok', 'Смена открыта (poster_shift_id=' + (shift?.id ?? '—') + ')');
        } catch (err) { ui.log('error', 'shift-start: ' + err.message); }
    });
    bridge.on('shiftClose', async ({ shift }) => {
        ui.log('info', 'POS shiftClose #' + shift?.id);
        try {
            await api.shiftEnd({ poster_shift_id: shift?.id ?? null });
            ui.setShift(null);
            ui.log('ok', 'Смена закрыта');
        } catch (err) { ui.log('error', 'shift-end: ' + err.message); }
    });
}

function wireManualButtons(bridge) {
    ui.onManualOpen(async () => {
        const u = await bridge.getActiveUser();
        if (!u) { ui.log('error', 'Нет активного пользователя в POS'); return; }
        await loginToBackend(u, { thenStart: true });
    });
    ui.onManualClose(async () => {
        try {
            await api.shiftEnd({});
            ui.setShift(null);
            ui.log('ok', 'Смена закрыта');
        } catch (err) { ui.log('error', 'shift-end: ' + err.message); }
    });
}

/**
 * Identify the active POS user to our backend. The backend learns
 * their PIN hash and (if not already open) starts a work shift,
 * then mints a 12h token that subsequent shift-start/end requests
 * carry in the Authorization header.
 */
async function loginToBackend(user, opts) {
    if (!user) return;
    try {
        const r = await api.login({
            poster_user_id: user.id,
            pin:            user.posPass || '',
            name:           user.name    || '',
            admin:          !!user.admin,
        });
        ui.setUser({ name: r.user?.name || user.name, admin: !!user.admin });
        ui.setShift(r.shift);
        ui.allowManual({ open: !r.shift, close: !!r.shift });
        ui.log('ok', 'Авторизован: ' + (user.name || user.id));
        if (opts?.thenStart && !r.shift) {
            const r2 = await api.shiftStart({});
            ui.setShift(r2.shift);
            ui.log('ok', 'Смена открыта');
        }
    } catch (err) {
        ui.log('error', 'login: ' + err.message);
    }
}
