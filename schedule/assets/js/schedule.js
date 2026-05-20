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
    // Live hall-name overlay: for hall-bound blocks, prefer the current
    // Poster hall name over whatever was stored on the block. Matches the
    // server-side overlay in content.php so confirms / toasts read the
    // same name the user sees in the grid header.
    function blockDisplayName(block) {
        if (block?.type === 'hall' && block?.hall_id) {
            const hall = App.halls.find((h) => h.id === block.hall_id);
            if (hall?.name) return hall.name;
        }
        return block?.name || '';
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
        const fullText = `${name}${star} · ${time}`;
        cell.innerHTML = `<div class="sch-shift ${color}" draggable="true" title="${esc(fullText)}"><span class="sch-name">${esc(name)}${star}</span><span class="sch-time">${esc(time)}</span></div>`;
    }


    // ════════════════ Live summary recompute ════════════════
    // After any shift change (popover save/del, drag-n-drop, template drop)
    // we recompute the per-day warn/budget cells AND the totals row in JS
    // so they stay in sync without a full page reload. The initial values
    // come from PHP at render time; afterwards JS owns them until the next
    // F5 / structure change.
    function hoursBetween(start, end) {
        if (!start || !end) return 0;
        const [h1, m1 = '0'] = start.split(':');
        const [h2, m2 = '0'] = end.split(':');
        const a = parseInt(h1, 10) + (parseInt(m1, 10) || 0) / 60;
        const b = parseInt(h2, 10) + (parseInt(m2, 10) || 0) / 60;
        return Math.max(0, b - a);
    }

    function parseHHMM(s) {
        if (!s) return 0;
        const [h, m = '0'] = String(s).split(':');
        return (parseInt(h, 10) || 0) + ((parseInt(m, 10) || 0) / 60);
    }
    function ruleScopeMatches(rule, block) {
        const s = rule.scope || 'all';
        if (s === 'all') return true;
        return blockColor(block) === s;
    }
    function empName(id) {
        return empById.get(id)?.name || ('uid:' + id);
    }

    // ════════════════ Rule engine ════════════════
    // Returns reasons[] for one day. Iterates enabled rules from
    // App.state.rules and accumulates human-readable strings. Each rule
    // type has its own evaluator; new types added here without touching
    // anything else.
    function dayWarnReasons(iso) {
        const reasons = [];
        const blocks  = App.state.blocks || [];
        const rules   = App.state.rules  || [];
        const dow     = new Date(iso + 'T00:00:00').getDay();
        const weekend = dow === 5 || dow === 6 || dow === 0;

        rules.filter((r) => r && r.enabled !== false).forEach((rule) => {
            switch (rule.type) {

                case 'needSenior': {
                    let anyShift = false, hasSenior = false;
                    blocks.forEach((blk) => {
                        const isSenior = blockColor(blk) === 'senior';
                        (blk.slots || []).forEach((_, sIdx) => {
                            if (getShift(iso, blk.id, sIdx)) {
                                anyShift = true;
                                if (isSenior) hasSenior = true;
                            }
                        });
                    });
                    if (anyShift && !hasSenior) reasons.push(rule.name || 'Нет старшего');
                    break;
                }

                case 'doubleBooking': {
                    const empTimes = new Map();
                    blocks.forEach((blk) => {
                        (blk.slots || []).forEach((_, sIdx) => {
                            const sh = getShift(iso, blk.id, sIdx);
                            if (!sh) return;
                            const id = parseInt(sh.emp_id, 10) || 0;
                            if (id <= 0) return;
                            const sH = parseHHMM(sh.start), eH = parseHHMM(sh.end);
                            if (eH > sH) {
                                if (!empTimes.has(id)) empTimes.set(id, []);
                                empTimes.get(id).push([sH, eH]);
                            }
                        });
                    });
                    empTimes.forEach((intervals, id) => {
                        if (intervals.length < 2) return;
                        intervals.sort((a, b) => a[0] - b[0]);
                        for (let i = 1; i < intervals.length; i++) {
                            if (intervals[i][0] < intervals[i - 1][1]) {
                                reasons.push(`Двойное бронирование: ${empName(id)}`);
                                break;
                            }
                        }
                    });
                    break;
                }

                case 'offRoster': {
                    const seen = new Set();
                    blocks.forEach((blk) => {
                        (blk.slots || []).forEach((_, sIdx) => {
                            const sh = getShift(iso, blk.id, sIdx);
                            if (!sh) return;
                            const id = parseInt(sh.emp_id, 10) || 0;
                            if (id <= 0) return;
                            const emp = empById.get(id);
                            if (emp && emp.in_schedule === false && !seen.has(id)) {
                                seen.add(id);
                                reasons.push(`Не в графике: ${empName(id)}`);
                            }
                        });
                    });
                    break;
                }

                case 'startTime': {
                    const expected = rule.value || '';
                    if (!expected) break;
                    blocks.forEach((blk) => {
                        if (!ruleScopeMatches(rule, blk)) return;
                        (blk.slots || []).forEach((_, sIdx) => {
                            const sh = getShift(iso, blk.id, sIdx);
                            if (!sh || !sh.start || sh.start === expected) return;
                            const id = parseInt(sh.emp_id, 10) || 0;
                            reasons.push(`${rule.name || 'Старт ≠ ' + expected}: ${empName(id)} в ${sh.start}`);
                        });
                    });
                    break;
                }

                case 'endTime': {
                    const expected = (weekend && rule.weekendValue) ? rule.weekendValue : (rule.value || '');
                    if (!expected) break;
                    blocks.forEach((blk) => {
                        if (!ruleScopeMatches(rule, blk)) return;
                        (blk.slots || []).forEach((_, sIdx) => {
                            const sh = getShift(iso, blk.id, sIdx);
                            if (!sh || !sh.end || sh.end === expected) return;
                            const id = parseInt(sh.emp_id, 10) || 0;
                            reasons.push(`${rule.name || 'Конец ≠ ' + expected}: ${empName(id)} до ${sh.end}`);
                        });
                    });
                    break;
                }
            }
        });

        return reasons;
    }

    function recomputeSummaries() {
        const blocks = App.state.blocks || [];
        const isos   = new Set();
        document.querySelectorAll('.sch-warn-cell[data-day-iso]').forEach((c) => isos.add(c.dataset.dayIso));

        const slotCounts = new Map();   // "blockId:sIdx" → count
        let warnDays = 0, totalSalary = 0;

        isos.forEach((iso) => {
            let daySalary = 0;
            blocks.forEach((blk) => {
                (blk.slots || []).forEach((_, sIdx) => {
                    const sh = getShift(iso, blk.id, sIdx);
                    if (!sh) return;
                    const hrs  = hoursBetween(sh.start, sh.end);
                    const rate = (empById.get(sh.emp_id)?.rate_per_hour) || 0;
                    if (hrs > 0) daySalary += hrs * rate;
                    const k = `${blk.id}:${sIdx}`;
                    slotCounts.set(k, (slotCounts.get(k) || 0) + 1);
                });
            });
            totalSalary += daySalary;

            const reasons = dayWarnReasons(iso);
            const warn = document.querySelector(`.sch-warn-cell[data-day-iso="${iso}"]`);
            if (warn) {
                const bad = reasons.length > 0;
                warn.classList.toggle('bad', bad);
                warn.classList.toggle('ok',  !bad);
                warn.textContent = bad ? '⚠' : '✓';
                warn.title       = bad ? reasons.join('\n') : 'Всё в порядке';
                if (bad) warnDays++;
            }
            const budget = document.querySelector(`.sch-budget-cell[data-day-iso="${iso}"]`);
            if (budget) {
                budget.textContent = daySalary > 0 ? (daySalary / 1_000_000).toFixed(2) + 'M' : '—';
            }
        });

        document.querySelectorAll('.sch-totals-cell[data-totals-slot]').forEach((c) => {
            c.textContent = String(slotCounts.get(c.dataset.totalsSlot) || 0);
        });
        const warnTotal = document.querySelector('[data-totals="warn"]');
        if (warnTotal) warnTotal.textContent = `${warnDays} ⚠`;
        const salTotal = document.querySelector('[data-totals="salary"]');
        if (salTotal) salTotal.textContent = totalSalary > 0
            ? (totalSalary / 1_000_000).toFixed(2) + 'M'
            : '—';

        // Heatmap is downstream of the same state — keep it in sync.
        heatmap?.recomputeFromState();
        // Payroll table likewise — re-build whole rows from state.
        recomputePayroll();
    }

    // ════════════════ Payroll forecast — per-employee live recompute ═════
    //
    // PHP renders the initial table; JS rebuilds it on every shift edit so
    // the totals don't go stale until F5. Same model — sum(hours) × rate
    // per emp_id, sorted by ЗП DESC.
    function fmtMoneyMln(v)  { return v > 0 ? (v / 1_000_000).toFixed(2) + 'M' : '—'; }
    function fmtRate(v)      { return v > 0 ? new Intl.NumberFormat('ru-RU').format(v) : '—'; }
    function fmtHours(v)     { return v.toFixed(1); }

    function recomputePayroll() {
        const tbl = document.getElementById('schPayrollTable');
        if (!tbl) return;
        const blocks = App.state.blocks || [];
        const acc = new Map();   // empId → {id,name,tag,rate,hours,zp}
        blocks.forEach((blk) => {
            (blk.slots || []).forEach((_, sIdx) => {
                Object.keys(App.state.shifts || {}).forEach((iso) => {
                    const sh = getShift(iso, blk.id, sIdx);
                    if (!sh) return;
                    const hrs = hoursBetween(sh.start, sh.end);
                    if (hrs <= 0) return;
                    const eid = parseInt(sh.emp_id, 10) || 0;
                    if (eid <= 0) return;
                    const emp = empById.get(eid);
                    let row = acc.get(eid);
                    if (!row) {
                        row = {
                            id:    eid,
                            name:  emp?.name || sh.emp_name || ('uid:' + eid),
                            tag:   emp?.tag  || '',
                            rate:  emp?.rate_per_hour || 0,
                            hours: 0,
                        };
                        acc.set(eid, row);
                    }
                    row.hours += hrs;
                });
            });
        });
        const rows = Array.from(acc.values()).map((r) => ({ ...r, zp: r.hours * r.rate }));
        rows.sort((a, b) => b.zp - a.zp);

        const tbody = tbl.querySelector('tbody');
        let html = '';
        if (rows.length === 0) {
            html = '<tr><td colspan="5" class="empty">Нет смен за выбранный период.</td></tr>';
        } else {
            rows.forEach((r) => {
                html += `
                  <tr data-emp-id="${r.id}">
                    <td class="who">${esc(r.name)}</td>
                    <td class="tag">${esc(r.tag)}</td>
                    <td class="num"><span class="hours">${fmtHours(r.hours)}</span></td>
                    <td class="num"><span class="rate">${fmtRate(r.rate)}</span></td>
                    <td class="num zp"><span class="zp-val">${fmtMoneyMln(r.zp)}</span></td>
                  </tr>`;
            });
        }
        tbody.innerHTML = html;
        const totalHours = rows.reduce((s, r) => s + r.hours, 0);
        const totalZp    = rows.reduce((s, r) => s + r.zp,    0);
        const hSpan = tbl.querySelector('[data-total="hours"]');
        const zSpan = tbl.querySelector('[data-total="zp"]');
        if (hSpan) hSpan.textContent = fmtHours(totalHours);
        if (zSpan) zSpan.textContent = fmtMoneyMln(totalZp);
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


    // ════════════════ Rule constructor UI ════════════════
    // List, toggle, delete + inline form for adding a new rule. The form
    // shows/hides time fields based on type. New rule -> pushed into
    // App.state.rules, recomputeSummaries() updates the ⚠ column live,
    // scheduleSave() persists.
    const RULE_TYPE_LABELS = {
        needSenior:    'Нет старшего',
        doubleBooking: 'Двойное бронирование',
        offRoster:     'Назначен «не в графике»',
        startTime:     'Старт смены',
        endTime:       'Конец смены',
    };
    const SCOPE_LABELS = {
        all:    'Все блоки',
        senior: 'Старшие',
        main:   'Главный зал',
        banya:  'Баня',
        custom: 'Кастомные',
    };

    function renderRulesList() {
        const list = document.getElementById('schRulesList');
        const cnt  = document.getElementById('schRulesCount');
        if (!list) return;
        const rules = App.state.rules || [];
        const on = rules.filter((r) => r.enabled !== false).length;
        if (cnt) cnt.textContent = `${on} из ${rules.length} включено`;
        let html = '';
        rules.forEach((r, i) => {
            const enabled = r.enabled !== false;
            const sys     = r.system ? ' system' : '';
            const dis     = enabled ? '' : ' disabled';
            const typeLbl = RULE_TYPE_LABELS[r.type] || r.type;
            const scopeLbl = SCOPE_LABELS[r.scope || 'all'];
            const valueChip = (r.type === 'startTime' || r.type === 'endTime')
                ? `<span class="sch-rules-chip">${esc(r.value || '?')}${r.weekendValue ? ' / ' + esc(r.weekendValue) : ''}</span>`
                : '';
            html += `
              <div class="sch-rules-item${sys}${dis}" data-idx="${i}">
                <input type="checkbox" class="sch-rules-toggle" ${enabled ? 'checked' : ''} data-idx="${i}">
                <span class="sch-rules-name">${esc(r.name || typeLbl)}</span>
                <span class="sch-rules-chip">${esc(typeLbl)}</span>
                <span class="sch-rules-chip scope">${esc(scopeLbl)}</span>
                ${valueChip}
                <button class="sch-rules-del" data-idx="${i}" title="Удалить правило">×</button>
              </div>`;
        });
        list.innerHTML = html;
    }

    document.addEventListener('change', (e) => {
        const cb = e.target.closest('.sch-rules-toggle');
        if (!cb) return;
        const i = parseInt(cb.dataset.idx, 10);
        const r = (App.state.rules || [])[i];
        if (!r) return;
        r.enabled = cb.checked;
        renderRulesList();
        recomputeSummaries();
        scheduleSave();
    });
    document.addEventListener('click', (e) => {
        const btn = e.target.closest('.sch-rules-del');
        if (!btn) return;
        e.preventDefault();
        const i = parseInt(btn.dataset.idx, 10);
        const r = (App.state.rules || [])[i];
        if (!r || r.system) return;
        if (!confirm(`Удалить правило «${r.name || r.type}»?`)) return;
        App.state.rules.splice(i, 1);
        renderRulesList();
        recomputeSummaries();
        scheduleSave();
    });

    // ─── Inline "add rule" form ───
    const ruleForm     = document.getElementById('schRulesForm');
    const ruleAddBtn   = document.getElementById('schRulesAddBtn');
    const ruleType     = document.getElementById('schRuleType');
    const ruleScope    = document.getElementById('schRuleScope');
    const ruleValue    = document.getElementById('schRuleValue');
    const ruleWeekend  = document.getElementById('schRuleWeekendValue');
    const ruleName     = document.getElementById('schRuleName');
    const ruleCancel   = document.getElementById('schRuleCancel');

    function refreshFormFields() {
        const t = ruleType?.value;
        const needsValue   = t === 'startTime' || t === 'endTime';
        const needsWeekend = t === 'endTime';
        const tf = document.querySelector('.sch-rules-time-field');
        const wf = document.querySelector('.sch-rules-weekend-field');
        if (tf) tf.style.display = needsValue   ? '' : 'none';
        if (wf) wf.style.display = needsWeekend ? '' : 'none';
        // Auto-suggest name if user hasn't customised it
        if (ruleName && !ruleName.dataset.custom) {
            const scope = SCOPE_LABELS[ruleScope?.value] || 'все блоки';
            if (t === 'startTime')    ruleName.value = `${scope}: старт в ${ruleValue?.value || 'HH:MM'}`;
            else if (t === 'endTime') ruleName.value = `${scope}: конец в ${ruleValue?.value || 'HH:MM'}${ruleWeekend?.value ? ' (' + ruleWeekend.value + ' Пт/Сб/Вс)' : ''}`;
            else ruleName.value = RULE_TYPE_LABELS[t] || '';
        }
    }
    ruleAddBtn?.addEventListener('click', () => {
        ruleForm.hidden = false;
        ruleAddBtn.hidden = true;
        if (ruleName) { ruleName.value = ''; delete ruleName.dataset.custom; }
        if (ruleValue)   ruleValue.value   = '10:00';
        if (ruleWeekend) ruleWeekend.value = '23:00';
        refreshFormFields();
    });
    ruleCancel?.addEventListener('click', () => {
        ruleForm.hidden = true;
        ruleAddBtn.hidden = false;
    });
    [ruleType, ruleScope, ruleValue, ruleWeekend].forEach((el) => {
        el?.addEventListener('input',  refreshFormFields);
        el?.addEventListener('change', refreshFormFields);
    });
    ruleName?.addEventListener('input', () => { ruleName.dataset.custom = '1'; });

    ruleForm?.addEventListener('submit', (e) => {
        e.preventDefault();
        const t = ruleType.value;
        const rule = {
            id:      'r-' + Date.now().toString(36),
            type:    t,
            scope:   ruleScope.value || 'all',
            enabled: true,
            name:    (ruleName.value || RULE_TYPE_LABELS[t] || t).trim(),
        };
        if (t === 'startTime' || t === 'endTime') {
            rule.value = (ruleValue.value || '').trim();
            if (!/^\d{1,2}:\d{2}$/.test(rule.value)) {
                toast('Некорректное время', 'err'); return;
            }
        }
        if (t === 'endTime' && ruleWeekend.value.trim()) {
            const w = ruleWeekend.value.trim();
            if (!/^\d{1,2}:\d{2}$/.test(w)) {
                toast('Некорректное время Пт/Сб/Вс', 'err'); return;
            }
            rule.weekendValue = w;
        }
        App.state.rules = App.state.rules || [];
        App.state.rules.push(rule);
        ruleForm.hidden = true;
        ruleAddBtn.hidden = false;
        renderRulesList();
        recomputeSummaries();
        scheduleSave();
        toast('Правило добавлено ✓');
    });

    // Initial render of the panel + summaries.
    renderRulesList();
    // After DOM is fully wired, compute warnings from the rule engine so
    // the ⚠ column reflects the current rules (PHP's initial render uses
    // a simpler subset).
    setTimeout(() => recomputeSummaries(), 0);


    // ════════════════ Save queue ════════════════
    //
    // Pattern: debounce + single-flight + pending follow-up.
    //
    //   scheduleSave()  — call after every edit. Fire-and-forget. Coalesces
    //                     rapid bursts via an 800 ms debounce timer. If a
    //                     POST is already in flight, sets `pending=true` so
    //                     a follow-up POST fires the moment the first one
    //                     finishes — no edits get dropped.
    //   flushSave()     — manual / structural save. Drains the debounce
    //                     timer immediately, awaits the POST AND any
    //                     follow-up POSTs queued during it. Returns when
    //                     state on the server matches App.state.
    //
    // Every POST hits ajax=save which UPSERTs the single is_current=1
    // draft row — no new snapshot history rows are created. Named
    // versions go through saveAsVersion() → ajax=save_version, a
    // separate endpoint.
    let saveTimer = null;
    let saveInFlight = false;
    let savePending  = false;   // edits arrived during in-flight POST

    function scheduleSave() {
        savePending = true;
        if (saveTimer) clearTimeout(saveTimer);
        saveTimer = setTimeout(() => { saveTimer = null; runSave(); }, 800);
    }

    async function flushSave() {
        if (saveTimer) { clearTimeout(saveTimer); saveTimer = null; }
        savePending = true;
        await runSave();
        // Drain follow-ups too (state may have changed during the await).
        while (savePending) await runSave();
    }

    async function runSave() {
        if (saveInFlight) { savePending = true; return; }
        if (!savePending) return;
        saveInFlight = true;
        savePending  = false;        // anything new from now on sets it again
        try {
            // No label — ajax=save always upserts the current draft.
            // Named versions go through saveAsVersion() below.
            const j = await api('save', { method: 'POST', body: { state: App.state } });
            App.snapshots = j.snapshots || App.snapshots;
            App.dirty = false;
            renderSnapshots();
            if (!savePending) toast('Сохранено ✓');   // suppress mid-burst toasts
        } catch (e) {
            toast('Ошибка сохранения: ' + e.message, 'err');
            console.error(e);
            savePending = true;       // retry on next runSave()
        } finally {
            saveInFlight = false;
        }
    }

    document.getElementById('schSaveBtn')?.addEventListener('click', () => flushSave());

    // Create a new NAMED version — separate endpoint, never updates draft.
    async function saveAsVersion() {
        const label = (prompt('Название версии:', 'Версия ' + new Date().toLocaleDateString('ru-RU')) || '').trim();
        if (!label) return;
        // First make sure the draft is fully persisted, so the version
        // snapshots exactly what's on screen.
        await flushSave();
        try {
            const j = await api('save_version', {
                method: 'POST',
                body:   { state: App.state, label },
            });
            App.snapshots = j.snapshots || App.snapshots;
            renderSnapshots();
            toast(`Версия «${label}» сохранена ✓`);
            // Bring the new pill into view and pulse it briefly so the
            // user clearly sees that the version appeared.
            highlightSnapshot(j.id);
        } catch (e) {
            toast('Ошибка: ' + e.message, 'err');
        }
    }
    function highlightSnapshot(id) {
        const pill = document.querySelector(`.sch-snap-pill[data-snap-id="${id}"]`);
        if (!pill) return;
        pill.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'nearest' });
        pill.classList.add('just-added');
        setTimeout(() => pill.classList.remove('just-added'), 1500);
    }
    document.getElementById('schSaveSnapBtn')?.addEventListener('click', saveAsVersion);


    // ════════════════ Save+reload (for structure mutations) ════════════════
    // Slot / block add/del / template CRUD need a server re-render so the
    // grid template-columns rebuild correctly. Drain the queue first so the
    // reload sees the latest state, then reload.
    async function saveAndReload(reason) {
        await flushSave();
        toast(reason + ' ✓');
        setTimeout(() => location.reload(), 400);
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
        // Defaults for a brand-new shift depend on the day of week:
        //   • Пт / Сб / Вс  → 10:00–23:00 (длиннее за счёт уикенд-трафика)
        //   • Пн–Чт         → 10:00–22:00
        // Existing shift's stored times always win — we only fall back
        // to these for empty cells / unset start/end.
        const dow = new Date(iso + 'T00:00:00').getDay();   // 0=Sun … 6=Sat
        const weekend = dow === 5 || dow === 6 || dow === 0;
        const dStart = '10:00';
        const dEnd   = weekend ? '23:00' : '22:00';

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

    document.getElementById('schPopoverSave')?.addEventListener('click', () => {
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
        recomputeSummaries();
        scheduleSave();
    });
    document.getElementById('schPopoverDel')?.addEventListener('click', () => {
        if (!popAnchor) return;
        delShift(popAnchor.dataset.dayIso, popAnchor.dataset.block, popAnchor.dataset.slot);
        renderChip(popAnchor, null);
        hidePopover();
        recomputeSummaries();
        scheduleSave();
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
        if (!confirm(`Удалить слот ${sIdx + 1} блока «${blockDisplayName(block)}»?${warn}`)) return;

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
        if (!confirm(`Удалить блок «${blockDisplayName(block)}» целиком?${warn}`)) return;

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
        await flushSave();
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
        await flushSave();
        toast(`Скопировано ${copied} смен на следующую неделю`);
        // Navigate to the next week
        const nextFrom = new Date(from); nextFrom.setDate(nextFrom.getDate() + 7);
        const nextTo   = new Date(to);   nextTo.setDate(nextTo.getDate() + 7);
        navigateToPeriod(nextFrom.toISOString().slice(0, 10), nextTo.toISOString().slice(0, 10));
    });


    // ════════════════ Named versions (snapshots) ════════════════
    // The "current draft" is implicit — `ajax=save` upserts it on every
    // edit. The list rendered here is named-versions only.
    function renderSnapshots() {
        const wrap = document.querySelector('.sch-snapshots');
        if (!wrap) return;
        const saveBtn = document.getElementById('schSaveSnapBtn');
        wrap.querySelectorAll('.sch-snap-pill, .sch-snap-empty').forEach((el) => el.remove());
        const insertBefore = saveBtn?.previousElementSibling || saveBtn;
        if (!App.snapshots || App.snapshots.length === 0) {
            const empty = document.createElement('span');
            empty.className = 'sch-snap-empty';
            empty.style.cssText = 'color: var(--muted); font-size: 12px;';
            empty.textContent = 'Именованных версий пока нет. «Сохранить версию» создаст первую.';
            wrap.insertBefore(empty, insertBefore);
            return;
        }
        App.snapshots.forEach((s) => {
            const pill = document.createElement('span');
            pill.className = 'sch-snap-pill';
            pill.dataset.snapId = s.id;
            pill.dataset.snapLabel = s.label || '';
            const shareUrl = s.share_code ? `/schedule/v/${s.share_code}` : '';
            if (shareUrl) pill.dataset.shareUrl = shareUrl;
            const when = (s.created_at || '').replace(/^(\d{4})-(\d{2})-(\d{2}) (\d{2}:\d{2}).*$/, '$3.$2 $4');
            const shareBtn = shareUrl
                ? `<button class="sch-snap-btn sch-snap-share" title="Скопировать публичную ссылку" data-share-url="${esc(shareUrl)}">🔗</button>`
                : '';
            pill.innerHTML = `
                <span class="sch-snap-name">${esc(s.label)}</span>
                <span class="when">${esc(when)}</span>
                ${shareBtn}
                <button class="sch-snap-btn sch-snap-rename" title="Переименовать" data-snap-id="${s.id}">✏</button>
                <button class="sch-snap-btn sch-snap-del"    title="Удалить"        data-snap-id="${s.id}">×</button>
            `;
            wrap.insertBefore(pill, insertBefore);
        });
    }

    async function loadSnapshot(id) {
        if (!confirm('Загрузить эту версию? Текущий черновик перепишется.')) return;
        try {
            const j = await api('snapshot', { query: 'id=' + id });
            App.state = j.state;
            if (Array.isArray(App.state.shifts)) App.state.shifts = {};
            if (!App.state.shifts || typeof App.state.shifts !== 'object') App.state.shifts = {};
            // Push loaded state into the current draft (no new version row).
            await api('save', { method: 'POST', body: { state: App.state } });
            toast('Версия загружена');
            setTimeout(() => location.reload(), 400);
        } catch (e) {
            toast('Ошибка: ' + e.message, 'err');
        }
    }
    async function renameSnap(id, currentLabel) {
        const next = (prompt('Новое название версии:', currentLabel || '') || '').trim();
        if (!next || next === currentLabel) return;
        try {
            const j = await api('rename_snap', { method: 'POST', body: { id, label: next } });
            App.snapshots = j.snapshots || App.snapshots;
            renderSnapshots();
            toast('Переименовано ✓');
        } catch (e) {
            toast('Ошибка: ' + e.message, 'err');
        }
    }
    async function deleteSnap(id, currentLabel) {
        if (!confirm(`Удалить версию «${currentLabel || id}»?`)) return;
        try {
            const j = await api('del_snap', { method: 'POST', body: { id } });
            App.snapshots = j.snapshots || App.snapshots;
            renderSnapshots();
            toast('Версия удалена ✓');
        } catch (e) {
            toast('Ошибка: ' + e.message, 'err');
        }
    }

    async function copyShareLink(relativeUrl) {
        const absolute = new URL(relativeUrl, location.origin).toString();
        try {
            await navigator.clipboard.writeText(absolute);
            toast(`Ссылка скопирована: ${absolute}`);
        } catch (_) {
            // Fallback for older browsers / non-HTTPS contexts: show the URL
            // in a prompt so the user can Ctrl+C it manually.
            prompt('Публичная ссылка (read-only):', absolute);
        }
    }

    // Single delegated handler — works for server-rendered AND JS-rerendered pills.
    document.addEventListener('click', (e) => {
        const shareBtn = e.target.closest('.sch-snap-share');
        if (shareBtn) {
            e.preventDefault(); e.stopPropagation();
            copyShareLink(shareBtn.dataset.shareUrl || '');
            return;
        }
        const renameBtn = e.target.closest('.sch-snap-rename');
        if (renameBtn) {
            e.preventDefault(); e.stopPropagation();
            const pill = renameBtn.closest('.sch-snap-pill');
            renameSnap(parseInt(renameBtn.dataset.snapId, 10), pill?.dataset.snapLabel || '');
            return;
        }
        const delBtn = e.target.closest('.sch-snap-del');
        if (delBtn) {
            e.preventDefault(); e.stopPropagation();
            const pill = delBtn.closest('.sch-snap-pill');
            deleteSnap(parseInt(delBtn.dataset.snapId, 10), pill?.dataset.snapLabel || '');
            return;
        }
        const pill = e.target.closest('.sch-snap-pill[data-snap-id]');
        if (pill) loadSnapshot(parseInt(pill.dataset.snapId, 10));
    });


    // ════════════════ Heatmap rebucketization + live recompute ═══════
    // initHeatmap returns { redraw, recomputeFromState } — the IIFE keeps
    // a reference in `heatmap` so other handlers (popover save/del,
    // drop, rule toggle) can force a refresh after editing shifts.
    let heatmap = null;
    const statsEl   = document.getElementById('schStatsData');
    const bucketSel = document.getElementById('schBucketSize');
    const filterSel = document.getElementById('schCoverageFilter');
    const covGrid   = document.getElementById('schCovGrid');
    if (statsEl && bucketSel && filterSel && covGrid) {
        let stats;
        try { stats = JSON.parse(statsEl.textContent); } catch (_) { stats = null; }
        if (stats) heatmap = initHeatmap(stats);
    }
    function initHeatmap(stats) {
        const startH     = stats.hourStart || 8;
        const endH       = stats.hourEnd   || 24;
        const COLOR_KEYS = ['senior', 'main', 'banya', 'custom'];
        // Default fallback labels — overridden by data-color-names on the
        // grid, which carries the actual block names (Poster hall overlay
        // applied). Updated live whenever blocks change (saveAndReload).
        const FALLBACK_LABELS = { senior: 'старшие', main: 'главный', banya: 'баня', custom: 'кастомные' };
        function labelFor(color) {
            const map = colorNameMap();
            return map[color] || FALLBACK_LABELS[color] || color;
        }
        function colorNameMap() {
            const raw = (covGrid.dataset.colorNames || '').split(',').filter(Boolean);
            const out = {};
            raw.forEach((entry) => {
                const i = entry.indexOf(':');
                if (i > 0) out[entry.slice(0, i)] = entry.slice(i + 1);
            });
            return out;
        }

        // ─── Pure helpers — mirror of PHP-side $schBucketize /
        // $schCovCellAttrs / $schFormatCellLabel in content.php. ─────
        function bucketize(hours, size) {
            const out = [];
            for (let h = startH; h < endH; h += size) {
                let max = 0;
                for (let k = 0; k < size && h + k < endH; k++) max = Math.max(max, hours[h + k] || 0);
                out.push({ from: h, to: Math.min(h + size, endH), max });
            }
            return out;
        }
        function covCellAttrs(value, maxValue) {
            const intensity = maxValue > 0 ? value / maxValue : 0;
            return {
                alpha: value > 0 ? 0.05 + intensity * 0.9 : 0,
                text:  intensity > 0.55 ? '#0f1117' : 'var(--text)',
            };
        }
        function formatLabel(values, asAvg) {
            if (!values.some((v) => v > 0.05)) return '·';
            return values.map((v) => {
                if (asAvg) {
                    const r = (Math.round(v * 10) / 10).toString();
                    return r.endsWith('.0') ? r.slice(0, -2) : r;
                }
                return String(Math.round(v));
            }).join('|');
        }
        function cellHtml(values, totalForColor, max, extra = '', title = '', asAvg = false) {
            const { alpha, text } = covCellAttrs(totalForColor, max);
            const label = formatLabel(values, asAvg);
            const t = title ? ` title="${esc(title)}"` : '';
            return `<div class="sch-cov-cell ${extra}" data-count="${asAvg ? totalForColor.toFixed(1) : Math.round(totalForColor)}" style="background: rgba(184,135,70,${alpha}); color: ${text};"${t}>${label}</div>`;
        }

        // Which colours actually have data — taken from server-rendered
        // <div data-color-order="..."> (computed from blocks); fall back
        // to all if missing.
        function activeColors(filter) {
            if (filter && filter !== 'all') {
                return COLOR_KEYS.includes(filter) ? [filter] : COLOR_KEYS;
            }
            const raw = (covGrid.dataset.colorOrder || '').split(',').filter(Boolean);
            return raw.length ? raw : COLOR_KEYS;
        }

        // ─── Render pipeline ──────────────────────────────────────────
        function buildRows(filter, bs) {
            const colors = activeColors(filter);
            const dayCount = Math.max(1, stats.dayCount || stats.days.length);

            // Per-day, per-colour bucket arrays + sum totals for the
            // colour-intensity scale.
            const dayPerColor = stats.days.map((d) => {
                const out = {};
                colors.forEach((c) => {
                    out[c] = bucketize(d.hours?.[c] || new Array(24).fill(0), bs);
                });
                return out;
            });

            // Avg per colour: sum over days / dayCount, then bucketize.
            const avgPerColor = {};
            colors.forEach((c) => {
                const hourly = new Array(24).fill(0);
                stats.days.forEach((d) => {
                    const src = d.hours?.[c] || [];
                    for (let h = 0; h < 24; h++) hourly[h] += src[h] || 0;
                });
                const avg = hourly.map((v) => v / dayCount);
                avgPerColor[c] = bucketize(avg, bs);
            });

            const colCount = avgPerColor[colors[0]]?.length || 0;
            let max = 0;
            for (let i = 0; i < colCount; i++) {
                let totalDay = 0, totalAvg = 0;
                dayPerColor.forEach((row) => colors.forEach((c) => { totalDay += row[c][i].max; }));
                // We compare *per-cell* totals, not aggregate across days.
                dayPerColor.forEach((row) => {
                    let t = 0; colors.forEach((c) => { t += row[c][i].max; });
                    if (t > max) max = t;
                });
                colors.forEach((c) => { totalAvg += avgPerColor[c][i].max; });
                if (totalAvg > max) max = totalAvg;
            }
            return { dayPerColor, avgPerColor, colors, max: Math.max(1, max), colCount };
        }
        function redraw() {
            const filter = filterSel.value;
            const bs     = parseInt(bucketSel.value, 10) || 2;
            const { dayPerColor, avgPerColor, colors, max, colCount } = buildRows(filter, bs);
            covGrid.style.setProperty('--cov-cols', colCount);
            covGrid.dataset.colorOrder = colors.join(',');

            const hint = colors.map(labelFor).join(' | ');
            let html = `<div class="sch-cov-corner" title="В ячейке: ${esc(hint)}">День \\ Час<br><small>${esc(hint)}</small></div>`;
            // Column headers — pull from any colour's buckets (they all share columns).
            const headBuckets = avgPerColor[colors[0]] || [];
            headBuckets.forEach((c) => {
                html += `<div class="sch-cov-col-head">${pad2(c.from)}–${pad2(c.to)}</div>`;
            });
            // Day rows
            stats.days.forEach((d, idx) => {
                const wk = d.weekend ? ' weekend' : '';
                html += `<div class="sch-cov-row-head${wk}">${esc(d.dow)} ${esc(d.date)}</div>`;
                const row = dayPerColor[idx];
                for (let i = 0; i < colCount; i++) {
                    const values = colors.map((c) => row[c][i].max);
                    const total  = values.reduce((s, v) => s + v, 0);
                    html += cellHtml(values, total, max, '',
                        `${hint}: ${formatLabel(values, false)} (${pad2(headBuckets[i].from)}–${pad2(headBuckets[i].to)})`,
                        false);
                }
            });
            // Avg row
            html += `<div class="sch-cov-row-head avg" title="Среднее по всем дням периода (${stats.dayCount} д)">Сред.</div>`;
            for (let i = 0; i < colCount; i++) {
                const values = colors.map((c) => avgPerColor[c][i].max);
                const total  = values.reduce((s, v) => s + v, 0);
                html += cellHtml(values, total, max, 'avg',
                    `Сред. ${hint}: ${formatLabel(values, true)}`,
                    true);
            }
            covGrid.innerHTML = html;
        }

        // Rebuild stats.days[*].hours from the live App.state — call this
        // after any shift mutation so the heatmap stays in sync without
        // a page reload.
        function recomputeFromState() {
            const blocks = App.state.blocks || [];
            stats.days.forEach((d) => {
                if (!d.iso) return;
                const buckets = { senior: new Array(24).fill(0), main: new Array(24).fill(0),
                                  banya:  new Array(24).fill(0), custom: new Array(24).fill(0) };
                blocks.forEach((blk) => {
                    const color = blockColor(blk);
                    if (!buckets[color]) return;
                    (blk.slots || []).forEach((_, sIdx) => {
                        const sh = getShift(d.iso, blk.id, sIdx);
                        if (!sh) return;
                        const sH = Math.floor(parseHHMM(sh.start));
                        const eH = Math.ceil(parseHHMM(sh.end));
                        for (let h = sH; h < eH; h++) {
                            if (h >= 0 && h <= 23) buckets[color][h]++;
                        }
                    });
                });
                d.hours = buckets;
            });
            redraw();
        }

        bucketSel.addEventListener('change', redraw);
        filterSel.addEventListener('change', redraw);
        return { redraw, recomputeFromState };
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


    // ════════════════ Drag-n-drop (shifts) ════════════════
    // Drag a shift chip into another cell:
    //   • plain drag        → move (swap if target occupied)
    //   • Ctrl/Cmd + drag   → copy (source stays, target overwritten)
    let dragSrc = null;   // source cell while a shift is being dragged
    const isCopyModifier = (e) => !!(e && (e.ctrlKey || e.metaKey));

    document.addEventListener('dragstart', (e) => {
        const chip = e.target.closest('.sch-shift');
        if (!chip) return;
        dragSrc = chip.closest('.sch-cell');
        chip.style.opacity = '0.4';
        e.dataTransfer.effectAllowed = 'copyMove';
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
        const copyMode = isCopyModifier(e);
        e.dataTransfer.dropEffect = copyMode ? 'copy' : 'move';
        cell.style.outline = copyMode ? '2px dashed #10b981' : '2px dashed var(--accent)';
    });
    document.addEventListener('dragleave', (e) => {
        const cell = e.target.closest('.sch-cell[data-block]');
        if (cell) cell.style.outline = '';
    });
    document.addEventListener('drop', (e) => {
        const cell = e.target.closest('.sch-cell[data-block]');
        if (!cell) return;
        cell.style.outline = '';
        if (!dragSrc || cell === dragSrc) return;
        e.preventDefault();

        const sIso = dragSrc.dataset.dayIso, sBlk = dragSrc.dataset.block, sSlot = dragSrc.dataset.slot;
        const dIso = cell.dataset.dayIso,    dBlk = cell.dataset.block,    dSlot = cell.dataset.slot;
        const sShift = getShift(sIso, sBlk, sSlot);
        if (!sShift) { dragSrc = null; return; }

        if (isCopyModifier(e)) {
            setShift(dIso, dBlk, dSlot, { ...sShift });
            renderChip(cell, { ...sShift });
            toast('Скопировано ✓');
        } else {
            const dShift = getShift(dIso, dBlk, dSlot);
            if (dShift) {
                setShift(sIso, sBlk, sSlot, dShift);
                renderChip(dragSrc, dShift);
            } else {
                delShift(sIso, sBlk, sSlot);
                renderChip(dragSrc, null);
            }
            setShift(dIso, dBlk, dSlot, sShift);
            renderChip(cell, sShift);
        }
        recomputeSummaries();
        scheduleSave();
        dragSrc = null;
    });

})();
