(() => {
    const dateFrom = document.getElementById('dateFrom');
    const dateTo = document.getElementById('dateTo');
    const btn = document.getElementById('loadBtn');
    const loader = document.getElementById('loader');
    const err = document.getElementById('err');
    const tbody = document.getElementById('tbody');
    const prog = document.getElementById('prog');
    const progBar = document.getElementById('progBar');
    const progLabel = document.getElementById('progLabel');
    const progDesc = document.getElementById('progDesc');
    const cancelBtn = document.getElementById('cancelBtn');
    const hideZeroCb = document.getElementById('hideZero');
    const colsBtn = document.getElementById('colsBtn');
    const colsMenu = document.getElementById('colsMenu');
    const rolesBtn = document.getElementById('rolesBtn');
    const rolesMenu = document.getElementById('rolesMenu');
    const empTable = document.getElementById('empTable');
    const tableWrap = empTable ? empTable.closest('.table-wrap') : null;
    const totTipsEl = document.getElementById('totTips');
    const totTipsPaidEl = document.getElementById('totTipsPaid');
    const totSlrPaidEl = document.getElementById('totSlrPaid');
    const totTtpEl = document.getElementById('totTtp');
    const totSalaryToPayEl = document.getElementById('totSalaryToPay');
    const totSalaryEl = document.getElementById('totSalary');
    const paidModal = document.getElementById('paidModal');
    const paidText = document.getElementById('paidText');
    const paidChecked = document.getElementById('paidChecked');
    const paidCancel = document.getElementById('paidCancel');
    const paidOk = document.getElementById('paidOk');
    const helpBtn = document.getElementById('helpBtn');
    const helpModal = document.getElementById('helpModal');
    const helpClose = document.getElementById('helpClose');
    const payExtraBtn = document.getElementById('payExtraBtn');
    const fixBtn = document.getElementById('fixBtn');
    const fixModal = document.getElementById('fixModal');
    const fixClose = document.getElementById('fixClose');
    const fixBody = document.getElementById('fixBody');
    const payExtraModal = document.getElementById('payExtraModal');
    const payExtraEmp = document.getElementById('payExtraEmp');
    const payExtraKind = document.getElementById('payExtraKind');
    const payExtraAmount = document.getElementById('payExtraAmount');
    const payExtraAccount = document.getElementById('payExtraAccount');
    const payExtraComment = document.getElementById('payExtraComment');
    const payExtraChecked = document.getElementById('payExtraChecked');
    const payExtraCancel = document.getElementById('payExtraCancel');
    const payExtraPay = document.getElementById('payExtraPay');
    let runAbort = null;
    let currentJobId = '';
    let paidResolve = null;
    let stickyWrap = null;
    let stickyTable = null;
    let lastStickyVisible = false;

    const setLoading = (on) => {
        btn.disabled = on;
        loader.style.display = on ? 'inline-flex' : 'none';
    };
    const setError = (msg) => {
        if (!msg) { err.style.display = 'none'; err.textContent = ''; return; }
        err.style.display = 'block';
        err.textContent = msg;
    };
    const showToast = (msg) => {
        let t = document.getElementById('empToast');
        if (!t) {
            t = document.createElement('div');
            t.id = 'empToast';
            t.className = 'emp-toast';
            document.body.appendChild(t);
        }
        t.textContent = msg;
        t.classList.add('show');
        if (t.timer) clearTimeout(t.timer);
        t.timer = setTimeout(() => t.classList.remove('show'), 2000);
    };
    const esc = (s) => String(s || '').replace(/[&<>"']/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const digitsOnly = (s) => String(s || '').replace(/\D+/g, '');
    const accountTagById = (acc) => {
        const a = Number(String(acc ?? '').trim()) || 0;
        const labels = { 1: 'QR', 2: 'КЕШ', 8: 'QR', 9: 'VC' };
        if (a > 0 && Object.prototype.hasOwnProperty.call(labels, a)) return '<span class="paid-tag">' + labels[a] + '</span>';
        if (a > 0) return '<span class="paid-tag">#' + String(a) + '</span>';
        return '';
    };
    const addDays = (isoDate, days) => {
        const m = String(isoDate || '').match(/^(\d{4})-(\d{2})-(\d{2})$/);
        if (!m) return '';
        const d = new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]), 12, 0, 0, 0);
        d.setDate(d.getDate() + Number(days || 0));
        const yyyy = d.getFullYear();
        const mm = String(d.getMonth() + 1).padStart(2, '0');
        const dd = String(d.getDate()).padStart(2, '0');
        return `${yyyy}-${mm}-${dd}`;
    };
    const fmtSpaces = (digits) => {
        const d = String(digits || '').replace(/\D+/g, '');
        if (!d) return '';
        const norm = d.replace(/^0+(?=\d)/, '');
        return norm.replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
    };
    const fmtMoney = (n) => fmtSpaces(String(Math.round(Number(n || 0))));
    const fmtMinor2 = (minor) => {
        const m = Number(minor);
        if (!isFinite(m)) return '';
        const neg = m < 0;
        const abs = Math.abs(m);
        const s = (abs / 100).toFixed(2);
        const parts = s.split('.');
        const intPart = fmtSpaces(parts[0] || '0');
        const frac = parts[1] || '00';
        return (neg ? '-' : '') + intPart + '.' + frac;
    };
    const calcSalary = (rate, hours) => Math.round(Number(rate || 0) * Number(hours || 0));
    const vndFromMinor = (minor) => Math.round(Number(minor || 0) / 100);
    const LS_KEY = 'employees_prefs_v1';
    const savePrefs = (obj) => { try { localStorage.setItem(LS_KEY, JSON.stringify(obj || {})); } catch (_) {} };
    const loadPrefs = () => { try { const raw = localStorage.getItem(LS_KEY) || ''; return raw ? JSON.parse(raw) : {}; } catch (_) { return {}; } };
    const prefs = loadPrefs();
    if (prefs.date_from) dateFrom.value = prefs.date_from;
    if (prefs.date_to) dateTo.value = prefs.date_to;
    let hideZero = (prefs.hide_zero === undefined) ? true : !!prefs.hide_zero;
    if (hideZeroCb) hideZeroCb.checked = !hideZero;
    const COLS_KEY = 'employees_cols_v1';
    const ROLES_KEY = 'employees_roles_v1';
    const defaultCols = {
        id: true,
        name: true,
        rate: true,
        role: true,
        checks: true,
        hours: true,
        tips: true,
        tipsPaid: true,
        slrPaid: true,
        tipsToPay: true,
        salary: true,
        salaryToPay: true,
    };
    const loadCols = () => {
        try {
            const raw = localStorage.getItem(COLS_KEY) || '';
            const j = raw ? JSON.parse(raw) : null;
            if (!j || typeof j !== 'object') return { ...defaultCols };
            return { ...defaultCols, ...j };
        } catch (_) {
            return { ...defaultCols };
        }
    };
    const saveCols = (cols) => { try { localStorage.setItem(COLS_KEY, JSON.stringify(cols || {})); } catch (_) {} };
    const colState = loadCols();
    const loadRoles = () => {
        try {
            const raw = localStorage.getItem(ROLES_KEY) || '';
            const j = raw ? JSON.parse(raw) : null;
            if (!j || typeof j !== 'object') return {};
            return j;
        } catch (_) {
            return {};
        }
    };
    const saveRoles = (roles) => { try { localStorage.setItem(ROLES_KEY, JSON.stringify(roles || {})); } catch (_) {} };
    const roleState = loadRoles();
    const normRoleName = (s) => String(s || '').trim();
    const roleLabel = (s) => {
        const r = normRoleName(s);
        return r ? r : '—';
    };
    const roleCollator = new Intl.Collator('ru', { numeric: true, sensitivity: 'base' });
    let roleDefs = [];
    const colDefs = [
        { key: 'id', label: 'ID' },
        { key: 'name', label: 'name' },
        { key: 'rate', label: 'Rate' },
        { key: 'role', label: 'role_name' },
        { key: 'checks', label: 'Чеков' },
        { key: 'hours', label: 'ЧасыРаботы' },
        { key: 'tips', label: 'Tips' },
        { key: 'tipsPaid', label: 'TipsPaid' },
        { key: 'slrPaid', label: 'SlrPaid' },
        { key: 'tipsToPay', label: 'TipsToPay' },
        { key: 'salary', label: 'Salary' },
        { key: 'salaryToPay', label: 'SalaryToPay' },
    ];
    const applyCols = () => {
        if (!empTable) return;
        colDefs.forEach(({ key }) => {
            empTable.classList.toggle('hide-col-' + key, !colState[key]);
        });
    };
    const renderColsMenu = () => {
        if (!colsMenu) return;
        colsMenu.innerHTML = '';
        colDefs.forEach(({ key, label }) => {
            const lab = document.createElement('label');
            lab.className = 'cols-item';
            const inp = document.createElement('input');
            inp.type = 'checkbox';
            inp.checked = !!colState[key];
            inp.addEventListener('change', () => {
                colState[key] = !!inp.checked;
                saveCols(colState);
                applyCols();
                syncStickyHeader(true);
            });
            const text = document.createElement('span');
            text.textContent = label;
            lab.appendChild(inp);
            lab.appendChild(text);
            colsMenu.appendChild(lab);
        });
    };
    const setColsMenuOpen = (on) => {
        if (!colsMenu) return;
        if (on && rolesMenu) rolesMenu.hidden = true;
        colsMenu.hidden = !on;
    };
    const syncRolesFromData = () => {
        const set = new Set();
        dataRows.forEach((r) => set.add(normRoleName(r && r.role_name)));
        roleDefs = Array.from(set);
        roleDefs.sort((a, b) => roleCollator.compare(roleLabel(a), roleLabel(b)));
        roleDefs.forEach((r) => {
            if (!Object.prototype.hasOwnProperty.call(roleState, r)) roleState[r] = true;
        });
        Object.keys(roleState).forEach((k) => { if (!set.has(k)) delete roleState[k]; });
        saveRoles(roleState);
        renderRolesMenu();
    };
    const renderRolesMenu = () => {
        if (!rolesMenu) return;
        rolesMenu.innerHTML = '';
        roleDefs.forEach((role) => {
            const lab = document.createElement('label');
            lab.className = 'cols-item';
            const inp = document.createElement('input');
            inp.type = 'checkbox';
            inp.checked = !!roleState[role];
            inp.addEventListener('change', () => {
                roleState[role] = !!inp.checked;
                saveRoles(roleState);
                renderTable();
                syncStickyHeader(true);
            });
            const text = document.createElement('span');
            text.textContent = roleLabel(role);
            lab.appendChild(inp);
            lab.appendChild(text);
            rolesMenu.appendChild(lab);
        });
    };
    const setRolesMenuOpen = (on) => {
        if (!rolesMenu) return;
        if (on && colsMenu) colsMenu.hidden = true;
        rolesMenu.hidden = !on;
    };
    applyCols();
    renderColsMenu();
    renderRolesMenu();
    dateFrom.addEventListener('change', () => { const p = loadPrefs(); p.date_from = dateFrom.value; savePrefs(p); });
    dateTo.addEventListener('change', () => { const p = loadPrefs(); p.date_to = dateTo.value; savePrefs(p); });
    if (hideZeroCb) hideZeroCb.addEventListener('change', () => {
        hideZero = !hideZeroCb.checked;
        const p = loadPrefs(); p.hide_zero = hideZero; savePrefs(p);
        renderTable();
    });
    if (colsBtn && colsMenu) {
        colsBtn.addEventListener('click', (e) => {
            e.preventDefault();
            setColsMenuOpen(colsMenu.hidden);
        });
        document.addEventListener('click', (e) => {
            if (colsMenu.hidden) return;
            const t = e.target;
            if (t === colsBtn || (colsMenu.contains && colsMenu.contains(t))) return;
            setColsMenuOpen(false);
        });
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') setColsMenuOpen(false);
        });
    }
    if (rolesBtn && rolesMenu) {
        rolesBtn.addEventListener('click', (e) => {
            e.preventDefault();
            if (!roleDefs || roleDefs.length === 0) {
                showToast('Сначала загрузите данные');
                return;
            }
            setRolesMenuOpen(rolesMenu.hidden);
        });
        document.addEventListener('click', (e) => {
            if (rolesMenu.hidden) return;
            const t = e.target;
            if (t === rolesBtn || (rolesMenu.contains && rolesMenu.contains(t))) return;
            setRolesMenuOpen(false);
        });
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') setRolesMenuOpen(false);
        });
    }
    let sortBy = prefs.sort_by || 'checks';
    let sortDir = prefs.sort_dir || 'desc';
    const setSort = (by) => {
        if (!by) return;
        if (sortBy === by) sortDir = (sortDir === 'asc' ? 'desc' : 'asc');
        else { sortBy = by; sortDir = 'asc'; }
        const p = loadPrefs(); p.sort_by = sortBy; p.sort_dir = sortDir; savePrefs(p);
        renderTable();
    };
    const ths = Array.from(document.querySelectorAll('th[data-sort]'));
    ths.forEach((th) => th.addEventListener('click', () => setSort(th.getAttribute('data-sort') || '')));
    window.addEventListener('scroll', () => syncStickyHeader(false), { passive: true });
    window.addEventListener('resize', () => syncStickyHeader(true), { passive: true });
    if (tableWrap) tableWrap.addEventListener('scroll', () => syncStickyHeader(false), { passive: true });
    let dataRows = [];
    let tipsPaidById = {};
    let slrPaidById = {};
    let payMeta = null;
    let payMetaSalary = null;
    let payMetaExtra = null;
    let payExtraOpening = false;
    let payExtraSubmitting = false;
    let tipsAccBalanceMinor = null;
    let lastTipsMinorTotal = 0;
    let lastTtpMinorTotal = 0;
    const tipsAccBalanceEl = document.getElementById('tipsAccBalance');
    const tipsTableSumEl = document.getElementById('tipsTableSum');
    const tipsBalanceDiffEl = document.getElementById('tipsBalanceDiff');
    const ltpRangeNote = document.getElementById('ltpRangeNote');
    const hoursDayCache = new Map();
    let hoursPopEl = null;
    const closeHoursPop = () => {
        if (hoursPopEl) { hoursPopEl.remove(); hoursPopEl = null; }
    };
    document.addEventListener('click', (e) => {
        if (!hoursPopEl) return;
        const t = e.target;
        if (hoursPopEl.contains && hoursPopEl.contains(t)) return;
        if (t && t.closest && t.closest('.hours-btn')) return;
        closeHoursPop();
    });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') closeHoursPop();
    });
    const showHoursPop = (anchor, html) => {
        closeHoursPop();
        const pop = document.createElement('div');
        pop.className = 'hours-pop';
        pop.innerHTML = html;
        document.body.appendChild(pop);
        hoursPopEl = pop;

        const r = anchor.getBoundingClientRect();
        const pr = pop.getBoundingClientRect();
        const margin = 10;
        let left = r.left;
        let top = r.bottom + 8;
        if (left + pr.width > window.innerWidth - margin) left = Math.max(margin, window.innerWidth - margin - pr.width);
        if (top + pr.height > window.innerHeight - margin) top = Math.max(margin, r.top - 8 - pr.height);
        pop.style.left = Math.round(left) + 'px';
        pop.style.top = Math.round(top) + 'px';
    };

    const renderTipsBalanceTotals = () => {
        const tipsTableMinor = Number(lastTtpMinorTotal || 0) || 0;
        if (tipsTableSumEl) tipsTableSumEl.textContent = fmtMoney(vndFromMinor(tipsTableMinor));
        if (tipsAccBalanceEl) {
            tipsAccBalanceEl.textContent = tipsAccBalanceMinor == null ? '—' : fmtMinor2(tipsAccBalanceMinor);
        }
        if (tipsBalanceDiffEl) {
            if (tipsAccBalanceMinor == null) tipsBalanceDiffEl.textContent = '—';
            else tipsBalanceDiffEl.textContent = fmtMinor2((Number(tipsAccBalanceMinor || 0) || 0) - tipsTableMinor);
        }
    };

    const loadTipsBalance = async () => {
        try {
            const url = new URL(location.href);
            url.searchParams.set('ajax', 'tips_balance');
            const res = await fetch(url.toString(), { headers: { 'Accept': 'application/json' } });
            const txt = await res.text();
            let j = null;
            try { j = JSON.parse(txt); } catch (_) {}
            if (!j || !j.ok) return;
            tipsAccBalanceMinor = (j.balance_minor == null) ? null : Number(j.balance_minor || 0);
        } catch (_) {
        } finally {
            renderTipsBalanceTotals();
        }
    };
    const boundRateIds = new Set();
    function bindRateInputs() {
        Array.from(tbody.querySelectorAll('.rate-input')).forEach((inp) => {
            const key = inp.getAttribute('data-user-id') || '';
            if (!key) return;
            if (inp.getAttribute('data-bound') === '1') return;
            inp.setAttribute('data-bound', '1');

            let saving = false;
            const applyFormat = () => {
                inp.value = fmtSpaces(digitsOnly(inp.value));
            };
            const updateSalary = (rateVal) => {
                const uid = inp.getAttribute('data-user-id') || '';
                const hours = Number(inp.getAttribute('data-hours') || 0);
                const salary = calcSalary(rateVal, hours);
                const cell = tbody.querySelector(`.salary-cell[data-user-id="${CSS.escape(uid)}"]`);
                if (cell) cell.textContent = fmtMoney(salary);
            };
            const save = async () => {
                if (saving) return;
                const uid = Number(inp.getAttribute('data-user-id') || 0);
                if (!uid) return;
                const prev = Number(inp.getAttribute('data-rate') || 0);
                const next = Number(digitsOnly(inp.value) || 0);
                if (prev === next) { updateSalary(next); return; }
                saving = true;
                inp.disabled = true;
                try {
                    const url = new URL(location.href);
                    url.searchParams.set('ajax', 'save_rate');
                    const res = await fetch(url.toString(), {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                        body: JSON.stringify({ user_id: uid, rate: String(next) }),
                    });
                    const txt = await res.text();
                    let j = null;
                    try { j = JSON.parse(txt); } catch (_) {}
                    if (!j || !j.ok) throw new Error((j && j.error) ? j.error : 'Ошибка сохранения');
                    const saved = Number(j.rate || 0);
                    inp.setAttribute('data-rate', String(saved));
                    inp.value = fmtSpaces(String(saved));
                    const row = dataRows.find((x) => Number(x.user_id) === uid);
                    if (row) {
                        row.rate = saved;
                        row.salary_minor = calcSalary(saved, row.worked_hours);
                    }
                    updateSalary(saved);
                    renderTable();
                } catch (e) {
                    setError(e && e.message ? e.message : 'Ошибка сохранения');
                    inp.value = fmtSpaces(String(prev));
                    updateSalary(prev);
                } finally {
                    inp.disabled = false;
                    saving = false;
                }
            };

            inp.addEventListener('input', () => {
                applyFormat();
                updateSalary(Number(digitsOnly(inp.value) || 0));
            }, { passive: true });
            inp.addEventListener('blur', () => { save(); });
            inp.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    save();
                }
            });
        });
    }

    const openFix = async () => {
        if (!fixModal || !fixBody) return;
        setModalVisible(fixModal, true);
        fixBody.innerHTML = '<div class="muted" style="padding:10px 0;">Загрузка…</div>';
        const r = await fetch('?ajax=fix_salary_tx');
        const j = await r.json();
        if (!j || !j.ok) throw new Error(j?.error || 'Ошибка');
        const rows = Array.isArray(j.rows) ? j.rows : [];
        if (!rows.length) {
            fixBody.innerHTML = '<div class="muted" style="padding:10px 0;">Нет данных</div>';
            return;
        }
        const html = [];
        html.push('<div><table style="border-collapse:collapse;">');
        html.push('<thead><tr>');
        html.push('<th style="text-align:left; padding:6px 8px;">Дата</th>');
        html.push('<th style="text-align:right; padding:6px 8px;">Сумма</th>');
        html.push('<th style="text-align:left; padding:6px 8px;">Счет</th>');
        html.push('<th style="text-align:left; padding:6px 8px;">Комментарий</th>');
        html.push('</tr></thead><tbody>');
        for (const row of rows) {
            const d = esc(row?.date || '');
            const a = fmtMoney(row?.amount_vnd || 0);
            const acc = esc(row?.account_from || '—');
            const c = esc(row?.comment || '');
            html.push('<tr>');
            html.push('<td style="padding:6px 8px; border-top:1px solid rgba(255,255,255,0.08); white-space:nowrap;">' + d + '</td>');
            html.push('<td style="padding:6px 8px; border-top:1px solid rgba(255,255,255,0.08); text-align:right; white-space:nowrap;">' + esc(a) + '</td>');
            html.push('<td style="padding:6px 8px; border-top:1px solid rgba(255,255,255,0.08); white-space:nowrap;">' + acc + '</td>');
            html.push('<td style="padding:6px 8px; border-top:1px solid rgba(255,255,255,0.08);">' + c + '</td>');
            html.push('</tr>');
        }
        html.push('</tbody></table></div>');
        fixBody.innerHTML = html.join('');
    };

    const closeFix = () => { if (fixModal) setModalVisible(fixModal, false); };
    if (fixBtn) fixBtn.addEventListener('click', () => { openFix().catch((e) => setError(e && e.message ? e.message : 'Ошибка')); });
    if (fixClose) fixClose.addEventListener('click', closeFix);
    if (fixModal) fixModal.addEventListener('click', (e) => { if (e.target === fixModal) closeFix(); });
    if (fixModal) document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && fixModal.style.display === 'flex') closeFix(); });

    function ensureStickyHeader() {
        if (!empTable || !empTable.tHead || !tableWrap) return;
        if (stickyWrap && stickyTable) return;
        stickyWrap = document.createElement('div');
        stickyWrap.className = 'sticky-head-wrap';
        stickyWrap.setAttribute('aria-hidden', 'true');
        stickyWrap.addEventListener('wheel', (e) => {
            if (tableWrap) tableWrap.scrollLeft += e.deltaY;
        }, { passive: true });
        document.body.appendChild(stickyWrap);

        stickyTable = document.createElement('table');
        stickyTable.id = 'empStickyHead';
        stickyTable.setAttribute('aria-hidden', 'true');
        stickyWrap.appendChild(stickyTable);

        const thead = empTable.tHead.cloneNode(true);
        Array.from(thead.querySelectorAll('[id]')).forEach((el) => el.removeAttribute('id'));
        Array.from(thead.querySelectorAll('th')).forEach((th) => {
            th.addEventListener('click', () => setSort(th.getAttribute('data-sort') || ''));
        });
        stickyTable.appendChild(thead);
    }

    function syncStickyHeader(forceMeasure) {
        if (!empTable || !empTable.tHead || !tableWrap) return;
        ensureStickyHeader();
        if (!stickyWrap || !stickyTable) return;

        const wrapRect = tableWrap.getBoundingClientRect();
        const headRect = empTable.tHead.getBoundingClientRect();
        const tableRect = empTable.getBoundingClientRect();
        const shouldShow = headRect.top < 0 && tableRect.bottom > 0 && wrapRect.bottom > 0 && wrapRect.width > 0;
        if (!shouldShow) {
            if (stickyWrap.style.display !== 'none') stickyWrap.style.display = 'none';
            lastStickyVisible = false;
            return;
        }

        stickyWrap.style.display = 'block';
        stickyWrap.style.left = Math.round(wrapRect.left) + 'px';
        stickyWrap.style.width = Math.round(wrapRect.width) + 'px';
        stickyWrap.style.top = '0px';
        stickyTable.className = empTable.className;
        stickyTable.style.width = String(empTable.scrollWidth || empTable.getBoundingClientRect().width) + 'px';

        const scrollLeft = tableWrap.scrollLeft || 0;
        stickyTable.style.transform = `translateX(${-scrollLeft}px)`;

        const needMeasure = forceMeasure || !lastStickyVisible;
        if (needMeasure) {
            const srcThs = Array.from(empTable.tHead.querySelectorAll('th'));
            const dstThs = Array.from(stickyTable.tHead.querySelectorAll('th'));
            const n = Math.min(srcThs.length, dstThs.length);
            for (let i = 0; i < n; i++) {
                const w = srcThs[i].getBoundingClientRect().width;
                const px = (isFinite(w) ? w : 0).toFixed(2) + 'px';
                dstThs[i].style.width = px;
                dstThs[i].style.minWidth = px;
                dstThs[i].style.maxWidth = px;
            }
        }
        lastStickyVisible = true;
    }
    function renderTable() {
        const coll = new Intl.Collator('ru', { numeric: true, sensitivity: 'base' });
        const dir = sortDir === 'desc' ? -1 : 1;
        const augmented = dataRows.slice().map((r) => {
            const tipsMinor = Number(r.tips_minor || 0) || 0;
            const tp = tipsPaidById[String(r.user_id)] || null;
            const tpTotal = tp ? Number(tp.total_amount || 0) : 0;
            const sp = slrPaidById[String(r.user_id)] || null;
            const spTotal = sp ? Number(sp.total_amount || 0) : 0;
            const tipsToPayMinor = Math.max(0, tipsMinor - Math.abs(tpTotal || 0));
            const salaryVnd = Math.round(Number(r.salary_minor || 0) || 0);
            const slrPaidVnd = vndFromMinor(Math.abs(spTotal || 0));
            const salaryToPayVnd = Math.max(0, salaryVnd - slrPaidVnd);
            return { ...r, tips_paid_minor: Math.abs(tpTotal || 0), slr_paid_minor: Math.abs(spTotal || 0), tips_to_pay_minor: tipsToPayMinor, salary_to_pay_vnd: salaryToPayVnd };
        });
        const filtered = hideZero
            ? augmented.filter((r) => {
                const checks = Number(r.checks || 0) || 0;
                const hours = Number(r.worked_hours || 0) || 0;
                const tips = Number(r.tips_minor || 0) || 0;
                const tipsPaid = Number(r.tips_paid_minor || 0) || 0;
                const slrPaid = Number(r.slr_paid_minor || 0) || 0;
                const tipsToPay = Number(r.tips_to_pay_minor || 0) || 0;
                const salary = Number(r.salary_minor || 0) || 0;
                const salaryToPay = Number(r.salary_to_pay_vnd || 0) || 0;
                return !(checks === 0 && hours === 0 && tips === 0 && tipsPaid === 0 && slrPaid === 0 && tipsToPay === 0 && salary === 0 && salaryToPay === 0);
            })
            : augmented;
        const filteredByRole = (() => {
            if (!roleDefs || roleDefs.length === 0) return filtered;
            const anySelected = roleDefs.some((r) => !!roleState[r]);
            if (!anySelected) return [];
            return filtered.filter((r) => !!roleState[normRoleName(r && r.role_name)]);
        })();
        const items = filteredByRole.slice().sort((a, b) => {
            const av = a[sortBy];
            const bv = b[sortBy];
            if (typeof av === 'number' || typeof bv === 'number') {
                const an = Number(av || 0), bn = Number(bv || 0);
                if (an === bn) return 0;
                return an < bn ? -1 * dir : 1 * dir;
            }
            const s = coll.compare(String(av || ''), String(bv || ''));
            return s * dir;
        });
        tbody.innerHTML = '';
        let totChecks = 0;
        let totHours = 0;
        let totTipsMinor = 0;
        let totTipsPaidMinor = 0;
        let totTtpMinor = 0;
        let totSalary = 0;
        let totSalaryToPayVnd = 0;
        let totSlrPaidMinor = 0;
        items.forEach((r) => {
            const tipsVnd = vndFromMinor(r.tips_minor || 0);
            const tp = tipsPaidById[String(r.user_id)] || null;
            const tpTotal = tp ? Number(tp.total_amount || 0) : 0;
            const tpAmt = (tpTotal && isFinite(tpTotal)) ? fmtMoney(vndFromMinor(Math.abs(tpTotal))) : '';
            const tpItems = tp && Array.isArray(tp.items) ? tp.items : [];
            const sp = slrPaidById[String(r.user_id)] || null;
            const spTotal = sp ? Number(sp.total_amount || 0) : 0;
            const spAmt = (spTotal && isFinite(spTotal)) ? fmtMoney(vndFromMinor(Math.abs(spTotal))) : '';
            const spItems = sp && Array.isArray(sp.items) ? sp.items : [];
            const tipsToPayMinor = Number(r.tips_to_pay_minor || 0) || 0;
            const tipsToPayVnd = vndFromMinor(tipsToPayMinor);
            const salaryVnd = Math.round(Number(r.salary_minor || 0) || 0);
            const salaryToPayVnd = Math.round(Number(r.salary_to_pay_vnd || 0) || 0);
            const tr = document.createElement('tr');
            totChecks += Number(r.checks || 0);
            totHours += Number(r.worked_hours || 0);
            totTipsMinor += Number(r.tips_minor || 0);
            totTipsPaidMinor += Math.abs(tpTotal || 0);
            totTtpMinor += tipsToPayMinor;
            totSalary += Number(r.salary_minor || 0);
            totSalaryToPayVnd += salaryToPayVnd;
            totSlrPaidMinor += Math.abs(spTotal || 0);
            const paidDisabled = tipsToPayMinor <= 0 ? 'disabled' : '';
            const salaryPayDisabled = salaryToPayVnd <= 0 ? 'disabled' : '';
            tr.innerHTML = `
                <td class="col-id">${esc(r.user_id)}</td>
                <td class="col-name"><div>${esc(r.name)}</div></td>
                <td class="col-rate" style="text-align:right;"><input class="rate-input" inputmode="numeric" data-user-id="${esc(r.user_id)}" data-hours="${esc(r.worked_hours)}" data-rate="${esc(r.rate)}" value="${esc(fmtSpaces(String(r.rate || '')))}"></td>
                <td class="col-role">${esc(r.role_name)}</td>
                <td class="col-checks" style="text-align:right;">${esc(r.checks)}</td>
                <td class="col-hours" style="text-align:right;"><button type="button" class="hours-btn" data-user-id="${esc(r.user_id)}">${esc(r.worked_hours)}</button></td>
                <td class="col-tips" style="text-align:right;">${esc(fmtMoney(tipsVnd))}</td>
                <td class="col-paid" style="text-align:right;">
                    ${tpAmt ? `<div style="font-weight:900;">${esc(tpAmt)}</div>` : '—'}
                    ${tpItems.length ? tpItems.map((it) => {
                        const raw = String(it && it.date ? it.date : '');
                        const parts = raw.split(' ');
                        const d = parts[0] || raw;
                        const tm = parts[1] || '';
                        const acc = Number(it && it.account_id ? it.account_id : 0) || 0;
                        const ic = accountTagById(acc);
                        const amt = fmtMoney(vndFromMinor(Math.abs(Number(it && it.amount ? it.amount : 0))));
                        return `<div class="paid-item">
                                    <div class="pi-cell date-cell">${esc(d)}</div>
                                    <div class="pi-cell type-cell">${ic ? ic : ''}</div>
                                    <div class="pi-cell time-cell">${esc(tm)}</div>
                                    <div class="pi-cell amt-cell">${esc(amt)}</div>
                                </div>`;
                    }).join('') : ''}
                </td>
                <td class="col-ttp" style="text-align:right;">
                    <div style="display:inline-flex; align-items:center; justify-content:flex-end; gap: 6px; width: 100%;">
                        <span>${esc(fmtMoney(tipsToPayVnd))}</span>
                        <button type="button" class="paid-btn" data-kind="tips" data-user-id="${esc(r.user_id)}" data-amount-vnd="${esc(String(tipsToPayVnd))}" ${paidDisabled}>PAY</button>
                    </div>
                </td>
                <td class="col-salary salary-cell" style="text-align:right;" data-user-id="${esc(r.user_id)}">${esc(fmtMoney(salaryVnd))}</td>
                <td class="col-slr" style="text-align:right;">
                    ${spAmt ? `<div style="font-weight:900;">${esc(spAmt)}</div>` : '—'}
                    ${spItems.length ? spItems.map((it) => {
                        const raw = String(it && it.date ? it.date : '');
                        const parts = raw.split(' ');
                        const d = parts[0] || raw;
                        const tm = parts[1] || '';
                        const acc = Number(it && it.account_id ? it.account_id : 0) || 0;
                        const ic = accountTagById(acc);
                        const amt = fmtMoney(vndFromMinor(Math.abs(Number(it && it.amount ? it.amount : 0))));
                        return `<div class="paid-item">
                                    <div class="pi-cell date-cell">${esc(d)}</div>
                                    <div class="pi-cell type-cell">${ic ? ic : ''}</div>
                                    <div class="pi-cell time-cell">${esc(tm)}</div>
                                    <div class="pi-cell amt-cell">${esc(amt)}</div>
                                </div>`;
                    }).join('') : ''}
                </td>
                <td class="col-salarytopay" style="text-align:right;">
                    <div style="display:inline-flex; align-items:center; justify-content:flex-end; gap: 6px; width: 100%;">
                        <span>${esc(fmtMoney(salaryToPayVnd))}</span>
                        <button type="button" class="paid-btn" data-kind="salary" data-user-id="${esc(r.user_id)}" data-amount-vnd="${esc(String(salaryToPayVnd))}" ${salaryPayDisabled}>PAY</button>
                    </div>
                </td>
            `;
            tbody.appendChild(tr);
        });
        bindRateInputs();
        if (totTipsEl) totTipsEl.textContent = fmtMoney(vndFromMinor(totTipsMinor));
        if (totTipsPaidEl) totTipsPaidEl.textContent = fmtMoney(vndFromMinor(totTipsPaidMinor));
        if (totTtpEl) totTtpEl.textContent = fmtMoney(vndFromMinor(totTtpMinor));
        if (totSalaryToPayEl) totSalaryToPayEl.textContent = fmtMoney(totSalaryToPayVnd);
        if (totSalaryEl) totSalaryEl.textContent = fmtMoney(totSalary);
        if (totSlrPaidEl) totSlrPaidEl.textContent = fmtMoney(vndFromMinor(totSlrPaidMinor));
        lastTipsMinorTotal = totTipsMinor;
        lastTtpMinorTotal = totTtpMinor;
        renderTipsBalanceTotals();
        syncStickyHeader(true);
    }

    tbody.addEventListener('click', async (e) => {
        const t = e.target;
        const btn = (t && t.closest) ? t.closest('.hours-btn') : null;
        if (!btn) return;
        const uid = Number(btn.getAttribute('data-user-id') || 0);
        if (!uid) return;
        const row = dataRows.find((x) => Number(x.user_id) === uid);
        const name = row ? String(row.name || '') : '';
        const df = dateFrom ? String(dateFrom.value || '').trim() : '';
        const dt = dateTo ? String(dateTo.value || '').trim() : '';
        if (!df || !dt) return;

        const key = uid + '|' + df + '|' + dt;
        showHoursPop(btn, `<div class="h-title">${esc(name || ('ID ' + String(uid)))}</div><div class="h-sub">Загрузка…</div>`);
        try {
            let cached = hoursDayCache.get(key) || null;
            if (!cached) {
                const url = new URL(location.href);
                url.searchParams.set('ajax', 'hours_by_day');
                url.searchParams.set('user_id', String(uid));
                url.searchParams.set('date_from', df);
                url.searchParams.set('date_to', dt);
                const res = await fetch(url.toString(), { headers: { 'Accept': 'application/json' } });
                const txt = await res.text();
                let j = null;
                try { j = JSON.parse(txt); } catch (_) {}
                if (!j || !j.ok) throw new Error((j && j.error) ? j.error : 'Ошибка');
                cached = j;
                hoursDayCache.set(key, cached);
            }
            const days = Array.isArray(cached.days) ? cached.days : [];
            const list = days.map((it) => {
                const d = String(it && it.date ? it.date : '');
                const v = Number(it && it.hours ? it.hours : 0) || 0;
                return `<div class="h-row"><div class="d">${esc(d)}</div><div class="v">${esc(String(v))}</div></div>`;
            }).join('');
            const tot = Number(cached.total_hours || 0) || 0;
            showHoursPop(btn, `
                <div class="h-title">${esc(name || ('ID ' + String(uid)))}</div>
                <div class="h-sub">Часы по дням · ${esc(df)} — ${esc(dt)}</div>
                <div class="h-list">${list || '<div class="h-sub">Нет данных</div>'}</div>
                <div class="h-total"><span>Итого</span><span>${esc(String(tot))}</span></div>
            `);
        } catch (err) {
            showHoursPop(btn, `<div class="h-title">${esc(name || ('ID ' + String(uid)))}</div><div class="h-sub">${esc(String(err && err.message ? err.message : err))}</div>`);
        }
    });

    const withTimeout = (ms = 30000) => {
        const ctrl = new AbortController();
        const t = setTimeout(() => ctrl.abort('timeout'), ms);
        return { signal: ctrl.signal, cleanup: () => clearTimeout(t), controller: ctrl };
    };

    const load = async () => {
        setError('');
        setLoading(true);
        tbody.innerHTML = '';
        prog.style.display = 'flex';
        loader.style.display = 'none';
        progBar.style.width = '0%';
        progLabel.textContent = '0%';
        progDesc.textContent = 'Загрузка данных официантов…';
        cancelBtn.style.display = 'inline-block';
        cancelBtn.disabled = false;
        let basePct = 0;
        const tick = setInterval(() => {
            if (basePct >= 20) return;
            basePct += 1;
            progBar.style.width = basePct + '%';
            progLabel.textContent = basePct + '%';
        }, 300);
        try {
            const url = new URL(location.href);
            url.searchParams.set('ajax', 'load');
            url.searchParams.set('date_from', dateFrom.value);
            url.searchParams.set('date_to', dateTo.value);
            const res = await fetch(url.toString(), { headers: { 'Accept': 'application/json' } });
            const txt = await res.text();
            let j = null;
            try { j = JSON.parse(txt); } catch (_) {}
            if (!j || !j.ok) throw new Error((j && j.error) ? j.error : 'Ошибка');
            clearInterval(tick);
            progBar.style.width = '20%';
            progLabel.textContent = '20%';
            progDesc.textContent = 'Подготовка загрузки Tips…';
            const rows = j.rows || [];
            let aggUser = {};
            let aggName = {};
            let tipsMode = '';
            const prepare = async () => {
                const url = new URL(location.href);
                url.searchParams.set('ajax', 'tips_prepare');
                url.searchParams.set('date_from', dateFrom.value);
                url.searchParams.set('date_to', dateTo.value);
                const { signal, cleanup } = withTimeout(20000);
                const res = await fetch(url.toString(), { headers: { 'Accept': 'application/json' }, signal });
                const t = await res.text();
                let j2 = null;
                try { j2 = JSON.parse(t); } catch (_) {}
                cleanup();
                if (!j2 || !j2.ok) throw new Error((j2 && j2.error) ? j2.error : 'Ошибка подготовки');
                currentJobId = String(j2.job_id || '');
                const total = Number(j2.total || 0);
                if (basePct < 25) {
                    basePct = 25;
                    progBar.style.width = basePct + '%';
                    progLabel.textContent = basePct + '%';
                }
                progDesc.textContent = `Подготовка Tips… дней: 0 из ${total}`;
                return j2;
            };
            const run = async (jobId, total) => {
                let done = 0;
                cancelBtn.style.display = 'inline-block';
                cancelBtn.disabled = false;
                runAbort = new AbortController();
                const abortSignal = runAbort.signal;
                while (done < total) {
                    if (abortSignal.aborted) break;
                    const url = new URL(location.href);
                    url.searchParams.set('ajax', 'tips_run');
                    url.searchParams.set('job_id', jobId);
                    url.searchParams.set('batch', '12');
                    const { signal, cleanup } = withTimeout(30000);
                    const res = await fetch(url.toString(), { headers: { 'Accept': 'application/json' }, signal });
                    const t = await res.text();
                    let j3 = null;
                    try { j3 = JSON.parse(t); } catch (_) {}
                    cleanup();
                    if (!j3 || !j3.ok) throw new Error((j3 && j3.error) ? j3.error : 'Ошибка загрузки чаевых');
                    done = Number(j3.done || 0);
                    const mode = String(j3.tips_mode || '');
                    if (mode) tipsMode = mode;
                    aggUser = j3.agg_user || aggUser;
                    aggName = j3.agg_name || aggName;
                    const pct = total ? (25 + Math.round((done / total) * 75)) : 100;
                    progBar.style.width = pct + '%';
                    progLabel.textContent = pct + '%';
                    progDesc.textContent = `Дни: ${done} из ${total}`;
                }
                runAbort = null;
            };
            const p = await prepare();
            await run(p.job_id, Number(p.total || 0));

            try {
                progBar.style.width = '98%';
                progLabel.textContent = '98%';
                progDesc.textContent = 'Загрузка LTP…';
                const urlLtp = new URL(location.href);
                urlLtp.searchParams.set('ajax', 'ltp_load');
                urlLtp.searchParams.set('date_from', dateFrom.value);
                urlLtp.searchParams.set('date_to', dateTo.value);
                const { signal, cleanup } = withTimeout(20000);
                const resLtp = await fetch(urlLtp.toString(), { headers: { 'Accept': 'application/json' }, signal });
                const txtLtp = await resLtp.text();
                cleanup();
                let jLtp = null;
                try { jLtp = JSON.parse(txtLtp); } catch (_) {}
                if (jLtp && jLtp.ok) {
                    tipsPaidById = jLtp.tips || {};
                    slrPaidById = jLtp.slr || {};
                    if (ltpRangeNote) ltpRangeNote.textContent = 'В учет TipsPaid SlrPaid взяты даты ' + String(jLtp.date_from || '') + ' — ' + String(jLtp.date_to || '');
                } else {
                    tipsPaidById = {};
                    slrPaidById = {};
                    if (ltpRangeNote) ltpRangeNote.textContent = '';
                }
            } catch (_) {
                tipsPaidById = {};
                slrPaidById = {};
                if (ltpRangeNote) ltpRangeNote.textContent = '';
            }

            try {
                await loadPayMeta();
            } catch (_) {
            }
            await loadTipsBalance();

            progBar.style.width = '100%';
            progLabel.textContent = '100%';
            progDesc.textContent = 'Готово';
            dataRows = rows.map((r) => {
                const rate = Number(r.rate || 0);
                const hours = Number(r.worked_hours || 0);
                const salary = calcSalary(rate, hours);
                const tipsMinor = (r.user_id && aggUser[String(r.user_id)]) ? Number(aggUser[String(r.user_id)]) : Number(aggName[String((r.name || '').toLowerCase())] || 0);
                return {
                    user_id: Number(r.user_id || 0),
                    name: String(r.name || ''),
                    role_name: String(r.role_name || ''),
                    rate,
                    checks: Number(r.checks || 0),
                    worked_hours: hours,
                    tips_minor: tipsMinor,
                    salary_minor: salary,
                };
            });
            syncRolesFromData();
            renderTable();
            prog.style.display = 'none';
            cancelBtn.style.display = 'none';
        } catch (e) {
            try { clearInterval(tick); } catch (_) {}
            setError(e && e.message ? e.message : 'Ошибка');
        } finally {
            setLoading(false);
        }
    };

    btn.addEventListener('click', load);

    const openPaidConfirm = (html) => new Promise((resolve) => {
        if (!paidModal || !paidText || !paidChecked || !paidCancel || !paidOk) return resolve(false);
        paidResolve = resolve;
        paidText.innerHTML = html;
        paidChecked.checked = false;
        paidOk.disabled = true;
        paidModal.style.display = 'flex';
        paidCancel.focus();
    });
    const closePaidConfirm = (ok) => {
        if (!paidModal) return;
        paidModal.style.display = 'none';
        const r = paidResolve;
        paidResolve = null;
        if (r) r(!!ok);
    };
    if (paidChecked) paidChecked.addEventListener('change', () => { if (paidOk) paidOk.disabled = !paidChecked.checked; });
    if (paidCancel) paidCancel.addEventListener('click', () => closePaidConfirm(false));
    if (paidOk) paidOk.addEventListener('click', () => closePaidConfirm(true));
    if (paidModal) {
        paidModal.addEventListener('click', (e) => { if (e.target === paidModal) closePaidConfirm(false); });
        document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && paidModal.style.display === 'flex') closePaidConfirm(false); });
    }

    const openHelp = () => { if (helpModal) helpModal.style.display = 'flex'; };
    const closeHelp = () => { if (helpModal) helpModal.style.display = 'none'; };
    if (helpBtn) helpBtn.addEventListener('click', openHelp);
    if (helpClose) helpClose.addEventListener('click', closeHelp);
    if (helpModal) {
        helpModal.addEventListener('click', (e) => { if (e.target === helpModal) closeHelp(); });
        document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && helpModal.style.display === 'flex') closeHelp(); });
    }

    const loadPayMeta = async () => {
        if (payMeta) return payMeta;
        const url = new URL(location.href);
        url.searchParams.set('ajax', 'pay_meta');
        const { signal, cleanup } = withTimeout(15000);
        const res = await fetch(url.toString(), { headers: { 'Accept': 'application/json' }, signal });
        const txt = await res.text();
        cleanup();
        let j = null;
        try { j = JSON.parse(txt); } catch (_) {}
        if (!j || !j.ok) throw new Error((j && j.error) ? j.error : 'Ошибка');
        payMeta = j;
        return payMeta;
    };

    const loadPayMetaSalary = async () => {
        if (payMetaSalary) return payMetaSalary;
        const url = new URL(location.href);
        url.searchParams.set('ajax', 'pay_meta_salary');
        const { signal, cleanup } = withTimeout(15000);
        const res = await fetch(url.toString(), { headers: { 'Accept': 'application/json' }, signal });
        const txt = await res.text();
        cleanup();
        let j = null;
        try { j = JSON.parse(txt); } catch (_) {}
        if (!j || !j.ok) throw new Error((j && j.error) ? j.error : 'Ошибка');
        payMetaSalary = j;
        return payMetaSalary;
    };

    const loadPayMetaExtra = async () => {
        if (payMetaExtra) return payMetaExtra;
        const url = new URL(location.href);
        url.searchParams.set('ajax', 'pay_meta_extra');
        const { signal, cleanup } = withTimeout(15000);
        const res = await fetch(url.toString(), { headers: { 'Accept': 'application/json' }, signal });
        const txt = await res.text();
        cleanup();
        let j = null;
        try { j = JSON.parse(txt); } catch (_) {}
        if (!j || !j.ok) throw new Error((j && j.error) ? j.error : 'Ошибка');
        payMetaExtra = j;
        return payMetaExtra;
    };

    const setModalVisible = (el, on) => {
        if (!el) return;
        el.style.display = on ? 'flex' : 'none';
    };

    const setPayExtraLoading = (on) => {
        if (!payExtraModal) return;
        if (on) payExtraModal.classList.add('loading');
        else payExtraModal.classList.remove('loading');
        const disabled = !!on;
        if (payExtraEmp) payExtraEmp.disabled = disabled;
        if (payExtraKind) payExtraKind.disabled = disabled;
        if (payExtraAmount) payExtraAmount.disabled = disabled;
        if (payExtraAccount) payExtraAccount.disabled = disabled;
        if (payExtraChecked) payExtraChecked.disabled = disabled;
    };

    const buildPayComment = (kind, empId, empName) => {
        const creatorEmail = String((window.__USER_EMAIL__ || '')).trim();
        const creatorLabel = creatorEmail ? creatorEmail : '—';
        const prefix = kind === 'salary' ? 'SLR' : 'TIPS';
        const namePart = empName ? (empName + ' ') : '';
        return `${prefix} ${namePart}ID=${String(empId)} by ${creatorLabel}`;
    };

    const refreshPayExtraComment = () => {
        if (!payExtraEmp || !payExtraKind || !payExtraComment) return;
        const uid = Number(payExtraEmp.value || 0);
        const row = dataRows.find((x) => Number(x.user_id) === uid);
        const name = row ? String(row.name || '').trim() : '';
        payExtraComment.value = uid ? buildPayComment(String(payExtraKind.value || 'tips'), uid, name) : '';
    };

    const payExtraAmountDigits = (value) => digitsOnly(value);
    const formatPayExtraAmount = (value) => fmtSpaces(payExtraAmountDigits(value));
    const readPayExtraAmount = () => {
        if (!payExtraAmount) return 0;
        const digits = payExtraAmountDigits(payExtraAmount.value);
        return Math.round(Number(digits || 0) || 0);
    };
    const applyPayExtraAmountFormatting = () => {
        if (!payExtraAmount) return;
        payExtraAmount.value = formatPayExtraAmount(payExtraAmount.value);
    };

    const fillPayExtraEmployees = () => {
        if (!payExtraEmp) return;
        payExtraEmp.innerHTML = '';
        const rows = dataRows.slice().sort((a, b) => String(a.name || '').localeCompare(String(b.name || ''), 'ru'));
        rows.forEach((r) => {
            const uid = Number(r.user_id || 0);
            if (!uid) return;
            const opt = document.createElement('option');
            opt.value = String(uid);
            opt.textContent = `${String(r.name || '').trim()} (#${uid})`;
            payExtraEmp.appendChild(opt);
        });
    };

    const openPayExtra = async () => {
        if (!payExtraModal) return;
        if (payExtraOpening || payExtraSubmitting) return;
        if (!dataRows.length) { setError('Сначала нажми ЗАГРУЗИТЬ'); return; }
        payExtraOpening = true;
        if (payExtraBtn) payExtraBtn.disabled = true;
        setModalVisible(payExtraModal, true);
        setPayExtraLoading(true);
        fillPayExtraEmployees();
        try {
            const meta = await loadPayMetaExtra();
            if (payExtraAccount) {
                payExtraAccount.innerHTML = '';
                const accs = Array.isArray(meta.accounts) ? meta.accounts : [];
                accs.forEach((a) => {
                    const id = Number(a && a.id ? a.id : 0);
                    if (!id) return;
                    const opt = document.createElement('option');
                    opt.value = String(id);
                    opt.textContent = String(a.name || ('#' + String(id)));
                    payExtraAccount.appendChild(opt);
                });
                if (payExtraKind && String(payExtraKind.value) === 'salary') payExtraAccount.value = '1';
                else payExtraAccount.value = '8';
            }
        } catch (e) {
            setError(e && e.message ? e.message : 'Ошибка');
        }
        if (payExtraAmount) payExtraAmount.value = '';
        if (payExtraChecked) payExtraChecked.checked = false;
        if (payExtraPay) payExtraPay.disabled = true;
        refreshPayExtraComment();
        setPayExtraLoading(false);
        payExtraOpening = false;
        if (payExtraBtn) payExtraBtn.disabled = false;
    };

    const closePayExtra = () => setModalVisible(payExtraModal, false);

    if (payExtraBtn) payExtraBtn.addEventListener('click', () => { openPayExtra().catch((e) => setError(e && e.message ? e.message : 'Ошибка')); });
    if (payExtraCancel) payExtraCancel.addEventListener('click', closePayExtra);
    if (payExtraModal) payExtraModal.addEventListener('click', (e) => { if (e.target === payExtraModal) closePayExtra(); });
    if (payExtraModal) document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && payExtraModal.style.display === 'flex') closePayExtra(); });
    if (payExtraEmp) payExtraEmp.addEventListener('change', refreshPayExtraComment);
    if (payExtraAmount) {
        payExtraAmount.addEventListener('input', applyPayExtraAmountFormatting);
        payExtraAmount.addEventListener('paste', (e) => {
            e.preventDefault();
            const text = (e.clipboardData && e.clipboardData.getData && e.clipboardData.getData('text')) || '';
            payExtraAmount.value = formatPayExtraAmount(text);
        });
    }
    if (payExtraKind) payExtraKind.addEventListener('change', () => {
        refreshPayExtraComment();
        if (payExtraAccount) {
            if (String(payExtraKind.value) === 'salary') payExtraAccount.value = '1';
            else payExtraAccount.value = '8';
        }
    });
    if (payExtraChecked && payExtraPay) payExtraChecked.addEventListener('change', () => { payExtraPay.disabled = !(payExtraChecked.checked && !payExtraSubmitting && !payExtraOpening); });
    if (payExtraPay) payExtraPay.addEventListener('click', async () => {
        if (!payExtraEmp || !payExtraKind || !payExtraAmount || !payExtraAccount) return;
        if (payExtraSubmitting || payExtraOpening) return;
        const uid = Number(payExtraEmp.value || 0);
        const kind = String(payExtraKind.value || 'tips');
        const amount = readPayExtraAmount();
        const accountFrom = Number(payExtraAccount.value || 0);
        const row = dataRows.find((x) => Number(x.user_id) === uid);
        const empName = row ? String(row.name || '').trim() : '';
        if (!uid || !amount || !accountFrom) return;

        payExtraSubmitting = true;
        payExtraPay.disabled = true;
        setPayExtraLoading(true);
        try {
            const url = new URL(location.href);
            url.searchParams.set('ajax', 'pay_extra');
            const res = await fetch(url.toString(), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify({ waiter_id: uid, kind, amount_vnd: amount, account_from: accountFrom, employee_name: empName }),
            });
            const txt = await res.text();
            let j = null;
            try { j = JSON.parse(txt); } catch (_) {}
            if (!j || !j.ok) throw new Error((j && j.error) ? j.error : 'Ошибка');
            const date = j.date ? String(j.date) : '';
            if (kind === 'salary') {
                const cur = slrPaidById[String(uid)] || { total_amount: 0, items: [] };
                const nextTotal = Number(cur.total_amount || 0) + (Math.abs(Number(j.amount_vnd || 0) || amount) * 100);
                const nextItems = Array.isArray(cur.items) ? cur.items.slice() : [];
                if (date) nextItems.unshift({ date, amount: -Math.abs((Number(j.amount_vnd || 0) || amount) * 100), account_id: accountFrom });
                slrPaidById[String(uid)] = { total_amount: nextTotal, items: nextItems };
            } else {
                const cur = tipsPaidById[String(uid)] || { total_amount: 0, items: [] };
                const nextTotal = Number(cur.total_amount || 0) + Math.abs(Number(j.amount_vnd || 0) || amount) * 100;
                const nextItems = Array.isArray(cur.items) ? cur.items.slice() : [];
                if (date) nextItems.unshift({ date, amount: -Math.abs((Number(j.amount_vnd || 0) || amount) * 100), account_id: accountFrom });
                tipsPaidById[String(uid)] = { total_amount: nextTotal, items: nextItems };
            }
            closePayExtra();
            renderTable();
            loadTipsBalance().catch(() => {});
            payExtraSubmitting = false;
            setPayExtraLoading(false);
        } catch (err) {
            setError(err && err.message ? err.message : 'Ошибка');
            payExtraSubmitting = false;
            setPayExtraLoading(false);
            if (payExtraChecked) payExtraPay.disabled = !payExtraChecked.checked;
        }
    });

    const empNameById = {};
    const loadEmployeeName = async (id) => {
        const uid = Number(id || 0);
        if (!uid) return '';
        if (Object.prototype.hasOwnProperty.call(empNameById, String(uid))) return String(empNameById[String(uid)] || '');
        const url = new URL(location.href);
        url.searchParams.set('ajax', 'employee_lookup');
        url.searchParams.set('user_id', String(uid));
        const { signal, cleanup } = withTimeout(15000);
        const res = await fetch(url.toString(), { headers: { 'Accept': 'application/json' }, signal });
        const txt = await res.text();
        cleanup();
        let j = null;
        try { j = JSON.parse(txt); } catch (_) {}
        if (!j || !j.ok) throw new Error((j && j.error) ? j.error : 'Ошибка');
        const name = String(j.name || '').trim();
        empNameById[String(uid)] = name;
        return name;
    };

    const openPayExtraPreset = async ({ kind, userId, amountVnd }) => {
        if (!payExtraModal || !payExtraEmp || !payExtraKind || !payExtraAmount || !payExtraAccount || !payExtraChecked || !payExtraPay) return;
        if (payExtraOpening || payExtraSubmitting) return;
        if (!dataRows.length) { setError('Сначала нажми ЗАГРУЗИТЬ'); return; }

        payExtraOpening = true;
        if (payExtraBtn) payExtraBtn.disabled = true;
        setModalVisible(payExtraModal, true);
        setPayExtraLoading(true);
        fillPayExtraEmployees();

        const uid = Number(userId || 0) || 0;
        const k = (String(kind) === 'salary') ? 'salary' : 'tips';
        const amt = Math.max(0, Math.round(Number(amountVnd || 0) || 0));

        payExtraKind.value = k;

        if (uid > 0) {
            const foundOpt = Array.from(payExtraEmp.options).some((o) => String(o.value) === String(uid));
            if (!foundOpt) {
                const opt = document.createElement('option');
                opt.value = String(uid);
                opt.textContent = `#${String(uid)}`;
                payExtraEmp.insertBefore(opt, payExtraEmp.firstChild);
            }
            payExtraEmp.value = String(uid);
        }

        try {
            const meta = await loadPayMetaExtra();
            payExtraAccount.innerHTML = '';
            const accs = Array.isArray(meta.accounts) ? meta.accounts : [];
            accs.forEach((a) => {
                const id = Number(a && a.id ? a.id : 0);
                if (!id) return;
                const opt = document.createElement('option');
                opt.value = String(id);
                opt.textContent = String(a.name || ('#' + String(id)));
                payExtraAccount.appendChild(opt);
            });
        } catch (e) {
            setError(e && e.message ? e.message : 'Ошибка');
        }

        const defaultAcc = (k === 'salary') ? 1 : 8;
        if (Array.from(payExtraAccount.options).some((o) => Number(o.value) === defaultAcc)) payExtraAccount.value = String(defaultAcc);

        payExtraAmount.value = amt > 0 ? formatPayExtraAmount(String(amt)) : '';
        if (payExtraChecked) payExtraChecked.checked = false;
        if (payExtraPay) payExtraPay.disabled = true;
        refreshPayExtraComment();

        setPayExtraLoading(false);
        payExtraOpening = false;
        if (payExtraBtn) payExtraBtn.disabled = false;
    };

    tbody.addEventListener('click', (e) => {
        const b = e.target && e.target.closest ? e.target.closest('.paid-btn') : null;
        if (!b) return;
        const kind = String(b.getAttribute('data-kind') || 'tips');
        const uid = Number(b.getAttribute('data-user-id') || 0);
        const amountVnd = Math.round(Number(b.getAttribute('data-amount-vnd') || 0) || 0);
        if (!uid || amountVnd <= 0) return;
        openPayExtraPreset({ kind, userId: uid, amountVnd }).catch((err) => setError(err && err.message ? err.message : 'Ошибка'));
    });
    cancelBtn.addEventListener('click', async () => {
        try {
            cancelBtn.disabled = true;
            if (runAbort) {
                runAbort.abort('user-cancel');
            }
            const url = new URL(location.href);
            url.searchParams.set('ajax', 'tips_cancel');
            url.searchParams.set('job_id', currentJobId || '');
            // best-effort cancel (no need to await)
            fetch(url.toString(), { headers: { 'Accept': 'application/json' } }).catch(() => {});
        } catch (_) {}
        prog.style.display = 'none';
        cancelBtn.style.display = 'none';
        setLoading(false);
    });
})();
