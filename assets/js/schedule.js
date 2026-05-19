// /schedule — full UI скрипт с persistence.
//
// Что внутри:
//   • Boot из server-emitted #schBootData (state + employees + halls + zones + snapshots)
//   • Hydrate state from DOM (only когда state.shifts пустой — для первой
//     визуальной сессии с демо-данными)
//   • Cell click → inline popover (employee select из state.employees) →
//     save обновляет state + DOM + POST /schedule?ajax=save
//   • Slot ×, + slot (демо-лог; реальное изменение blocks/slots — Phase 2B)
//   • + add block → modal (демо POST → real /schedule?ajax=add_zone для custom)
//   • Heatmap rebucketization live
//   • Drag-n-drop chips между ячейками
//   • Snapshot pills: click → load /schedule?ajax=snapshot&id=N → apply to DOM
//   • Save button → manual POST current state
//   • Toast for save/load feedback
//   • help-mode toggle + floating tooltip (без клипа)

'use strict';

(() => {

    // ════════════════ Boot — load state from server-emitted JSON ════════════════
    const boot = (() => {
        try {
            const el = document.getElementById('schBootData');
            return el ? JSON.parse(el.textContent) : null;
        } catch (e) { return null; }
    })();
    if (!boot) {
        console.warn('[schedule] no boot data — page is in read-only demo mode');
    }

    const App = {
        state:     boot?.state     || { blocks: [], shifts: {}, templates: [] },
        period:    boot?.period    || { from: '', to: '' },
        employees: boot?.employees || [],
        halls:     boot?.halls     || [],
        zones:     boot?.zones     || [],
        snapshots: boot?.snapshots || [],
        dirty:     false,
        saving:    false,
    };

    // shifts can be {} or [] from server (json_encode of stdClass / empty array)
    if (Array.isArray(App.state.shifts)) App.state.shifts = {};
    if (!App.state.shifts || typeof App.state.shifts !== 'object') App.state.shifts = {};

    // empId → employee lookup
    const employeesById = new Map();
    App.employees.forEach((e) => employeesById.set(e.id, e));
    const employeesByName = new Map();
    App.employees.forEach((e) => employeesByName.set(e.name, e));


    // ════════════════ Helpers ════════════════
    function pad2(n) { return String(n).padStart(2, '0'); }
    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c]);
    }
    function parseTimeRange(str) {
        // accepts "09:00-17:00", "09-17", "09–17"
        if (!str) return null;
        const m = String(str).match(/(\d{1,2})(?::(\d{2}))?[\s\-–—]+(\d{1,2})(?::(\d{2}))?/);
        if (!m) return null;
        return {
            start: `${pad2(m[1])}:${m[2] || '00'}`,
            end:   `${pad2(m[3])}:${m[4] || '00'}`,
        };
    }
    function fmtShortTime(hhmm) {
        // 09:00 → 09 ; 09:30 → 09:30
        if (!hhmm) return '';
        return hhmm.endsWith(':00') ? hhmm.slice(0, 2) : hhmm;
    }
    function fmtTimeRange(start, end) {
        return `${fmtShortTime(start)}–${fmtShortTime(end)}`;
    }

    // Toast
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
        toast._t = setTimeout(() => toastEl.classList.remove('visible'), 2200);
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
        catch (e) {
            console.error('[schedule] non-JSON response', { url, txt: txt.slice(0, 200) });
            throw new Error('Bad JSON response (' + res.status + ')');
        }
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


    // ════════════════ Hydrate state from DOM (only if state empty) ════════════════
    // Server рендерит демо-смены. Если в БД ещё нет ни одного snapshot'а —
    // state.shifts пустой; вытащим визуальные демо-смены из DOM в state,
    // чтобы при первом save-е они сохранились.
    function hydrateFromDom() {
        if (Object.keys(App.state.shifts).length > 0) return; // state уже есть
        document.querySelectorAll('.sch-cell[data-day-iso][data-block]').forEach((cell) => {
            const chip = cell.querySelector('.sch-shift');
            if (!chip) return;
            const name = (chip.querySelector('.sch-name')?.textContent || '').replace('★', '').trim();
            const time = chip.querySelector('.sch-time')?.textContent || '';
            const range = parseTimeRange(time);
            if (!name || !range) return;
            const emp = employeesByName.get(name);
            setShift(cell.dataset.dayIso, cell.dataset.block, cell.dataset.slot, {
                emp_id:   emp?.id || 0,
                emp_name: name,
                start:    range.start,
                end:      range.end,
            });
        });
        App.dirty = false; // hydration ≠ user edit
    }


    // ════════════════ Cell rendering (state → DOM) ════════════════
    function renderChip(cell, shift) {
        if (!shift) {
            const blockId = cell.dataset.block;
            const empty = (blockId === 'hall:2' || blockId.startsWith('zone:')) ? '—' : '+';
            cell.innerHTML = `<span class="sch-empty">${empty}</span>`;
            return;
        }
        const block = cell.dataset.block;
        const cls = block === 'senior' ? 'senior'
                  : block === 'hall:2' ? 'banya'
                  : block.startsWith('zone:') ? 'custom'
                  : 'main';
        const emp = shift.emp_id ? employeesById.get(shift.emp_id) : null;
        const name = emp?.name || shift.emp_name || '?';
        const star = (block === 'senior' && emp?.can_be_senior) ? ' ★' : '';
        const time = fmtTimeRange(shift.start, shift.end);
        cell.innerHTML = `<div class="sch-shift ${cls}" draggable="true"><span class="sch-name">${escapeHtml(name)}${star}</span><span class="sch-time">${escapeHtml(time)}</span></div>`;
    }
    function applyStateToDom() {
        document.querySelectorAll('.sch-cell[data-day-iso][data-block]').forEach((cell) => {
            const shift = getShift(cell.dataset.dayIso, cell.dataset.block, cell.dataset.slot);
            renderChip(cell, shift);
        });
    }


    // ════════════════ Help mode + tooltip (preserved from v6) ════════════════
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
    tip.setAttribute('role', 'tooltip');
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
        const spaceAbove = r.top, spaceBelow = vh - r.bottom;
        if (spaceAbove >= th + gap || spaceAbove >= spaceBelow) {
            top = r.top - th - gap; arrow = 'above';
            if (top < margin) top = margin;
        } else {
            top = r.bottom + gap; arrow = 'below';
            if (top + th > vh - margin) top = vh - th - margin;
        }
        tip.setAttribute('data-arrow', arrow);
        tip.style.left = left + 'px';
        tip.style.top  = top + 'px';
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


    // ════════════════ Inline popover ════════════════
    const popover = document.getElementById('schPopover');
    const popoverMeta = document.getElementById('schPopoverMeta');
    const popoverEmp  = document.getElementById('schPopoverEmp');
    const popoverFrom = document.getElementById('schPopoverFrom');
    const popoverTo   = document.getElementById('schPopoverTo');
    const popoverHall = document.getElementById('schPopoverHall');
    let popoverAnchor = null;

    // Populate employee/hall dropdowns from real data
    function populateEmployeeDropdown(filterSenior = false) {
        if (!popoverEmp) return;
        let html = '<option value="">— не назначен —</option>';
        App.employees
            .filter((e) => e.in_schedule)
            .filter((e) => !filterSenior || e.can_be_senior)
            .forEach((e) => {
                const star = e.can_be_senior ? ' ★' : '';
                html += `<option value="${e.id}">${escapeHtml(e.name)}${star} (${escapeHtml(e.tag)})</option>`;
            });
        popoverEmp.innerHTML = html;
    }
    function populateHallDropdown() {
        if (!popoverHall) return;
        let html = '<option value="">— любой —</option>';
        App.halls.forEach((h) => {
            html += `<option value="${h.id}">${escapeHtml(h.icon || '')} ${escapeHtml(h.name)}</option>`;
        });
        App.zones.forEach((z) => {
            html += `<option value="zone:${z.id}">${escapeHtml(z.icon || '🌿')} ${escapeHtml(z.name)}</option>`;
        });
        popoverHall.innerHTML = html;
    }

    function showPopover(cell) {
        popoverAnchor = cell;
        const block = cell.dataset.block || '—';
        const slot  = cell.dataset.slot  || '—';
        const iso   = cell.dataset.dayIso || '—';
        popoverMeta.textContent = `Блок: ${block} · Слот: ${parseInt(slot, 10) + 1} · Дата: ${iso}`;

        populateEmployeeDropdown(block === 'senior');
        populateHallDropdown();

        const existing = getShift(iso, block, slot);
        if (existing) {
            popoverEmp.value  = existing.emp_id || '';
            popoverFrom.value = existing.start || '09:00';
            popoverTo.value   = existing.end   || '17:00';
            popoverHall.value = existing.hall_id ? String(existing.hall_id) : '';
        } else {
            popoverEmp.value = '';
            popoverFrom.value = '09:00';
            popoverTo.value   = '17:00';
            popoverHall.value = '';
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
        popover.style.left = left + 'px';
        popover.style.top  = top + 'px';
    }
    function hidePopover() {
        popover.classList.remove('visible');
        popoverAnchor = null;
    }

    // Save handler (Save inside popover)
    popover?.querySelector('.actions .save')?.addEventListener('click', async () => {
        if (!popoverAnchor) return;
        const empId = parseInt(popoverEmp.value, 10) || 0;
        const start = popoverFrom.value || '09:00';
        const end   = popoverTo.value   || '17:00';
        const hall  = popoverHall.value || '';
        if (empId === 0) {
            // empty value = delete
            delShift(popoverAnchor.dataset.dayIso, popoverAnchor.dataset.block, popoverAnchor.dataset.slot);
            renderChip(popoverAnchor, null);
        } else {
            const emp = employeesById.get(empId);
            const shift = {
                emp_id:   empId,
                emp_name: emp?.name || '',
                start, end,
                ...(hall ? { hall_id: hall } : {}),
            };
            setShift(popoverAnchor.dataset.dayIso, popoverAnchor.dataset.block, popoverAnchor.dataset.slot, shift);
            renderChip(popoverAnchor, shift);
        }
        hidePopover();
        await saveDebounced();
    });

    popover?.querySelector('.actions .del')?.addEventListener('click', async () => {
        if (!popoverAnchor) return;
        delShift(popoverAnchor.dataset.dayIso, popoverAnchor.dataset.block, popoverAnchor.dataset.slot);
        renderChip(popoverAnchor, null);
        hidePopover();
        await saveDebounced();
    });

    document.querySelector('[data-demo-noop="popover-cancel"]')?.addEventListener('click', hidePopover);

    document.addEventListener('click', (e) => {
        if (document.body.classList.contains('sch-help-mode')) return;
        if (e.target.closest('.sch-slot-del')) return;
        if (e.target.closest('#schPopover')) return;
        const cell = e.target.closest('.sch-cell[data-block]');
        if (cell) {
            e.preventDefault();
            showPopover(cell);
            return;
        }
        if (popoverAnchor) hidePopover();
    });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            hidePopover();
            closeAddBlockModal();
        }
    });


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
            const j = await api('save', {
                method: 'POST',
                body: { state: App.state, label },
            });
            App.snapshots = j.snapshots || App.snapshots;
            App.dirty = false;
            renderSnapshots();
            toast('Сохранено ✓');
        } catch (e) {
            console.error('[schedule] save failed', e);
            toast('Ошибка сохранения: ' + e.message, 'err');
        } finally {
            App.saving = false;
        }
    }

    // Header "Сохранить" button
    document.querySelectorAll('[data-demo-noop="save"]').forEach((b) => {
        b.removeAttribute('data-demo-noop');
        b.addEventListener('click', () => saveNow('manual'));
    });
    // "Сохранить текущую версию" in snapshots row
    document.querySelectorAll('[data-demo-noop="save-snapshot"]').forEach((b) => {
        b.removeAttribute('data-demo-noop');
        b.addEventListener('click', () => {
            const label = prompt('Название версии:', 'Версия ' + new Date().toLocaleDateString('ru-RU'));
            if (label) saveNow(label);
        });
    });


    // ════════════════ Snapshot pills ════════════════
    function renderSnapshots() {
        const wrap = document.querySelector('.sch-snapshots');
        if (!wrap) return;
        const label = wrap.querySelector('.sch-snap-label');
        const saveBtn = wrap.querySelector('button.sch-btn');
        // remove existing pills + filler
        wrap.querySelectorAll('.sch-snap-pill, .sch-snap-filler').forEach((el) => el.remove());

        App.snapshots.forEach((s) => {
            const pill = document.createElement('span');
            pill.className = 'sch-snap-pill' + (s.is_current ? ' current' : '');
            pill.dataset.snapId = s.id;
            const when = (s.created_at || '').replace(/^(\d{4})-(\d{2})-(\d{2}) (\d{2}:\d{2}).*$/, '$3.$2 $4');
            pill.innerHTML = `${escapeHtml(s.label || 'auto')} <span class="when">${escapeHtml(when)}</span>`;
            if (!s.is_current) {
                pill.addEventListener('click', () => loadSnapshot(s.id));
            }
            wrap.insertBefore(pill, saveBtn || null);
        });

        const filler = document.createElement('span');
        filler.className = 'sch-snap-filler';
        filler.style.flex = '1';
        if (saveBtn) wrap.insertBefore(filler, saveBtn);
    }

    async function loadSnapshot(id) {
        try {
            const j = await api('snapshot', { query: 'id=' + id });
            App.state = j.state;
            if (Array.isArray(App.state.shifts)) App.state.shifts = {};
            if (!App.state.shifts || typeof App.state.shifts !== 'object') App.state.shifts = {};
            applyStateToDom();
            toast('Версия загружена');
        } catch (e) {
            console.error('[schedule] load snapshot failed', e);
            toast('Не удалось загрузить версию', 'err');
        }
    }


    // ════════════════ Slot × delete ════════════════
    document.addEventListener('click', (e) => {
        const del = e.target.closest('.sch-slot-del');
        if (!del) return;
        e.preventDefault();
        e.stopPropagation();
        const id = del.getAttribute('data-demo-noop') || '';
        if (!confirm('Удалить эту колонку-слот?\nЕсли в ней есть смены — они исчезнут.\n\n(' + id + ')')) return;
        // TODO Phase 2B: actually mutate state.blocks[N].slots, save, re-render structure.
        console.info('[schedule] del-slot (structure mutation pending Phase 2B)', id);
        toast('Удаление слота → Phase 2B', 'err');
    });


    // ════════════════ Add block modal ════════════════
    const modal = document.getElementById('schModalAddBlock');
    const modalCloseBtn = document.getElementById('schModalAddBlockClose');
    const radioCards = document.querySelectorAll('#schBlockTypeRadio .card');
    const hallGroup   = document.getElementById('schBlockHallGroup');
    const customGroup = document.getElementById('schBlockCustomGroup');

    function openAddBlockModal() { modal?.classList.add('visible'); }
    function closeAddBlockModal() { modal?.classList.remove('visible'); }

    document.querySelector('.sch-add-block-btn')?.addEventListener('click', (e) => {
        e.preventDefault();
        openAddBlockModal();
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

    document.querySelectorAll('[data-demo-noop="modal-add-block-save"]').forEach((b) => {
        b.removeAttribute('data-demo-noop');
        b.addEventListener('click', async () => {
            const activeType = document.querySelector('#schBlockTypeRadio .card.active')?.dataset.type || 'hall';
            if (activeType === 'custom') {
                const name = document.getElementById('schBlockCustomName')?.value?.trim();
                const icon = document.getElementById('schBlockCustomIcon')?.value?.trim() || '🌿';
                if (!name) { toast('Введи название зоны', 'err'); return; }
                try {
                    const j = await api('add_zone', { method: 'POST', body: { name, icon } });
                    App.zones = j.zones;
                    toast(`Зона «${name}» добавлена → перезагрузи страницу чтобы блок появился`);
                    closeAddBlockModal();
                } catch (e) {
                    toast('Ошибка: ' + e.message, 'err');
                }
            } else {
                toast('Добавление Hall-блока в state.blocks → Phase 2B', 'err');
                closeAddBlockModal();
            }
        });
    });


    // ════════════════ Heatmap rebucketization (preserved from v6) ════════════════
    const statsEl = document.getElementById('schStatsData');
    const bucketSel = document.getElementById('schBucketSize');
    const filterSel = document.getElementById('schCoverageFilter');
    const covGrid   = document.getElementById('schCovGrid');
    if (statsEl && bucketSel && filterSel && covGrid) {
        let stats;
        try { stats = JSON.parse(statsEl.textContent); } catch (e) { stats = null; }
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
                        h[i] = (d.hours.senior?.[i] || 0) + (d.hours.main?.[i] || 0)
                             + (d.hours.banya?.[i]  || 0) + (d.hours.custom?.[i] || 0);
                    } else {
                        h[i] = d.hours[filter]?.[i] || 0;
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
                let max = 0, sum = 0, cnt = 0;
                for (let k = 0; k < size && h + k < endH; k++) {
                    max = Math.max(max, hours[h + k]);
                    sum += hours[h + k]; cnt++;
                }
                out.push({ from: h, to: Math.min(h + size, endH), max, avg: cnt > 0 ? +(sum / cnt).toFixed(1) : 0 });
            }
            return out;
        }
        function redrawMatrix(perDay, bucketSize) {
            const dayBuckets = perDay.map((h) => bucketize(h, bucketSize));
            let globalMax = 0;
            dayBuckets.forEach((dayBs) => dayBs.forEach((b) => { if (b.max > globalMax) globalMax = b.max; }));
            if (globalMax < 1) globalMax = 1;
            const cols = dayBuckets[0]?.length || 0;
            covGrid.style.setProperty('--cov-cols', cols);
            let html = '<div class="sch-cov-corner">День \\ Час</div>';
            if (dayBuckets.length > 0) {
                dayBuckets[0].forEach((b) => {
                    html += `<div class="sch-cov-col-head">${pad2(b.from)}–${pad2(b.to)}</div>`;
                });
            }
            stats.days.forEach((d, idx) => {
                const weekend = d.weekend ? ' weekend' : '';
                html += `<div class="sch-cov-row-head${weekend}">${escapeHtml(d.dow)} ${escapeHtml(d.date)}.05</div>`;
                dayBuckets[idx].forEach((b) => {
                    const intensity = b.max / globalMax;
                    const alpha = b.max > 0 ? 0.05 + intensity * 0.90 : 0;
                    const txt   = intensity > 0.55 ? '#0f1117' : 'var(--text)';
                    const label = b.max > 0 ? b.max : '·';
                    const title = `пик ${b.max} чел/ч в окне ${pad2(b.from)}–${pad2(b.to)}`;
                    html += `<div class="sch-cov-cell" data-count="${b.max}" data-from="${b.from}" data-to="${b.to}" data-day-idx="${idx}" style="background: rgba(184,135,70,${alpha}); color: ${txt};" title="${escapeHtml(title)}">${label}</div>`;
                });
            });
            covGrid.innerHTML = html;
        }
        function redrawHistogram(totals) {
            const wrap = document.querySelector('.sch-agg-histogram');
            if (!wrap) return;
            const grid = wrap.querySelector('.sch-bar-grid');
            const h4 = wrap.querySelector('h4');
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
                const filterLabel = filterSel.options[filterSel.selectedIndex]?.textContent || '';
                h4.textContent = `Средняя загрузка по часам за период (${stats.dayCount} дней) · ${filterLabel}`;
            }
        }
        function redraw() {
            const filter = filterSel.value;
            const bucketSize = parseInt(bucketSel.value, 10) || 2;
            const { perDay, totals } = applyFilter(filter);
            redrawMatrix(perDay, bucketSize);
            redrawHistogram(totals);
        }
        bucketSel.addEventListener('change', redraw);
        filterSel.addEventListener('change', redraw);
        redraw();
    }


    // ════════════════ Drag & drop (preserved) ════════════════
    let dragSource = null;
    document.addEventListener('dragstart', (e) => {
        const chip = e.target.closest('.sch-shift');
        if (!chip) return;
        dragSource = chip.closest('.sch-cell');
        chip.style.opacity = '0.4';
        e.dataTransfer.effectAllowed = 'move';
        try { e.dataTransfer.setData('text/plain', dragSource.dataset.block + ':' + dragSource.dataset.slot); } catch (_) {}
    });
    document.addEventListener('dragend', (e) => {
        const chip = e.target.closest('.sch-shift');
        if (chip) chip.style.opacity = '';
        dragSource = null;
    });
    document.addEventListener('dragover', (e) => {
        const cell = e.target.closest('.sch-cell[data-block]');
        if (!cell || !dragSource || cell === dragSource) return;
        e.preventDefault();
        cell.style.outline = '2px dashed var(--accent)';
    });
    document.addEventListener('dragleave', (e) => {
        const cell = e.target.closest('.sch-cell[data-block]');
        if (cell) cell.style.outline = '';
    });
    document.addEventListener('drop', async (e) => {
        const cell = e.target.closest('.sch-cell[data-block]');
        if (!cell || !dragSource || cell === dragSource) return;
        e.preventDefault();
        cell.style.outline = '';
        const srcIso  = dragSource.dataset.dayIso;
        const srcBlk  = dragSource.dataset.block;
        const srcSlot = dragSource.dataset.slot;
        const dstIso  = cell.dataset.dayIso;
        const dstBlk  = cell.dataset.block;
        const dstSlot = cell.dataset.slot;
        const srcShift = getShift(srcIso, srcBlk, srcSlot);
        const dstShift = getShift(dstIso, dstBlk, dstSlot);
        if (!srcShift) { dragSource = null; return; }
        // Move (or swap if target has shift)
        if (dstShift) {
            setShift(srcIso, srcBlk, srcSlot, dstShift);
            renderChip(dragSource, dstShift);
        } else {
            delShift(srcIso, srcBlk, srcSlot);
            renderChip(dragSource, null);
        }
        setShift(dstIso, dstBlk, dstSlot, srcShift);
        renderChip(cell, srcShift);
        dragSource = null;
        await saveDebounced();
    });


    // ════════════════ Demo-noop wiring for remaining controls ════════════════
    document.querySelectorAll('[data-demo-noop]').forEach((el) => {
        if (el.classList.contains('sch-slot-del')) return;
        if (el.id === 'schModalAddBlockClose') return;
        if (el.closest('#schPopover .actions')) return;
        el.addEventListener('click', (e) => {
            e.preventDefault();
            const label = el.getAttribute('data-demo-noop') || el.textContent.trim();
            console.info(`[schedule:demo] noop: ${label}`);
        });
    });


    // ════════════════ Boot sequence ════════════════
    hydrateFromDom();    // если state пустой — берём демо с DOM
    applyStateToDom();   // если state непустой — заменяем демо на сохранённое
    renderSnapshots();   // обновляем pill'ы из bootstrap snapshots
})();
