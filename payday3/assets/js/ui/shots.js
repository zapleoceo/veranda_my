// Gallery of saved balance screenshots at the bottom of the payday3 page.
// Data: GET /payday3/api/balances/shots → { items: [{name, at, bytes, mime}], latest }.
// Images: GET /payday3/api/balances/shots/<name>. Refreshes when balances.js
// fires 'pd3:shot-saved' after a successful send.

import { api } from '../api.js';

const DATE_FMT = new Intl.DateTimeFormat('ru-RU', {
    timeZone: 'Asia/Ho_Chi_Minh',
    day: '2-digit', month: '2-digit', year: 'numeric',
    hour: '2-digit', minute: '2-digit',
});

function esc(s) {
    return String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

function fmtAt(iso) {
    if (!iso) return '';
    const d = new Date(iso);
    return Number.isNaN(d.getTime()) ? iso : DATE_FMT.format(d);
}

function setStatus(text) {
    const el = document.getElementById('pd3ShotsStatus');
    if (el) el.textContent = text || '';
}

function render(data) {
    const grid = document.getElementById('pd3ShotsGrid');
    const empty = document.getElementById('pd3ShotsEmpty');
    if (!grid) return;
    const items = Array.isArray(data?.items) ? data.items : [];
    // Keep the empty-state paragraph node, drop everything else.
    grid.querySelectorAll('.pd3-shot').forEach((n) => n.remove());
    if (empty) empty.hidden = items.length > 0;
    for (const it of items) {
        const isLatest = it.name === data.latest;
        const fig = document.createElement('figure');
        fig.className = 'pd3-shot' + (isLatest ? ' pd3-shot--latest' : '');
        fig.innerHTML = `
            <a href="/payday3/api/balances/shots/${esc(it.name)}" target="_blank" rel="noopener">
                <img loading="lazy" alt="Снимок баланса" src="/payday3/api/balances/shots/${esc(it.name)}">
            </a>
            <figcaption>
                ${esc(fmtAt(it.at))}
                ${isLatest ? '<span class="pd3-shot__badge">в 08:00</span>' : ''}
            </figcaption>`;
        grid.appendChild(fig);
    }
    setStatus(items.length ? `Всего: ${items.length}` : '');
}

export async function loadShots() {
    if (!document.getElementById('pd3Shots')) return;
    try {
        render(await api.get('/payday3/api/balances/shots'));
    } catch (e) {
        setStatus('Не удалось загрузить снимки: ' + (e.message || 'error'));
    }
}

export function initShots() {
    if (!document.getElementById('pd3Shots')) return;
    loadShots();
    document.addEventListener('pd3:shot-saved', () => loadShots());
}
