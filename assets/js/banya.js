const elFrom = document.getElementById('dateFrom');
    const elTo = document.getElementById('dateTo');
    const btn = document.getElementById('loadBtn');
    const loader = document.getElementById('loader');
    const prog = document.getElementById('prog');
    const progBar = document.getElementById('progBar');
    const progLabel = document.getElementById('progLabel');
    const progDesc = document.getElementById('progDesc');
    const err = document.getElementById('err');
    const tbody = document.getElementById('tbody');
    const totChecks = document.getElementById('totChecks');
    const totSum = document.getElementById('totSum');
    const totHookah = document.getElementById('totHookah');
    const totWithout = document.getElementById('totWithout');

    const setLoading = (on) => {
        btn.disabled = on;
        if (loader) loader.style.display = 'none';
        if (prog) prog.style.display = on ? 'inline-flex' : 'none';
    };
    const setProgress = (pct, desc) => {
        const p = Math.max(0, Math.min(100, Math.round(Number(pct || 0))));
        if (progBar) progBar.style.width = p + '%';
        if (progLabel) progLabel.textContent = p + '%';
        if (progDesc) progDesc.textContent = String(desc || '');
    };

    const setError = (msg) => {
        if (!msg) { err.style.display = 'none'; err.textContent = ''; return; }
        err.style.display = 'block';
        err.textContent = msg;
    };

    const esc = (s) => String(s || '').replace(/[&<>"']/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const hookahSvg = '<svg class="hookah-ico" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M11 2h2l-1 3h2l-2 5" stroke="#b65930" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><path d="M9.2 10.8c1-.9 4.6-.9 5.6 0 1 .9.8 2.6.3 3.4-.6 1-1 1.6-1 2.8 0 1.9-1.3 3-2.1 3s-2.1-1.1-2.1-3c0-1.2-.4-1.8-1-2.8-.5-.8-.7-2.5.3-3.4Z" stroke="#b65930" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><path d="M14.8 13.8h2.6c1.2 0 2.1 1 2.1 2.2v3.1c0 1.1-.9 2-2 2h-3.7" stroke="#b65930" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><path d="M6 20h8" stroke="#b65930" stroke-width="1.8" stroke-linecap="round"/><path d="M18.5 16.2h-1.6" stroke="#b65930" stroke-width="1.8" stroke-linecap="round"/></svg>';

    const PREFS_COOKIE = 'banya_prefs_v1';
    const getCookie = (name) => {
        const parts = String(document.cookie || '').split(';').map(s => s.trim());
        for (const p of parts) {
            if (!p) continue;
            const eq = p.indexOf('=');
            if (eq < 0) continue;
            const k = p.slice(0, eq).trim();
            if (k !== name) continue;
            return decodeURIComponent(p.slice(eq + 1));
        }
        return '';
    };
    const setCookie = (name, value, days = 180) => {
        const maxAge = Math.max(0, Math.round(days * 24 * 60 * 60));
        document.cookie = `${name}=${encodeURIComponent(value)}; Max-Age=${maxAge}; Path=/; SameSite=Lax`;
    };
    const loadPrefs = () => {
        try {
            const raw = getCookie(PREFS_COOKIE);
            if (!raw) return null;
            const obj = JSON.parse(raw);
            return (obj && typeof obj === 'object') ? obj : null;
        } catch (_) {
            return null;
        }
    };
    const savePrefs = (prefs) => {
        try {
            setCookie(PREFS_COOKIE, JSON.stringify(prefs || {}));
        } catch (_) {}
    };

    // Пагинация и сортировка
    const pagerTop = document.getElementById('pagerTop');
    const pagerBottom = document.getElementById('pagerBottom');
    const noPagesCb = document.getElementById('noPages');
    const groupByDayCb = document.getElementById('groupByDay');
    const tableFilterBtn = document.getElementById('tableFilterBtn');
    const tableFilterPop = document.getElementById('tableFilterPop');
    const ths = Array.from(document.querySelectorAll('th[data-sort]'));
    let dataItems = [];
    let sortBy = 'date';
    let sortDir = 'asc';
    let page = 1;
    const pageSize = 20;
    let pagesOn = true;
    let tableFilterIds = [];
    let tableFilterIdsAll = [];
    let groupByDay = false;
    const collapsedDays = new Set();
    let collapseAllDaysOnNextRender = false;

    const applyPrefsToUi = () => {
        const p = loadPrefs();
        if (!p) return;
        if (typeof p.date_from === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(p.date_from)) elFrom.value = p.date_from;
        if (typeof p.date_to === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(p.date_to)) elTo.value = p.date_to;
        if (typeof p.pages_on === 'boolean') {
            pagesOn = !!p.pages_on;
        } else if (typeof p.no_pages === 'boolean') {
            pagesOn = !p.no_pages;
        }
        if (noPagesCb) noPagesCb.checked = !!pagesOn;
        if (typeof p.group_by_day === 'boolean') {
            groupByDay = !!p.group_by_day;
            if (groupByDayCb) groupByDayCb.checked = groupByDay;
        }
        if (typeof p.sort_by === 'string') sortBy = p.sort_by;
        if (p.sort_dir === 'asc' || p.sort_dir === 'desc') sortDir = p.sort_dir;
        if (typeof p.page === 'number' && isFinite(p.page) && p.page > 0) page = Math.floor(p.page);
        if (Array.isArray(p.table_filter_ids)) {
            tableFilterIds = p.table_filter_ids.map((x) => String(x)).filter((x) => /^\d+$/.test(x));
        } else if (typeof p.table_filter === 'string' && /^\d+$/.test(p.table_filter)) {
            tableFilterIds = [String(p.table_filter)];
        }
    };
    const persistPrefsFromUi = () => {
        savePrefs({
            date_from: elFrom.value,
            date_to: elTo.value,
            pages_on: !!pagesOn,
            sort_by: sortBy,
            sort_dir: sortDir,
            page: page,
            table_filter_ids: tableFilterIds,
            group_by_day: !!groupByDay,
        });
    };

    const updateSortIndicators = () => {
        ths.forEach((th) => {
            const arrow = th.querySelector('.sort-arrow');
            if (!arrow) return;
            const k = th.getAttribute('data-sort') || '';
            if (!k || k !== sortBy) {
                arrow.textContent = '';
                return;
            }
            arrow.textContent = (sortDir === 'asc') ? '▲' : '▼';
        });
    };

    const fmtVnd = (minor) => {
        const vnd = Math.round(Number(minor || 0) / 100);
        return String(vnd).replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
    };

    const rebuildTableFilter = () => {
        if (!tableFilterPop) return;
        const set = new Map();
        dataItems.forEach((it) => {
            const id = String(it.table_id || '');
            if (!id) return;
            const label = String(it.table || it.table_id || '').trim();
            if (!set.has(id)) set.set(id, label || id);
        });
        const entries = Array.from(set.entries()).sort((a, b) => {
            const an = Number(a[0] || 0);
            const bn = Number(b[0] || 0);
            if (an && bn) return an - bn;
            return String(a[1]).localeCompare(String(b[1]), 'ru');
        });
        tableFilterIdsAll = entries.map(([id]) => String(id));
        tableFilterIds = (tableFilterIds || []).filter((id) => tableFilterIdsAll.includes(String(id)));
        const isAll = tableFilterIds.length === 0;
        const selected = new Set(tableFilterIds.map((x) => String(x)));
        const html = [
            '<div style="font-weight:900; margin-bottom: 6px; color: rgba(182,89,48,0.95);">Фильтр столов</div>',
            entries.map(([id, label]) => {
                const checked = isAll || selected.has(String(id)) ? 'checked' : '';
                return `<label style="display:flex; align-items:center; gap: 10px; padding: 5px 6px; border-radius: 10px; cursor: pointer; white-space: nowrap; flex-wrap: nowrap;"><span style="flex: 1 1 auto; min-width: 0; overflow: hidden; text-overflow: ellipsis;">${esc(label)}</span><input type="checkbox" class="tf-cb" data-id="${esc(id)}" ${checked} style="margin:0; flex: 0 0 auto;"></label>`;
            }).join(''),
            '<div style="margin-top: 8px; display:flex; gap: 8px; justify-content:flex-end;">' +
                '<button type="button" class="secondary small" id="tfAllBtn">Все</button>' +
                '<button type="button" class="secondary small" id="tfClearBtn">Сброс</button>' +
            '</div>',
        ].join('');
        tableFilterPop.innerHTML = html;
        const applyFromDom = () => {
            const ids = [];
            tableFilterPop.querySelectorAll('input.tf-cb').forEach((cb) => {
                if (cb.checked) ids.push(String(cb.getAttribute('data-id') || ''));
            });
            const uniq = Array.from(new Set(ids)).filter((x) => /^\d+$/.test(x));
            if (uniq.length === tableFilterIdsAll.length) tableFilterIds = [];
            else tableFilterIds = uniq;
            page = 1;
            renderTable();
        };
        tableFilterPop.querySelectorAll('input.tf-cb').forEach((cb) => cb.addEventListener('change', applyFromDom));
        const allBtn = tableFilterPop.querySelector('#tfAllBtn');
        if (allBtn) allBtn.addEventListener('click', () => {
            tableFilterIds = [];
            rebuildTableFilter();
            page = 1;
            renderTable();
        });
        const clearBtn = tableFilterPop.querySelector('#tfClearBtn');
        if (clearBtn) clearBtn.addEventListener('click', () => {
            tableFilterIds = [];
            rebuildTableFilter();
            page = 1;
            renderTable();
        });
    };

    const applySort = (arr) => {
        const coll = new Intl.Collator('ru', {numeric:true, sensitivity:'base'});
        const dir = sortDir === 'desc' ? -1 : 1;
        const get = (o, k) => (o && Object.prototype.hasOwnProperty.call(o, k)) ? o[k] : '';
        return arr.slice().sort((a, b) => {
            const av = get(a, sortBy);
            const bv = get(b, sortBy);
            if (typeof av === 'number' || typeof bv === 'number') {
                const an = Number(av || 0), bn = Number(bv || 0);
                if (an === bn) return 0;
                return an < bn ? -1*dir : 1*dir;
            }
            const s = coll.compare(String(av || ''), String(bv || ''));
            return s * dir;
        });
    };

    const buildPageList = (pages, current) => {
        if (pages <= 1) return [1];
        const keep = new Set([1, pages, current, current - 1, current - 2, current + 1, current + 2]);
        const out = [];
        let last = 0;
        for (let i = 1; i <= pages; i++) {
            if (!keep.has(i)) continue;
            if (last && i - last > 1) out.push('…');
            out.push(i);
            last = i;
        }
        return out;
    };

    const renderPager = (el, pages, current) => {
        if (!el) return;
        if (!pagesOn || pages <= 1) {
            el.innerHTML = '';
            return;
        }
        const items = buildPageList(pages, current);
        el.innerHTML = '';
        items.forEach((it) => {
            if (it === '…') {
                const span = document.createElement('span');
                span.className = 'page-dots';
                span.textContent = '…';
                el.appendChild(span);
                return;
            }
            const b = document.createElement('button');
            b.type = 'button';
            b.className = 'page-btn' + (it === current ? ' active' : '');
            b.textContent = String(it);
            b.setAttribute('data-page', String(it));
            el.appendChild(b);
        });
    };

    const renderTable = () => {
        const useFilter = Array.isArray(tableFilterIds) && tableFilterIds.length > 0;
        const base = useFilter ? dataItems.filter((x) => tableFilterIds.includes(String(x.table_id || ''))) : dataItems;
        const items = applySort(base);
        const total = items.length;
        const pages = pagesOn ? Math.max(1, Math.ceil(total / pageSize)) : 1;
        if (page > pages) page = pages;
        const start = pagesOn ? (page - 1) * pageSize : 0;
        const slice = pagesOn ? items.slice(start, start + pageSize) : items;

        tbody.innerHTML = '';
        if (groupByDay) {
            const dayStats = {};
            items.forEach((row) => {
                const day = String(row.date || '').slice(0, 10);
                if (!day) return;
                if (!dayStats[day]) dayStats[day] = { checks: 0, sum_minor: 0 };
                dayStats[day].checks += 1;
                dayStats[day].sum_minor += Number(row.sum_minor || 0);
            });
            if (collapseAllDaysOnNextRender) {
                collapsedDays.clear();
                Object.keys(dayStats).forEach((d) => collapsedDays.add(d));
                collapseAllDaysOnNextRender = false;
            }
            let lastDay = '';
            slice.forEach((row) => {
                const day = String(row.date || '').slice(0, 10);
                if (day && day !== lastDay) {
                    lastDay = day;
                    const st = dayStats[day] || { checks: 0, sum_minor: 0 };
                    const isCollapsed = collapsedDays.has(day);
                    const trG = document.createElement('tr');
                    trG.className = 'day-group';
                    trG.innerHTML = `<td colspan="7"><button type="button" class="day-toggle" data-day="${esc(day)}">${isCollapsed ? '▸' : '▾'}</button>${esc(day)} · чеков ${esc(String(st.checks))} · сумма ${esc(fmtVnd(st.sum_minor))}</td>`;
                    tbody.appendChild(trG);
                    const btn = trG.querySelector('button.day-toggle');
                    if (btn) {
                        btn.addEventListener('click', (e) => {
                            e.preventDefault();
                            e.stopPropagation();
                            const d = String(btn.getAttribute('data-day') || '');
                            if (!d) return;
                            if (collapsedDays.has(d)) collapsedDays.delete(d);
                            else collapsedDays.add(d);
                            renderTable();
                        });
                    }
                }

                const tr = document.createElement('tr');
                tr.setAttribute('data-day', day);
                const txId = Number(row.transaction_id || 0);
                const hasHookah = Number(row.hookah_sum_minor || 0) > 0;
                tr.innerHTML = `
                    <td>${esc(row.date || '')}</td>
                    <td>${esc(row.hall || '')}</td>
                    <td>${esc(row.table || '')}</td>
                    <td>${esc(row.receipt || '')}</td>
                    <td>${esc(row.waiter || '')}</td>
                    <td class="num">${esc(row.sum || '')}</td>
                    <td><button type="button" class="secondary small" data-tx="${esc(txId)}">Детали</button>${hasHookah ? hookahSvg : ''}</td>
                `;
                const isCollapsed = day ? collapsedDays.has(day) : false;
                tr.style.display = isCollapsed ? 'none' : '';
                tbody.appendChild(tr);

                const trD = document.createElement('tr');
                trD.setAttribute('data-day', day);
                trD.className = 'details-row';
                trD.style.display = 'none';
                trD.innerHTML = `<td colspan="7"><div class="details-box muted">Загрузка…</div></td>`;
                tbody.appendChild(trD);

                const btnDetails = tr.querySelector('button');
                if (btnDetails) {
                    btnDetails.addEventListener('click', async () => {
                        if (day && collapsedDays.has(day)) return;
                        const isOpen = trD.style.display !== 'none';
                        if (isOpen) {
                            trD.style.display = 'none';
                            return;
                        }
                        trD.style.display = '';
                        const tx = Number(btnDetails.getAttribute('data-tx') || 0);
                        try {
                            const lines = await loadDetails(tx);
                            const box = document.createElement('div');
                            box.className = 'details-box';
                            if (!lines.length) {
                                box.innerHTML = `<div class="muted">Нет данных</div>`;
                            } else {
                                lines.forEach((ln) => {
                                    const line = document.createElement('div');
                                    line.className = 'detail-line';
                                    const qty = Number(ln.qty || 0);
                                    const qtyTxt = (qty && Math.abs(qty - Math.round(qty)) < 0.0001) ? String(Math.round(qty)) : String(qty);
                                    line.innerHTML = `<div>${esc(ln.name || '')}${qty ? ' × ' + esc(qtyTxt) : ''}</div><div class="detail-sum">${esc(ln.sum || '0')}</div>`;
                                    box.appendChild(line);
                                });
                            }
                            const td = trD.querySelector('td');
                            if (td) { td.innerHTML = ''; td.appendChild(box); }
                        } catch (e) {
                            trD.innerHTML = `<td colspan="7"><div class="details-box" style="color:#b91c1c; font-weight:700;">${esc(e && e.message ? e.message : 'Ошибка')}</div></td>`;
                        }
                    });
                }
            });
        } else {
            slice.forEach((row) => {
            const tr = document.createElement('tr');
            const txId = Number(row.transaction_id || 0);
            const hasHookah = Number(row.hookah_sum_minor || 0) > 0;
            tr.innerHTML = `
                <td>${esc(row.date || '')}</td>
                <td>${esc(row.hall || '')}</td>
                <td>${esc(row.table || '')}</td>
                <td>${esc(row.receipt || '')}</td>
                <td>${esc(row.waiter || '')}</td>
                <td class="num">${esc(row.sum || '')}</td>
                <td><button type="button" class="secondary small" data-tx="${esc(txId)}">Детали</button>${hasHookah ? hookahSvg : ''}</td>
            `;
            tbody.appendChild(tr);

            const trD = document.createElement('tr');
            trD.className = 'details-row';
            trD.style.display = 'none';
            trD.innerHTML = `<td colspan="7"><div class="details-box muted">Загрузка…</div></td>`;
            tbody.appendChild(trD);

            const btnDetails = tr.querySelector('button');
            if (btnDetails) {
                btnDetails.addEventListener('click', async () => {
                    const isOpen = trD.style.display !== 'none';
                    if (isOpen) {
                        trD.style.display = 'none';
                        return;
                    }
                    trD.style.display = '';
                    const tx = Number(btnDetails.getAttribute('data-tx') || 0);
                    try {
                        const lines = await loadDetails(tx);
                        const box = document.createElement('div');
                        box.className = 'details-box';
                        if (!lines.length) {
                            box.innerHTML = `<div class="muted">Нет данных</div>`;
                        } else {
                            lines.forEach((ln) => {
                                const line = document.createElement('div');
                                line.className = 'detail-line';
                                const qty = Number(ln.qty || 0);
                                const qtyTxt = (qty && Math.abs(qty - Math.round(qty)) < 0.0001) ? String(Math.round(qty)) : String(qty);
                                line.innerHTML = `<div>${esc(ln.name || '')}${qty ? ' × ' + esc(qtyTxt) : ''}</div><div class="detail-sum">${esc(ln.sum || '0')}</div>`;
                                box.appendChild(line);
                            });
                        }
                        const td = trD.querySelector('td');
                        if (td) { td.innerHTML = ''; td.appendChild(box); }
                    } catch (e) {
                        trD.innerHTML = `<td colspan="7"><div class="details-box" style="color:#b91c1c; font-weight:700;">${esc(e && e.message ? e.message : 'Ошибка')}</div></td>`;
                    }
                });
            }
            });
        }
        renderPager(pagerTop, pages, page);
        renderPager(pagerBottom, pages, page);
        try {
            const checks = total;
            let sumMinor = 0;
            let hookahMinor = 0;
            items.forEach((r) => {
                sumMinor += Number(r.sum_minor || 0);
                hookahMinor += Number(r.hookah_sum_minor || 0);
            });
            totChecks.textContent = `Итого чеков: ${String(checks)}`;
            totSum.textContent = `Итого сумма: ${fmtVnd(sumMinor)}`;
            totHookah.textContent = `Сумма кальянов: ${fmtVnd(hookahMinor)}`;
            totWithout.textContent = `Сумма без кальянов: ${fmtVnd(sumMinor - hookahMinor)}`;
        } catch (_) {}
        updateSortIndicators();
        persistPrefsFromUi();
    };

    const onPagerClick = (e) => {
        const btn = e.target.closest?.('.page-btn');
        if (!btn) return;
        const p = Number(btn.getAttribute('data-page') || 0);
        if (!p) return;
        page = p;
        renderTable();
    };
    if (pagerTop) pagerTop.addEventListener('click', onPagerClick);
    if (pagerBottom) pagerBottom.addEventListener('click', onPagerClick);
    ths.forEach((th) => {
        th.addEventListener('click', () => {
            const key = th.getAttribute('data-sort');
            if (!key) return;
            if (sortBy === key) sortDir = (sortDir === 'asc') ? 'desc' : 'asc';
            else { sortBy = key; sortDir = 'asc'; }
            page = 1;
            renderTable();
        });
    });
    const closeTableFilter = () => {
        if (!tableFilterPop) return;
        tableFilterPop.style.display = 'none';
    };
    const toggleTableFilter = () => {
        if (!tableFilterPop) return;
        const open = tableFilterPop.style.display !== 'none';
        if (open) {
            tableFilterPop.style.display = 'none';
            return;
        }
        if (!String(tableFilterPop.innerHTML || '').trim()) {
            if (Array.isArray(dataItems) && dataItems.length) rebuildTableFilter();
            else tableFilterPop.innerHTML = '<div class="muted" style="padding: 6px 8px;">Сначала нажми «ЗАГРУЗИТЬ»</div>';
        }
        tableFilterPop.style.display = 'block';
    };
    if (tableFilterBtn) {
        tableFilterBtn.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            toggleTableFilter();
        });
    }
    if (tableFilterPop) {
        tableFilterPop.addEventListener('click', (e) => e.stopPropagation());
        document.addEventListener('click', () => closeTableFilter());
        document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeTableFilter(); });
    }

    if (noPagesCb) {
        noPagesCb.addEventListener('change', () => {
            pagesOn = !!noPagesCb.checked;
            page = 1;
            renderTable();
        });
    }
    if (groupByDayCb) {
        groupByDayCb.addEventListener('change', () => {
            groupByDay = !!groupByDayCb.checked;
            if (groupByDay) {
                collapseAllDaysOnNextRender = true;
            } else {
                collapsedDays.clear();
                collapseAllDaysOnNextRender = false;
            }
            page = 1;
            renderTable();
        });
    }

    elFrom.addEventListener('change', () => persistPrefsFromUi());
    elTo.addEventListener('change', () => persistPrefsFromUi());

    const loadDetails = async (transactionId) => {
        const url = new URL(location.href);
        url.searchParams.set('ajax', 'tx');
        url.searchParams.set('transaction_id', String(transactionId || ''));
        const res = await fetch(url.toString(), { headers: { 'Accept': 'application/json' } });
        const txt = await res.text();
        let j = null;
        try { j = JSON.parse(txt); } catch (_) {}
        if (!j || !j.ok) throw new Error((j && j.error) ? j.error : 'Ошибка деталей');
        return j.lines || [];
    };

    const load = async () => {
        setError('');
        setLoading(true);
        setProgress(0, 'Подготовка…');
        tbody.innerHTML = '';
        totChecks.textContent = 'Итого чеков: 0';
        totSum.textContent = 'Итого сумма: 0';
        totHookah.textContent = 'Сумма кальянов: 0';
        totWithout.textContent = 'Сумма без кальянов: 0';
        try {
            const from = String(elFrom.value || '');
            const to = String(elTo.value || '');
            if (!/^\d{4}-\d{2}-\d{2}$/.test(from) || !/^\d{4}-\d{2}-\d{2}$/.test(to)) throw new Error('Некорректный период');

            const concurrency = 6;
            const base = new URL(location.href);
            base.searchParams.set('ajax', 'load_day');
            const seen = new Set();
            const out = [];
            let done = 0;

            const dayList = (() => {
                const out = [];
                const a = new Date(from + 'T00:00:00Z');
                const b = new Date(to + 'T00:00:00Z');
                if (isNaN(a.getTime()) || isNaN(b.getTime()) || a.getTime() > b.getTime()) return out;
                for (let t = a.getTime(); t <= b.getTime(); t += 86400000) {
                    out.push(new Date(t).toISOString().slice(0, 10));
                }
                return out;
            })();
            if (!dayList.length) throw new Error('Некорректный период');

            const fetchDay = async (d) => {
                const url = new URL(base.toString());
                url.searchParams.set('date', String(d));
                const res = await fetch(url.toString(), { headers: { 'Accept': 'application/json' } });
                const txt = await res.text();
                let j = null;
                try { j = JSON.parse(txt); } catch (_) {}
                if (!j || !j.ok) throw new Error((j && j.error) ? j.error : 'Ошибка загрузки');
                const items = Array.isArray(j.items) ? j.items : [];
                items.forEach((it) => {
                    const tid = Number(it && it.transaction_id ? it.transaction_id : 0);
                    if (!tid || seen.has(tid)) return;
                    seen.add(tid);
                    out.push(it);
                });
            };

            const runPool = async () => {
                let idx = 0;
                const workers = new Array(Math.min(concurrency, dayList.length)).fill(0).map(async () => {
                    while (idx < dayList.length) {
                        const d = dayList[idx++];
                        await fetchDay(d);
                        done++;
                        const pct = Math.max(1, Math.round((done / dayList.length) * 100));
                        const dd = String(d).slice(8, 10);
                        const mm = String(d).slice(5, 7);
                        setProgress(pct, `- день ${done}/${dayList.length} (${dd}/${mm})`);
                    }
                });
                await Promise.all(workers);
            };

            setProgress(1, `- день 0/${dayList.length}`);
            await runPool();

            dataItems = out;
            rebuildTableFilter();
            page = 1;
            renderTable();
            persistPrefsFromUi();
        } catch (e) {
            setError(e && e.message ? e.message : 'Ошибка');
        } finally {
            setProgress(100, 'Готово');
            setLoading(false);
        }
    };

    btn.addEventListener('click', load);
    applyPrefsToUi();
    persistPrefsFromUi();
