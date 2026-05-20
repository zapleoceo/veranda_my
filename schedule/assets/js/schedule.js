// /schedule — full state-driven UI.
// Phase 2B: structure mutations (slot add/del, block add/del), period live,
// real save/load via AJAX, snapshots, custom zones.
//
// Architecture:
//   1. Boot data from #schBootData → App.{state, period, employees, halls, zones, snapshots}
//   2. Cell edits update App.state.shifts, debounced save POSTs to server
//   3. Structure changes (add/del slot or block) → POST → reload page so server
//      re-renders the new grid template (avoids client-side grid rebuild)
//   4. Period changes → reload with new ?from=&to= query

'use strict';

(() => {

    // ════════════════ Boot ════════════════
    const boot = (() => {
        try { return JSON.parse(document.getElementById('schBootData').textContent); }
        catch (e) { return null; }
    })();
    if (!boot) { console.warn('[schedule] no boot data'); return; }

    const App = {
        state:     boot.state     || { blocks: [], shifts: {}, templates: [] },
        period:    boot.period    || { from: '', to: '' },
        employees: boot.employees || [],
        halls:     boot.halls     || [],
        zones:     boot.zones     || [],
        snapshots: boot.snapshots || [],
        dirty:     false,
        saving:    false,
    };
    if (Array.isArray(App.state.shifts)) App.state.shifts = {};
    if (!App.state.shifts || typeof App.state.shifts !== 'object') App.state.shifts = {};

    const empById = new Map();
    App.employees.forEach((e) => empById.set(e.id, e));


    // ════════════════ Helpers ════════════════
    function pad2(n) { return String(n).padStart(2, '0'); }
    function esc(s) {
        return String(s).replace(/[&<>"']/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c]);
    }
    function fmtShortTime(hhmm) {
        if (!hhmm) return '';
        return hhmm.endsWith(':00') ? hhmm.slice(0, 2) : hhmm;
    }
    function fmtTimeRange(s, e) {
        return `${fmtShortTime(s)}–${fmtShortTime(e)}`;
    }
    function blockColor(block) {
        const c = block?.color;
        if (['senior', 'main', 'banya', 'custom'].includes(c)) return c;
        if (block?.type === 'senior') return 'senior';
        if (block?.type === 'custom') return 'custom';
        if (block?.id === 'hall:2') return 'banya';
        return 'main';
    }


    // ════════════════ Toast ════════════════
    let toastEl;
    function toast(msg, kind = 'ok') {
        if (!toastEl) {
            toastEl = document.createElement('div');
            toastEl.className = 'sch-toast';
            document.body.appendChild(toastEl);
        }
        toastEl.textContent = msg;
        toastEl.dataset.kind = kind;
        toastEl.classList.add('visible');
        clearTimeout(toast._t);
        toast._t = setTimeout(() => toastEl.classList.remove('visible'), 2400);
    }


    // ════════════════ AJAX ════════════════
    async function api(action, opts = {}) {
        const method = (opts.method || 'GET').toUpperCase();
        const url = `/schedule/?ajax=${encodeURIComponent(action)}` + (opts.query ? `&${opts.query}` : '');
        const init = { method, credentials: 'same-origin', headers: { 'Accept': 'application/json' } };
        if (method !== 'GET' && opts.body) {
            init.headers['Content-Type'] = 'application/json';
            init.body = JSON.stringify(opts.body);
        }
        const res = await fetch(url, init);
        const txt = await res.text();
        let j;
        try { j = JSON.parse(txt); }
        catch (_) { throw new Error('Bad JSON response (' + res.status + ')'); }
        if (!res.ok || !j.ok) throw new Error(j.error || `HTTP ${res.status}`);
        return j;
    }


    // ════════════════ State manipulation ════════════════
    function setShift(iso, blockId, slot, shift) {
        if (!App.state.shifts[iso]) App.state.shifts[iso] = {};
        App.state.shifts[iso][`${blockId}:${slot}`] = shift;
        App.dirty = true;
    }
    function delShift(iso, blockId, slot) {
        if (!App.state.shifts[iso]) return;
        delete App.state.shifts[iso][`${blockId}:${slot}`];
        if (Object.keys(App.state.shifts[iso]).length === 0) delete App.state.shifts[iso];
        App.dirty = true;
    }
    function getShift(iso, blockId, slot) {
        return App.state.shifts?.[iso]?.[`${blockId}:${slot}`] || null;
    }


    // ════════════════ Render chip into cell ════════════════
    function renderChip(cell, shift) {
        const blockId = cell.dataset.block;
        const blk = App.state.blocks.find((b) => b.id === blockId);
        const color = blk ? blockColor(blk) : 'main';
        if (!shift) {
            const empty = (color === 'banya' || color === 'custom') ? '—' : '+';
            cell.innerHTML = `<span class="sch-empty">${empty}</span>`;
            return;
        }
        const emp = shift.emp_id ? empById.get(shift.emp_id) : null;
        const name = emp?.name || shift.emp_name || '?';
        const star = (color === 'senior' && emp?.can_be_senior) ? ' ★' : '';
        const time = fmtTimeRange(shift.start, shift.end);
        cell.innerHTML = `<div class="sch-shift ${color}" draggable="true"><span class="sch-name">${esc(name)}${star}</span><span class="sch-time">${esc(time)}</span></div>`;
    }


    // ════════════════ Help mode + floating tooltip ════════════════
    const helpBtn = document.getElementById('schHelpBtn');
    if (helpBtn) {
        let helpOn = false;
        helpBtn.addEventListener('click', () => {
            helpOn = !helpOn;
            document.body.classList.toggle('sch-help-mode', helpOn);
            helpBtn.setAttribute('aria-pressed', helpOn ? 'true' : 'false');
            if (!helpOn) hideTip();
        });
    }
    const tip = document.createElement('div');
    tip.className = 'sch-help-tip';
    document.body.appendChild(tip);
    let tipTarget = null;
    function showTip(el) {
        const txt = el.getAttribute('data-help-abs');
        if (!txt) return;
        tipTarget = el;
        tip.textContent = txt;
        tip.classList.add('visible');
        tip.style.left = '-9999px'; tip.style.top = '0px';
        const tw = tip.offsetWidth, th = tip.offsetHeight;
        const r = el.getBoundingClientRect();
        const vw = window.innerWidth, vh = window.innerHeight;
        const margin = 8, gap = 12;
        let left = r.left + r.width / 2 - tw / 2;
        if (left < margin) left = margin;
        if (left + tw > vw - margin) left = vw - tw - margin;
        let top, arrow;
        if (r.top >= th + gap || r.top >= vh - r.bottom) {
            top = r.top - th - gap; arrow = 'above';
            if (top < margin) top = margin;
        } else {
            top = r.bottom + gap; arrow = 'below';
            if (top + th > vh - margin) top = vh - th - margin;
        }
        tip.setAttribute('data-arrow', arrow);
        tip.style.left = left + 'px'; tip.style.top = top + 'px';
    }
    function hideTip() { tip.classList.remove('visible'); tipTarget = null; }
    document.addEventListener('mouseover', (e) => {
        if (!document.body.classList.contains('sch-help-mode')) return;
        const el = e.target.closest('[data-help-abs]');
        if (el && el !== tipTarget) showTip(el);
    });
    document.addEventListener('mouseout', (e) => {
        if (!document.body.classList.contains('sch-help-mode')) return;
        const el = e.target.closest('[data-help-abs]');
        if (!el) return;
        const to = e.relatedTarget && e.relatedTarget.closest && e.relatedTarget.closest('[data-help-abs]');
        if (to === el) return;
        hideTip();
    });
    window.addEventListener('scroll', hideTip, true);
    window.addEventListener('resize', hideTip);


    // ════════════════ Save (debounced + manual) ════════════════
    let saveTimer = null;
    function saveDebounced() {
        return new Promise((resolve) => {
            if (saveTimer) clearTimeout(saveTimer);
            saveTimer = setTimeout(async () => {
                await saveNow().catch(() => {});
                resolve();
            }, 800);
        });
    }
    async function saveNow(label = 'auto') {
        if (App.saving) return;
        App.saving = true;
        try {
            const j = await api('save', { method: 'POST', body: { state: App.state, label } });
            App.snapshots = j.snapshots || App.snapshots;
            App.dirty = false;
            renderSnapshots();
            toast('Сохранено ✓');
        } catch (e) {
            toast('Ошибка сохранения: ' + e.message, 'err');
            console.error(e);
        } finally {
            App.saving = false;
        }
    }

    document.getElementById('schSaveBtn')?.addEventListener('click', () => saveNow('manual'));
    document.getElementById('schSaveSnapBtn')?.addEventListener('click', () => {
        const label = prompt('Название версии:', 'Версия ' + new Date().toLocaleDateString('ru-RU'));
        if (label) saveNow(label);
    });


    // ════════════════ Save+reload (for structure mutations) ════════════════
    async function saveAndReload(reason) {
        if (App.saving) return;
        App.saving = true;
        try {
            await api('save', { method: 'POST', body: { state: App.state, label: 'auto' } });
            toast(reason + ' ✓');
            // Give toast a moment, then reload page (server re-renders grid structure)
            setTimeout(() => location.reload(), 400);
        } catch (e) {
            App.saving = false;
            toast('Ошибка: ' + e.message, 'err');
            console.error(e);
        }
    }


    // ════════════════ Cell popover ════════════════
    const popover = document.getElementById('schPopover');
    const popMeta = document.getElementById('schPopoverMeta');
    const popEmp  = document.getElementById('schPopoverEmp');
    const popFrom = document.getElementById('schPopoverFrom');
    const popTo   = document.getElementById('schPopoverTo');
    const popHall = document.getElementById('schPopoverHall');
    let popAnchor = null;

    function populatePopoverDropdowns(forSenior) {
        // Employees: filter by in_schedule + (can_be_senior if senior block)
        let html = '<option value="">— не назначен —</option>';
        App.employees
            .filter((e) => e.in_schedule)
            .filter((e) => !forSenior || e.can_be_senior)
            .forEach((e) => {
                const star = e.can_be_senior ? ' ★' : '';
                html += `<option value="${e.id}">${esc(e.name)}${star} (${esc(e.tag)})</option>`;
            });
        popEmp.innerHTML = html;

        // Halls + custom zones
        let hhtml = '<option value="">— любой —</option>';
        App.halls.forEach((h) => {
            hhtml += `<option value="${h.id}">${esc(h.icon || '')} ${esc(h.name)}</option>`;
        });
        App.zones.forEach((z) => {
            hhtml += `<option value="zone:${z.id}">${esc(z.icon || '🌿')} ${esc(z.name)}</option>`;
        });
        popHall.innerHTML = hhtml;
    }

    function showPopover(cell) {
        popAnchor = cell;
        const blockId = cell.dataset.block || '';
        const slotIdx = parseInt(cell.dataset.slot, 10) || 0;
        const iso     = cell.dataset.dayIso || '';
        const block   = App.state.blocks.find((b) => b.id === blockId);
        const isSenior = block && blockColor(block) === 'senior';

        popMeta.textContent = `${block?.icon || ''} ${block?.name || ''} · слот ${slotIdx + 1} · ${iso}`;
        populatePopoverDropdowns(isSenior);

        const existing = getShift(iso, blockId, slotIdx);
        // Parse default time from slot if no existing
        let dStart = '09:00', dEnd = '17:00';
        const dt = block?.slots?.[slotIdx]?.defaultTime || '';
        const dmm = dt.match(/^(\d{2}:\d{2})-(\d{2}:\d{2})$/);
        if (dmm) { dStart = dmm[1]; dEnd = dmm[2]; }

        if (existing) {
            popEmp.value  = existing.emp_id || '';
            popFrom.value = existing.start || dStart;
            popTo.value   = existing.end   || dEnd;
            popHall.value = existing.hall_id ? String(existing.hall_id) : '';
        } else {
            popEmp.value  = '';
            popFrom.value = dStart;
            popTo.value   = dEnd;
            popHall.value = '';
        }

        popover.classList.add('visible');
        popover.style.left = '-9999px'; popover.style.top = '0px';
        const pw = popover.offsetWidth, ph = popover.offsetHeight;
        const r = cell.getBoundingClientRect();
        const vw = window.innerWidth, vh = window.innerHeight;
        const margin = 10, gap = 10;
        let left = r.left + r.width / 2 - pw / 2;
        if (left < margin) left = margin;
        if (left + pw > vw - margin) left = vw - pw - margin;
        let top, arrow;
        if (vh - r.bottom >= ph + gap || vh - r.bottom >= r.top) {
            top = r.bottom + gap; arrow = 'above';
            if (top + ph > vh - margin) top = vh - ph - margin;
        } else {
            top = r.top - ph - gap; arrow = 'below';
            if (top < margin) top = margin;
        }
        popover.setAttribute('data-arrow', arrow);
        popover.style.left = left + 'px'; popover.style.top = top + 'px';
    }
    function hidePopover() {
        popover.classList.remove('visible');
        popAnchor = null;
    }

    document.getElementById('schPopoverSave')?.addEventListener('click', async () => {
        if (!popAnchor) return;
        const empId = parseInt(popEmp.value, 10) || 0;
        const start = popFrom.value || '09:00';
        const end   = popTo.value   || '17:00';
        const hall  = popHall.value || '';
        if (empId === 0) {
            delShift(popAnchor.dataset.dayIso, popAnchor.dataset.block, popAnchor.dataset.slot);
            renderChip(popAnchor, null);
        } else {
            const emp = empById.get(empId);
            const sh = { emp_id: empId, emp_name: emp?.name || '', start, end };
            if (hall) sh.hall_id = hall;
            setShift(popAnchor.dataset.dayIso, popAnchor.dataset.block, popAnchor.dataset.slot, sh);
            renderChip(popAnchor, sh);
        }
        hidePopover();
        await saveDebounced();
    });
    document.getElementById('schPopoverDel')?.addEventListener('click', async () => {
        if (!popAnchor) return;
        delShift(popAnchor.dataset.dayIso, popAnchor.dataset.block, popAnchor.dataset.slot);
        renderChip(popAnchor, null);
        hidePopover();
        await saveDebounced();
    });
    document.getElementById('schPopoverCancel')?.addEventListener('click', hidePopover);

    document.addEventListener('click', (e) => {
        if (document.body.classList.contains('sch-help-mode')) return;
        if (e.target.closest('.sch-slot-del')) return;
        if (e.target.closest('.sch-block-del')) return;
        if (e.target.closest('.sch-block-add-slot')) return;
        if (e.target.closest('#schPopover')) return;
        const cell = e.target.closest('.sch-cell[data-block]');
        if (cell) { e.preventDefault(); showPopover(cell); return; }
        if (popAnchor) hidePopover();
    });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') { hidePopover(); closeAddBlockModal(); }
    });


    // ════════════════ Structure mutations ════════════════

    // × on individual slot
    document.addEventListener('click', async (e) => {
        const del = e.target.closest('.sch-slot-del');
        if (!del) return;
        e.preventDefault(); e.stopPropagation();
        const blkIdx = parseInt(del.dataset.blockIdx, 10);
        const sIdx   = parseInt(del.dataset.slotIdx, 10);
        const block  = App.state.blocks[blkIdx];
        if (!block) return;
        if (block.slots.length <= 1) {
            toast('Это единственный слот в блоке. Удалите блок целиком через ⋮.', 'err');
            return;
        }
        // Count shifts in this slot
        let count = 0;
        Object.values(App.state.shifts).forEach((day) => {
            if (day[`${block.id}:${sIdx}`]) count++;
        });
        const warn = count > 0 ? `\nВ этом слоте ${count} смен(ы) — они исчезнут.` : '';
        if (!confirm(`Удалить слот ${sIdx + 1} блока «${block.name}»?${warn}`)) return;

        // Remove the slot and shift all higher-indexed slot shifts down by one
        block.slots.splice(sIdx, 1);
        Object.keys(App.state.shifts).forEach((iso) => {
            const day = App.state.shifts[iso];
            // Delete the removed slot's shifts
            delete day[`${block.id}:${sIdx}`];
            // Shift down keys above the removed index
            for (let k = sIdx + 1; k <= 20; k++) {
                if (day[`${block.id}:${k}`]) {
                    day[`${block.id}:${k - 1}`] = day[`${block.id}:${k}`];
                    delete day[`${block.id}:${k}`];
                }
            }
        });

        await saveAndReload(`Слот удалён`);
    });

    // + slot in block header
    document.addEventListener('click', async (e) => {
        const btn = e.target.closest('.sch-block-add-slot');
        if (!btn) return;
        e.preventDefault(); e.stopPropagation();
        const blkIdx = parseInt(btn.dataset.blockIdx, 10);
        const block = App.state.blocks[blkIdx];
        if (!block) return;
        if (block.slots.length >= 12) {
            toast('Максимум 12 слотов в блоке', 'err');
            return;
        }
        // Inherit default time from last slot
        const lastTime = block.slots[block.slots.length - 1]?.defaultTime || '09:00-17:00';
        block.slots.push({ label: '', defaultTime: lastTime });
        await saveAndReload(`Слот добавлен`);
    });

    // ⋮ delete block
    document.addEventListener('click', async (e) => {
        const btn = e.target.closest('.sch-block-del');
        if (!btn) return;
        e.preventDefault(); e.stopPropagation();
        const blkIdx = parseInt(btn.dataset.blockIdx, 10);
        const block = App.state.blocks[blkIdx];
        if (!block) return;
        if (App.state.blocks.length <= 1) {
            toast('Нельзя удалить последний блок', 'err');
            return;
        }
        // Count shifts in this block
        let count = 0;
        Object.values(App.state.shifts).forEach((day) => {
            Object.keys(day).forEach((k) => { if (k.startsWith(block.id + ':')) count++; });
        });
        const warn = count > 0 ? `\nВ этом блоке ${count} смен — они исчезнут.` : '';
        if (!confirm(`Удалить блок «${block.name}» целиком?${warn}`)) return;

        // Remove block + all its shifts
        App.state.blocks.splice(blkIdx, 1);
        Object.keys(App.state.shifts).forEach((iso) => {
            const day = App.state.shifts[iso];
            Object.keys(day).forEach((k) => {
                if (k.startsWith(block.id + ':')) delete day[k];
            });
            if (Object.keys(day).length === 0) delete App.state.shifts[iso];
        });

        // For custom blocks, also soft-delete the zone in DB
        if (block.type === 'custom' && block.zone_id) {
            try { await api('del_zone', { method: 'POST', body: { id: block.zone_id } }); }
            catch (_) {}
        }

        await saveAndReload(`Блок удалён`);
    });


    // ════════════════ Add-block modal ════════════════
    const modal = document.getElementById('schModalAddBlock');
    const modalCloseBtn = document.getElementById('schModalAddBlockClose');
    const radioCards = document.querySelectorAll('#schBlockTypeRadio .card');
    const hallGroup   = document.getElementById('schBlockHallGroup');
    const customGroup = document.getElementById('schBlockCustomGroup');
    const hallSelect  = document.getElementById('schBlockHallSelect');

    function openAddBlockModal() {
        modal?.classList.add('visible');
        // Populate hall select with halls not already used
        const usedHallIds = new Set(
            App.state.blocks
                .filter((b) => b.type === 'hall' && b.hall_id)
                .map((b) => parseInt(b.hall_id, 10))
        );
        let html = '';
        App.halls.forEach((h) => {
            const used = usedHallIds.has(h.id);
            html += `<option value="${h.id}" ${used ? 'disabled' : ''}>${esc(h.icon || '')} ${esc(h.name)} (hall_id ${h.id})${used ? ' — уже добавлен' : ''}</option>`;
        });
        if (hallSelect) hallSelect.innerHTML = html;
    }
    function closeAddBlockModal() { modal?.classList.remove('visible'); }

    document.getElementById('schAddBlockBtn')?.addEventListener('click', (e) => {
        e.preventDefault(); openAddBlockModal();
    });
    modalCloseBtn?.addEventListener('click', closeAddBlockModal);
    modal?.addEventListener('click', (e) => { if (e.target === modal) closeAddBlockModal(); });
    radioCards.forEach((c) => {
        c.addEventListener('click', () => {
            radioCards.forEach((x) => x.classList.remove('active'));
            c.classList.add('active');
            const isHall = c.dataset.type === 'hall';
            if (hallGroup)   hallGroup.style.display   = isHall ? '' : 'none';
            if (customGroup) customGroup.style.display = isHall ? 'none' : '';
        });
    });

    document.getElementById('schModalAddBlockSave')?.addEventListener('click', async () => {
        const activeType = document.querySelector('#schBlockTypeRadio .card.active')?.dataset.type || 'hall';
        const slotCount = Math.max(1, Math.min(10, parseInt(document.getElementById('schBlockSlots')?.value, 10) || 1));

        let newBlock;
        if (activeType === 'custom') {
            const name = document.getElementById('schBlockCustomName')?.value?.trim();
            const icon = (document.getElementById('schBlockCustomIcon')?.value?.trim()) || '🌿';
            if (!name) { toast('Введи название зоны', 'err'); return; }
            try {
                const j = await api('add_zone', { method: 'POST', body: { name, icon } });
                App.zones = j.zones;
                const newZone = j.zones.find((z) => z.name === name);
                newBlock = {
                    id:    'zone:' + j.id,
                    type:  'custom',
                    color: 'custom',
                    zone_id: j.id,
                    name, icon,
                    slots: makeSlots(slotCount, '18:00-23:00', 'по брони'),
                };
            } catch (e) { toast('Ошибка: ' + e.message, 'err'); return; }
        } else {
            const hallId = parseInt(hallSelect?.value, 10);
            if (!hallId) { toast('Выбери зал', 'err'); return; }
            const hall = App.halls.find((h) => h.id === hallId);
            if (!hall) { toast('Зал не найден', 'err'); return; }
            const color = hall.id === 2 ? 'banya' : 'main';
            newBlock = {
                id:    'hall:' + hallId,
                type:  'hall',
                color,
                hall_id: hallId,
                name: hall.name,
                icon: hall.icon || '🏛',
                slots: makeSlots(slotCount, color === 'banya' ? '10:00-18:00' : '09:00-17:00', ''),
            };
        }

        App.state.blocks.push(newBlock);
        closeAddBlockModal();
        await saveAndReload('Блок добавлен');
    });

    function makeSlots(count, defaultTime, label) {
        const slots = [];
        for (let i = 0; i < count; i++) slots.push({ label, defaultTime });
        return slots;
    }


    // ════════════════ Period selector ════════════════
    const periodFromInput = document.getElementById('schPeriodFrom');
    const periodToInput   = document.getElementById('schPeriodTo');

    function navigateToPeriod(from, to) {
        const u = new URL(location.href);
        u.searchParams.set('from', from);
        u.searchParams.set('to', to);
        location.href = u.toString();
    }
    periodFromInput?.addEventListener('change', () => {
        if (periodFromInput.value && periodToInput.value) {
            navigateToPeriod(periodFromInput.value, periodToInput.value);
        }
    });
    periodToInput?.addEventListener('change', () => {
        if (periodFromInput.value && periodToInput.value) {
            navigateToPeriod(periodFromInput.value, periodToInput.value);
        }
    });
    document.getElementById('schPeriodPrev')?.addEventListener('click', () => {
        const from = new Date(App.period.from);
        const to   = new Date(App.period.to);
        const days = Math.round((to - from) / 86400000) + 1;
        from.setDate(from.getDate() - days);
        to.setDate(to.getDate() - days);
        navigateToPeriod(from.toISOString().slice(0, 10), to.toISOString().slice(0, 10));
    });
    document.getElementById('schPeriodNext')?.addEventListener('click', () => {
        const from = new Date(App.period.from);
        const to   = new Date(App.period.to);
        const days = Math.round((to - from) / 86400000) + 1;
        from.setDate(from.getDate() + days);
        to.setDate(to.getDate() + days);
        navigateToPeriod(from.toISOString().slice(0, 10), to.toISOString().slice(0, 10));
    });
    document.querySelectorAll('[data-period-preset]').forEach((b) => {
        b.addEventListener('click', () => {
            const preset = b.getAttribute('data-period-preset');
            if (preset === 'custom') {
                periodFromInput?.focus();
                return;
            }
            const days = parseInt(preset, 10) || 14;
            const from = new Date(App.period.from);
            const to = new Date(from);
            to.setDate(to.getDate() + days - 1);
            navigateToPeriod(from.toISOString().slice(0, 10), to.toISOString().slice(0, 10));
        });
    });


    // ════════════════ Clear period / copy week ════════════════
    document.getElementById('schClearPeriod')?.addEventListener('click', async () => {
        if (!confirm('Удалить все смены за выбранный период?')) return;
        // Iterate over period days, delete shifts on each
        const from = new Date(App.period.from);
        const to   = new Date(App.period.to);
        for (let d = new Date(from); d <= to; d.setDate(d.getDate() + 1)) {
            const iso = d.toISOString().slice(0, 10);
            delete App.state.shifts[iso];
        }
        App.dirty = true;
        await saveNow('clear');
        location.reload();
    });

    document.getElementById('schCopyWeek')?.addEventListener('click', async () => {
        const from = new Date(App.period.from);
        const to   = new Date(App.period.to);
        const days = Math.round((to - from) / 86400000) + 1;
        if (days !== 7) {
            toast('Кнопка работает только для недельного периода. Сейчас ' + days + ' дней.', 'err');
            return;
        }
        // Copy each day's shifts to next week
        let copied = 0;
        for (let i = 0; i < 7; i++) {
            const src = new Date(from); src.setDate(src.getDate() + i);
            const dst = new Date(src);  dst.setDate(dst.getDate() + 7);
            const srcIso = src.toISOString().slice(0, 10);
            const dstIso = dst.toISOString().slice(0, 10);
            if (App.state.shifts[srcIso]) {
                App.state.shifts[dstIso] = JSON.parse(JSON.stringify(App.state.shifts[srcIso]));
                copied += Object.keys(App.state.shifts[srcIso]).length;
            }
        }
        if (copied === 0) {
            toast('Нет смен для копирования', 'err');
            return;
        }
        App.dirty = true;
        await saveNow('copy-week');
        toast(`Скопировано ${copied} смен на следующую неделю`);
        // Navigate to the next week
        const nextFrom = new Date(from); nextFrom.setDate(nextFrom.getDate() + 7);
        const nextTo   = new Date(to);   nextTo.setDate(nextTo.getDate() + 7);
        navigateToPeriod(nextFrom.toISOString().slice(0, 10), nextTo.toISOString().slice(0, 10));
    });


    // ════════════════ Snapshots ════════════════
    function renderSnapshots() {
        const wrap = document.querySelector('.sch-snapshots');
        if (!wrap) return;
        const saveBtn = document.getElementById('schSaveSnapBtn');
        wrap.querySelectorAll('.sch-snap-pill').forEach((el) => el.remove());
        App.snapshots.forEach((s) => {
            const pill = document.createElement('span');
            pill.className = 'sch-snap-pill' + (s.is_current ? ' current' : '');
            pill.dataset.snapId = s.id;
            const when = (s.created_at || '').replace(/^(\d{4})-(\d{2})-(\d{2}) (\d{2}:\d{2}).*$/, '$3.$2 $4');
            pill.innerHTML = `${esc(s.label || 'auto')} <span class="when">${esc(when)}</span>`;
            if (!s.is_current) pill.addEventListener('click', () => loadSnapshot(s.id));
            wrap.insertBefore(pill, saveBtn?.previousElementSibling || saveBtn);
        });
    }
    async function loadSnapshot(id) {
        if (!confirm('Загрузить эту версию? Текущие несохранённые изменения пропадут.')) return;
        try {
            const j = await api('snapshot', { query: 'id=' + id });
            App.state = j.state;
            if (Array.isArray(App.state.shifts)) App.state.shifts = {};
            if (!App.state.shifts || typeof App.state.shifts !== 'object') App.state.shifts = {};
            await api('save', { method: 'POST', body: { state: App.state, label: 'restored-' + id } });
            toast('Версия загружена');
            setTimeout(() => location.reload(), 400);
        } catch (e) {
            toast('Ошибка: ' + e.message, 'err');
        }
    }
    // Wire existing snapshot pills (server-rendered)
    document.querySelectorAll('.sch-snap-pill[data-snap-id]:not(.current)').forEach((pill) => {
        pill.addEventListener('click', () => loadSnapshot(parseInt(pill.dataset.snapId, 10)));
    });


    // ════════════════ Heatmap rebucketization ════════════════
    const statsEl   = document.getElementById('schStatsData');
    const bucketSel = document.getElementById('schBucketSize');
    const filterSel = document.getElementById('schCoverageFilter');
    const covGrid   = document.getElementById('schCovGrid');
    if (statsEl && bucketSel && filterSel && covGrid) {
        let stats;
        try { stats = JSON.parse(statsEl.textContent); } catch (_) { stats = null; }
        if (stats) initHeatmap(stats);
    }
    function initHeatmap(stats) {
        const startH = stats.hourStart || 8;
        const endH   = stats.hourEnd   || 24;
        function applyFilter(filter) {
            const totals = new Array(24).fill(0);
            const perDay = stats.days.map((d) => {
                const h = new Array(24).fill(0);
                for (let i = 0; i < 24; i++) {
                    if (filter === 'all') {
                        h[i] = (d.hours?.senior?.[i] || 0) + (d.hours?.main?.[i] || 0)
                             + (d.hours?.banya?.[i]  || 0) + (d.hours?.custom?.[i] || 0);
                    } else {
                        h[i] = d.hours?.[filter]?.[i] || 0;
                    }
                    totals[i] += h[i];
                }
                return h;
            });
            return { perDay, totals };
        }
        function bucketize(hours, size) {
            const out = [];
            for (let h = startH; h < endH; h += size) {
                let max = 0;
                for (let k = 0; k < size && h + k < endH; k++) max = Math.max(max, hours[h + k]);
                out.push({ from: h, to: Math.min(h + size, endH), max });
            }
            return out;
        }
        function redrawMatrix(perDay, bs) {
            const buckets = perDay.map((h) => bucketize(h, bs));
            let globalMax = 0;
            buckets.forEach((b) => b.forEach((c) => { if (c.max > globalMax) globalMax = c.max; }));
            if (globalMax < 1) globalMax = 1;
            const cols = buckets[0]?.length || 0;
            covGrid.style.setProperty('--cov-cols', cols);
            let html = '<div class="sch-cov-corner">День \\ Час</div>';
            if (buckets.length > 0) {
                buckets[0].forEach((c) => {
                    html += `<div class="sch-cov-col-head">${pad2(c.from)}–${pad2(c.to)}</div>`;
                });
            }
            stats.days.forEach((d, idx) => {
                const wk = d.weekend ? ' weekend' : '';
                html += `<div class="sch-cov-row-head${wk}">${esc(d.dow)} ${esc(d.date)}</div>`;
                buckets[idx].forEach((c) => {
                    const intensity = c.max / globalMax;
                    const alpha = c.max > 0 ? 0.05 + intensity * 0.9 : 0;
                    const txt   = intensity > 0.55 ? '#0f1117' : 'var(--text)';
                    html += `<div class="sch-cov-cell" style="background: rgba(184,135,70,${alpha}); color: ${txt};" title="пик ${c.max} чел/ч в ${pad2(c.from)}–${pad2(c.to)}">${c.max > 0 ? c.max : '·'}</div>`;
                });
            });
            covGrid.innerHTML = html;
        }
        function redrawHistogram(totals) {
            const grid = document.querySelector('.sch-agg-histogram .sch-bar-grid');
            const h4 = document.querySelector('.sch-agg-histogram h4');
            if (!grid) return;
            const avgs = totals.map((v) => v / Math.max(1, stats.dayCount));
            const max = Math.max(1, ...avgs);
            let html = '';
            for (let h = startH; h < endH; h++) {
                const avg = +avgs[h].toFixed(1);
                const w = max > 0 ? Math.round((avg / max) * 1000) / 10 : 0;
                html += `<div class="sch-bar-label">${pad2(h)}:00</div><div class="sch-bar-track"><div class="sch-bar-fill" style="width: ${w}%;"></div></div><div class="sch-bar-value">${avg} чел</div>`;
            }
            grid.innerHTML = html;
            if (h4) {
                const filter = filterSel.options[filterSel.selectedIndex]?.textContent || '';
                h4.textContent = `Средняя загрузка по часам за период (${stats.dayCount} дней) · ${filter}`;
            }
        }
        function redraw() {
            const filter = filterSel.value;
            const bs = parseInt(bucketSel.value, 10) || 2;
            const { perDay, totals } = applyFilter(filter);
            redrawMatrix(perDay, bs);
            redrawHistogram(totals);
        }
        bucketSel.addEventListener('change', redraw);
        filterSel.addEventListener('change', redraw);
    }


    // ════════════════ Staff modal ════════════════
    const staffModal     = document.getElementById('schModalStaff');
    const staffTableBody = staffModal?.querySelector('tbody');

    function renderStaffTable() {
        if (!staffTableBody) return;
        let html = '';
        App.employees.forEach((e) => {
            html += `
                <tr data-uid="${e.id}" style="border-bottom: 1px solid var(--border);">
                    <td style="padding: 8px 10px;"><strong>${esc(e.name)}</strong><br>
                        <span style="color: var(--muted); font-size: 10px;">user_id ${e.id}</span></td>
                    <td style="padding: 8px 10px; color: var(--muted);">${esc(e.poster_role || '')}</td>
                    <td style="padding: 8px 10px;">
                        <input type="text" class="staff-tag" value="${esc(e.tag || '')}"
                               style="width: 110px; background: var(--bg); border: 1px solid var(--border); color: var(--text); border-radius: 6px; padding: 4px 8px; font-family: inherit;">
                    </td>
                    <td style="padding: 8px 10px; text-align: center;">
                        <input type="checkbox" class="staff-active" ${e.in_schedule ? 'checked' : ''}
                               style="width: 18px; height: 18px; accent-color: var(--accent);">
                    </td>
                    <td style="padding: 8px 10px; text-align: center;">
                        <button type="button" class="staff-senior" data-on="${e.can_be_senior ? '1' : '0'}"
                                style="background: transparent; border: 0; cursor: pointer; font-size: 18px; color: ${e.can_be_senior ? 'var(--accent)' : 'var(--border)'}; padding: 0;">
                            ${e.can_be_senior ? '★' : '☆'}
                        </button>
                    </td>
                    <td style="padding: 8px 10px; text-align: right;">
                        <input type="number" class="staff-rate" value="${e.rate_per_hour || 0}" step="1000" min="0"
                               style="width: 100px; background: var(--bg); border: 1px solid var(--border); color: var(--text); border-radius: 6px; padding: 4px 8px; font-family: inherit; text-align: right;">
                    </td>
                </tr>`;
        });
        staffTableBody.innerHTML = html;

        staffTableBody.querySelectorAll('.staff-senior').forEach((btn) => {
            btn.addEventListener('click', () => {
                const on = btn.getAttribute('data-on') === '1';
                const next = !on;
                btn.setAttribute('data-on', next ? '1' : '0');
                btn.textContent = next ? '★' : '☆';
                btn.style.color = next ? 'var(--accent)' : 'var(--border)';
            });
        });
    }

    document.getElementById('schStaffBtn')?.addEventListener('click', () => {
        renderStaffTable();
        staffModal?.classList.add('visible');
    });
    document.getElementById('schModalStaffClose')?.addEventListener('click', () => {
        staffModal?.classList.remove('visible');
    });
    staffModal?.addEventListener('click', (e) => {
        if (e.target === staffModal) staffModal.classList.remove('visible');
    });

    document.getElementById('schModalStaffSave')?.addEventListener('click', async () => {
        if (!staffTableBody) return;
        const tags = [];
        staffTableBody.querySelectorAll('tr[data-uid]').forEach((tr) => {
            tags.push({
                user_id:       parseInt(tr.dataset.uid, 10),
                in_schedule:   tr.querySelector('.staff-active')?.checked || false,
                can_be_senior: tr.querySelector('.staff-senior')?.getAttribute('data-on') === '1',
                custom_tag:    tr.querySelector('.staff-tag')?.value || '',
                rate_per_hour: parseInt(tr.querySelector('.staff-rate')?.value, 10) || 0,
                only_in_blocks: '',
            });
        });
        try {
            const j = await api('save_staff_tags', { method: 'POST', body: { tags } });
            App.employees = j.employees;
            App.employees.forEach((e) => empById.set(e.id, e));
            toast('Теги сохранены ✓');
            staffModal?.classList.remove('visible');
            // Re-render cells so star/name updates pick up immediately
            document.querySelectorAll('.sch-cell[data-day-iso][data-block]').forEach((cell) => {
                const sh = getShift(cell.dataset.dayIso, cell.dataset.block, cell.dataset.slot);
                if (sh) renderChip(cell, sh);
            });
        } catch (e) {
            toast('Ошибка: ' + e.message, 'err');
        }
    });

    document.getElementById('schReloadPoster')?.addEventListener('click', async (e) => {
        e.preventDefault();
        try {
            const j = await api('reload_poster');
            App.employees = j.employees;
            App.halls     = j.halls;
            App.employees.forEach((emp) => empById.set(emp.id, emp));
            renderStaffTable();
            toast('Кэш сброшен, данные перезагружены');
        } catch (err) {
            toast('Ошибка: ' + err.message, 'err');
        }
    });


    // ════════════════ Drag-n-drop ════════════════
    let dragSrc = null;
    document.addEventListener('dragstart', (e) => {
        const chip = e.target.closest('.sch-shift');
        if (!chip) return;
        dragSrc = chip.closest('.sch-cell');
        chip.style.opacity = '0.4';
        e.dataTransfer.effectAllowed = 'move';
    });
    document.addEventListener('dragend', (e) => {
        const chip = e.target.closest('.sch-shift');
        if (chip) chip.style.opacity = '';
        dragSrc = null;
    });
    document.addEventListener('dragover', (e) => {
        const cell = e.target.closest('.sch-cell[data-block]');
        if (!cell || !dragSrc || cell === dragSrc) return;
        e.preventDefault();
        cell.style.outline = '2px dashed var(--accent)';
    });
    document.addEventListener('dragleave', (e) => {
        const cell = e.target.closest('.sch-cell[data-block]');
        if (cell) cell.style.outline = '';
    });
    document.addEventListener('drop', async (e) => {
        const cell = e.target.closest('.sch-cell[data-block]');
        if (!cell || !dragSrc || cell === dragSrc) return;
        e.preventDefault();
        cell.style.outline = '';
        const sIso = dragSrc.dataset.dayIso, sBlk = dragSrc.dataset.block, sSlot = dragSrc.dataset.slot;
        const dIso = cell.dataset.dayIso,    dBlk = cell.dataset.block,    dSlot = cell.dataset.slot;
        const sShift = getShift(sIso, sBlk, sSlot);
        const dShift = getShift(dIso, dBlk, dSlot);
        if (!sShift) { dragSrc = null; return; }
        if (dShift) {
            setShift(sIso, sBlk, sSlot, dShift);
            renderChip(dragSrc, dShift);
        } else {
            delShift(sIso, sBlk, sSlot);
            renderChip(dragSrc, null);
        }
        setShift(dIso, dBlk, dSlot, sShift);
        renderChip(cell, sShift);
        dragSrc = null;
        await saveDebounced();
    });

})();
