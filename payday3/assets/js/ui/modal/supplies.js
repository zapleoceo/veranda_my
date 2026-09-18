// «Поставки» modal — Poster supplies of the period.

'use strict';

const _i = (await import(new URL('../cacheBust.js' + new URL(import.meta.url).search, import.meta.url).href)).importer(import.meta.url);
const { api }            = await _i('../../api.js');
const { esc, withRange } = await _i('../format.js');

function render(supplies, accounts) {
    if (!supplies.length) return '<p class="muted">Поставок за период не найдено.</p>';
    // Poster's finance.getAccounts returns the human-readable label
    // under `name` — payday2 used the same key.
    const accLabel = (id) => {
        const a = accounts.find((x) => Number(x.account_id) === Number(id));
        return a ? (a.name || a.account_name || a.title || ('#' + id)) : ('#' + id);
    };
    return `<table class="pd3-table">
        <thead><tr><th>ID</th><th>Дата</th><th>Поставщик</th><th class="right">Сумма</th><th>Аккаунт</th></tr></thead>
        <tbody>${supplies.map((s) => `<tr>
            <td class="nowrap">${esc(s.supply_id || s.id || '')}</td>
            <td class="nowrap">${esc(s.supply_date_start || s.date || '')}</td>
            <td>${esc(s.supplier_name || s.supplier || '')}</td>
            <td class="right nowrap">${esc(s.supply_sum || s.sum || '')}</td>
            <td>${esc(accLabel(s.account_id))}</td>
        </tr>`).join('')}</tbody></table>`;
}

async function load(state) {
    const body = document.getElementById('pd3SuppliesBody');
    if (!body) return;
    body.innerHTML = '<p class="pd3-modal__loading">Загрузка поставок…</p>';
    try {
        const data = await api.get(withRange('/payday3/api/poster/supplies', state.get('range')));
        body.innerHTML = render(data.supplies || [], data.accounts || []);
    } catch (e) {
        body.innerHTML = `<p class="muted">Не удалось загрузить: ${esc(e.message || 'ошибка')}.</p>`;
    }
}

export function initSupplies({ host, state }) {
    host.register('pd3SuppliesModal', { trigger: 'pd3SuppliesBtn', onOpen: () => load(state) });
}
