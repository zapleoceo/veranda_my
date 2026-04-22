window.initPayday2_KashShift = function() {
    const btnKashShift = document.getElementById('btnKashShift');
    const kashshiftModal = document.getElementById('kashshiftModal');
    const kashshiftClose = document.getElementById('kashshiftClose');
    const kashshiftBody = document.getElementById('kashshiftBody');

    const escapeHtml = (s) => String(s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');

    const posterMinorToVnd = (val) => {
        const v = Number(val || 0);
        return isNaN(v) ? 0 : v / 100;
    };

    const fmtVnd0 = (val) => {
        const v = Number(val || 0);
        if (isNaN(v)) return '0';
        return v.toLocaleString('ru-RU', { minimumFractionDigits: 0, maximumFractionDigits: 0 }).replace(/,/g, ' ');
    };

    let shifts = [];
    let shiftsSortCol = '';
    let shiftsSortAsc = true;

    const parseDateValue = (val) => {
        if (val === null || val === undefined) return null;
        if (typeof val === 'number') return val;
        const s = String(val).trim();
        if (s === '') return null;
        const n = Number(s);
        if (!isNaN(n) && isFinite(n)) {
            if (String(Math.floor(n)).length === 10) return n * 1000;
            return n;
        }
        const m = s.match(/^(\d{4})-(\d{2})-(\d{2})(?:\s+(\d{2}):(\d{2})(?::(\d{2}))?)?/);
        if (m) {
            const y = Number(m[1]);
            const mo = Number(m[2]) - 1;
            const d = Number(m[3]);
            const hh = Number(m[4] || 0);
            const mm = Number(m[5] || 0);
            const ss = Number(m[6] || 0);
            const dt = new Date(y, mo, d, hh, mm, ss);
            const t = dt.getTime();
            if (!isNaN(t)) return t;
        }
        return null;
    };

    const compareValues = (a, b) => {
        if (a === null || a === undefined) a = '';
        if (b === null || b === undefined) b = '';

        const na = Number(a);
        const nb = Number(b);
        if (a !== '' && b !== '' && !isNaN(na) && !isNaN(nb)) return na - nb;

        const da = parseDateValue(a);
        const db = parseDateValue(b);
        if (da !== null && db !== null) return da - db;

        const sa = String(a).toLowerCase();
        const sb = String(b).toLowerCase();
        if (sa < sb) return -1;
        if (sa > sb) return 1;
        return 0;
    };

    const renderShifts = () => {
        if (!kashshiftBody) return;
        if (!Array.isArray(shifts) || shifts.length === 0) {
            kashshiftBody.innerHTML = '<div style="text-align:center; padding:15px; color:var(--muted);">Нет данных за период</div>';
            return;
        }

        const keys = ['cash_shift_id', 'date_start', 'date_end', 'amount_start'];
        const displayKeys = ['ID смены', 'Дата открытия', 'Дата закрытия', 'Сумма на старте'];

        const th = (key, title) => {
            const ind = shiftsSortCol === key ? (shiftsSortAsc ? ' ▲' : ' ▼') : '';
            return '<th class="pd2-ks-sort" data-sort="' + escapeHtml(String(key)) + '" style="text-align:left; border-bottom:1px solid var(--border); padding:6px; background:var(--card); cursor:pointer; user-select:none;">' + escapeHtml(title) + ind + '</th>';
        };

        let html = '<div style="overflow-x:auto;"><table style="width:100%; border-collapse:collapse; white-space:nowrap; font-size:13px;"><thead><tr>';
        displayKeys.forEach((t, idx) => { html += th(keys[idx], t); });
        html += '</tr></thead><tbody>';

        shifts.forEach(row => {
            const rawShiftId = String(row.cash_shift_id || row.shift_id || '');
            const escShiftId = escapeHtml(rawShiftId);
            html += '<tr class="pd2-ks-row" style="cursor:pointer;" data-shift-id="' + escShiftId + '">';
            keys.forEach(k => {
                let val = row[k];
                if (val === null || val === undefined) val = '';

                if ((k.includes('amount') || k.includes('sum')) && val !== '') {
                    const nVal = Number(val);
                    if (!isNaN(nVal)) val = fmtVnd0(posterMinorToVnd(nVal));
                }

                if ((k.includes('date') || k.includes('time')) && val !== '') {
                    let ts = Number(val);
                    if (!isNaN(ts) && ts > 0 && String(Math.floor(ts)).length === 10) ts = ts * 1000;
                    if (!isNaN(ts) && ts > 0) {
                        const d = new Date(ts);
                        if (!isNaN(d.getTime())) {
                            const p = n => String(n).padStart(2, '0');
                            val = `${p(d.getDate())}.${p(d.getMonth()+1)}.${d.getFullYear()} ${p(d.getHours())}:${p(d.getMinutes())}`;
                        }
                    }
                }

                html += '<td style="border-bottom:1px solid var(--border); padding:6px;">' + escapeHtml(val) + '</td>';
            });
            html += '</tr>';
            if (escShiftId) {
                html += '<tr id="shift_detail_' + escShiftId + '" style="display:none; background:var(--card2);">';
                html += '<td colspan="' + keys.length + '" style="border-bottom:1px solid var(--border); padding:15px; white-space:normal;" class="shift-detail-content">Загрузка...</td>';
                html += '</tr>';
            }
        });

        html += '</tbody></table></div>';
        kashshiftBody.innerHTML = html;

        kashshiftBody.querySelectorAll('.pd2-ks-sort').forEach((hdr) => {
            hdr.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                const col = String(hdr.getAttribute('data-sort') || '');
                if (!col) return;
                if (shiftsSortCol === col) shiftsSortAsc = !shiftsSortAsc;
                else { shiftsSortCol = col; shiftsSortAsc = true; }
                shifts.sort((a, b) => {
                    const d = compareValues(a ? a[col] : '', b ? b[col] : '');
                    return shiftsSortAsc ? d : -d;
                });
                renderShifts();
            });
        });

        kashshiftBody.querySelectorAll('.pd2-ks-row').forEach((rowEl) => {
            rowEl.addEventListener('click', () => {
                const sid = String(rowEl.getAttribute('data-shift-id') || '');
                if (!sid) return;
                window.toggleShiftDetail(rowEl, sid);
            });
        });
    };

    if (btnKashShift && kashshiftModal) {
        btnKashShift.addEventListener('click', () => {
            kashshiftModal.style.display = 'flex';
            kashshiftBody.innerHTML = '<div style="text-align:center;">Загрузка...</div>';
            
            const dFrom = document.querySelector('input[name="dateFrom"]').value || '';
            const dTo = document.querySelector('input[name="dateTo"]').value || '';
            
            const url = '?ajax=kashshift&dateFrom=' + encodeURIComponent(dFrom) + '&dateTo=' + encodeURIComponent(dTo);
            
            const p = (typeof window.fetchJsonSafe === 'function') 
                ? window.fetchJsonSafe(url) 
                : fetch(url).then(async (r) => { const txt = await r.text(); let j; try { j = JSON.parse(txt); } catch (e) { throw new Error('Bad JSON: ' + (txt || '(empty)')); } return j; });

            p.then(res => {
                if (!res.ok) throw new Error(res.error || 'Ошибка');
                
                if (!res.data || res.data.length === 0) {
                    kashshiftBody.innerHTML = '<div style="text-align:center; padding:15px; color:var(--muted);">Нет данных за период</div>';
                    return;
                }
                
                shifts = Array.isArray(res.data) ? res.data.slice() : [];
                shiftsSortCol = '';
                shiftsSortAsc = true;
                renderShifts();
            }).catch(e => {
                kashshiftBody.innerHTML = '<div class="error">' + escapeHtml(e.message) + '</div>';
            });
        });
        
        kashshiftClose.addEventListener('click', () => {
            kashshiftModal.style.display = 'none';
        });
        
        kashshiftModal.addEventListener('click', (e) => {
            if (e.target === kashshiftModal) {
                kashshiftModal.style.display = 'none';
            }
        });
    }

    window.toggleShiftDetail = function(tr, shiftId) {
        const detailTr = document.getElementById('shift_detail_' + shiftId);
        if (!detailTr) return;
        if (detailTr.style.display === 'none') {
            detailTr.style.display = 'table-row';
            const contentDiv = detailTr.querySelector('.shift-detail-content');
            if (contentDiv && contentDiv.innerHTML === 'Загрузка...') {
                const url = '?ajax=kashshift_detail&shiftId=' + encodeURIComponent(shiftId);
                const p = (typeof window.fetchJsonSafe === 'function') 
                    ? window.fetchJsonSafe(url) 
                    : fetch(url).then(async (r) => { const txt = await r.text(); let j; try { j = JSON.parse(txt); } catch (e) { throw new Error('Bad JSON: ' + (txt || '(empty)')); } return j; });

                p.then(res => {
                        if (!res.ok) throw new Error(res.error || 'Ошибка загрузки транзакций смены');
                        const arrRaw = res.data;
                        const arr = Array.isArray(arrRaw)
                            ? arrRaw.filter((tx) => Number(tx && tx.delete ? tx.delete : 0) !== 1)
                            : [];
                        if (arr.length === 0) {
                            contentDiv.innerHTML = '<div style="color:var(--muted);">Нет транзакций в этой смене</div>';
                            return;
                        }
                        
                        let detailSortCol = '';
                        let detailSortAsc = true;

                        const mkHdr = (col, title, alignRight) => {
                            const ind = detailSortCol === col ? (detailSortAsc ? ' ▲' : ' ▼') : '';
                            const ta = alignRight ? 'text-align:right;' : 'text-align:left;';
                            return '<th class="pd2-ksd-sort" data-sort="' + escapeHtml(col) + '" style="' + ta + ' border-bottom:1px solid var(--border); padding:6px; cursor:pointer; user-select:none; width:1%;">' + escapeHtml(title) + ind + '</th>';
                        };

                        let h = '<div style="overflow-x:auto;"><table style="width:100%; border-collapse:collapse; white-space:nowrap; font-size:13px; background:var(--card);"><thead><tr>';
                        h += mkHdr('time', 'Дата', false);
                        h += mkHdr('type', 'Тип', false);
                        h += mkHdr('tr_amount', 'Сумма', true);
                        h += '<th style="text-align:left; border-bottom:1px solid var(--border); padding:6px; width:auto;">Комментарий</th>';
                        h += '</tr></thead><tbody>';
                        
                        const getTypeLabel = (type) => {
                            const id = Number(type);
                            const cfg = window.PAYDAY_CONFIG || {};
                            const catNames =
                                (cfg && cfg.catNames) ? cfg.catNames :
                                (cfg && cfg.localSettings && cfg.localSettings.custom_category_names) ? cfg.localSettings.custom_category_names :
                                {};
                            const name = catNames[id] || catNames[String(id)];
                            if (name) return escapeHtml(String(name));

                            if (id === 1) return '<span style="color:#fbbf24;">Открытие</span>';
                            if (id === 2) return '<span style="color:#4ade80;">Доход</span>';
                            if (id === 3) return '<span style="color:#f87171;">Расход</span>';
                            if (id === 4) return '<span style="color:#fbbf24;">Инкассация</span>';
                            if (id === 5) return '<span style="color:#fbbf24;">Закрытие</span>';
                            return String(type);
                        };
                        
                        const formatDate = (tsStr) => {
                            if (!tsStr) return '';
                            let ts = Number(tsStr);
                            if (isNaN(ts) || ts <= 0) return tsStr;
                            if (String(Math.floor(ts)).length === 10) ts *= 1000;
                            const d = new Date(ts);
                            if (isNaN(d.getTime())) return tsStr;
                            const p = n => String(n).padStart(2, '0');
                            return `${p(d.getDate())}.${p(d.getMonth()+1)} ${p(d.getHours())}:${p(d.getMinutes())}`;
                        };
                        
                        const fmtSum = (v) => {
                            const n = Number(v);
                            if (isNaN(n)) return v;
                            return fmtVnd0(posterMinorToVnd(n));
                        };
                        
                        const renderRows = (list) => {
                            let out = '';
                            list.forEach(tx => {
                            const typeLabel = getTypeLabel(tx.type);
                            let sumSigned = tx.tr_amount ?? tx.amount ?? 0;
                            if (tx.type === 3 || tx.type === 4) {
                                sumSigned = -Math.abs(sumSigned);
                            }
                            
                            out += '<tr>';
                            out += '<td style="border-bottom:1px solid var(--border); padding:6px; width:1%;">' + escapeHtml(formatDate(tx.time)) + '</td>';
                            out += '<td style="border-bottom:1px solid var(--border); padding:6px; width:1%;">' + typeLabel + '</td>';
                            out += '<td style="border-bottom:1px solid var(--border); padding:6px; width:1%; text-align:right;">' + escapeHtml(fmtSum(sumSigned)) + '</td>';
                            out += '<td style="border-bottom:1px solid var(--border); padding:6px; width:auto; white-space:normal;">' + escapeHtml(tx.comment || '') + '</td>';
                            out += '</tr>';
                            });
                            return out;
                        };

                        const sortDetail = () => {
                            if (!detailSortCol) return arr.slice();
                            const list = arr.slice();
                            list.sort((a, b) => {
                                let va = a ? (a[detailSortCol] ?? '') : '';
                                let vb = b ? (b[detailSortCol] ?? '') : '';
                                if (detailSortCol === 'tr_amount') {
                                    va = a.tr_amount ?? a.amount ?? 0;
                                    vb = b.tr_amount ?? b.amount ?? 0;
                                }
                                const d = compareValues(va, vb);
                                return detailSortAsc ? d : -d;
                            });
                            return list;
                        };

                        h += renderRows(sortDetail());
                        h += '</tbody></table></div>';
                        contentDiv.innerHTML = h;

                        const hdrs = contentDiv.querySelectorAll('.pd2-ksd-sort');
                        hdrs.forEach((hdr) => {
                            hdr.addEventListener('click', () => {
                                const col = String(hdr.getAttribute('data-sort') || '');
                                if (!col) return;
                                if (detailSortCol === col) detailSortAsc = !detailSortAsc;
                                else { detailSortCol = col; detailSortAsc = true; }
                                const tbody = contentDiv.querySelector('tbody');
                                if (tbody) tbody.innerHTML = renderRows(sortDetail());
                                hdrs.forEach((h2) => {
                                    const c = String(h2.getAttribute('data-sort') || '');
                                    const base = (h2.textContent || '').replace(/\s*[▲▼]\s*$/, '');
                                    const ind = detailSortCol === c ? (detailSortAsc ? ' ▲' : ' ▼') : '';
                                    h2.textContent = base + ind;
                                });
                            });
                        });
                    })
                    .catch(e => {
                        contentDiv.innerHTML = '<div class="error">' + escapeHtml(e.message) + '</div>';
                    });
            }
        } else {
            detailTr.style.display = 'none';
        }
    };
};
