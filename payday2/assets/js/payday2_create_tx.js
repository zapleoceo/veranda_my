window.initPaydayCreateTx = function() {
    const modal = document.getElementById('createTxModal');
    const closeBtn = document.getElementById('createTxClose');
    const form = document.getElementById('createTxForm');
    
    if (!modal || !closeBtn || !form) return;

    const dateInput = document.getElementById('createTxDate');
    const timeInput = document.getElementById('createTxTime');
    const typeSelect = document.getElementById('createTxType');
    const accFromWrap = document.getElementById('createTxAccountFromWrap');
    const accFromSelect = document.getElementById('createTxAccountFrom');
    const accToWrap = document.getElementById('createTxAccountToWrap');
    const accToSelect = document.getElementById('createTxAccountTo');
    const amountInput = document.getElementById('createTxAmount');
    const categorySelect = document.getElementById('createTxCategory');
    const commentInput = document.getElementById('createTxComment');
    const errorDiv = document.getElementById('createTxError');
    const submitBtn = document.getElementById('createTxSubmitBtn');

    const successModal = document.getElementById('createTxSuccessModal');
    const successCloseBtn = document.getElementById('createTxSuccessClose');
    const successDetails = document.getElementById('createTxSuccessDetails');
    const successTitle = document.getElementById('createTxSuccessTitle');

    let accountsLoaded = false;
    let categoriesData = null;

    const mountCustomSelect = (selectEl) => {
        if (!selectEl) return null;
        if (selectEl.dataset.pd2Custom === '1') {
            return {
                refresh: () => {
                    const btn = selectEl.parentElement ? selectEl.parentElement.querySelector('.pd2-cs-btn') : null;
                    const menu = selectEl.parentElement ? selectEl.parentElement.querySelector('.pd2-cs-menu') : null;
                    if (!btn || !menu) return;
                    menu.innerHTML = '';
                    for (const opt of Array.from(selectEl.options || [])) {
                        const b = document.createElement('button');
                        b.type = 'button';
                        b.className = 'pd2-cs-opt';
                        b.textContent = String(opt.text || '');
                        b.dataset.value = String(opt.value || '');
                        if (opt.disabled) b.disabled = true;
                        if (String(opt.value) === String(selectEl.value)) b.setAttribute('aria-selected', 'true');
                        b.addEventListener('click', () => {
                            selectEl.value = String(opt.value || '');
                            selectEl.dispatchEvent(new Event('change'));
                            btn.textContent = String(opt.text || '');
                            menu.classList.add('pd2-d-none');
                        });
                        menu.appendChild(b);
                    }
                    const selected = selectEl.options && selectEl.selectedIndex >= 0 ? selectEl.options[selectEl.selectedIndex] : null;
                    btn.textContent = selected ? String(selected.text || '') : (selectEl.options && selectEl.options[0] ? String(selectEl.options[0].text || '') : '');
                }
            };
        }

        selectEl.dataset.pd2Custom = '1';
        const host = document.createElement('div');
        host.className = 'pd2-cs';

        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = selectEl.className ? (selectEl.className + ' pd2-cs-btn') : 'btn pd2-cs-btn';

        const menu = document.createElement('div');
        menu.className = 'pd2-cs-menu pd2-d-none';

        host.appendChild(btn);
        host.appendChild(menu);
        selectEl.insertAdjacentElement('beforebegin', host);
        selectEl.style.display = 'none';

        const closeAll = () => {
            menu.classList.add('pd2-d-none');
        };

        btn.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            const isOpen = !menu.classList.contains('pd2-d-none');
            if (isOpen) {
                closeAll();
            } else {
                document.querySelectorAll('.pd2-cs-menu').forEach((m) => m.classList.add('pd2-d-none'));
                menu.classList.remove('pd2-d-none');
                const sel = menu.querySelector('[aria-selected="true"]');
                if (sel && typeof sel.scrollIntoView === 'function') sel.scrollIntoView({ block: 'nearest' });
            }
        });

        document.addEventListener('click', (e) => {
            if (!host.contains(e.target)) closeAll();
        });
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') closeAll();
        });

        const api = {
            refresh: () => {
                menu.innerHTML = '';
                for (const opt of Array.from(selectEl.options || [])) {
                    const b = document.createElement('button');
                    b.type = 'button';
                    b.className = 'pd2-cs-opt';
                    b.textContent = String(opt.text || '');
                    b.dataset.value = String(opt.value || '');
                    if (opt.disabled) b.disabled = true;
                    if (String(opt.value) === String(selectEl.value)) b.setAttribute('aria-selected', 'true');
                    b.addEventListener('click', () => {
                        selectEl.value = String(opt.value || '');
                        selectEl.dispatchEvent(new Event('change'));
                        btn.textContent = String(opt.text || '');
                        closeAll();
                    });
                    menu.appendChild(b);
                }
                const selected = selectEl.options && selectEl.selectedIndex >= 0 ? selectEl.options[selectEl.selectedIndex] : null;
                btn.textContent = selected ? String(selected.text || '') : (selectEl.options && selectEl.options[0] ? String(selectEl.options[0].text || '') : '');
            }
        };

        selectEl.addEventListener('change', () => {
            api.refresh();
        });

        api.refresh();
        return api;
    };

    const customSelects = {
        type: null,
        accFrom: null,
        accTo: null,
        category: null,
    };

    const escapeHtml = (str) => {
        return String(str || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    };

    const showError = (msg) => {
        if (!msg) {
            errorDiv.textContent = '';
            errorDiv.classList.add('pd2-d-none');
        } else {
            errorDiv.textContent = msg;
            errorDiv.classList.remove('pd2-d-none');
        }
    };

    const formatVndInt = (value) => {
        const n = Math.round(Number(value || 0));
        if (!Number.isFinite(n) || n <= 0) return '';
        try {
            return new Intl.NumberFormat('en-US', { maximumFractionDigits: 0 })
                .format(n)
                .replace(/,/g, '\u202F');
        } catch (_) {
            return String(n).replace(/\B(?=(\d{3})+(?!\d))/g, '\u202F');
        }
    };

    const parseVndInt = (raw) => {
        const s = String(raw || '').trim();
        if (!s) return 0;
        const cleaned = s
            .replaceAll('\u202F', '')
            .replaceAll(' ', '')
            .replace(/[^\d-]/g, '');
        const n = parseInt(cleaned, 10);
        return Number.isFinite(n) ? n : 0;
    };

    const loadOptions = async () => {
        try {
            if (!accountsLoaded) {
                const r = await fetch('?ajax=finance_accounts');
                const j = await r.json();
                if (!j || !j.ok) throw new Error(j?.error || 'Ошибка загрузки счетов');
                
                accFromSelect.innerHTML = '<option value="">Выберите счет...</option>';
                accToSelect.innerHTML = '<option value="">Выберите счет...</option>';
                
                for (const [id, name] of Object.entries(j.accounts || {})) {
                    accFromSelect.insertAdjacentHTML('beforeend', `<option value="${id}">${name}</option>`);
                    accToSelect.insertAdjacentHTML('beforeend', `<option value="${id}">${name}</option>`);
                }
                accountsLoaded = true;
            }

            if (!categoriesData) {
                const r = await fetch('?ajax=finance_categories');
                const j = await r.json();
                if (!j || !j.ok) throw new Error(j?.error || 'Ошибка загрузки категорий');
                categoriesData = j.categories || {};
            }
                
            const ls = (window.PAYDAY_CONFIG && window.PAYDAY_CONFIG.localSettings) ? window.PAYDAY_CONFIG.localSettings : null;
            const allowed = ls && ls.allowed_categories ? ls.allowed_categories : [];
            const customNames = ls && ls.custom_category_names ? ls.custom_category_names : {};
            const allowedSet = new Set(
                (Array.isArray(allowed) ? allowed : [])
                    .map((x) => Number(x))
                    .filter((n) => Number.isFinite(n) && n > 0)
            );

            const cats = categoriesData;
            const roots = [];
            const byId = {};
            
            for (const [idStr, data] of Object.entries(cats)) {
                const id = Number(idStr);
                byId[id] = { id, name: data.name, parent_id: Number(data.parent_id || 0), children: [] };
            }
            
            for (const id in byId) {
                const node = byId[id];
                if (node.parent_id && byId[node.parent_id]) {
                    byId[node.parent_id].children.push(node);
                } else {
                    roots.push(node);
                }
            }

            categorySelect.innerHTML = '<option value="">Без категории</option>';
            const renderedAllowed = new Set();
            
            const renderOptions = (node, depth) => {
                const nodeIdNum = Number(node.id);
                const isAllowed = allowedSet.has(nodeIdNum);
                
                if (isAllowed) {
                    const customName = customNames[node.id] || customNames[String(node.id)] || node.name;
                    const prefix = '— '.repeat(depth);
                    categorySelect.insertAdjacentHTML('beforeend', `<option value="${node.id}">${escapeHtml(prefix + customName)}</option>`);
                    renderedAllowed.add(nodeIdNum);
                }
                for (const child of node.children) {
                    renderOptions(child, depth + 1);
                }
            };

            for (const root of roots) {
                renderOptions(root, 0);
            }

            // Safety net: if some allowed category IDs exist in the categories map but were not rendered (tree edge-cases),
            // append them at the end so "checked in settings" always appears in "+" modal.
            const missing = [];
            for (const id of allowedSet) {
                if (!renderedAllowed.has(id) && byId[id]) missing.push(id);
            }
            if (missing.length) {
                missing.sort((a, b) => a - b);
                for (const id of missing) {
                    const node = byId[id];
                    const title = customNames[node.id] || customNames[String(node.id)] || node.name || String(id);
                    categorySelect.insertAdjacentHTML('beforeend', `<option value="${id}">${escapeHtml(title)}</option>`);
                }
            }

            if (!customSelects.type) customSelects.type = mountCustomSelect(typeSelect);
            if (!customSelects.accFrom) customSelects.accFrom = mountCustomSelect(accFromSelect);
            if (!customSelects.accTo) customSelects.accTo = mountCustomSelect(accToSelect);
            if (!customSelects.category) customSelects.category = mountCustomSelect(categorySelect);
            if (customSelects.type) customSelects.type.refresh();
            if (customSelects.accFrom) customSelects.accFrom.refresh();
            if (customSelects.accTo) customSelects.accTo.refresh();
            if (customSelects.category) customSelects.category.refresh();
        } catch (e) {
            showError(e.message);
        }
    };

    const openModal = async (amount, dateStr, timeStr) => {
        showError('');
        
        if (dateStr && dateStr.includes('/')) {
            const parts = dateStr.split('/');
            if (parts.length === 3) {
                dateInput.value = `${parts[2]}-${parts[1]}-${parts[0]}`;
            }
        } else {
            const now = new Date();
            dateInput.value = now.toISOString().split('T')[0];
        }

        if (timeStr) {
            timeInput.value = timeStr.slice(0, 5);
        } else {
            const now = new Date();
            timeInput.value = now.toTimeString().slice(0, 5);
        }

        typeSelect.value = '2'; // Расход
        typeSelect.dispatchEvent(new Event('change'));

        const av = Math.round(Number(amount || 0));
        amountInput.value = formatVndInt(av);
        
        const userEmail = (window.PAYDAY_CONFIG && window.PAYDAY_CONFIG.userEmail) ? window.PAYDAY_CONFIG.userEmail : 'User';
        commentInput.value = `Created by ${userEmail}`;

        modal.style.display = 'flex';
        
        submitBtn.disabled = true;
        await loadOptions();
        submitBtn.disabled = false;
    };

    const closeModal = () => {
        modal.style.display = 'none';
        form.reset();
        showError('');
    };

    const openSuccessModal = (detailsHtml, titleText) => {
        if (!successModal || !successDetails) return;
        successDetails.innerHTML = detailsHtml;
        if (successTitle && titleText) {
            successTitle.innerHTML = titleText;
        }
        successModal.style.display = 'flex';
    };

    const posterVerifyStart = async (targetElId, payload) => {
        const wrap = document.getElementById(targetElId);
        if (!wrap) return;

        const setHtml = (html) => { wrap.innerHTML = html; };
        const esc = escapeHtml;

        const fmtMoney = (n) => Math.round(Number(n || 0)).toLocaleString('en-US').replace(/,/g, '\u202F');

        const renderReq = (req) => {
            const docsUrl = String(req?.docs || '');
            const rqs = Array.isArray(req?.requests) ? req.requests : [];
            let html = '';
            html += `<div class="pd2-poster-verify-head">Проверка в Poster</div>`;
            html += `<div class="pd2-poster-verify-sub">API: <a href="${esc(docsUrl)}" target="_blank" rel="noreferrer">${esc(req?.api || 'finance.getTransactions')}</a></div>`;
            if (rqs.length) {
                html += `<div class="pd2-poster-verify-sub">Запросы:</div>`;
                for (const q of rqs) {
                    const lines = [];
                    for (const [k, v] of Object.entries(q || {})) {
                        lines.push(`${esc(k)}=${esc(v)}`);
                    }
                    html += `<div class="pd2-poster-verify-req">${lines.join('<br>')}</div>`;
                }
            }
            return html;
        };

        const renderFound = (match) => {
            if (!match) return '';
            const lines = [];
            lines.push('В постере транзакция создана — OK');
            lines.push(`ID: ${esc(match.transaction_id)}`);
            if (match.date) lines.push(`Дата: ${esc(match.date)}`);
            if (match.amount !== undefined && match.amount !== null) lines.push(`Сумма: ${esc(fmtMoney(match.amount))} VND`);
            if (match.account_id) lines.push(`Счет: ${esc(match.account_name || match.account_id)}`);
            if (match.category_id) lines.push(`Категория: ${esc(match.category_name || match.category_id)}`);
            if (match.comment) lines.push(`Комментарий: ${esc(match.comment)}`);
            return `<div class="pd2-poster-verify-result">${lines.join('<br>')}</div>`;
        };

        const renderNotFound = () => `<div class="pd2-poster-verify-result">Транзакция в постере не найдена.</div>`;

        const attempts = [800, 1500, 2500];
        setHtml(`<div class="pd2-poster-verify-loading">Проверяю транзакцию в Poster…</div>`);

        for (let i = 0; i < attempts.length; i++) {
            if (i > 0) await new Promise((r) => setTimeout(r, attempts[i]));
            try {
                const r = await fetch('?ajax=poster_verify_transaction', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload),
                });
                const j = await r.json();
                if (!j || !j.ok) throw new Error(j?.error || 'Poster verify error');

                const base = renderReq(j.request);
                if (j.found) {
                    setHtml(base + renderFound(j.match));
                    return;
                }
                setHtml(base + `<div class="pd2-poster-verify-loading">Пока не вижу в Poster, повторяю…</div>`);
            } catch (_) {
                setHtml(`<div class="pd2-poster-verify-loading">Проверяю транзакцию в Poster…</div>`);
            }
        }

        try {
            const r = await fetch('?ajax=poster_verify_transaction', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
            });
            const j = await r.json();
            if (j && j.ok) {
                setHtml(renderReq(j.request) + (j.found ? renderFound(j.match) : renderNotFound()));
                return;
            }
        } catch (_) {}

        setHtml(renderNotFound());
    };

    const closeSuccessModal = () => {
        if (successModal) successModal.style.display = 'none';
        // Click the refresh button of out table instead of reloading the whole page via form submit
        const outMailBtn = document.getElementById('outMailBtn');
        if (outMailBtn) {
            outMailBtn.click();
        }
    };

    closeBtn.addEventListener('click', closeModal);
    modal.addEventListener('click', (e) => {
        if (e.target === modal) closeModal();
    });

    if (successCloseBtn) successCloseBtn.addEventListener('click', closeSuccessModal);
    if (successModal) {
        successModal.addEventListener('click', (e) => {
            if (e.target === successModal) closeSuccessModal();
        });
    }

    typeSelect.addEventListener('change', () => {
        const t = typeSelect.value;
        if (t === '1') { // Приход
            accFromWrap.classList.add('pd2-d-none');
            accToWrap.classList.remove('pd2-d-none');
        } else if (t === '2') { // Расход
            accFromWrap.classList.remove('pd2-d-none');
            accToWrap.classList.add('pd2-d-none');
        } else if (t === '3') { // Перевод
            accFromWrap.classList.remove('pd2-d-none');
            accToWrap.classList.remove('pd2-d-none');
        }
    });

    amountInput.addEventListener('input', () => {
        const n = parseVndInt(amountInput.value);
        amountInput.value = n > 0 ? formatVndInt(n) : '';
    });
    amountInput.addEventListener('blur', () => {
        const n = parseVndInt(amountInput.value);
        amountInput.value = n > 0 ? formatVndInt(n) : '';
    });

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        showError('');

        const t = typeSelect.value;
        const date = dateInput.value;
        const time = timeInput.value;
        const amount = parseVndInt(amountInput.value);
        const accFrom = Number(accFromSelect.value);
        const accTo = Number(accToSelect.value);
        const categoryId = Number(categorySelect.value);
        const comment = commentInput.value.trim();

        if (!date || !time) return showError('Укажите дату и время');
        if (amount <= 0) return showError('Сумма должна быть больше 0');

        if (t === '1' && !accTo) return showError('Укажите счет пополнения');
        if (t === '2' && !accFrom) return showError('Укажите счет списания');
        if (t === '3') {
            if (!accFrom || !accTo) return showError('Укажите оба счета');
            if (accFrom === accTo) return showError('Счета должны быть разными');
        }

        const payload = {
            type: Number(t),
            amount: amount,
            date: `${date} ${time}:00`,
            comment: comment,
            category_id: categoryId,
            account_from: accFrom,
            account_to: accTo
        };

        submitBtn.disabled = true;
        const oldHtml = submitBtn.innerHTML;
        submitBtn.innerHTML = 'Создание...';

        try {
            const r = await fetch('?ajax=create_poster_transaction', {
                method: 'POST',
                headers: { 
                    'Content-Type': 'application/json',
                    'X-PAYDAY2-CSRF': window.PAYDAY_CONFIG?.csrfToken || ''
                },
                body: JSON.stringify(payload)
            });
            const j = await r.json();
            
            if (!j || !j.ok) throw new Error(j?.error || 'Ошибка при создании транзакции');

            let details = '';
            const tText = typeSelect.options[typeSelect.selectedIndex].text;
            details += `<div><strong>Тип:</strong> ${escapeHtml(tText)}</div>`;
            details += `<div><strong>Сумма:</strong> ${Math.round(amount).toLocaleString('en-US').replace(/,/g, '\u202F')} VND</div>`;

            let successTitleText = 'Транзакция успешно создана в Poster!';
            let accName = '';
            let catName = '';

            if (t === '1') {
                accName = accToSelect.options[accToSelect.selectedIndex].text;
                details += `<div><strong>На счет:</strong> ${escapeHtml(accName)}</div>`;
                successTitleText = `Приход на счет «${escapeHtml(accName)}» успешно создан!`;
            }
            if (t === '2') {
                accName = accFromSelect.options[accFromSelect.selectedIndex].text;
                details += `<div><strong>Со счета:</strong> ${escapeHtml(accName)}</div>`;
                successTitleText = `Расход со счета «${escapeHtml(accName)}» успешно создан!`;
            }
            if (t === '3') {
                const accFromText = accFromSelect.options[accFromSelect.selectedIndex].text;
                const accToText = accToSelect.options[accToSelect.selectedIndex].text;
                details += `<div><strong>Со счета:</strong> ${escapeHtml(accFromText)}</div>`;
                details += `<div><strong>На счет:</strong> ${escapeHtml(accToText)}</div>`;
                successTitleText = `Перевод «${escapeHtml(accFromText)}» ➔ «${escapeHtml(accToText)}» успешно создан!`;
            }

            if (categoryId) {
                catName = categorySelect.options[categorySelect.selectedIndex].text;
                details += `<div><strong>Категория:</strong> ${escapeHtml(catName)}</div>`;
                // Если это приход или расход (не перевод) и есть категория, добавим её в заголовок
                if (t !== '3') {
                    successTitleText += `<br><span style="font-size: 14px; font-weight: normal; color: var(--muted);">Категория: ${escapeHtml(catName)}</span>`;
                }
            }
            closeModal();

            const verifyTargetId = `pd2PosterVerify_${Date.now()}_${Math.floor(Math.random() * 100000)}`;
            details += `<div class="pd2-poster-verify"><div id="${verifyTargetId}"></div></div>`;
            openSuccessModal(details, successTitleText);
            posterVerifyStart(verifyTargetId, payload);

            // Автоматическое обновление таблицы Poster тр-ии
            const outFinanceBtn = document.getElementById('outFinanceBtn');
            if (outFinanceBtn) {
                outFinanceBtn.click();
            }

        } catch (err) {
            showError(err.message);
        } finally {
            submitBtn.disabled = false;
            submitBtn.innerHTML = oldHtml;
        }
    });

    // Global click listener to open modal
    document.addEventListener('click', (e) => {
        const btn = e.target.closest('.out-create-poster-tx-btn');
        if (btn) {
            const amount = btn.getAttribute('data-amount');
            const date = btn.getAttribute('data-date');
            const time = btn.getAttribute('data-time');
            openModal(amount, date, time);
        }
    });
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', window.initPaydayCreateTx);
} else {
    window.initPaydayCreateTx();
}
