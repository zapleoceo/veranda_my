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
                
                // Оставляем только нужные колонки для кассовых смен
                const keys = ['cash_shift_id', 'date_start', 'date_end', 'amount_start'];
                const displayKeys = ['ID смены', 'Дата открытия', 'Дата закрытия', 'Сумма на старте'];
                
                let html = '<div style="overflow-x:auto;"><table style="width:100%; border-collapse:collapse; white-space:nowrap; font-size:13px;"><thead><tr>';
                displayKeys.forEach(k => {
                    html += '<th style="text-align:left; border-bottom:1px solid var(--border); padding:6px; background:var(--card);">' + escapeHtml(k) + '</th>';
                });
                html += '</tr></thead><tbody>';
                let firstShiftId = '';
                res.data.forEach(row => {
                    const rawShiftId = String(row.cash_shift_id || row.shift_id || '');
                    const escShiftId = escapeHtml(rawShiftId);
                    if (!firstShiftId && rawShiftId) firstShiftId = rawShiftId;
                    html += '<tr style="cursor:pointer;" onclick="toggleShiftDetail(this, \'' + escShiftId + '\')">';
                    keys.forEach(k => {
                        let val = row[k];
                        if (val === null || val === undefined) val = '';
                        
                        // Форматирование сумм (убираем копейки)
                        if ((k.includes('amount') || k.includes('sum')) && val !== '') {
                            const nVal = Number(val);
                            if (!isNaN(nVal)) {
                                val = fmtVnd0(posterMinorToVnd(nVal));
                            }
                        }
                        
                        // Форматирование дат (могут приходить в миллисекундах)
                        if ((k.includes('date') || k.includes('time')) && val !== '') {
                            let ts = Number(val);
                            // Если число небольшое, значит это секунды
                            if (!isNaN(ts) && ts > 0 && String(Math.floor(ts)).length === 10) {
                                ts = ts * 1000;
                            }
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
                if (res.data && res.data.length > 0) {
                    const firstRow = res.data[0];
                    const sId = String(firstRow.cash_shift_id || firstRow.shift_id || '');
                    if (sId) {
                        const firstTr = kashshiftBody.querySelector('tbody tr');
                        if (firstTr) {
                            window.toggleShiftDetail(firstTr, sId);
                        }
                    }
                }
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
                        const arr = res.data;
                        if (!Array.isArray(arr) || arr.length === 0) {
                            contentDiv.innerHTML = '<div style="color:var(--muted);">Нет транзакций в этой смене</div>';
                            return;
                        }
                        
                        let h = '<div style="overflow-x:auto;"><table style="width:100%; border-collapse:collapse; white-space:nowrap; font-size:13px; background:var(--card);"><thead><tr>';
                        h += '<th style="text-align:left; border-bottom:1px solid var(--border); padding:6px; width:1%;">Дата</th>';
                        h += '<th style="text-align:left; border-bottom:1px solid var(--border); padding:6px; width:1%;">Тип</th>';
                        h += '<th style="text-align:left; border-bottom:1px solid var(--border); padding:6px; width:1%;">Категория</th>';
                        h += '<th style="text-align:right; border-bottom:1px solid var(--border); padding:6px; width:1%;">Сумма</th>';
                        h += '<th style="text-align:left; border-bottom:1px solid var(--border); padding:6px; width:auto;">Комментарий</th>';
                        h += '</tr></thead><tbody>';
                        
                        const getTypeLabel = (type) => {
                            if (type === 1) return '<span style="color:#fbbf24;">Открытие</span>';
                            if (type === 2) return '<span style="color:#4ade80;">Доход</span>';
                            if (type === 3) return '<span style="color:#f87171;">Расход</span>';
                            if (type === 4) return '<span style="color:#fbbf24;">Инкассация</span>';
                            if (type === 5) return '<span style="color:#fbbf24;">Закрытие</span>';
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
                        
                        const ls = window.PAYDAY_CONFIG?.localSettings || {};
                        const catNames = ls.custom_category_names || window.PAYDAY_CONFIG?.catNames || {};

                        arr.forEach(tx => {
                            const typeLabel = getTypeLabel(tx.type);
                            let sumSigned = tx.sum !== undefined ? tx.sum : (tx.amount || 0);
                            if (tx.type === 3 || tx.type === 4) {
                                sumSigned = -Math.abs(sumSigned);
                            }
                            
                            const catId = tx.category_id || tx.category || '';
                            let catLabel = '';
                            if (catId) {
                                catLabel = catNames[catId] || catNames[String(catId)] || catId;
                            }
                            
                            h += '<tr>';
                            h += '<td style="border-bottom:1px solid var(--border); padding:6px; width:1%;">' + escapeHtml(formatDate(tx.time)) + '</td>';
                            h += '<td style="border-bottom:1px solid var(--border); padding:6px; width:1%;">' + typeLabel + '</td>';
                            h += '<td style="border-bottom:1px solid var(--border); padding:6px; width:1%;">' + escapeHtml(catLabel) + '</td>';
                            h += '<td style="border-bottom:1px solid var(--border); padding:6px; width:1%; text-align:right;">' + escapeHtml(fmtSum(sumSigned)) + '</td>';
                            h += '<td style="border-bottom:1px solid var(--border); padding:6px; width:auto; white-space:normal;">' + escapeHtml(tx.comment || '') + '</td>';
                            h += '</tr>';
                        });
                        h += '</tbody></table></div>';
                        contentDiv.innerHTML = h;
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
