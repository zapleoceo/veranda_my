// Modals — facade over one module per feature (ui/modal/*):
//   host.js         the overlay: open / close / register, Esc, backdrop, ×
//   kashShift.js    «Кассовые смены»
//   supplies.js     «Поставки»
//   checkFinder.js  «Поиск чека»
//   settings.js     «Настройки»
// The «i» info pane needs no loader — it is registered bare.
//
// Public API unchanged: initModals({ state }) and modalHost.

'use strict';

import { modalHost } from './modal/host.js';
import { initKashShift } from './modal/kashShift.js';
import { initSupplies } from './modal/supplies.js';
import { initCheckFinder } from './modal/checkFinder.js';
import { initSettings } from './modal/settings.js';

export { modalHost };

export function initModals({ state }) {
    if (!modalHost.init()) return;
    modalHost.register('pd3InfoModal', { trigger: 'pd3InfoBtn' });
    const deps = { host: modalHost, state };
    initSettings(deps);
    initKashShift(deps);
    initSupplies(deps);
    initCheckFinder(deps);
}
