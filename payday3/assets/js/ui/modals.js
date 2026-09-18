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

const _i = (await import(new URL('./cacheBust.js' + new URL(import.meta.url).search, import.meta.url).href)).importer(import.meta.url);
const [
    { modalHost },
    { initKashShift },
    { initSupplies },
    { initCheckFinder },
    { initSettings },
] = await Promise.all([
    _i('./modal/host.js'),
    _i('./modal/kashShift.js'),
    _i('./modal/supplies.js'),
    _i('./modal/checkFinder.js'),
    _i('./modal/settings.js'),
]);

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
