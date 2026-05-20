// Location picker — bottom sheet with cascading Spot → Hall → Table.
//
// Spot dropdown hidden when there's only one spot (auto-selected).
// Tables render as a grid of pill buttons for fast tapping.

'use strict';

const _self = new URL(import.meta.url);
const _v    = _self.searchParams.get('v') || '';
const _qs   = _v ? '?v=' + encodeURIComponent(_v) : '';
const { t } = await import(new URL('../i18n.js' + _qs, import.meta.url).href);

const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
})[c]);

function render(state) {
    const $spotWrap = document.getElementById('noSpotWrap');
    const $spot     = document.getElementById('noSpotSelect');
    const $hall     = document.getElementById('noHallSelect');
    const $tables   = document.getElementById('noTableGrid');
    if (!$spot || !$hall || !$tables) return;

    const spots = state.s.spots;
    $spotWrap.hidden = spots.length <= 1;
    $spot.innerHTML = [`<option value="">${esc(t('spot'))}…</option>`,
        ...spots.map((s) => `<option value="${s.id}" ${s.id === state.s.spotId ? 'selected' : ''}>${esc(s.name)}</option>`)].join('');

    // Halls scoped to the chosen spot.
    const halls = state.s.halls.filter((h) => !state.s.spotId || h.spot_id === state.s.spotId);
    $hall.innerHTML = [`<option value="">${esc(t('hallAll'))}</option>`,
        ...halls.map((h) => `<option value="${h.id}" ${h.id === state.s.hallId ? 'selected' : ''}>${esc(h.name)}</option>`)].join('');

    // Tables scoped to spot+hall.
    const hallIds = new Set(halls.map((h) => h.id));
    const tables = state.s.tables.filter((t) => {
        if (!hallIds.has(t.hall_id)) return false;
        if (state.s.hallId && t.hall_id !== state.s.hallId) return false;
        return true;
    });
    // Rename the iter var so we don't shadow our `t()` translation helper.
    $tables.innerHTML = tables.length
        ? tables.map((tbl) => `<button type="button" class="no-table-btn ${tbl.id === state.s.tableId ? 'is-active' : ''}" data-table-id="${tbl.id}">${esc(tbl.name)}</button>`).join('')
        : `<div class="no-empty" style="padding:16px 0">${esc(t('noTables'))}</div>`;

    // Sync the top-bar label.
    const $label = document.getElementById('noLocationLabel');
    const $btn   = document.getElementById('noLocationBtn');
    if ($label && $btn) {
        const tbl = state.findTable(state.s.tableId);
        const sp  = state.findSpot(state.s.spotId);
        const parts = [];
        if (sp) parts.push(sp.name);
        if (tbl) parts.push(t('locationDefault') + ' ' + tbl.name);
        const label = parts.length ? parts.join(' · ') : t('locationDefault');
        $label.textContent = label;
        $btn.classList.toggle('is-set', state.s.tableId > 0);
    }
}

function open() {
    const $sheet = document.getElementById('noLocSheet');
    $sheet.hidden = false;
    $sheet.setAttribute('aria-hidden', 'false');
}
function close() {
    const $sheet = document.getElementById('noLocSheet');
    $sheet.hidden = true;
    $sheet.setAttribute('aria-hidden', 'true');
}

export function initLocationPicker({ state, onChange }) {
    state.on(() => render(state));
    render(state);

    const sheet = document.getElementById('noLocSheet');
    sheet.addEventListener('click', (e) => {
        if (e.target.closest('[data-no-close]')) close();
        const tbtn = e.target.closest('.no-table-btn');
        if (tbtn) {
            state.setTable(Number(tbtn.dataset.tableId));
            onChange?.();
            close();
        }
    });
    document.getElementById('noSpotSelect')?.addEventListener('change', (e) => {
        state.setSpot(Number(e.target.value) || 0);
        onChange?.();
    });
    document.getElementById('noHallSelect')?.addEventListener('change', (e) => {
        state.setHall(Number(e.target.value) || 0);
    });

    return open;
}
