// Финансовые транзакции card. Loads GET /payday3/api/finance/transfers
// on page boot (and on the operator's manual reload click), renders the
// total + mini-table per row, and decides whether the "Создать
// транзакцию" button is enabled — disabled when the expected total is
// 0/missing or when a matching transfer already exists in Poster.

'use strict';

// Cache-bust cross-module imports — see comment in out/bootstrap.js.
const _v = new URL(import.meta.url).searchParams.get('v') || '';
const _qs = _v ? '?v=' + encodeURIComponent(_v) : '';
const { api } = await import(new URL('../api.js' + _qs, import.meta.url).href);

const KINDS = ['vietnam', 'tips'];

const fmt = (n) => {
    if (n === null || n === undefined) return '—';
    const v = Math.round(Number(n) || 0);
    try { return new Intl.NumberFormat('en-US', { maximumFractionDigits: 0 }).format(v).replace(/,/g, ' '); }
    catch (_) { return String(v).replace(/\B(?=(\d{3})+(?!\d))/g, ' '); }
};

const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
})[c]);

const fmtDate = (ts) => {
    const d = new Date((Number(ts) || 0) * 1000);
    if (Number.isNaN(d.getTime())) return '—';
    const p = (n) => String(n).padStart(2, '0');
    return `${p(d.getDate())}.${p(d.getMonth() + 1)}.${d.getFullYear()}`;
};

const fmtTime = (ts) => {
    const d = new Date((Number(ts) || 0) * 1000);
    if (Number.isNaN(d.getTime())) return '';
    const p = (n) => String(n).padStart(2, '0');
    return `${p(d.getHours())}:${p(d.getMinutes())}:${p(d.getSeconds())}`;
};

function renderMiniTable(rows) {
    if (!rows || rows.length === 0) return '';
    const body = rows.map((f) => {
        const isOut = f.type === '0' || String(f.type).toLowerCase() === 'out' || String(f.type).toUpperCase() === 'O';
        const sumMinor = Math.abs(Number(f.sum_minor) || 0);
        // normMoneyMinor() on the server already returns VND — no
        // extra /100 needed here (that was the double-division bug).
        const sumVnd  = sumMinor;
        const signed  = isOut ? -sumVnd : sumVnd;
        return `<tr>
            <td class="pd3-finance-td">${esc(fmtDate(f.ts))}<br><span class="muted">${esc(fmtTime(f.ts))}</span></td>
            <td class="pd3-finance-td right">${esc(fmt(signed))}</td>
            <td class="pd3-finance-td">${esc(f.account || '')}</td>
            <td class="pd3-finance-td">${esc(f.user || '')}</td>
            <td class="pd3-finance-td-comment">${esc(f.comment || '')}</td>
        </tr>`;
    }).join('');
    return `<div class="pd3-finance__scroll">
        <table class="pd3-table pd3-finance__mini">
            <thead><tr>
                <th>Дата<br><span class="pd3-fw-normal">Время</span></th>
                <th class="right">Сумма</th>
                <th>Счет</th>
                <th>Кто</th>
                <th>Комментарий</th>
            </tr></thead>
            <tbody>${body}</tbody>
        </table>
    </div>`;
}

function renderRow(kind, payload) {
    const totalEl  = document.getElementById('pd3FinanceTotal_'  + kind);
    const statusEl = document.getElementById('pd3FinanceStatus_' + kind);
    const btn      = document.getElementById('pd3FinanceCreateBtn_' + kind);
    if (!totalEl || !statusEl) return;

    const totalVnd = payload?.total_vnd ?? null;
    const found    = payload?.found ?? [];

    totalEl.textContent = totalVnd === null ? '—' : fmt(totalVnd);

    // Already-exists detection mirrors payday2: any found transfer
    // whose VND magnitude equals the expected VND total.
    // sum_minor from the server is already in VND (normMoneyMinor
    // handles the Poster-cents heuristic server-side).
    let exists = false;
    if (totalVnd !== null && totalVnd > 0 && Array.isArray(found)) {
        for (const f of found) {
            if (Math.abs(Number(f.sum_minor) || 0) === totalVnd) { exists = true; break; }
        }
    }

    let disabled = true;
    if (totalVnd === null) {
        statusEl.innerHTML = 'Нет данных за период. Нажми загрузку Poster чеков.';
    } else if (totalVnd <= 0) {
        statusEl.innerHTML = 'Сумма = 0 за выбранный период.';
    } else if (exists) {
        statusEl.innerHTML = renderMiniTable(found);
    } else {
        statusEl.innerHTML = found.length > 0
            ? renderMiniTable(found)
            : '<span class="pd3-finance__empty">Транзакция не найдена</span>';
        disabled = false;
    }
    if (btn) btn.disabled = disabled;
}

let inFlight = false;
let lastAccounts = null;

async function load(state) {
    if (inFlight) return;
    inFlight = true;
    const btn = document.getElementById('pd3FinanceReloadBtn');
    btn?.classList.add('is-busy');
    if (btn) btn.disabled = true;
    try {
        const range = state.get('range') || {};
        const p = new URLSearchParams();
        if (range.from) p.set('dateFrom', range.from);
        if (range.to)   p.set('dateTo',   range.to);
        const data = await api.get('/payday3/api/finance/transfers?' + p.toString());
        if (!data) return;
        lastAccounts = data.accounts || null;
        renderRow('vietnam', data.vietnam);
        renderRow('tips',    data.tips);
    } catch (e) {
        for (const k of KINDS) {
            const s = document.getElementById('pd3FinanceStatus_' + k);
            if (s) s.textContent = 'Ошибка: ' + (e.message || 'load failed');
        }
    } finally {
        inFlight = false;
        btn?.classList.remove('is-busy');
        if (btn) btn.disabled = false;
    }
}

export function initFinanceTransfers({ state }) {
    if (!document.getElementById('pd3Finance')) return { reload: () => {} };

    document.getElementById('pd3FinanceReloadBtn')?.addEventListener('click', () => load(state));

    // "Создать" buttons → POST /payday3/api/finance/transfers/create.
    // Server walks today's finance.getTransactions for an idempotent
    // duplicate first, then POSTs finance.createTransactions if not
    // found. Status strip shows progress; on success we reload the
    // card so the freshly-created transaction appears in the mini-
    // table and the button disables itself.
    document.querySelectorAll('.pd3-finance__create').forEach((b) => {
        b.addEventListener('click', async () => {
            const kind = b.dataset.kind;
            const status = document.getElementById('pd3FinanceStatus_' + kind);
            if (!kind || b.disabled) return;
            b.disabled = true;
            if (status) status.innerHTML = '<span class="muted">Создаю в Poster…</span>';
            try {
                const range = state.get('range') || {};
                const res = await api.post('/payday3/api/finance/transfers/create', {
                    kind,
                    dateFrom: range.from || '',
                    dateTo:   range.to   || range.from || '',
                });
                if (status) {
                    status.innerHTML = res?.already
                        ? '<span class="muted">Уже была создана сегодня.</span>'
                        : '<span class="muted">Создана в Poster. Обновляю…</span>';
                }
                await load(state);            // reload list so the new tx appears
            } catch (err) {
                if (status) status.innerHTML = '<span class="pd3-finance__empty">Ошибка: '
                    + (err.message || 'не удалось создать').replace(/[<>&]/g, '') + '</span>';
                b.disabled = false;
            }
        });
    });

    load(state);
    return { reload: () => load(state) };
}
