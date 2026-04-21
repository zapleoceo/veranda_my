window.initPayday2_CheckFinder = function() {
    const checkFinderBtn = document.getElementById('payday2CheckFinderBtn');
    const checkFinderModal = document.getElementById('checkFinderModal');
    const checkFinderClose = document.getElementById('checkFinderClose');
    const checkFinderNumber = document.getElementById('checkFinderNumber');
    const checkFinderSearchBtn = document.getElementById('checkFinderSearchBtn');
    const checkFinderError = document.getElementById('checkFinderError');
    const checkFinderResult = document.getElementById('checkFinderResult');
    const checkFinderActions = document.getElementById('checkFinderActions');
    const checkFinderDeleteBtn = document.getElementById('checkFinderDeleteBtn');
    let checkFinderFoundId = 0;

    const escapeHtml = (s) => String(s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');

    const showToast = (msg) => {
        if (typeof window.showToast === 'function' && window.showToast !== showToast) {
            window.showToast(msg);
            return;
        }
        let t = document.getElementById('pd2Toast');
        if (!t) {
            t = document.createElement('div');
            t.id = 'pd2Toast';
            t.style.cssText = 'visibility:hidden; opacity:0; position:fixed; bottom:20px; left:50%; transform:translateX(-50%); background:rgba(0,0,0,0.8); color:#fff; padding:10px 20px; border-radius:8px; font-size:14px; font-weight:bold; z-index:99999; transition:opacity 0.3s ease, visibility 0.3s ease;';
            document.body.appendChild(t);
        }
        t.textContent = msg;
        t.style.visibility = 'visible';
        t.style.opacity = '1';
        if (t.timer) clearTimeout(t.timer);
        t.timer = setTimeout(() => {
            t.style.opacity = '0';
            setTimeout(() => { t.style.visibility = 'hidden'; }, 300);
        }, 3000);
    };
    window.showToast = showToast;

    const checkFinderShowError = (msg) => {
        if (!checkFinderError) return;
        if (!msg) {
            checkFinderError.textContent = '';
            checkFinderError.classList.add('pd2-d-none');
        } else {
            checkFinderError.textContent = msg;
            checkFinderError.classList.remove('pd2-d-none');
        }
    };

    const checkFinderReset = () => {
        checkFinderFoundId = 0;
        checkFinderShowError('');
        if (checkFinderResult) checkFinderResult.innerHTML = '';
        if (checkFinderActions) checkFinderActions.classList.add('pd2-d-none');
    };

    const openCheckFinder = () => {
        if (!checkFinderModal) return;
        checkFinderReset();
        checkFinderModal.style.display = 'flex';
        if (checkFinderNumber) {
            checkFinderNumber.value = '';
            checkFinderNumber.focus();
        }
    };

    const closeCheckFinder = () => {
        if (!checkFinderModal) return;
        checkFinderModal.style.display = 'none';
        checkFinderReset();
    };

    const getCurrentRange = () => {
        if (typeof window.getCurrentRange === 'function') {
            return window.getCurrentRange();
        }
        const dFromEl = document.querySelector('input[name="dateFrom"]');
        const dToEl = document.querySelector('input[name="dateTo"]');
        const dFrom = (dFromEl && dFromEl.value) ? String(dFromEl.value) : String(window.PAYDAY_CONFIG?.dateFrom || '');
        const dToRaw = (dToEl && dToEl.value) ? String(dToEl.value) : String(window.PAYDAY_CONFIG?.dateTo || '');
        const dTo = dToRaw || dFrom;
        return { dFrom, dTo };
    };

    const payTypeLabel = (v) => {
        const n = Number(v || 0) || 0;
        if (n === 0) return '0 — без оплаты';
        if (n === 1) return '1 — наличные';
        if (n === 2) return '2 — безнал';
        if (n === 3) return '3 — смешанная';
        return String(n);
    };

    const statusLabel = (v) => {
        const n = Number(v || 0) || 0;
        if (n === 1) return '1 — открыт';
        if (n === 2) return '2 — закрыт';
        if (n === 3) return '3 — удален';
        return String(n || '');
    };

    const fmtDec = (v) => {
        const n = Number(v);
        if (!isFinite(n)) return '';
        return n.toFixed(2);
    };

    let checksAll = [];
    const renderChecks = (list) => {
        const arr = Array.isArray(list) ? list : [];
        if (!checkFinderResult) return;
        if (!arr.length) {
            checkFinderResult.innerHTML = '<div class="muted">Нет чеков</div>';
            return;
        }
        let html = '<div style="overflow-x:auto;"><table class="pd2-check-table"><thead><tr>';
        html += '<th class="pd2-check-th">transaction_id</th>';
        html += '<th class="pd2-check-th">table_id</th>';
        html += '<th class="pd2-check-th">sum</th>';
        html += '<th class="pd2-check-th">payed_sum</th>';
        html += '<th class="pd2-check-th">status</th>';
        html += '<th class="pd2-check-th">pay_type</th>';
        html += '</tr></thead><tbody>';
        arr.forEach((c) => {
            const id = Number(c && c.transaction_id ? c.transaction_id : 0) || 0;
            const tableId = Number(c && c.table_id ? c.table_id : 0) || 0;
            const sum = c && c.sum != null ? String(c.sum) : '';
            const payed = c && c.payed_sum != null ? String(c.payed_sum) : '';
            const status = Number(c && c.status != null ? c.status : 0) || 0;
            const payType = status === 2 ? payTypeLabel(c && c.pay_type != null ? c.pay_type : 0) : '';
            const statusTxt = statusLabel(status);
            const dateClose = c && c.date_close ? String(c.date_close) : '';
            const products = Array.isArray(c && c.products ? c.products : null) ? c.products : [];
            const rowCls = status === 2 ? ' pd2-check-row-s2' : (status === 3 ? ' pd2-check-row-s3' : '');
            html += '<tr class="pd2-check-row-trigger' + rowCls + '" data-check-id="' + escapeHtml(String(id)) + '" style="cursor:pointer;">';
            html += '<td class="pd2-check-td">' + escapeHtml(String(id)) + '</td>';
            html += '<td class="pd2-check-td">' + escapeHtml(String(tableId || '')) + '</td>';
            html += '<td class="pd2-check-td">' + escapeHtml(sum) + '</td>';
            html += '<td class="pd2-check-td">' + escapeHtml(payed) + '</td>';
            html += '<td class="pd2-check-td">' + escapeHtml(statusTxt) + '</td>';
            html += '<td class="pd2-check-td">' + escapeHtml(payType) + '</td>';
            html += '</tr>';

            html += '<tr class="pd2-check-row-details pd2-d-none" data-check-details="' + escapeHtml(String(id)) + '"><td class="pd2-check-td" colspan="6">';
            html += '<div class="muted" style="margin-bottom:8px;">date_close: ' + escapeHtml(dateClose || '—') + '</div>';
            html += '<div style="font-weight:900; margin-bottom:6px;">Состав</div>';
            if (!products.length) {
                html += '<div class="muted">Нет продуктов</div>';
            } else {
                html += '<div style="overflow-x:auto;"><table class="pd2-check-table"><thead><tr>';
                html += '<th class="pd2-check-th">Название продукта</th>';
                html += '<th class="pd2-check-th">Цена</th>';
                html += '<th class="pd2-check-th">Кол-во</th>';
                html += '<th class="pd2-check-th">Итог</th>';
                html += '</tr></thead><tbody>';
                products.forEach((p) => {
                    const name = p && p.name ? String(p.name) : '';
                    const qty = p && p.qty != null ? String(p.qty) : '';
                    const unit = p && p.unit_price != null ? fmtDec(p.unit_price) : '';
                    const total = p && p.total != null ? fmtDec(p.total) : '';
                    html += '<tr>';
                    html += '<td class="pd2-check-td">' + escapeHtml(name) + '</td>';
                    html += '<td class="pd2-check-td">' + escapeHtml(unit) + '</td>';
                    html += '<td class="pd2-check-td">' + escapeHtml(qty) + '</td>';
                    html += '<td class="pd2-check-td">' + escapeHtml(total) + '</td>';
                    html += '</tr>';
                });
                html += '</tbody></table></div>';
            }
            html += '<div style="display:flex; justify-content:flex-end; gap:10px; margin-top:10px;">';
            html += '<button type="button" class="btn2 pd2-check-edit-btn" data-edit-check="' + escapeHtml(String(id)) + '">Редактировать</button>';
            html += '<button type="button" class="btn2 pd2-btn-danger pd2-check-del-btn" data-del-check="' + escapeHtml(String(id)) + '">Удалить</button>';
            html += '</div>';
            html += '</td></tr>';
        });
        html += '</tbody></table></div>';
        checkFinderResult.innerHTML = html;
    };

    const filterAndRender = () => {
        const qRaw = String(checkFinderNumber ? checkFinderNumber.value : '').trim();
        const q = qRaw.replace(/\D+/g, '');
        if (!q) {
            renderChecks(checksAll);
            return;
        }
        renderChecks(checksAll.filter((c) => String(c && c.transaction_id != null ? c.transaction_id : '').indexOf(q) !== -1));
    };

    const loadChecks = async () => {
        checkFinderReset();
        const { dFrom, dTo } = getCurrentRange();
        if (!dFrom || !dTo) {
            checkFinderShowError('Не выбран период (dateFrom/dateTo)');
            return;
        }
        if (checkFinderSearchBtn) checkFinderSearchBtn.disabled = true;
        if (checkFinderResult) {
            checkFinderResult.innerHTML = '<div style="padding:40px;text-align:center;"><svg class="pd2-loader-spin" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:block;margin:0 auto 10px auto;"><path d="M21 12a9 9 0 1 1-6.219-8.56"></path></svg><span style="color:var(--muted);font-size:14px;">Загрузка чеков...</span></div>';
        }
        try {
            const url = '?ajax=poster_checks_list&date_from=' + encodeURIComponent(dFrom) + '&date_to=' + encodeURIComponent(dTo);
            const p = (typeof window.fetchJsonSafe === 'function') 
                ? window.fetchJsonSafe(url) 
                : fetch(url).then(async (r) => { const txt = await r.text(); let j; try { j = JSON.parse(txt); } catch (e) { throw new Error('Bad JSON: ' + (txt || '(empty)')); } return j; });
            
            const j = await p;
            if (!j || !j.ok) throw new Error((j && j.error) ? j.error : 'Ошибка');
            checksAll = Array.isArray(j.checks) ? j.checks : [];
            filterAndRender();
        } catch (e) {
            checkFinderShowError(e && e.message ? e.message : 'Ошибка');
        } finally {
            if (checkFinderSearchBtn) checkFinderSearchBtn.disabled = false;
        }
    };

    const deleteCheck = async (id) => {
        const txId = Number(id || 0) || 0;
        if (!txId) return;
        if (!confirm('Удалить чек #' + String(txId) + ' ?')) return;
        checkFinderShowError('');
        if (checkFinderDeleteBtn) {
            checkFinderDeleteBtn.disabled = true;
            checkFinderDeleteBtn.textContent = 'Удаление...';
        }
        try {
            const r = await fetch('?ajax=poster_check_remove', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify({ transaction_id: txId }),
            });
            const txt = await r.text();
            let j = null;
            try { j = JSON.parse(txt); } catch (_) {}
            if (!j || !j.ok) throw new Error((j && j.error) ? j.error : 'Ошибка');
            checksAll = checksAll.filter((c) => Number(c && c.transaction_id ? c.transaction_id : 0) !== txId);
            filterAndRender();
            showToast('Удалено: ' + String(txId));
            checkFinderReset();
        } catch (e) {
            checkFinderShowError(e && e.message ? e.message : 'Ошибка');
        } finally {
            if (checkFinderDeleteBtn) {
                checkFinderDeleteBtn.disabled = false;
                checkFinderDeleteBtn.textContent = 'Удалить';
            }
        }
    };

    if (checkFinderBtn && checkFinderModal) {
        checkFinderBtn.addEventListener('click', () => { openCheckFinder(); loadChecks().catch(() => {}); });
        if (checkFinderClose) checkFinderClose.addEventListener('click', closeCheckFinder);
        checkFinderModal.addEventListener('click', (e) => { if (e.target === checkFinderModal) closeCheckFinder(); });
        if (!window._pd2CheckFinderKeydown) {
            window._pd2CheckFinderKeydown = true;
            document.addEventListener('keydown', (e) => {
                const modal = document.getElementById('checkFinderModal');
                if (e.key === 'Escape' && modal && modal.style.display === 'flex') {
                    modal.style.display = 'none';
                    if (document.getElementById('checkFinderResult')) {
                        document.getElementById('checkFinderResult').innerHTML = '';
                    }
                }
            });
        }
        if (checkFinderSearchBtn) checkFinderSearchBtn.addEventListener('click', () => { loadChecks().catch(() => {}); });
        if (checkFinderNumber) {
            let t = 0;
            checkFinderNumber.addEventListener('input', () => {
                if (t) clearTimeout(t);
                t = setTimeout(() => filterAndRender(), 120);
            });
        }
        if (checkFinderResult) {
            checkFinderResult.addEventListener('click', (e) => {
                const trg = e.target;
                const delBtn = trg && trg.closest ? trg.closest('.pd2-check-del-btn') : null;
                if (delBtn) {
                    const id = Number(delBtn.getAttribute('data-del-check') || 0) || 0;
                    deleteCheck(id).catch(() => {});
                    return;
                }
                const row = trg && trg.closest ? trg.closest('.pd2-check-row-trigger') : null;
                if (!row) return;
                const id = row.getAttribute('data-check-id') || '';
                const det = checkFinderResult.querySelector('[data-check-details="' + id + '"]');
                if (det) det.classList.toggle('pd2-d-none');
            });
        }
    }

    if (!window._pd2CheckReloadListener) {
        window._pd2CheckReloadListener = true;
        window.addEventListener('pd2_checks_reload', () => {
            try {
                const modal = document.getElementById('checkFinderModal');
                if (modal && modal.style.display === 'flex') {
                    // Trigger click on search button to reload
                    const searchBtn = document.getElementById('checkFinderSearchBtn');
                    if (searchBtn) searchBtn.click();
                }
            } catch (_) {}
        });
    }
};
