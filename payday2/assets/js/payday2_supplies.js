document.addEventListener('DOMContentLoaded', () => {
    const btnSupplies = document.getElementById('btnSupplies');
    const suppliesModal = document.getElementById('suppliesModal');
    const suppliesClose = document.getElementById('suppliesClose');
    const suppliesBody = document.getElementById('suppliesBody');

    let currentSupplies = [];
    let currentAccounts = [];
    let currentSortCol = '';
    let currentSortAsc = true;

    const escapeHtml = (s) => String(s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');

    // Helper for formatting Vnd without decimals
    const posterMinorToVnd = (val) => {
        const v = Number(val || 0);
        return isNaN(v) ? 0 : v / 100;
    };
    const fmtVnd0 = (val) => {
        const v = Number(val || 0);
        if (isNaN(v)) return '0';
        return v.toLocaleString('ru-RU', { minimumFractionDigits: 0, maximumFractionDigits: 0 }).replace(/,/g, ' ');
    };

    const renderSuppliesTable = () => {
        if (!currentSupplies || currentSupplies.length === 0) {
            suppliesBody.innerHTML = '<div style="text-align:center; padding:15px; color:var(--muted);">Нет данных за период</div>';
            return;
        }

        const accMap = {};
        if (currentAccounts) {
            currentAccounts.forEach(a => {
                accMap[a.account_id] = a.account_name || a.name;
            });
        }

        const allKeys = new Set();
        const ignoredKeys = ['supply_sum_netto', 'supplier_id', 'storage_id', 'delete', 'supply_comment'];
        currentSupplies.forEach(row => {
            Object.keys(row).forEach(k => {
                if (!ignoredKeys.includes(k)) {
                    allKeys.add(k);
                }
            });
        });
        const keys = Array.from(allKeys);
        
        let html = '<div style="overflow-x:auto;"><table style="width:100%; border-collapse:collapse; white-space:nowrap; font-size:13px;"><thead><tr>';
        keys.forEach(k => {
            const displayKey = k === 'account_id' ? 'Название Счета' : k;
            let sortIndicator = '';
            if (currentSortCol === k) {
                sortIndicator = currentSortAsc ? ' ▲' : ' ▼';
            }
            html += `<th class="pd2-supply-sortable" data-sort="${escapeHtml(k)}" style="text-align:left; border-bottom:1px solid var(--border); padding:6px; background:var(--card); cursor:pointer; user-select:none;">${escapeHtml(displayKey)}${sortIndicator}</th>`;
        });
        html += '</tr></thead><tbody>';
        
        currentSupplies.forEach(row => {
            html += '<tr>';
            keys.forEach(k => {
                let val = row[k];
                if (val === null || val === undefined) val = '';
                
                if (k === 'account_id') {
                    const supplyId = row.supply_id || row.id || '';
                    const accountId = row.account_id || (row.payed_sum && row.payed_sum.length > 0 ? row.payed_sum[0].account_id : null);
                    
                    let accName = '';
                    if (accountId && accMap[accountId]) {
                        accName = accMap[accountId];
                    } else if (accountId) {
                        accName = String(accountId);
                    } else {
                        accName = '—';
                    }
                    
                    let selectHtml = `<select class="pd2-d-none pd2-supply-acc-select" data-supply-id="${escapeHtml(String(supplyId))}" style="margin-right:5px;">`;
                    if (currentAccounts) {
                        currentAccounts.forEach(a => {
                            const sel = (String(a.account_id) === String(accountId)) ? ' selected' : '';
                            selectHtml += `<option value="${escapeHtml(String(a.account_id))}"${sel}>${escapeHtml(a.account_name || a.name)}</option>`;
                        });
                    }
                    selectHtml += `</select>`;
                    
                    val = `
                        <div class="pd2-d-flex pd2-align-center pd2-supply-acc-wrapper">
                            <span class="pd2-supply-acc-text" style="margin-right:5px;">${escapeHtml(accName)}</span>
                            ${selectHtml}
                            <button type="button" class="btn tiny pd2-p-2-4 pd2-supply-acc-edit" data-supply-id="${escapeHtml(String(supplyId))}" title="Изменить счет">✏️</button>
                            <button type="button" class="btn tiny pd2-p-2-4 pd2-supply-acc-save pd2-d-none" data-supply-id="${escapeHtml(String(supplyId))}" title="Сохранить">💾</button>
                            <div class="pd2-supply-acc-loader pd2-d-none" style="margin-left:5px;"><svg class="pd2-loader-spin" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 1 1-6.219-8.56"></path></svg></div>
                        </div>
                    `;
                    html += '<td style="border-bottom:1px solid var(--border); padding:6px;">' + val + '</td>';
                    return; 
                }
                
                if ((k === 'supply_sum' || k === 'total_sum') && val !== '') {
                    val = fmtVnd0(posterMinorToVnd(val));
                }
                
                if (typeof val === 'object') {
                    val = JSON.stringify(val);
                }
                
                html += '<td style="border-bottom:1px solid var(--border); padding:6px;">' + escapeHtml(val) + '</td>';
            });
            html += '</tr>';
        });
        
        html += '</tbody></table></div>';
        suppliesBody.innerHTML = html;
        
        // Headers sorting
        const headers = suppliesBody.querySelectorAll('.pd2-supply-sortable');
        headers.forEach(th => {
            th.addEventListener('click', () => {
                const sortKey = th.getAttribute('data-sort');
                if (currentSortCol === sortKey) {
                    currentSortAsc = !currentSortAsc;
                } else {
                    currentSortCol = sortKey;
                    currentSortAsc = true;
                }
                
                currentSupplies.sort((a, b) => {
                    let valA = a[currentSortCol];
                    let valB = b[currentSortCol];
                    if (valA === null || valA === undefined) valA = '';
                    if (valB === null || valB === undefined) valB = '';
                    
                    // Numeric sort if possible
                    let numA = Number(valA);
                    let numB = Number(valB);
                    if (!isNaN(numA) && !isNaN(numB) && valA !== '' && valB !== '') {
                        return currentSortAsc ? (numA - numB) : (numB - numA);
                    }
                    
                    // String sort
                    let strA = String(valA).toLowerCase();
                    let strB = String(valB).toLowerCase();
                    if (strA < strB) return currentSortAsc ? -1 : 1;
                    if (strA > strB) return currentSortAsc ? 1 : -1;
                    return 0;
                });
                
                renderSuppliesTable();
            });
        });

        // Edit buttons logic
        const editBtns = suppliesBody.querySelectorAll('.pd2-supply-acc-edit');
        const saveBtns = suppliesBody.querySelectorAll('.pd2-supply-acc-save');
        
        editBtns.forEach(btn => {
            btn.addEventListener('click', (e) => {
                const wrapper = e.target.closest('.pd2-supply-acc-wrapper');
                const text = wrapper.querySelector('.pd2-supply-acc-text');
                const select = wrapper.querySelector('.pd2-supply-acc-select');
                const saveBtn = wrapper.querySelector('.pd2-supply-acc-save');
                
                text.classList.add('pd2-d-none');
                btn.classList.add('pd2-d-none');
                
                select.classList.remove('pd2-d-none');
                saveBtn.classList.remove('pd2-d-none');
            });
        });
        
        saveBtns.forEach(btn => {
            btn.addEventListener('click', async (e) => {
                const wrapper = e.target.closest('.pd2-supply-acc-wrapper');
                const text = wrapper.querySelector('.pd2-supply-acc-text');
                const select = wrapper.querySelector('.pd2-supply-acc-select');
                const editBtn = wrapper.querySelector('.pd2-supply-acc-edit');
                const loader = wrapper.querySelector('.pd2-supply-acc-loader');
                
                const supplyId = btn.getAttribute('data-supply-id');
                const newAccountId = select.value;
                const newAccountText = select.options[select.selectedIndex].text;
                
                btn.classList.add('pd2-d-none');
                select.classList.add('pd2-d-none');
                loader.classList.remove('pd2-d-none');
                
                try {
                    const r = await fetch('?ajax=supply_change_account', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                        body: JSON.stringify({ supply_id: Number(supplyId), account_id: Number(newAccountId) }),
                    });
                    const txt = await r.text();
                    let j = null;
                    try { j = JSON.parse(txt); } catch (_) {}
                    if (!j || !j.ok) throw new Error((j && j.error) ? j.error : 'Ошибка сохранения');
                    
                    text.textContent = newAccountText;
                    
                    // Update in current state array
                    const s = currentSupplies.find(x => String(x.supply_id || x.id) === String(supplyId));
                    if (s) {
                        s.account_id = newAccountId;
                    }

                    if (typeof window.showToast === 'function') {
                        window.showToast(`Счет для поставки #${supplyId} изменен`);
                    } else {
                        alert(`Счет для поставки #${supplyId} изменен`);
                    }
                } catch (err) {
                    alert(err && err.message ? err.message : 'Ошибка при сохранении');
                    const oldText = text.textContent;
                    for (let i = 0; i < select.options.length; i++) {
                        if (select.options[i].text === oldText) {
                            select.selectedIndex = i;
                            break;
                        }
                    }
                } finally {
                    loader.classList.add('pd2-d-none');
                    text.classList.remove('pd2-d-none');
                    editBtn.classList.remove('pd2-d-none');
                }
            });
        });
    };

    if (btnSupplies && suppliesModal) {
        btnSupplies.addEventListener('click', () => {
            suppliesModal.style.display = 'flex';
            suppliesBody.innerHTML = '<div style="text-align:center;">Загрузка...</div>';
            
            const dFrom = document.querySelector('input[name="dateFrom"]').value || '';
            const dTo = document.querySelector('input[name="dateTo"]').value || '';
            
            const url = '?ajax=supplies&dateFrom=' + encodeURIComponent(dFrom) + '&dateTo=' + encodeURIComponent(dTo);
            
            if (typeof window.fetchJsonSafe === 'function') {
                window.fetchJsonSafe(url).then(res => {
                    if (!res.ok) throw new Error(res.error || 'Ошибка');
                    currentAccounts = res.accounts || [];
                    currentSupplies = res.supplies || [];
                    currentSortCol = '';
                    renderSuppliesTable();
                }).catch(e => {
                    suppliesBody.innerHTML = '<div class="error">' + escapeHtml(e.message) + '</div>';
                });
            } else {
                fetch(url).then(r => r.json()).then(res => {
                    if (!res.ok) throw new Error(res.error || 'Ошибка');
                    currentAccounts = res.accounts || [];
                    currentSupplies = res.supplies || [];
                    currentSortCol = '';
                    renderSuppliesTable();
                }).catch(e => {
                    suppliesBody.innerHTML = '<div class="error">' + escapeHtml(e.message) + '</div>';
                });
            }
        });
        
        if (suppliesClose) {
            suppliesClose.addEventListener('click', () => {
                suppliesModal.style.display = 'none';
            });
        }
        
        suppliesModal.addEventListener('click', (e) => {
            if (e.target === suppliesModal) {
                suppliesModal.style.display = 'none';
            }
        });
    }
});
