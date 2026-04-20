(function () {
    if (typeof window.fetch !== 'function' || window.__payday2FetchPatched) return;
    window.__payday2FetchPatched = true;
    const origFetch = window.fetch.bind(window);
    window.fetch = function (input, init) {
        init = init == null ? {} : Object.assign({}, init);
        const method = String(init.method || 'GET').toUpperCase();
        const cfg = window.PAYDAY_CONFIG;
        if (method === 'POST' && cfg && cfg.csrfToken) {
            let urlStr = '';
            if (typeof input === 'string') urlStr = input;
            else if (input && typeof input.url === 'string') urlStr = input.url;
            const samePayday =
                urlStr.indexOf('ajax=') !== -1 ||
                urlStr.indexOf('/payday2') !== -1 ||
                (urlStr.charAt(0) === '?' && urlStr.length > 1);
            if (samePayday) {
                const h = new Headers(init.headers || undefined);
                if (!h.has('X-Payday2-Csrf')) h.set('X-Payday2-Csrf', cfg.csrfToken);
                init.headers = h;
            }
        }
        return origFetch(input, init);
    };
})();

(function () {
    const el = document.getElementById('payday2-config-json');
    try {
        window.PAYDAY_CONFIG = el ? JSON.parse(el.textContent || '{}') : {};
    } catch (e) {
        window.PAYDAY_CONFIG = {};
    }
})();

if (!window._paydayPjaxLoaded) {
    window._paydayPjaxLoaded = true;

    window.doPjax = async function(url, options = {}) {
        try {
            const res = await fetch(url, options);
            if (res.redirected) {
                url = res.url;
            }
            const html = await res.text();
            const parser = new DOMParser();
            const doc = parser.parseFromString(html, 'text/html');

            const newContainer = doc.querySelector('.container');
            const oldContainer = document.querySelector('.container');
            if (newContainer && oldContainer) {
                oldContainer.innerHTML = newContainer.innerHTML;
            } else {
                document.body.innerHTML = doc.body.innerHTML;
            }

            const jsonCfg = doc.getElementById('payday2-config-json');
            if (jsonCfg) {
                try {
                    window.PAYDAY_CONFIG = JSON.parse(jsonCfg.textContent || '{}');
                } catch (err) {
                    console.error('Payday2 config parse error:', err);
                }
            }

            if (options.method !== 'POST' || res.redirected) {
                try {
                    window.history.pushState({}, '', url);
                } catch (err) {}
            }

            if (typeof window.initPayday2 === 'function') {
                try {
                    window.initPayday2();
                } catch (err) {
                    console.error('initPayday2 error:', err);
                }
            }
        } catch (e) {
            console.error('PJAX Error:', e);
            if (options.method !== 'POST') {
                window.location.href = url;
            }
        }
    };

    const clickHandler = function pjaxListener(e) {
        const a = e.target.closest('a');
        if (a && a.href && a.href.includes('/payday2') && !a.hasAttribute('download') && !a.target && !e.ctrlKey && !e.metaKey) {
            e.preventDefault();
            window.doPjax(a.href);
        }
    };
    document.addEventListener('click', clickHandler);

    const popstateHandler = function pjaxListener() {
        window.doPjax(window.location.href);
    };
    window.addEventListener('popstate', popstateHandler);

    const submitHandler = function pjaxListener(e) {
        const form = e.target;
        if (e.defaultPrevented) return;

        const action = form.getAttribute('action') || window.location.href;
        const method = (form.getAttribute('method') || 'GET').toUpperCase();

        if (action.includes('/payday2') || action.startsWith('?') || !action.includes('//')) {
            e.preventDefault();

            const formData = new FormData(form);
            if (method === 'GET') {
                const baseUrl = action.startsWith('http') ? action : new URL(action, window.location.href).href;
                const url = new URL(baseUrl);
                for (const [k, v] of formData.entries()) {
                    url.searchParams.set(k, v);
                }
                window.doPjax(url.href);
            } else {
                window.doPjax(action, {
                    method: 'POST',
                    body: formData
                });
            }
        }
    };
    document.addEventListener('submit', submitHandler);
}

window.initPayday2 = function() {
    if (window.__payday2InitAbort) {
        try { window.__payday2InitAbort.abort(); } catch (_) {}
    }
    window.__payday2InitAbort = new AbortController();
    const pd2Signal = window.__payday2InitAbort.signal;
    const pd2on = (target, type, listener, options) => {
        if (!target || typeof target.addEventListener !== 'function') return;
        const o = typeof options === 'boolean' ? { capture: options } : (options && typeof options === 'object' ? Object.assign({}, options) : {});
        o.signal = pd2Signal;
        target.addEventListener(type, listener, o);
    };

    window.__USER_EMAIL__ = window.PAYDAY_CONFIG.userEmail;
    let links = window.PAYDAY_CONFIG.links || [];

    const escapeHtml = (s) => String(s)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');

    const modeToggleEl = document.getElementById('modeToggle');
    const modeToggleOutEl = document.getElementById('modeToggleOut');
    const payday2InfoBtn = document.getElementById('payday2InfoBtn');
    const payday2InfoModal = document.getElementById('payday2InfoModal');
    const payday2InfoModalClose = document.getElementById('payday2InfoModalClose');
    const payday2SettingsBtn = document.getElementById('payday2SettingsBtn');
    const payday2SettingsModal = document.getElementById('payday2SettingsModal');
    const payday2SettingsClose = document.getElementById('payday2SettingsClose');
    const payday2SettingsSave = document.getElementById('payday2SettingsSave');
    const payday2SettingsErr = document.getElementById('payday2SettingsErr');
    const applyMode = (mode) => {
        const m = (mode === 'lite') ? 'lite' : 'full';
        document.body.classList.toggle('mode-lite', m === 'lite');
        if (modeToggleEl) modeToggleEl.checked = (m === 'full');
        if (modeToggleOutEl) modeToggleOutEl.checked = (m === 'full');
        try { localStorage.setItem('payday_mode', m); } catch (_) {}
    };
    const tabIn = document.getElementById('tabIn');
    const tabOut = document.getElementById('tabOut');
    const outSection = document.getElementById('outSection');
    const outMailBtn = document.getElementById('outMailBtn');
    const outFinanceBtn = document.getElementById('outFinanceBtn');
    const outSepayTable = document.getElementById('outSepayTable');
    const outPosterTable = document.getElementById('outPosterTable');
    const toggleOutMailHiddenBtn = document.getElementById('toggleOutMailHiddenBtn');
    const fetchJsonSafe = (url) => fetch(url).then(async (r) => { const txt = await r.text(); let j; try { j = JSON.parse(txt); } catch (e) { throw new Error('Bad JSON: ' + (txt || '(empty)')); } return j; });
    const posterMinorToVnd = (n) => {
        const x = Number(n || 0);
        return x / 100;
    };
    const fmtVnd2 = (v) => {
        try {
            return new Intl.NumberFormat('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 0 }).format(Math.round(Number(v) || 0)).replace(/,/g, '\u202F');
        } catch (_) {
            const num = Math.round(Number(v) || 0);
            return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, '\u202F');
        }
    };
    const fmtVnd0 = (v) => {
        try {
            return new Intl.NumberFormat('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 0 }).format(Math.round(Number(v) || 0)).replace(/,/g, '\u202F');
        } catch (_) {
            const num = Math.round(Number(v) || 0);
            return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, '\u202F');
        }
    };
    const formatOutDT = (txTime, dateStr) => {
        const s1 = String(txTime || '').trim();
        const s2 = String(dateStr || '').trim();
        if (s1 && /\d{2}\/\d{2}\/\d{4}\s+\d{2}:\d{2}:\d{2}/.test(s1)) {
            const m = s1.match(/^(\d{2}\/\d{2}\/\d{4})\s+(\d{2}:\d{2}:\d{2})$/);
            if (m) return { date: m[1], time: m[2] };
        }
        if (s2 && /\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2}/.test(s2)) {
            const [dRaw, t] = s2.split(/\s+/);
            const [Y, M, D] = dRaw.split('-');
            return { date: `${D}/${M}/${Y}`, time: t };
        }
        return { date: '', time: '' };
    };
    const getDateRange = () => {
        const dfEl = document.querySelector('input[name="dateFrom"]');
        const dtEl = document.querySelector('input[name="dateTo"]');
        return { dateFrom: dfEl ? dfEl.value : '', dateTo: dtEl ? dtEl.value : '' };
    };
    const setBtnBusy = (btn, state) => {
        if (!btn) return () => {};
        const origHtml = btn.innerHTML;
        btn.dataset.origHtml = origHtml;
        
        btn.innerHTML = `Загрузка...`;
        btn.classList.add('loading-fill');
        btn.disabled = true;
        
        const pct = Number(state && state.pct != null ? state.pct : 10);
        const pctClamped = Math.max(10, Math.min(100, Math.round(pct)));
        btn.style.setProperty('--btn-progress', String(pctClamped) + '%');
        
        return () => {
            btn.classList.remove('loading-fill');
            btn.disabled = false;
            btn.style.removeProperty('--btn-progress');
            btn.innerHTML = btn.dataset.origHtml || origHtml;
            delete btn.dataset.origHtml;
        };
    };
    const updateBtnBusy = (btn, state) => {
        if (!btn || !btn.classList.contains('loading-fill')) return;
        const pct = Number(state && state.pct != null ? state.pct : NaN);
        if (Number.isFinite(pct)) {
            const pctClamped = Math.max(10, Math.min(100, Math.round(pct)));
            btn.style.setProperty('--btn-progress', String(pctClamped) + '%');
        }
    };
    let activeTab = 'in';
    const setTab = (m) => {
        const inOn = m === 'in';
        const tablesRoot = document.getElementById('tablesRoot');
        const lineLayer = document.getElementById('lineLayer');
        if (tablesRoot) tablesRoot.classList.toggle('pd2-d-none', !inOn);
        if (lineLayer) lineLayer.classList.toggle('pd2-d-none', !inOn);
        if (tabIn && tabOut) { tabIn.classList.toggle('active', inOn); tabOut.classList.toggle('active', !inOn); }
        if (outSection) outSection.classList.toggle('pd2-d-none', inOn);
        if (outMailBtn) outMailBtn.classList.toggle('pd2-d-none', inOn);
        if (outFinanceBtn) outFinanceBtn.classList.toggle('pd2-d-none', inOn);
        const posterSyncForm = document.getElementById('posterSyncForm');
        const sepaySyncForm = document.getElementById('sepaySyncForm');
        const clearDayForm = document.getElementById('clearDayForm');
        if (posterSyncForm) posterSyncForm.classList.toggle('pd2-d-none', !inOn);
        if (sepaySyncForm) sepaySyncForm.classList.toggle('pd2-d-none', !inOn);
        if (clearDayForm) clearDayForm.classList.toggle('pd2-d-none', !inOn);
        activeTab = inOn ? 'in' : 'out';
        if (!inOn) outScheduleRelayout();
    };
    if (tabIn) tabIn.addEventListener('click', () => setTab('in'));
    if (tabOut) tabOut.addEventListener('click', () => setTab('out'));
    const openPayday2InfoModal = () => {
        if (payday2InfoModal) payday2InfoModal.style.display = 'flex';
    };
    const closePayday2InfoModal = () => {
        if (payday2InfoModal) payday2InfoModal.style.display = 'none';
    };
    const fillPayday2SettingsForm = () => {
        const ls = (window.PAYDAY_CONFIG && window.PAYDAY_CONFIG.localSettings) ? window.PAYDAY_CONFIG.localSettings : null;
        if (!ls) return;
        const set = (id, v) => { const el = document.getElementById(id); if (el) el.value = v !== undefined && v !== null ? String(v) : ''; };
        set('pd2sett_tg_chat', ls.telegram_chat_id);
        set('pd2sett_tg_thread', ls.telegram_message_thread_id);
        set('pd2sett_svc_user', ls.service_user_id);
        if (ls.accounts) {
            set('pd2sett_acc_andrey', ls.accounts.andrey);
            set('pd2sett_acc_tips', ls.accounts.tips);
            set('pd2sett_acc_vietnam', ls.accounts.vietnam);
        }
        set('pd2sett_balance_sinc', ls.balance_sinc_account_id);
        
        // Allowed categories will be set after categories are loaded
    };
    
    let payday2CategoriesLoaded = false;
    const loadPayday2Categories = () => {
        const listEl = document.getElementById('pd2sett_categories_list');
        if (!listEl || payday2CategoriesLoaded) return;
        
        fetchJsonSafe(location.pathname + '?ajax=finance_categories').then(j => {
            if (!j || !j.ok) throw new Error(j.error || 'Ошибка');
            listEl.innerHTML = '';
            const ls = (window.PAYDAY_CONFIG && window.PAYDAY_CONFIG.localSettings) ? window.PAYDAY_CONFIG.localSettings : null;
            const allowed = ls && ls.allowed_categories ? ls.allowed_categories : [];
            const customNames = ls && ls.custom_category_names ? ls.custom_category_names : {};
            
            const cats = j.categories || {};
            
            // Build tree
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
            
            const renderNode = (node, depth) => {
                const checked = allowed.includes(node.id) ? 'checked' : '';
                const customName = customNames[node.id] || node.name;
                const margin = depth * 20;
                
                let html = `
                    <div class="pd2-d-flex pd2-align-center pd2-gap-8 pd2-mb-6" style="margin-left: ${margin}px;">
                        <label class="pd2-d-flex pd2-align-center pd2-gap-8 pd2-pointer pd2-m-0">
                            <input type="checkbox" class="pd2-sett-cat-cb" value="${node.id}" ${checked}>
                            <span class="pd2-ws-nowrap">${escapeHtml(node.name)}</span>
                        </label>
                        <input type="text" class="btn pd2-sett-cat-name pd2-flex-1" data-id="${node.id}" value="${escapeHtml(customName)}" placeholder="${escapeHtml(node.name)}">
                    </div>
                `;
                
                for (const child of node.children) {
                    html += renderNode(child, depth + 1);
                }
                return html;
            };
            
            let html = '';
            for (const root of roots) {
                html += renderNode(root, 0);
            }
            
            listEl.innerHTML = html;
            payday2CategoriesLoaded = true;
        }).catch(e => {
            listEl.innerHTML = '<div class="error pd2-text-center">Ошибка загрузки категорий</div>';
        });
    };

    const readPayday2SettingsPayload = () => {
        const num = (id) => {
            const el = document.getElementById(id);
            const n = el ? parseInt(String(el.value || '').trim(), 10) : NaN;
            return Number.isFinite(n) ? n : 0;
        };
        const str = (id) => {
            const el = document.getElementById(id);
            return el ? String(el.value || '').trim() : '';
        };
        
        const allowedCats = [];
        const customNames = {};
        document.querySelectorAll('.pd2-sett-cat-cb:checked').forEach(cb => {
            const id = Number(cb.value);
            allowedCats.push(id);
            const input = document.querySelector(`.pd2-sett-cat-name[data-id="${id}"]`);
            if (input) {
                const val = input.value.trim();
                if (val) {
                    customNames[id] = val;
                }
            }
        });

        return {
            telegram_chat_id: str('pd2sett_tg_chat'),
            telegram_message_thread_id: str('pd2sett_tg_thread'),
            service_user_id: num('pd2sett_svc_user'),
            accounts: {
                andrey: num('pd2sett_acc_andrey'),
                tips: num('pd2sett_acc_tips'),
                vietnam: num('pd2sett_acc_vietnam'),
            },
            balance_sinc_account_id: num('pd2sett_balance_sinc'),
            allowed_categories: allowedCats,
            custom_category_names: customNames,
        };
    };
    const openPayday2SettingsModal = () => {
        if (payday2SettingsErr) { payday2SettingsErr.textContent = ''; payday2SettingsErr.classList.add('pd2-d-none'); }
        fillPayday2SettingsForm();
        if (payday2SettingsModal) payday2SettingsModal.style.display = 'flex';
        
        const catSpoiler = document.getElementById('pd2sett_categories_spoiler');
        if (catSpoiler) {
            catSpoiler.addEventListener('toggle', () => {
                if (catSpoiler.open) loadPayday2Categories();
            });
        }
    };
    const closePayday2SettingsModal = () => {
        if (payday2SettingsModal) payday2SettingsModal.style.display = 'none';
    };
    if (payday2SettingsBtn) payday2SettingsBtn.addEventListener('click', openPayday2SettingsModal);
    if (payday2SettingsClose) payday2SettingsClose.addEventListener('click', closePayday2SettingsModal);
    if (payday2SettingsSave) {
        payday2SettingsSave.addEventListener('click', () => {
            const payload = readPayday2SettingsPayload();
            if (payday2SettingsErr) { payday2SettingsErr.textContent = ''; payday2SettingsErr.classList.add('pd2-d-none'); }
            payday2SettingsSave.disabled = true;
            fetch('?ajax=save_local_config', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
            })
                .then((r) => r.json())
                .then((j) => {
                    if (!j || !j.ok) throw new Error((j && j.error) ? j.error : 'Ошибка сохранения');
                    if (window.PAYDAY_CONFIG) window.PAYDAY_CONFIG.localSettings = payload;
                    closePayday2SettingsModal();
                })
                .catch((e) => {
                    if (payday2SettingsErr) {
                        payday2SettingsErr.textContent = e && e.message ? e.message : 'Ошибка';
                        payday2SettingsErr.classList.remove('pd2-d-none');
                    }
                })
                .finally(() => { payday2SettingsSave.disabled = false; });
        });
    }
    if (payday2SettingsModal) {
        payday2SettingsModal.addEventListener('click', (ev) => {
            if (ev.target === payday2SettingsModal) closePayday2SettingsModal();
        });
    }
    if (payday2InfoBtn) payday2InfoBtn.addEventListener('click', openPayday2InfoModal);
    if (payday2InfoModalClose) payday2InfoModalClose.addEventListener('click', closePayday2InfoModal);
    if (payday2InfoModal) {
        payday2InfoModal.addEventListener('click', (ev) => {
            if (ev.target === payday2InfoModal) closePayday2InfoModal();
        });
    }
    pd2on(document, 'keydown', (ev) => {
        if (ev.key !== 'Escape') return;
        if (payday2SettingsModal && payday2SettingsModal.style.display === 'flex') {
            closePayday2SettingsModal();
            return;
        }
        if (payday2InfoModal && payday2InfoModal.style.display === 'flex') {
            closePayday2InfoModal();
        }
    });
    const loadOutMail = (onProgress) => {
        const { dateFrom, dateTo } = getDateRange();
        const qs = new URLSearchParams({ dateFrom, dateTo, include_hidden: '1' });
        if (typeof onProgress === 'function') onProgress(10, 'SePay: начало');
        return fetchJsonSafe(location.pathname + '?' + qs.toString() + '&ajax=mail_out').then((j) => {
            if (typeof onProgress === 'function') onProgress(50, 'SePay: письма загружены');
            if (!j || !j.ok) throw new Error((j && j.error) ? j.error : 'Ошибка mail_out');
            const tbody = outSepayTable.tBodies[0]; tbody.innerHTML = '';
            (j.rows || []).forEach((row) => {
                const tr = document.createElement('tr');
                tr.setAttribute('data-mail-uid', String(row.mail_uid || 0));
                tr.setAttribute('data-sum', String(row.amount || 0));
                const isHidden = Number(row.is_hidden || 0) === 1;
                const hiddenComment = String(row.hidden_comment || '').trim();
                if (isHidden) {
                    tr.classList.add('row-hidden');
                    tr.setAttribute('data-hidden', '1');
                }
                const dt = formatOutDT(row.tx_time, row.date);
                const contentShow = (isHidden && hiddenComment) ? hiddenComment : String(row.content || '');
                tr.innerHTML = `
                    <td class="nowrap col-out-hide"><button type="button" class="sepay-hide out-hide" data-mail-uid="${Number(row.mail_uid || 0)}" title="Скрыть (не чек)" data-help-abs="Скрыть транзакцию, если она не относится к расходам Poster.">−</button></td>
                    <td class="col-out-content">${escapeHtml(contentShow)}</td>
                    <td class="nowrap col-out-time"><div class="col-out-date-part">${escapeHtml(dt.date)}</div><div class="col-out-time-part">${escapeHtml(dt.time)}</div></td>
                    <td class="sum col-out-sum">
                        <div class="pd2-d-flex pd2-align-center pd2-justify-end">
                            <button type="button" class="out-create-poster-tx-btn" title="Создать транзакцию в Poster" data-help-abs="Создать новую транзакцию расхода в Poster." data-amount="${Number(row.amount || 0)}" data-date="${dt.date}" data-time="${dt.time}">+</button>
                            ${Math.round(Number(row.amount || 0)).toLocaleString('en-US').replace(/,/g, '\u202F')}
                        </div>
                    </td>
                    <td class="col-out-select"><input type="checkbox" class="out-sepay-cb" data-id="${Number(row.mail_uid || 0)}"></td>
                    <td class="col-out-anchor"><span class="anchor" id="out-sepay-${Number(row.mail_uid || 0)}"></span></td>
                `;
                tbody.appendChild(tr);
            });
            return fetchJsonSafe(location.pathname + '?ajax=out_links&dateTo=' + encodeURIComponent(dateTo));
        }).then((j2) => {
            if (typeof onProgress === 'function') onProgress(80, 'SePay: связи загружены');
            if (!j2 || !j2.ok) throw new Error((j2 && j2.error) ? j2.error : 'Ошибка out_links');
            outLinks.length = 0;
            outLinkByMail.clear();
            outLinkByFin.clear();
            (j2.links || []).forEach((l) => {
                const link = { mail_uid: Number(l.mail_uid || 0), finance_id: Number(l.finance_id || 0), link_type: String(l.link_type || ''), is_manual: !!l.is_manual };
                if (!link.mail_uid || !link.finance_id) return;
                outLinks.push(link);
                if (!outLinkByMail.has(link.mail_uid)) outLinkByMail.set(link.mail_uid, []);
                if (!outLinkByFin.has(link.finance_id)) outLinkByFin.set(link.finance_id, []);
                outLinkByMail.get(link.mail_uid).push(link);
                outLinkByFin.get(link.finance_id).push(link);
            });
            applyOutRowClasses();
            applyOutHideLinked();
            outScheduleRelayout();
            if (typeof onProgress === 'function') onProgress(100, 'SePay: готово');
        });
    };
    let employeesMap = null;
    let categoriesMap = null;
    const ensureEmployees = () => {
        if (employeesMap) return Promise.resolve(employeesMap);
        return fetchJsonSafe(location.pathname + '?ajax=poster_employees')
            .then((j) => {
                if (!j || !j.ok) throw new Error((j && j.error) ? j.error : 'Ошибка poster_employees');
                employeesMap = j.employees || {};
                return employeesMap;
            });
    };
    const ensureCategories = () => {
        if (categoriesMap) return Promise.resolve(categoriesMap);
        return fetchJsonSafe(location.pathname + '?ajax=finance_categories')
            .then((j) => {
                if (!j || !j.ok) throw new Error((j && j.error) ? j.error : 'Ошибка finance_categories');
                categoriesMap = j.categories || {};
                return categoriesMap;
            });
    };
    const loadOutFinance = (onProgress) => {
        const { dateFrom, dateTo } = getDateRange();
        const qs = new URLSearchParams({ dateFrom, dateTo });
        if (typeof onProgress === 'function') onProgress(10, 'Poster: пользователи/категории');
        return Promise.all([
            ensureEmployees(),
            ensureCategories(),
            fetchJsonSafe(location.pathname + '?' + qs.toString() + '&ajax=finance_out'),
        ]).then(([emps, cats, j]) => {
            if (typeof onProgress === 'function') onProgress(60, 'Poster: транзакции');
            if (!j || !j.ok) throw new Error((j && j.error) ? j.error : 'Ошибка finance_out');
            const tbody = outPosterTable.tBodies[0]; tbody.innerHTML = '';
            (j.rows || []).forEach((row) => {
                const rawAmount = Number(row.amount || 0);
                const sign = rawAmount > 0 ? '+' : (rawAmount < 0 ? '−' : '');
                const amountVnd = posterMinorToVnd(Math.abs(rawAmount));
                const balanceVnd = posterMinorToVnd(Math.abs(Number(row.balance || 0)));
                const amountInt = Math.round(amountVnd);
                const balanceInt = Math.round(balanceVnd);
                const userName = String(emps && emps[Number(row.user_id || 0)] ? emps[Number(row.user_id || 0)] : row.user_id || '');
                let catName = '';
                const catObj = cats && cats[Number(row.category_id || 0)] ? cats[Number(row.category_id || 0)] : null;
                if (catObj && typeof catObj === 'object' && catObj.name) {
                    catName = String(catObj.name);
                } else if (typeof catObj === 'string') {
                    catName = String(catObj);
                } else {
                    catName = String(row.category_id || '');
                }
                if (catName === 'book_category_action_supplies') catName = 'поставки';
                const tr = document.createElement('tr');
                tr.setAttribute('data-finance-id', String(row.transaction_id || 0));
                tr.setAttribute('data-sum', String(amountInt));
                const dt2 = formatOutDT('', row.date);
                tr.innerHTML = `
                    <td class="nowrap"><div class="cell-anchor"><span class="anchor" id="out-poster-${Number(row.transaction_id || 0)}"></span><input type="checkbox" class="out-poster-cb" data-id="${Number(row.transaction_id || 0)}"></div></td>
                    <td class="nowrap col-out-date"><div class="col-out-date-date">${escapeHtml(dt2.date)}</div><div class="col-out-date-time">${escapeHtml(dt2.time)}</div></td>
                    <td class="col-out-user">${escapeHtml(userName)}</td>
                    <td class="col-out-category">${escapeHtml(catName)}</td>
                    <td class="col-out-type">${Number(row.type || 0)}</td>
                    <td class="sum col-out-amount">${sign}${fmtVnd0(amountInt)}</td>
                    <td class="sum col-out-balance">${fmtVnd0(balanceInt)}</td>
                    <td class="col-out-comment">${escapeHtml(row.comment || '')}</td>
                `;
                tbody.appendChild(tr);
            });
            applyOutRowClasses();
            applyOutHideLinked();
            if (typeof onProgress === 'function') onProgress(100, 'Poster: готово');
        });
    };
    if (outMailBtn) outMailBtn.addEventListener('click', () => {
        const restore = setBtnBusy(outMailBtn, { title: 'OUT SePay', pct: 0 });
        loadOutMail((pct) => updateBtnBusy(outMailBtn, { pct, title: 'OUT SePay' }))
            .catch((e) => alert(e && e.message ? e.message : 'Ошибка'))
            .finally(() => { restore(); outScheduleRelayout(); });
    });
    if (outFinanceBtn) outFinanceBtn.addEventListener('click', () => {
        const restore = setBtnBusy(outFinanceBtn, { title: 'OUT Poster', pct: 0 });
        loadOutFinance((pct) => updateBtnBusy(outFinanceBtn, { pct, title: 'OUT Poster' }))
            .catch((e) => alert(e && e.message ? e.message : 'Ошибка'))
            .finally(() => { restore(); outScheduleRelayout(); });
    });
    const dateForm = document.getElementById('dateForm');
    if (dateForm) {
        const dateFromInput = dateForm.querySelector('input[name="dateFrom"]');
        const dateToInput = dateForm.querySelector('input[name="dateTo"]');
        const dateFormLoader = document.getElementById('dateFormLoader');
        let syncingDateRange = false;
        if (dateFromInput && dateToInput) {
            dateFromInput.addEventListener('change', () => {
                if (syncingDateRange) return;
                syncingDateRange = true;
                dateToInput.value = dateFromInput.value || '';
                syncingDateRange = false;
                dateForm.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
            });
        }
        dateForm.addEventListener('submit', (ev) => {
            ev.preventDefault();
            if (dateFormLoader) {
                dateFormLoader.classList.remove('pd2-d-none');
                dateFormLoader.classList.add('pd2-d-flex');
            }
            const formData = new FormData(dateForm);
            const baseUrl = new URL(dateForm.getAttribute('action') || window.location.href, window.location.href);
            const nextUrl = new URL(baseUrl.href);
            nextUrl.search = '';
            for (const [k, v] of formData.entries()) {
                nextUrl.searchParams.set(k, String(v));
            }
            if (activeTab === 'out') nextUrl.searchParams.set('tab', 'out');
            else nextUrl.searchParams.delete('tab');

            if (typeof window.doPjax === 'function') {
                window.doPjax(nextUrl.href);
            } else {
                window.location.href = nextUrl.href;
            }
        });
    }

    const clearDayFormEl = document.getElementById('clearDayForm');
    if (clearDayFormEl) {
        pd2on(clearDayFormEl, 'submit', (ev) => {
            const msg = 'Сбросить день (Soft Reset)? Записи Poster и SePay за выбранную дату будут помечены скрытыми (was_deleted); строки и связи в БД не удаляются физически. После повторной синхронизации данные снова появятся.';
            if (!confirm(msg)) {
                ev.preventDefault();
                ev.stopImmediatePropagation();
            }
        });
    }

    const outLinkMakeBtn = document.getElementById('outLinkMakeBtn');
    const outHideLinkedBtn = document.getElementById('outHideLinkedBtn');
    const outLinkAutoBtn = document.getElementById('outLinkAutoBtn');
    const outLinkClearBtn = document.getElementById('outLinkClearBtn');
    const outSelSepaySumEl = document.getElementById('outSelSepaySum');
    const outSelPosterSumEl = document.getElementById('outSelPosterSum');
    const outSelDiffEl = document.getElementById('outSelDiff');
    const outSelMatchEl = document.getElementById('outSelMatch');
    const outGrid = document.getElementById('outGrid');
    const outLineLayer = document.getElementById('outLineLayer');
    const outSepayScroll = document.getElementById('outSepayScroll');
    const outPosterScroll = document.getElementById('outPosterScroll');
    const outWidgets = new Map();
    const outSvgState = { svg: null, defs: null, group: null };

    const outClearLines = () => {
        if (outSvgState.group) {
            while (outSvgState.group.firstChild) outSvgState.group.removeChild(outSvgState.group.firstChild);
        }
        Array.from(outWidgets.values()).forEach((btn) => { try { btn.remove(); } catch (_) {} });
        outWidgets.clear();
    };

    const outReloadLinks = (dateTo) => {
        return fetchJsonSafe(location.pathname + '?ajax=out_links&dateTo=' + encodeURIComponent(String(dateTo || '')))
            .then((j2) => {
                if (!j2 || !j2.ok) throw new Error((j2 && j2.error) ? j2.error : 'Ошибка out_links');
                outLinks.length = 0;
                outLinkByMail.clear();
                outLinkByFin.clear();
                (j2.links || []).forEach((lx) => {
                    const link = {
                        mail_uid: Number(lx.mail_uid || 0),
                        finance_id: Number(lx.finance_id || 0),
                        link_type: String(lx.link_type || ''),
                        is_manual: (lx.is_manual === true || lx.is_manual === 1 || lx.is_manual === '1')
                    };
                    if (!link.mail_uid || !link.finance_id) return;
                    outLinks.push(link);
                    if (!outLinkByMail.has(link.mail_uid)) outLinkByMail.set(link.mail_uid, []);
                    if (!outLinkByFin.has(link.finance_id)) outLinkByFin.set(link.finance_id, []);
                    outLinkByMail.get(link.mail_uid).push(link);
                    outLinkByFin.get(link.finance_id).push(link);
                });
                applyOutRowClasses();
                applyOutHideLinked();
                updateOutSelection();
                outScheduleRelayout();
            });
    };

    const outSyncButtons = () => {
        if (!outGrid) return;
        const keep = new Set();
        outLinks.forEach((l) => {
            const key = String(l.mail_uid) + ':' + String(l.finance_id);
            keep.add(key);
            if (outWidgets.has(key)) return;
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'link-x';
            btn.textContent = '×';
            btn.title = 'Удалить связь';
            btn.style.display = 'none';
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                const { dateTo } = getDateRange();
                fetch(location.pathname + '?ajax=out_unlink', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ dateTo, mail_uid: Number(l.mail_uid || 0), finance_id: Number(l.finance_id || 0) }),
                })
                .then((r) => r.json())
                .then((j) => {
                    if (!j || !j.ok) throw new Error((j && j.error) ? j.error : 'Ошибка out_unlink');
                    return outReloadLinks(dateTo);
                })
                .catch((err) => alert(err && err.message ? err.message : 'Ошибка'));
            });
            outGrid.appendChild(btn);
            outWidgets.set(key, btn);
        });
        Array.from(outWidgets.entries()).forEach(([key, btn]) => {
            if (keep.has(key)) return;
            try { btn.remove(); } catch (_) {}
            outWidgets.delete(key);
        });
    };

    const outEnsureSvg = () => {
        if (!outLineLayer || !outGrid) return;
        if (outSvgState.svg) return;
        const ns = 'http://www.w3.org/2000/svg';
        const svg = document.createElementNS(ns, 'svg');
        svg.setAttribute('width', '100%');
        svg.setAttribute('height', '100%');
        svg.style.display = 'block';
        svg.style.pointerEvents = 'none';
        const defs = document.createElementNS(ns, 'defs');
        const g = document.createElementNS(ns, 'g');
        svg.appendChild(defs);
        svg.appendChild(g);
        outLineLayer.appendChild(svg);
        outSvgState.svg = svg;
        outSvgState.defs = defs;
        outSvgState.group = g;
    };

    const outIsVisibleInScrollY = (el, scrollEl) => {
        if (!el || !scrollEl) return false;
        const tr = el.closest('tr');
        if (!tr) return false;
        if (!tr.getClientRects().length) return false;
        if (tr.style.display === 'none') return false;
        const r = tr.getBoundingClientRect();
        const sr = scrollEl.getBoundingClientRect();
        return r.bottom >= sr.top && r.top <= sr.bottom;
    };

    const outDrawLines = () => {
        outEnsureSvg();
        outClearLines();
        outSyncButtons();
        if (!outGrid || !outSvgState.svg || !outSvgState.group) return;
        const rootRect = outGrid.getBoundingClientRect();
        const w = Math.max(1, Math.round(rootRect.width));
        const h = Math.max(1, Math.round(rootRect.height));
        const scrollW = outGrid.scrollWidth || w;
        outSvgState.svg.setAttribute('viewBox', `0 0 ${scrollW} ${h}`);
        outSvgState.svg.setAttribute('preserveAspectRatio', 'none');
        outSvgState.svg.style.width = scrollW + 'px';
        outSvgState.svg.style.height = h + 'px';

        outLinks.forEach((l) => {
            const s = document.getElementById('out-sepay-' + l.mail_uid);
            const p = document.getElementById('out-poster-' + l.finance_id);
            if (!s || !p) return;
            if (!s.getClientRects().length || !p.getClientRects().length) return;
            if (outSepayScroll && !outIsVisibleInScrollY(s, outSepayScroll)) return;
            if (outPosterScroll && !outIsVisibleInScrollY(p, outPosterScroll)) return;
            const size = 2;
            const color = colorFor(l.link_type, l.link_type === 'manual');

            const a0 = outGetAnchorPoint(s, 'right', rootRect);
            const b0 = outGetAnchorPoint(p, 'left', rootRect);
            if (a0.y < 0 || b0.y < 0 || a0.y > h || b0.y > h) return;
            
            if (outSepayScroll) {
                const sr = outSepayScroll.getBoundingClientRect();
                a0.x = Math.max(sr.left - rootRect.left + outGrid.scrollLeft, Math.min(sr.right - rootRect.left + outGrid.scrollLeft, a0.x));
            }
            if (outPosterScroll) {
                const sr = outPosterScroll.getBoundingClientRect();
                b0.x = Math.max(sr.left - rootRect.left + outGrid.scrollLeft, Math.min(sr.right - rootRect.left + outGrid.scrollLeft, b0.x));
            }

            const clamp = (v, min, max) => Math.max(min, Math.min(max, v));
            const a = { x: clamp(a0.x, 0, w + outGrid.scrollLeft), y: clamp(a0.y, 0, h) };
            const b = { x: clamp(b0.x, 0, w + outGrid.scrollLeft), y: clamp(b0.y, 0, h) };
            const dx = b.x - a.x;
            const cdx = Math.min(120, Math.max(40, Math.abs(dx) * 0.35));
            const c1x = a.x + cdx;
            const c1y = a.y;
            const c2x = b.x - cdx;
            const c2y = b.y;
            const d = `M ${a.x} ${a.y} C ${c1x} ${c1y}, ${c2x} ${c2y}, ${b.x} ${b.y}`;

            const ns = 'http://www.w3.org/2000/svg';
            const outline = document.createElementNS(ns, 'path');
            outline.setAttribute('d', d);
            outline.setAttribute('fill', 'none');
            outline.setAttribute('stroke', 'rgba(255,255,255,0.65)');
            outline.setAttribute('stroke-width', String(size + 2));
            outline.setAttribute('stroke-linecap', 'round');
            outline.setAttribute('stroke-linejoin', 'round');
            outSvgState.group.appendChild(outline);

            const path = document.createElementNS(ns, 'path');
            path.setAttribute('d', d);
            path.setAttribute('fill', 'none');
            path.setAttribute('stroke', color);
            path.setAttribute('stroke-width', String(size));
            path.setAttribute('stroke-linecap', 'round');
            path.setAttribute('stroke-linejoin', 'round');
            outSvgState.group.appendChild(path);

            const key = String(l.mail_uid) + ':' + String(l.finance_id);
            const btn = outWidgets.get(key);
            if (btn) {
                const dxBtn = b.x - a.x;
                const dyBtn = b.y - a.y;
                const lenBtn = Math.hypot(dxBtn, dyBtn) || 1;
                const insetPx = 6;
                const tBtn = Math.min(0.99, Math.max(0.75, 1 - (insetPx / lenBtn)));
                const mx = a.x + dxBtn * tBtn;
                const my = a.y + dyBtn * tBtn;
                const localX = Math.max(8, Math.min(scrollW - 8, mx));
                const localY = Math.max(8, Math.min(h - 8, my));
                btn.style.left = Math.round(localX - 8) + 'px';
                btn.style.top = Math.round(localY - 8) + 'px';
                btn.style.display = 'flex';
            }
        });
    };

    const outPositionLines = () => outDrawLines();
    const outScheduleRelayout = () => {
        requestAnimationFrame(outPositionLines);
        setTimeout(outPositionLines, 50);
        setTimeout(outPositionLines, 200);
        setTimeout(outPositionLines, 600);
    };
    if (outGrid) {
        outGrid.addEventListener('scroll', () => outScheduleRelayout(), { passive: true, capture: true });
    }
    if (outSepayScroll) {
        outSepayScroll.addEventListener('scroll', () => outScheduleRelayout(), { passive: true });
    }
    if (outPosterScroll) {
        outPosterScroll.addEventListener('scroll', () => outScheduleRelayout(), { passive: true });
    }
    pd2on(window, 'resize', () => outScheduleRelayout(), { passive: true });

    let initialTab = 'in';
    try {
        const search = new URLSearchParams(window.location.search || '');
        if (search.get('tab') === 'out') initialTab = 'out';
    } catch (_) {}
    setTab(initialTab);

    let outHideLinkedOn = false;
    let showOutMailHidden = false;
    try { showOutMailHidden = localStorage.getItem('payday_show_out_mail_hidden') === '1'; } catch (e) {}
    const outLinks = [];
    const outLinkByMail = new Map();
    const outLinkByFin = new Map();
    const outSelectedMail = new Set();
    const outSelectedFin = new Set();

    const updateOutSelection = () => {
        const mailRows = Array.from(outSepayTable.tBodies[0]?.rows || []);
        const finRows = Array.from(outPosterTable.tBodies[0]?.rows || []);
        const sumMail = mailRows.reduce((acc, tr) => {
            const cb = tr.querySelector('input.out-sepay-cb');
            const id = cb ? Number(cb.getAttribute('data-id') || 0) : 0;
            if (!id || !outSelectedMail.has(id)) return acc;
            return acc + Number(tr.getAttribute('data-sum') || 0);
        }, 0);
        const sumFin = finRows.reduce((acc, tr) => {
            const cb = tr.querySelector('input.out-poster-cb');
            const id = cb ? Number(cb.getAttribute('data-id') || 0) : 0;
            if (!id || !outSelectedFin.has(id)) return acc;
            return acc + Number(tr.getAttribute('data-sum') || 0);
        }, 0);
        const diff = Math.abs(sumMail - sumFin);
        if (outSelSepaySumEl) outSelSepaySumEl.textContent = Math.round(Number(sumMail)).toLocaleString('en-US').replace(/,/g, '\u202F');
        if (outSelPosterSumEl) outSelPosterSumEl.textContent = Math.round(Number(sumFin)).toLocaleString('en-US').replace(/,/g, '\u202F');
        if (outSelDiffEl) outSelDiffEl.textContent = Math.round(Number(diff)).toLocaleString('en-US').replace(/,/g, '\u202F');
        if (outSelMatchEl) outSelMatchEl.style.color = diff === 0 ? '#16a34a' : '#dc2626';
        if (outLinkMakeBtn) outLinkMakeBtn.disabled = (outSelectedMail.size === 0 || outSelectedFin.size === 0);
    };

    const applyOutRowClasses = () => {
        const mailRows = Array.from(outSepayTable.tBodies[0]?.rows || []);
        mailRows.forEach((tr) => {
            tr.classList.remove('row-red', 'row-gray', 'row-green', 'row-yellow');
            const uid = Number(tr.getAttribute('data-mail-uid') || 0);
            if (uid && outLinkByMail.has(uid)) {
                const arr = outLinkByMail.get(uid) || [];
                const hasManual = arr.some((l) => l && (l.is_manual || l.link_type === 'manual'));
                const hasYellow = arr.some((l) => l && l.link_type === 'auto_yellow');
                if (hasManual) tr.classList.add('row-gray');
                else if (hasYellow) tr.classList.add('row-yellow');
                else tr.classList.add('row-green');
            } else {
                tr.classList.add('row-red');
            }
        });
        const finRows = Array.from(outPosterTable.tBodies[0]?.rows || []);
        finRows.forEach((tr) => {
            tr.classList.remove('row-red', 'row-gray', 'row-green', 'row-yellow');
            const fid = Number(tr.getAttribute('data-finance-id') || 0);
            if (fid && outLinkByFin.has(fid)) {
                const arr = outLinkByFin.get(fid) || [];
                const hasManual = arr.some((l) => l && (l.is_manual || l.link_type === 'manual'));
                const hasYellow = arr.some((l) => l && l.link_type === 'auto_yellow');
                if (hasManual) tr.classList.add('row-gray');
                else if (hasYellow) tr.classList.add('row-yellow');
                else tr.classList.add('row-green');
            } else {
                tr.classList.add('row-red');
            }
        });
    };

    const applyOutHideLinked = () => {
        const mailRows = Array.from(outSepayTable.tBodies[0]?.rows || []);
        mailRows.forEach((tr) => {
            const uid = Number(tr.getAttribute('data-mail-uid') || 0);
            const isHiddenRow = String(tr.getAttribute('data-hidden') || '0') === '1';
            const isLinked = uid && outLinkByMail.has(uid);
            const hidden = (outHideLinkedOn && isLinked) || (isHiddenRow && !showOutMailHidden);
            tr.style.display = hidden ? 'none' : '';
            if (hidden) {
                const cb = tr.querySelector('input.out-sepay-cb');
                if (cb) cb.checked = false;
                outSelectedMail.delete(uid);
            }
        });
        const finRows = Array.from(outPosterTable.tBodies[0]?.rows || []);
        finRows.forEach((tr) => {
            if (!outHideLinkedOn) { tr.style.display = ''; return; }
            const fid = Number(tr.getAttribute('data-finance-id') || 0);
            tr.style.display = (fid && outLinkByFin.has(fid)) ? 'none' : '';
        });
    };

    pd2on(document, 'change', (ev) => {
        const t = ev.target;
        if (!(t instanceof HTMLInputElement)) return;
        if (t.classList.contains('out-sepay-cb')) {
            const id = Number(t.getAttribute('data-id') || 0);
            if (!id) return;
            if (t.checked) outSelectedMail.add(id); else outSelectedMail.delete(id);
            updateOutSelection();
        }
        if (t.classList.contains('out-poster-cb')) {
            const id = Number(t.getAttribute('data-id') || 0);
            if (!id) return;
            if (t.checked) outSelectedFin.add(id); else outSelectedFin.delete(id);
            updateOutSelection();
        }
    });

    if (outLinkMakeBtn) outLinkMakeBtn.addEventListener('click', async () => {
        const mails = Array.from(outSelectedMail);
        const fins = Array.from(outSelectedFin);
        const pairs = [];
        if (mails.length === 1 && fins.length >= 1) {
            const uid = mails[0];
            fins.forEach((fid) => {
                if (!uid || !fid) return;
                pairs.push({ mail_uid: uid, finance_id: fid });
            });
        } else if (fins.length === 1 && mails.length >= 1) {
            const fid = fins[0];
            mails.forEach((uid) => {
                if (!uid || !fid) return;
                pairs.push({ mail_uid: uid, finance_id: fid });
            });
        } else {
            const n = Math.min(mails.length, fins.length);
            for (let i = 0; i < n; i++) {
                const uid = mails[i], fid = fins[i];
                if (!uid || !fid) continue;
                pairs.push({ mail_uid: uid, finance_id: fid });
            }
        }
        const { dateTo } = getDateRange();
        
        const restore = setBtnBusy(outLinkMakeBtn, { title: '🎯', pct: 0 });
        try {
            const r = await fetch(location.pathname + '?ajax=out_manual_link', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ dateTo, links: pairs }),
            });
            const j = await r.json();
            if (!j || !j.ok) throw new Error((j && j.error) ? j.error : 'Ошибка out_manual_link');
            
            const j2 = await fetchJsonSafe(location.pathname + '?ajax=out_links&dateTo=' + encodeURIComponent(dateTo));
            if (!j2 || !j2.ok) throw new Error((j2 && j2.error) ? j2.error : 'Ошибка out_links');
            
            outLinks.length = 0;
            outLinkByMail.clear();
            outLinkByFin.clear();
            (j2.links || []).forEach((l) => {
                const link = { mail_uid: Number(l.mail_uid || 0), finance_id: Number(l.finance_id || 0), link_type: String(l.link_type || ''), is_manual: !!l.is_manual };
                if (!link.mail_uid || !link.finance_id) return;
                outLinks.push(link);
                if (!outLinkByMail.has(link.mail_uid)) outLinkByMail.set(link.mail_uid, []);
                if (!outLinkByFin.has(link.finance_id)) outLinkByFin.set(link.finance_id, []);
                outLinkByMail.get(link.mail_uid).push(link);
                outLinkByFin.get(link.finance_id).push(link);
            });
            applyOutRowClasses();
            applyOutHideLinked();
            updateOutSelection();
            outScheduleRelayout();
        } catch (e) {
            alert(e && e.message ? e.message : 'Ошибка');
        } finally {
            restore();
        }
    });

    if (outLinkClearBtn) outLinkClearBtn.addEventListener('click', async () => {
        const { dateTo } = getDateRange();
        const restore = setBtnBusy(outLinkClearBtn, { title: '⛓️‍💥', pct: 0 });
        try {
            const r = await fetch(location.pathname + '?ajax=out_clear_links', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ dateTo }),
            });
            const j = await r.json();
            if (!j || !j.ok) throw new Error((j && j.error) ? j.error : 'Ошибка out_clear_links');
            outSelectedMail.clear();
            outSelectedFin.clear();
            Array.from(outSepayTable.querySelectorAll('input.out-sepay-cb')).forEach((cb) => { cb.checked = false; });
            Array.from(outPosterTable.querySelectorAll('input.out-poster-cb')).forEach((cb) => { cb.checked = false; });
            outHideLinkedOn = false;
            await outReloadLinks(dateTo);
        } catch (e) {
            alert(e && e.message ? e.message : 'Ошибка');
        } finally {
            restore();
        }
    });

    if (outHideLinkedBtn) outHideLinkedBtn.addEventListener('click', () => {
        outHideLinkedOn = !outHideLinkedOn;
        applyOutHideLinked();
        outScheduleRelayout();
    });

    if (toggleOutMailHiddenBtn) {
        toggleOutMailHiddenBtn.classList.toggle('on', showOutMailHidden);
        toggleOutMailHiddenBtn.addEventListener('click', () => {
            showOutMailHidden = !showOutMailHidden;
            try { localStorage.setItem('payday_show_out_mail_hidden', showOutMailHidden ? '1' : '0'); } catch (e) {}
            toggleOutMailHiddenBtn.classList.toggle('on', showOutMailHidden);
            applyOutHideLinked();
            outScheduleRelayout();
        });
    }

    if (outLinkAutoBtn) outLinkAutoBtn.addEventListener('click', async () => {
        const mailRows = Array.from(outSepayTable.tBodies[0]?.rows || []);
        const finRows = Array.from(outPosterTable.tBodies[0]?.rows || []);
        const finBySum = new Map();
        finRows.forEach((tr) => {
            const fid = Number(tr.getAttribute('data-finance-id') || 0);
            if (!fid || outLinkByFin.has(fid)) return;
            const sum = Number(tr.getAttribute('data-sum') || 0);
            if (!finBySum.has(sum)) finBySum.set(sum, []);
            finBySum.get(sum).push(fid);
        });
        const pairs = [];
        mailRows.forEach((tr) => {
            const uid = Number(tr.getAttribute('data-mail-uid') || 0);
            if (!uid || outLinkByMail.has(uid)) return;
            const sum = Number(tr.getAttribute('data-sum') || 0);
            const arr = finBySum.get(sum);
            if (!arr || arr.length === 0) return;
            const fid = arr.shift();
            const lt = (arr.length === 0) ? 'auto_green' : 'auto_yellow';
            pairs.push({ mail_uid: uid, finance_id: fid, link_type: lt });
        });
        if (pairs.length === 0) {
            alert('Нет совпадений для автосвязи по сумме');
            return;
        }
        const { dateTo } = getDateRange();
        
        const restore = setBtnBusy(outLinkAutoBtn, { title: '🧩', pct: 0 });
        try {
            const r = await fetch(location.pathname + '?ajax=out_auto_link', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ dateTo, links: pairs }),
            });
            const j = await r.json();
            if (!j || !j.ok) throw new Error((j && j.error) ? j.error : 'Ошибка out_auto_link');
            
            const j2 = await fetchJsonSafe(location.pathname + '?ajax=out_links&dateTo=' + encodeURIComponent(dateTo));
            if (!j2 || !j2.ok) throw new Error((j2 && j2.error) ? j2.error : 'Ошибка out_links');
            
            outLinks.length = 0;
            outLinkByMail.clear();
            outLinkByFin.clear();
            (j2.links || []).forEach((l) => {
                const link = { mail_uid: Number(l.mail_uid || 0), finance_id: Number(l.finance_id || 0), link_type: String(l.link_type || ''), is_manual: !!l.is_manual };
                if (!link.mail_uid || !link.finance_id) return;
                outLinks.push(link);
                if (!outLinkByMail.has(link.mail_uid)) outLinkByMail.set(link.mail_uid, []);
                if (!outLinkByFin.has(link.finance_id)) outLinkByFin.set(link.finance_id, []);
                outLinkByMail.get(link.mail_uid).push(link);
                outLinkByFin.get(link.finance_id).push(link);
            });
            applyOutRowClasses();
            applyOutHideLinked();
            updateOutSelection();
            outScheduleRelayout();
        } catch (e) {
            alert(e && e.message ? e.message : 'Ошибка');
        } finally {
            restore();
        }
    });

    pd2on(document, 'click', (ev) => {
        const t = ev.target;
        if (!(t instanceof HTMLElement)) return;
        if (t.classList.contains('out-hide')) {
            const uid = Number(t.getAttribute('data-mail-uid') || 0);
            const { dateTo } = getDateRange();
            if (!uid || !dateTo) return;
            const c = prompt('Комментарий (почему скрываем):', '');
            if (c === null) return;
            const comment = String(c || '').trim();
            fetch(location.pathname + '?ajax=mail_hide', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ mail_uid: uid, dateTo, comment }),
                credentials: 'same-origin',
            })
                .then((r) => r.json())
                .then((j) => {
                    if (!j || !j.ok) throw new Error((j && j.error) ? j.error : 'Ошибка');
                    const tr = t.closest('tr');
                    if (tr) {
                        tr.classList.add('row-hidden');
                        tr.setAttribute('data-hidden', '1');
                        tr.setAttribute('data-content', comment.toLowerCase());
                        const td = tr.querySelector('td.col-out-content');
                        if (td) td.textContent = comment || 'Скрыто';
                        const cb = tr.querySelector('input.out-sepay-cb');
                        if (cb) cb.checked = false;
                    }
                    outSelectedMail.delete(uid);
                    applyOutHideLinked();
                    updateOutSelection();
                    outScheduleRelayout();
                })
                .catch((e) => alert(e && e.message ? e.message : 'Ошибка'));
        }
    });

    const setFormLoading = (formId, btnId, defaultTitle, defaultStep) => {
        const form = document.getElementById(formId);
        const btn = document.getElementById(btnId);
        if (!form || !btn) return;
        form.addEventListener('submit', async (ev) => {
            if (ev.defaultPrevented) return;
            ev.preventDefault();
            const restore = setBtnBusy(btn, { title: defaultStep || 'Загрузка…', pct: 0 });
            try {
                const fd = new FormData(form);
                fd.append('ajax', '1');
                const res = await fetch(location.href, { method: 'POST', body: fd });
                if (!res.body) throw new Error('No response body');
                const reader = res.body.getReader();
                const decoder = new TextDecoder();
                let buffer = '';
                while (true) {
                    const { done, value } = await reader.read();
                    if (done) break;
                    buffer += decoder.decode(value, { stream: true });
                    const lines = buffer.split('\n');
                    buffer = lines.pop();
                    for (const line of lines) {
                        if (!line.trim()) continue;
                        try {
                            const j = JSON.parse(line);
                            if (j.pct !== undefined) {
                                updateBtnBusy(btn, { pct: j.pct, title: j.step || defaultStep });
                            }
                            if (j.ok) {
                                updateBtnBusy(btn, { pct: 100, title: 'Готово. Обновление...' });
                                setTimeout(() => { if(window.doPjax) window.doPjax(window.location.href); else window.location.reload(); }, 400);
                                return;
                            }
                        } catch(e) {}
                    }
                }
                if(window.doPjax) window.doPjax(window.location.href); else window.location.reload();
            } catch (err) {
                alert(err && err.message ? err.message : 'Ошибка');
                restore();
            }
        });
    };
    setFormLoading('posterSyncForm', 'posterSyncBtn', 'IN', 'Poster: загрузка чеков');
    setFormLoading('sepaySyncForm', 'sepaySyncBtn', 'IN', 'SePay: загрузка платежей');
    setFormLoading('clearDayForm', 'clearDayBtn', 'IN', 'Сброс дня');

    const posterAccountsBtn = document.getElementById('posterAccountsBtn');
    const posterAccountsTbody = document.getElementById('posterAccountsTbody');
    const balAndreyEl = document.getElementById('balAndrey');
    const balVietnamEl = document.getElementById('balVietnam');
    const balCashEl = document.getElementById('balCash');
    const balTotalEl = document.getElementById('balTotal');
    const balanceSyncBtn = document.getElementById('balanceSyncBtn');
    const balAndreyActualEl = document.getElementById('balAndreyActual');
    const balVietnamActualEl = document.getElementById('balVietnamActual');
    const balCashActualEl = document.getElementById('balCashActual');
    const balTotalActualEl = document.getElementById('balTotalActual');
    const balAndreyDiffEl = document.getElementById('balAndreyDiff');
    const balVietnamDiffEl = document.getElementById('balVietnamDiff');
    const balCashDiffEl = document.getElementById('balCashDiff');
    const balTotalDiffEl = document.getElementById('balTotalDiff');
    const posterBalancesTelegramBtn = document.getElementById('posterBalancesTelegramBtn');

    const fmtIntSpaces = (n) => String(n).replace(/\B(?=(\d{3})+(?!\d))/g, '\u202F');
    const fmtVndCentsJs = (cents) => {
        const c = Number(cents || 0) || 0;
        const neg = c < 0;
        const abs = Math.abs(c);
        const i = Math.round(abs / 100);
        return (neg && i > 0 ? '-' : '') + fmtIntSpaces(i);
    };
    const parseVndCentsJs = (raw) => {
        const s = String(raw || '').trim();
        if (!s) return null;
        const cleaned = s.replace(/[^\d.,-]/g, '').replaceAll('\u202F', '').replaceAll(' ', '').replaceAll(',', '.').trim();
        if (!cleaned) return null;
        const n = Number(cleaned);
        if (!Number.isFinite(n)) return null;
        return Math.round(n * 100);
    };
    const digitsOnly = (s) => String(s || '').replace(/\D+/g, '');
    const fmtDigitsSpaces = (digits) => {
        const d = String(digits || '').replace(/\D+/g, '');
        if (!d) return '';
        const norm = d.replace(/^0+(?=\d)/, '');
        return norm.replace(/\B(?=(\d{3})+(?!\d))/g, '\u202F');
    };
    const sanitizeInputVndInt = (el) => {
        if (!el) return;
        const v = digitsOnly(el.value);
        el.value = fmtDigitsSpaces(v);
    };
    const updateTotalActual = () => {
        const a = Number(digitsOnly(balAndreyActualEl ? balAndreyActualEl.value : '')) || 0;
        const v = Number(digitsOnly(balVietnamActualEl ? balVietnamActualEl.value : '')) || 0;
        const c = Number(digitsOnly(balCashActualEl ? balCashActualEl.value : '')) || 0;
        const sum = a + v + c;
        if (balTotalActualEl) balTotalActualEl.value = fmtDigitsSpaces(String(sum));
    };

    const setDiff = (el, diffCents) => {
        if (!el) return;
        el.classList.remove('bal-diff-pos', 'bal-diff-neg');
        if (diffCents === null) {
            el.textContent = '—';
            return;
        }
        const d = Number(diffCents) || 0;
        if (d > 0) {
            el.classList.add('bal-diff-pos');
            el.textContent = '+' + fmtVndCentsJs(d);
        } else if (d < 0) {
            el.classList.add('bal-diff-neg');
            el.textContent = fmtVndCentsJs(d);
        } else {
            el.textContent = fmtVndCentsJs(0);
        }
    };

    const updateBalanceDiffs = () => {
        const expAndrey = balAndreyEl ? parseInt(balAndreyEl.getAttribute('data-cents') || '', 10) : NaN;
        const expVietnam = balVietnamEl ? parseInt(balVietnamEl.getAttribute('data-cents') || '', 10) : NaN;
        const expCash = balCashEl ? parseInt(balCashEl.getAttribute('data-cents') || '', 10) : NaN;
        const expTotal = balTotalEl ? parseInt(balTotalEl.getAttribute('data-cents') || '', 10) : NaN;

        const factAndrey = balAndreyActualEl ? parseVndCentsJs(balAndreyActualEl.value) : null;
        const factVietnam = balVietnamActualEl ? parseVndCentsJs(balVietnamActualEl.value) : null;
        const factCash = balCashActualEl ? parseVndCentsJs(balCashActualEl.value) : null;
        const factTotal = balTotalActualEl ? parseVndCentsJs(balTotalActualEl.value) : null;

        setDiff(balAndreyDiffEl, Number.isFinite(expAndrey) && factAndrey !== null ? (factAndrey - expAndrey) : null);
        setDiff(balVietnamDiffEl, Number.isFinite(expVietnam) && factVietnam !== null ? (factVietnam - expVietnam) : null);
        setDiff(balCashDiffEl, Number.isFinite(expCash) && factCash !== null ? (factCash - expCash) : null);
        setDiff(balTotalDiffEl, Number.isFinite(expTotal) && factTotal !== null ? (factTotal - expTotal) : null);

        try {
            if (balAndreyActualEl) localStorage.setItem('payday_bal_andrey', balAndreyActualEl.value || '');
            if (balVietnamActualEl) localStorage.setItem('payday_bal_vietnam', balVietnamActualEl.value || '');
            if (balCashActualEl) localStorage.setItem('payday_bal_cash', balCashActualEl.value || '');
            if (balTotalActualEl) localStorage.setItem('payday_bal_total', balTotalActualEl.value || '');
        } catch (_) {}
    };

    const refreshPosterAccounts = () => {
        if (balAndreyEl) { balAndreyEl.textContent = '...'; balAndreyEl.setAttribute('data-cents', ''); }
        if (balVietnamEl) { balVietnamEl.textContent = '...'; balVietnamEl.setAttribute('data-cents', ''); }
        if (balCashEl) { balCashEl.textContent = '...'; balCashEl.setAttribute('data-cents', ''); }
        if (balTotalEl) { balTotalEl.textContent = '...'; balTotalEl.setAttribute('data-cents', ''); }
        if (posterAccountsTbody) posterAccountsTbody.innerHTML = '<tr class="pd2-acct-empty-row"><td colspan="3">Обновление...</td></tr>';
        
        const url = `?dateFrom=${encodeURIComponent(window.PAYDAY_CONFIG.dateFrom)}&dateTo=${encodeURIComponent(window.PAYDAY_CONFIG.dateTo)}&ajax=poster_accounts`;
        return fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({}),
        })
        .then((r) => r.json())
        .then((j) => {
            if (!j || !j.ok) throw new Error((j && j.error) ? j.error : 'Ошибка');
            if (balAndreyEl) { balAndreyEl.textContent = String(j.balance_andrey || '—'); if ('balance_andrey_cents' in j) balAndreyEl.setAttribute('data-cents', String(j.balance_andrey_cents)); }
            if (balVietnamEl) { balVietnamEl.textContent = String(j.balance_vietnam || '—'); if ('balance_vietnam_cents' in j) balVietnamEl.setAttribute('data-cents', String(j.balance_vietnam_cents)); }
            if (balCashEl) { balCashEl.textContent = String(j.balance_cash || '—'); if ('balance_cash_cents' in j) balCashEl.setAttribute('data-cents', String(j.balance_cash_cents)); }
            if (balTotalEl) { balTotalEl.textContent = String(j.balance_total || '—'); if ('balance_total_cents' in j) balTotalEl.setAttribute('data-cents', String(j.balance_total_cents)); }

            if (posterAccountsTbody) {
                const rows = Array.isArray(j.accounts) ? j.accounts : [];
                if (rows.length === 0) {
                    posterAccountsTbody.innerHTML = '<tr class="pd2-acct-empty-row"><td colspan="3">Нет данных</td></tr>';
                } else {
                    posterAccountsTbody.innerHTML = rows.map((a) => {
                        const id = Number(a.account_id || 0);
                        const name = String(a.name || '');
                        const bal = String(a.balance || '0');
                        return `<tr>
                            <td class="pd2-acct-td">${String(id)}</td>
                            <td class="pd2-acct-td">${escapeHtml(name)}</td>
                            <td class="pd2-acct-td-num">${escapeHtml(bal)}</td>
                        </tr>`;
                    }).join('');
                }
            }
            updateBalanceDiffs();
        });
    };

    if (posterAccountsBtn) {
        posterAccountsBtn.addEventListener('click', () => {
            posterAccountsBtn.classList.add('loading');
            posterAccountsBtn.disabled = true;
            refreshPosterAccounts()
                .catch((e) => alert(e && e.message ? e.message : 'Ошибка'))
                .finally(() => {
                    posterAccountsBtn.classList.remove('loading');
                    posterAccountsBtn.disabled = false;
                });
        });
    }
    const collectPosterBalancePayload = () => {
        const getText = (el) => el ? String(el.textContent || '').trim() : '—';
        const getVal = (el) => el ? String(el.value || '').trim() : '';
        const summaryRows = [
            {
                label: 'Счет Андрей',
                poster: getText(balAndreyEl),
                actual: getVal(balAndreyActualEl) || '—',
                diff: getText(balAndreyDiffEl),
            },
            {
                label: 'Вьет. счет',
                poster: getText(balVietnamEl),
                actual: getVal(balVietnamActualEl) || '—',
                diff: getText(balVietnamDiffEl),
            },
            {
                label: 'Касса',
                poster: getText(balCashEl),
                actual: getVal(balCashActualEl) || '—',
                diff: getText(balCashDiffEl),
            },
            {
                label: 'Total',
                poster: getText(balTotalEl),
                actual: getVal(balTotalActualEl) || '—',
                diff: getText(balTotalDiffEl),
            },
        ];
        const accountsRows = posterAccountsTbody
            ? Array.from(posterAccountsTbody.querySelectorAll('tr')).map((tr) => {
                const tds = tr.querySelectorAll('td');
                return {
                    id: tds[0] ? String(tds[0].textContent || '').trim() : '',
                    name: tds[1] ? String(tds[1].textContent || '').trim() : '',
                    balance: tds[2] ? String(tds[2].textContent || '').trim() : '',
                };
            }).filter((row) => row.id || row.name || row.balance)
            : [];
        return {
            dateFrom: window.PAYDAY_CONFIG.dateFrom,
            dateTo: window.PAYDAY_CONFIG.dateTo,
            summaryRows,
            accountsRows,
        };
    };
    if (posterBalancesTelegramBtn) {
        if (typeof window.initPaydayTelegramScreenshot === 'function') {
            window.initPaydayTelegramScreenshot();
        }
    }

    try {
        if (balAndreyActualEl) balAndreyActualEl.value = localStorage.getItem('payday_bal_andrey') || '';
        if (balVietnamActualEl) balVietnamActualEl.value = localStorage.getItem('payday_bal_vietnam') || '';
        if (balCashActualEl) balCashActualEl.value = localStorage.getItem('payday_bal_cash') || '';
        if (balTotalActualEl) balTotalActualEl.value = localStorage.getItem('payday_bal_total') || '';
    } catch (_) {}
    sanitizeInputVndInt(balAndreyActualEl);
    sanitizeInputVndInt(balVietnamActualEl);
    sanitizeInputVndInt(balCashActualEl);
    updateTotalActual();
    updateBalanceDiffs();

    [balAndreyActualEl, balVietnamActualEl, balCashActualEl].forEach((el) => {
        if (!el) return;
        el.addEventListener('input', () => {
            sanitizeInputVndInt(el);
            updateTotalActual();
            updateBalanceDiffs();
        }, { passive: true });
    });

    if (balanceSyncBtn) {
        balanceSyncBtn.addEventListener('click', () => {
            const exp = balAndreyEl ? parseInt(balAndreyEl.getAttribute('data-cents') || '', 10) : NaN;
            const fact = balAndreyActualEl ? parseVndCentsJs(balAndreyActualEl.value) : null;
            if (!Number.isFinite(exp)) return alert('Нет баланса Poster по Счету Андрей');
            if (fact === null) return alert('Заполни фактический баланс (Счет Андрей)');
            const diff = fact - exp;
            if (!diff) return alert('Разница = 0');

            balanceSyncBtn.classList.add('loading');
            balanceSyncBtn.disabled = true;
            const urlPlan = `?dateFrom=${encodeURIComponent(window.PAYDAY_CONFIG.dateFrom)}&dateTo=${encodeURIComponent(window.PAYDAY_CONFIG.dateTo)}&ajax=balance_sinc_plan`;
            const urlCommit = `?dateFrom=${encodeURIComponent(window.PAYDAY_CONFIG.dateFrom)}&dateTo=${encodeURIComponent(window.PAYDAY_CONFIG.dateTo)}&ajax=balance_sinc_commit`;
            fetch(urlPlan, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ diff_cents: diff }),
            })
            .then((r) => r.json())
            .then((j) => {
                if (!j || !j.ok) throw new Error((j && j.error) ? j.error : 'Ошибка');
                const p = j.plan || {};
                const sum = String(p.sum || '');
                const accId = Number(p.account_to || p.account_from || 0);
                const accName = String(p.account_name || '');
                const type = Number(p.type || 0);
                const action = type === 1 ? 'Начислить' : 'Списать';
                const accLabel = accName ? `счёт ${accId} (${accName})` : `счёт ${accId}`;
                const ok = confirm(`${action} ${sum} на ${accLabel}?\nКомментарий: ${String(p.comment || '')}`);
                if (!ok) return null;

                const nonce = String(j.nonce || '');
                if (!nonce) throw new Error('Нет подтверждения (nonce)');
                return fetch(urlCommit, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ nonce }),
                }).then((r) => r.json());
            })
            .then((j) => {
                if (j === null) return null;
                if (!j || !j.ok) throw new Error((j && j.error) ? j.error : 'Ошибка');
                return refreshPosterAccounts();
            })
            .catch((e) => alert(e && e.message ? e.message : 'Ошибка'))
            .finally(() => {
                balanceSyncBtn.classList.remove('loading');
                balanceSyncBtn.disabled = false;
            });
        });
    }

    let initialMode = 'full';
    try { initialMode = localStorage.getItem('payday_mode') || 'full'; } catch (_) {}
    if (!initialMode || initialMode === 'full') {
        try {
            const mq = window.matchMedia('(max-width: 1050px)');
            if (mq && mq.matches) initialMode = 'lite';
        } catch (_) {}
    }
    applyMode(initialMode);

    if (modeToggleEl) {
        modeToggleEl.addEventListener('change', () => {
            const next = modeToggleEl.checked ? 'full' : 'lite';
            applyMode(next);
            try { window.dispatchEvent(new Event('resize')); } catch (_) {}
            setTimeout(() => { try { window.dispatchEvent(new Event('resize')); } catch (_) {} }, 200);
        });
    }
    if (modeToggleOutEl) {
        modeToggleOutEl.addEventListener('change', () => {
            const next = modeToggleOutEl.checked ? 'full' : 'lite';
            applyMode(next);
            try { window.dispatchEvent(new Event('resize')); } catch (_) {}
            setTimeout(() => { try { window.dispatchEvent(new Event('resize')); } catch (_) {} }, 200);
        });
    }

    const widgets = new Map();
    const svgState = { svg: null, defs: null, group: null, markers: new Map() };

    const colorFor = (t, isManual) => {
        if (isManual || t === 'manual') return '#6b7280';
        if (t === 'auto_green') return '#2e7d32';
        if (t === 'auto_yellow') return '#f6c026';
        return '#9aa4b2';
    };

    const endPlugFor = (t, isManual) => {
        if (isManual || t === 'manual') return 'hand';
        return 'arrow1';
    };

    const clearLines = () => {
        if (svgState.group) {
            while (svgState.group.firstChild) svgState.group.removeChild(svgState.group.firstChild);
        }
    };

    const ensureSvg = () => {
        if (!lineLayer || !tablesRoot) return;
        if (svgState.svg) return;
        const ns = 'http://www.w3.org/2000/svg';
        const svg = document.createElementNS(ns, 'svg');
        svg.setAttribute('width', '100%');
        svg.setAttribute('height', '100%');
        svg.style.display = 'block';
        svg.style.pointerEvents = 'none';
        const defs = document.createElementNS(ns, 'defs');
        const g = document.createElementNS(ns, 'g');
        svg.appendChild(defs);
        svg.appendChild(g);
        lineLayer.appendChild(svg);
        svgState.svg = svg;
        svgState.defs = defs;
        svgState.group = g;
    };

    const ensureMarker = (color) => {
        ensureSvg();
        if (!svgState.defs) return null;
        const key = String(color || '');
        if (svgState.markers.has(key)) return svgState.markers.get(key);
        const ns = 'http://www.w3.org/2000/svg';
        const id = 'm' + (svgState.markers.size + 1);
        const marker = document.createElementNS(ns, 'marker');
        marker.setAttribute('id', id);
        marker.setAttribute('viewBox', '0 0 10 10');
        marker.setAttribute('refX', '10');
        marker.setAttribute('refY', '5');
        marker.setAttribute('markerWidth', '6');
        marker.setAttribute('markerHeight', '6');
        marker.setAttribute('orient', 'auto');
        const path = document.createElementNS(ns, 'path');
        path.setAttribute('d', 'M 0 0 L 10 5 L 0 10 z');
        path.setAttribute('fill', key || '#9aa4b2');
        marker.appendChild(path);
        svgState.defs.appendChild(marker);
        svgState.markers.set(key, id);
        return id;
    };

    const getAnchorPoint = (el, side, rootRect) => {
        const r = el.getBoundingClientRect();
        // Shift X coordinate by tablesRoot scrollLeft
        const scrollLeft = tablesRoot ? tablesRoot.scrollLeft : 0;
        const cx = (r.left + r.width / 2) - rootRect.left + scrollLeft;
        const cy = (r.top + r.height / 2) - rootRect.top;
        const x = Math.round(cx) + 0.5;
        let y = Math.round(cy) + 0.5;
        return { x, y };
    };

    const outGetAnchorPoint = (el, side, rootRect) => {
        const r = el.getBoundingClientRect();
        // Shift X coordinate by outGrid scrollLeft
        const scrollLeft = outGrid ? outGrid.scrollLeft : 0;
        const cx = (r.left + r.width / 2) - rootRect.left + scrollLeft;
        const cy = (r.top + r.height / 2) - rootRect.top;
        const x = Math.round(cx) + 0.5;
        let y = Math.round(cy) + 0.5;
        return { x, y };
    };

    const isInside = (pt, w, h) => pt.x >= 0 && pt.y >= 0 && pt.x <= w && pt.y <= h;

    const syncButtons = () => {
        if (!tablesRoot) return;
        const keep = new Set();
        links.forEach((l) => {
            const key = String(l.sepay_id) + ':' + String(l.poster_transaction_id);
            keep.add(key);
            if (widgets.has(key)) return;
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'link-x';
            btn.textContent = '×';
            btn.title = 'Удалить связь';
            btn.style.display = 'none';
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                unlink(Number(l.sepay_id || 0), Number(l.poster_transaction_id || 0)).catch((err) => {
                    alert(err && err.message ? err.message : 'Ошибка');
                });
            });
            tablesRoot.appendChild(btn);
            widgets.set(key, btn);
        });
        Array.from(widgets.entries()).forEach(([key, btn]) => {
            if (keep.has(key)) return;
            try { btn.remove(); } catch (_) {}
            widgets.delete(key);
        });
    };

    const fmtVnd = (v) => {
        try {
            return new Intl.NumberFormat('en-US', { maximumFractionDigits: 0 }).format(Math.round(Number(v) || 0)).replace(/,/g, '\u202F');
        } catch (_) {
            return String(Math.round(Number(v) || 0)).replace(/\B(?=(\d{3})+(?!\d))/g, '\u202F');
        }
    };

    const buildLinkState = () => {
        const sepay = new Map();
        const poster = new Map();
        links.forEach((l) => {
            const sid = Number(l.sepay_id || 0);
            const pid = Number(l.poster_transaction_id || 0);
            if (!sid || !pid) return;

            const s = sepay.get(sid) || { hasAny: false, hasManual: false, hasYellow: false };
            s.hasAny = true;
            if (l.is_manual) s.hasManual = true;
            if (l.link_type === 'auto_yellow') s.hasYellow = true;
            sepay.set(sid, s);

            const p = poster.get(pid) || { hasAny: false, hasManual: false, hasYellow: false };
            p.hasAny = true;
            if (l.is_manual) p.hasManual = true;
            if (l.link_type === 'auto_yellow') p.hasYellow = true;
            poster.set(pid, p);
        });
        return { sepay, poster };
    };

    const applyRowClasses = () => {
        const state = buildLinkState();

        const sepayRows = Array.from(document.querySelectorAll('#sepayTable tbody tr[data-sepay-id]'));
        sepayRows.forEach((tr) => {
            const sid = Number(tr.getAttribute('data-sepay-id') || 0);
            const s = state.sepay.get(sid);
            tr.classList.remove('row-red', 'row-green', 'row-yellow', 'row-gray');
            if (!s || !s.hasAny) {
                tr.classList.add('row-red');
            } else if (s.hasManual) {
                tr.classList.add('row-gray');
            } else if (s.hasYellow) {
                tr.classList.add('row-yellow');
            } else {
                tr.classList.add('row-green');
            }
        });

        const posterRows = Array.from(document.querySelectorAll('#posterTable tbody tr[data-poster-id]'));
        posterRows.forEach((tr) => {
            const isVietnam = String(tr.getAttribute('data-vietnam') || '0') === '1';
            tr.classList.remove('row-red', 'row-green', 'row-yellow', 'row-gray', 'row-blue');
            if (isVietnam) {
                tr.classList.add('row-blue');
                return;
            }
            const pid = Number(tr.getAttribute('data-poster-id') || 0);
            const p = state.poster.get(pid);
            if (!p || !p.hasAny) {
                tr.classList.add('row-red');
            } else if (p.hasManual) {
                tr.classList.add('row-gray');
            } else if (p.hasYellow) {
                tr.classList.add('row-yellow');
            } else {
                tr.classList.add('row-green');
            }
        });
    };

    const updateStats = () => {
        const state = buildLinkState();

        let sepayTotal = 0;
        let sepayLinked = 0;
        let sepayUnlinked = 0;
        const sepaySumById = new Map();
        document.querySelectorAll('#sepayTable tbody tr[data-sepay-id]').forEach((tr) => {
            if (tr.style && tr.style.display === 'none') return;
            const sid = Number(tr.getAttribute('data-sepay-id') || 0);
            const sum = Number(tr.getAttribute('data-sum') || 0) || 0;
            if (sid > 0) sepaySumById.set(sid, sum);
            sepayTotal += sum;
            if (state.sepay.has(sid)) sepayLinked += sum;
            else sepayUnlinked += sum;
        });

        let posterTotal = 0;
        let posterLinked = 0;
        let posterUnlinked = 0;
        let posterTipsLinked = 0;
        const posterVietnam = new Set();
        document.querySelectorAll('#posterTable tbody tr[data-poster-id]').forEach((tr) => {
            if (tr.style && tr.style.display === 'none') return;
            const isVietnam = String(tr.getAttribute('data-vietnam') || '0') === '1';
            const pid = Number(tr.getAttribute('data-poster-id') || 0);
            const sum = Number(tr.getAttribute('data-total') || 0) || 0;
            const tips = Number(tr.getAttribute('data-tips') || 0) || 0;
            if (isVietnam) {
                if (pid > 0) posterVietnam.add(pid);
                return;
            }
            posterTotal += sum;
            if (state.poster.has(pid)) {
                posterLinked += sum;
                posterTipsLinked += tips;
            } else {
                posterUnlinked += sum;
            }
        });

        const setText = (id, v) => {
            const el = document.getElementById(id);
            if (!el) return;
            el.textContent = fmtVnd(v);
        };
        setText('sepayTotal', sepayTotal);
        setText('sepayLinked', sepayLinked);
        setText('sepayUnlinked', sepayUnlinked);
        setText('posterTotal', posterTotal);
        setText('posterTipsLinked', posterTipsLinked);
        setText('posterLinked', posterLinked);
        setText('posterUnlinked', posterUnlinked);

        const totalsDiffEl = document.getElementById('totalsDiff');
        if (totalsDiffEl) {
            let vcSepaySum = 0;
            if (Array.isArray(links) && posterVietnam.size > 0 && sepaySumById.size > 0) {
                const vcSepayIds = new Set();
                for (const l of links) {
                    const pid = Number(l.poster_transaction_id || 0);
                    if (!pid || !posterVietnam.has(pid)) continue;
                    const sid = Number(l.sepay_id || 0);
                    if (sid > 0) vcSepayIds.add(sid);
                }
                for (const sid of vcSepayIds) {
                    vcSepaySum += Number(sepaySumById.get(sid) || 0);
                }
            }
            const sepayNoVc = sepayTotal - vcSepaySum;
            const diff = sepayNoVc - posterTotal;
            const arrow = diff > 0 ? '←' : (diff < 0 ? '→' : '↔');
            totalsDiffEl.textContent = `${arrow} ${fmtVnd(Math.abs(diff))}`;
        }
    };

    const refreshLinks = () => {
        const url = `?dateFrom=${encodeURIComponent(window.PAYDAY_CONFIG.dateFrom)}&dateTo=${encodeURIComponent(window.PAYDAY_CONFIG.dateTo)}&ajax=links`;
        return fetch(url)
            .then((r) => r.json())
            .then((j) => {
                if (!j || !j.ok) throw new Error((j && j.error) ? j.error : 'Ошибка');
                const rows = Array.isArray(j.links) ? j.links : [];
                links = rows.map((l) => ({
                    poster_transaction_id: Number(l.poster_transaction_id || 0),
                    sepay_id: Number(l.sepay_id || 0),
                    link_type: String(l.link_type || ''),
                    is_manual: !!l.is_manual,
                }));
                drawLines();
                applyRowClasses();
                updateStats();
                applyHideLinked();
                setTimeout(() => { positionLines(); positionWidgets(); }, 0);
                setTimeout(() => { positionLines(); positionWidgets(); }, 200);
            });
    };

    const unlink = (sepayId, posterId) => {
        const url = `?dateFrom=${encodeURIComponent(window.PAYDAY_CONFIG.dateFrom)}&dateTo=${encodeURIComponent(window.PAYDAY_CONFIG.dateTo)}&ajax=unlink`;
        return fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ sepay_id: sepayId, poster_transaction_id: posterId }),
        })
            .then((r) => r.json())
            .then((j) => {
                if (!j || !j.ok) throw new Error((j && j.error) ? j.error : 'Ошибка');
                return refreshLinks();
            });
    };

    const drawLines = () => {
        ensureSvg();
        clearLines();
        syncButtons();
        if (!tablesRoot || !svgState.svg || !svgState.group) return;
        const rootRect = tablesRoot.getBoundingClientRect();
        const w = Math.max(1, Math.round(rootRect.width));
        const h = Math.max(1, Math.round(rootRect.height));
        
        const scrollW = tablesRoot.scrollWidth || w;
        svgState.svg.setAttribute('viewBox', `0 0 ${scrollW} ${h}`);
        svgState.svg.setAttribute('preserveAspectRatio', 'none');
        svgState.svg.style.width = scrollW + 'px';
        svgState.svg.style.height = h + 'px';

        widgets.forEach((btn) => { btn.style.display = 'none'; });

        const isVisibleInScrollY = (el, scrollEl) => {
            if (!el || !scrollEl) return false;
            const tr = el.closest('tr');
            if (!tr) return false;
            if (!tr.getClientRects().length) return false;
            if (tr.style.display === 'none') return false;
            const r = tr.getBoundingClientRect();
            const sr = scrollEl.getBoundingClientRect();
            return r.bottom >= sr.top && r.top <= sr.bottom;
        };

        const sepayCount = {};
        const posterCount = {};
        links.forEach((l) => {
            const sid = Number(l.sepay_id || 0);
            const pid = Number(l.poster_transaction_id || 0);
            if (sid) sepayCount[sid] = (sepayCount[sid] || 0) + 1;
            if (pid) posterCount[pid] = (posterCount[pid] || 0) + 1;
        });

        links.forEach((l) => {
            const s = document.getElementById('sepay-' + l.sepay_id);
            const p = document.getElementById('poster-' + l.poster_transaction_id);
            if (!s || !p) return;
            if (!s.getClientRects().length || !p.getClientRects().length) return;
            if (sepayScroll && !isVisibleInScrollY(s, sepayScroll)) return;
            if (posterScroll && !isVisibleInScrollY(p, posterScroll)) return;
            const isMany = (sepayCount[l.sepay_id] || 0) > 1 || (posterCount[l.poster_transaction_id] || 0) > 1;
            const isMainGreen = !isMany && !l.is_manual && l.link_type === 'auto_green';
            const size = 2;
            const color = colorFor(l.link_type, l.is_manual);

            const a0 = getAnchorPoint(s, 'right', rootRect);
            const b0 = getAnchorPoint(p, 'left', rootRect);
            
            if (a0.y < -50 || b0.y < -50 || a0.y > h + 50 || b0.y > h + 50) return;

            if (sepayScroll) {
                const sr = sepayScroll.getBoundingClientRect();
                a0.x = Math.max(sr.left - rootRect.left + tablesRoot.scrollLeft, Math.min(sr.right - rootRect.left + tablesRoot.scrollLeft, a0.x));
            }
            if (posterScroll) {
                const sr = posterScroll.getBoundingClientRect();
                b0.x = Math.max(sr.left - rootRect.left + tablesRoot.scrollLeft, Math.min(sr.right - rootRect.left + tablesRoot.scrollLeft, b0.x));
            }

            const clamp = (v, min, max) => Math.max(min, Math.min(max, v));
            const a = { x: clamp(a0.x, 0, w + tablesRoot.scrollLeft), y: clamp(a0.y, 0, h) };
            const b = { x: clamp(b0.x, 0, w + tablesRoot.scrollLeft), y: clamp(b0.y, 0, h) };

            const dx = b.x - a.x;
            const cdx = Math.min(120, Math.max(40, Math.abs(dx) * 0.35));
            const c1x = a.x + cdx;
            const c1y = a.y;
            const c2x = b.x - cdx;
            const c2y = b.y;
            const d = `M ${a.x} ${a.y} C ${c1x} ${c1y}, ${c2x} ${c2y}, ${b.x} ${b.y}`;

            const ns = 'http://www.w3.org/2000/svg';
            const outline = document.createElementNS(ns, 'path');
            outline.setAttribute('d', d);
            outline.setAttribute('fill', 'none');
            outline.setAttribute('stroke', 'rgba(255,255,255,0.65)');
            outline.setAttribute('stroke-width', String(size + 2));
            outline.setAttribute('stroke-linecap', 'round');
            outline.setAttribute('stroke-linejoin', 'round');
            svgState.group.appendChild(outline);

            const path = document.createElementNS(ns, 'path');
            path.setAttribute('d', d);
            path.setAttribute('fill', 'none');
            path.setAttribute('stroke', color);
            path.setAttribute('stroke-width', String(size));
            path.setAttribute('stroke-linecap', 'round');
            path.setAttribute('stroke-linejoin', 'round');
            svgState.group.appendChild(path);

            const key = String(l.sepay_id) + ':' + String(l.poster_transaction_id);
            const btn = widgets.get(key);
            if (btn) {
                const dxBtn = b.x - a.x;
                const dyBtn = b.y - a.y;
                const lenBtn = Math.hypot(dxBtn, dyBtn) || 1;
                const insetPx = 6;
                const tBtn = Math.min(0.99, Math.max(0.75, 1 - (insetPx / lenBtn)));
                const mx = a.x + dxBtn * tBtn;
                const my = a.y + dyBtn * tBtn;
                const localX = Math.max(8, Math.min(scrollW - 8, mx));
                const localY = Math.max(8, Math.min(h - 8, my));
                btn.style.left = Math.round(localX - 8) + 'px';
                btn.style.top = Math.round(localY - 8) + 'px';
                btn.style.display = 'flex';
            }
        });
    };

    const positionLines = () => {
        drawLines();
    };

    const positionWidgets = () => {
        return;
    };

    const tablesRoot = document.getElementById('tablesRoot');
    const lineLayer = document.getElementById('lineLayer');
    const sepayScroll = document.getElementById('sepayScroll');
    const posterScroll = document.getElementById('posterScroll');

    let relayoutRaf = 0;
    const scheduleRelayout = () => {
        if (relayoutRaf) return;
        relayoutRaf = requestAnimationFrame(() => {
            relayoutRaf = 0;
            positionLines();
            positionWidgets();
        });
    };
    const scheduleRelayoutBurst = () => {
        scheduleRelayout();
        setTimeout(scheduleRelayout, 50);
        setTimeout(scheduleRelayout, 200);
        setTimeout(scheduleRelayout, 600);
    };

    if (tablesRoot) {
        tablesRoot.addEventListener('scroll', () => scheduleRelayout(), { passive: true, capture: true });
    }
    if (sepayScroll) {
        sepayScroll.addEventListener('scroll', () => scheduleRelayout(), { passive: true });
    }
    if (posterScroll) {
        posterScroll.addEventListener('scroll', () => scheduleRelayout(), { passive: true });
    }
    pd2on(window, 'resize', () => scheduleRelayoutBurst(), { passive: true });
    pd2on(window, 'pageshow', () => scheduleRelayoutBurst(), { passive: true });
    try {
        if (window.visualViewport) {
            pd2on(window.visualViewport, 'resize', () => scheduleRelayoutBurst(), { passive: true });
            pd2on(window.visualViewport, 'scroll', () => scheduleRelayout(), { passive: true });
        }
    } catch (_) {}

    try {
        if (typeof ResizeObserver !== 'undefined') {
            const ro = new ResizeObserver(() => scheduleRelayoutBurst());
            if (tablesRoot) ro.observe(tablesRoot);
            if (sepayScroll) ro.observe(sepayScroll);
            if (posterScroll) ro.observe(posterScroll);
            pd2Signal.addEventListener('abort', () => {
                try { ro.disconnect(); } catch (_) {}
            });
        }
    } catch (_) {}

    pd2on(window, 'load', () => {
        drawLines();
        applyRowClasses();
        updateStats();
        applyHideLinked();
        scheduleRelayoutBurst();
    });

    const sepayTable = document.getElementById('sepayTable');
    const posterTable = document.getElementById('posterTable');
    // if (!sepayTable || !posterTable) return; // REMOVED early return

    const selectedSepay = new Set();
    const selectedPoster = new Set();

    const linkMakeBtn = document.getElementById('linkMakeBtn');
    const hideLinkedBtn = document.getElementById('hideLinkedBtn');
    const linkAutoBtn = document.getElementById('linkAutoBtn');
    const linkClearBtn = document.getElementById('linkClearBtn');
    const selSepaySumEl = document.getElementById('selSepaySum');
    const selPosterSumEl = document.getElementById('selPosterSum');
    const selMatchEl = document.getElementById('selMatch');
    const selDiffEl = document.getElementById('selDiff');

    let hideLinked = false;
    let hideVietnam = false;
    try { hideVietnam = localStorage.getItem('payday_hide_vietnam') === '1'; } catch (e) {}
    const toggleVietnamBtn = document.getElementById('toggleVietnamBtn');
    let showSepayHidden = false;
    try { showSepayHidden = localStorage.getItem('payday_show_sepay_hidden') === '1'; } catch (e) {}
    const toggleSepayHiddenBtn = document.getElementById('toggleSepayHiddenBtn');

    const updateSelectionSums = () => {
        let sSum = 0;
        selectedSepay.forEach((id) => {
            const tr = document.querySelector(`#sepayTable tbody tr[data-sepay-id="${Number(id)}"]`);
            if (!tr) return;
            sSum += Number(tr.getAttribute('data-sum') || 0) || 0;
        });
        let pSum = 0;
        selectedPoster.forEach((id) => {
            const tr = document.querySelector(`#posterTable tbody tr[data-poster-id="${Number(id)}"]`);
            if (!tr) return;
            pSum += Number(tr.getAttribute('data-total') || 0) || 0;
        });
        if (selSepaySumEl) selSepaySumEl.textContent = fmtVnd(sSum);
        if (selPosterSumEl) selPosterSumEl.textContent = fmtVnd(pSum);
        if (selDiffEl) {
            const diff = pSum - sSum;
            selDiffEl.textContent = fmtVnd(Math.abs(diff));
        }
        if (selMatchEl) {
            const ok = sSum === pSum;
            selMatchEl.textContent = ok ? '✅' : '❗';
            selMatchEl.style.color = ok ? '#16a34a' : '#dc2626';
        }
    };

    const updateLinkButtonState = () => {
        if (!linkMakeBtn) return;
        const ok = (selectedSepay.size > 0 && selectedPoster.size > 0 && !(selectedSepay.size > 1 && selectedPoster.size > 1));
        linkMakeBtn.disabled = !ok;
        updateSelectionSums();
    };

    const updateHideButtonState = () => {
        if (!hideLinkedBtn) return;
        hideLinkedBtn.classList.toggle('active', hideLinked);
    };

    const updateVietnamButtonState = () => {
        if (!toggleVietnamBtn) return;
        toggleVietnamBtn.classList.toggle('on', hideVietnam);
    };
    const updateSepayHiddenButtonState = () => {
        if (!toggleSepayHiddenBtn) return;
        toggleSepayHiddenBtn.classList.toggle('on', showSepayHidden);
    };

    const clearCheckboxes = () => {
        document.querySelectorAll('input.sepay-cb, input.poster-cb').forEach((cb) => {
            cb.checked = false;
        });
        selectedSepay.clear();
        selectedPoster.clear();
        updateLinkButtonState();
    };

    const setupSort = (table) => {
        const state = { key: null, dir: 'asc' };
        const ths = Array.from(table.querySelectorAll('th.sortable[data-sort-key]'));
        ths.forEach((th) => {
            th.style.cursor = 'pointer';
            th.addEventListener('click', () => {
                const key = (th.getAttribute('data-sort-key') || '').trim();
                if (!key) return;
                state.dir = (state.key === key && state.dir === 'asc') ? 'desc' : 'asc';
                state.key = key;

                const tbody = table.tBodies && table.tBodies[0] ? table.tBodies[0] : null;
                if (!tbody) return;
                const rows = Array.from(tbody.querySelectorAll('tr'));
                rows.sort((a, b) => {
                    const av = (a.dataset && a.dataset[key]) ? a.dataset[key] : '';
                    const bv = (b.dataset && b.dataset[key]) ? b.dataset[key] : '';
                    const na = Number(av);
                    const nb = Number(bv);
                    let cmp = 0;
                    if (av !== '' && bv !== '' && !Number.isNaN(na) && !Number.isNaN(nb)) {
                        cmp = na - nb;
                    } else {
                        cmp = String(av).localeCompare(String(bv), 'ru', { numeric: true, sensitivity: 'base' });
                    }
                    return state.dir === 'asc' ? cmp : -cmp;
                });
                rows.forEach((r) => tbody.appendChild(r));
                positionLines();
                positionWidgets();
            });
        });
    };

    if (sepayTable) setupSort(sepayTable);
    if (posterTable) setupSort(posterTable);

    const sendManualLinks = (sepayIds, posterIds) => {
        const url = `?dateFrom=${encodeURIComponent(window.PAYDAY_CONFIG.dateFrom)}&dateTo=${encodeURIComponent(window.PAYDAY_CONFIG.dateTo)}&ajax=manual_link`;
        return fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ sepay_ids: sepayIds, poster_transaction_ids: posterIds }),
        })
        .then((r) => r.json())
        .then((j) => {
            if (!j || !j.ok) throw new Error((j && j.error) ? j.error : 'Ошибка');
            return refreshLinks();
        });
    };

    const sendAutoLinks = () => {
        const url = `?dateFrom=${encodeURIComponent(window.PAYDAY_CONFIG.dateFrom)}&dateTo=${encodeURIComponent(window.PAYDAY_CONFIG.dateTo)}&ajax=auto_link`;
        return fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({}),
        })
        .then((r) => r.json())
        .then((j) => {
            if (!j || !j.ok) throw new Error((j && j.error) ? j.error : 'Ошибка');
            return refreshLinks();
        });
    };

    const sendClearLinks = () => {
        const url = `?dateFrom=${encodeURIComponent(window.PAYDAY_CONFIG.dateFrom)}&dateTo=${encodeURIComponent(window.PAYDAY_CONFIG.dateTo)}&ajax=clear_links`;
        return fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({}),
        })
        .then((r) => r.json())
        .then((j) => {
            if (!j || !j.ok) throw new Error((j && j.error) ? j.error : 'Ошибка');
            return refreshLinks();
        });
    };

    const applyHideLinked = () => {
        const state = buildLinkState();
        document.querySelectorAll('#sepayTable tbody tr[data-sepay-id]').forEach((tr) => {
            const sid = Number(tr.getAttribute('data-sepay-id') || 0);
            const linked = state.sepay.has(sid);
            const isHiddenRow = String(tr.getAttribute('data-hidden') || '0') === '1';
            const hidden = (hideLinked && linked) || (isHiddenRow && !showSepayHidden);
            tr.style.display = hidden ? 'none' : '';
            if (hidden) {
                const cb = tr.querySelector('input.sepay-cb');
                if (cb) cb.checked = false;
                selectedSepay.delete(sid);
            }
        });
        document.querySelectorAll('#posterTable tbody tr[data-poster-id]').forEach((tr) => {
            const isVietnam = String(tr.getAttribute('data-vietnam') || '0') === '1';
            const pid = Number(tr.getAttribute('data-poster-id') || 0);
            const linked = state.poster.has(pid);
            const hidden = (hideLinked && linked) || (hideVietnam && isVietnam);
            tr.style.display = hidden ? 'none' : '';
            if (hidden) {
                const cb = tr.querySelector('input.poster-cb');
                if (cb) cb.checked = false;
                selectedPoster.delete(pid);
            }
        });
        updateLinkButtonState();
        updateStats();
    };

    document.querySelectorAll('input.sepay-cb').forEach((cb) => {
        cb.addEventListener('change', () => {
            const id = Number(cb.getAttribute('data-id') || 0);
            if (!id) return;
            if (cb.checked) selectedSepay.add(id);
            else selectedSepay.delete(id);
            updateLinkButtonState();
        });
    });
    document.querySelectorAll('button.sepay-hide').forEach((btn) => {
        btn.addEventListener('click', () => {
            const sepayId = Number(btn.getAttribute('data-sepay-id') || 0);
            if (!sepayId) return;
            const comment = prompt('Комментарий (почему скрываем этот платеж):', '');
            if (comment === null) return;
            const c = String(comment || '').trim();
            if (!c) return;
            fetch('?ajax=sepay_hide', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ sepay_id: sepayId, comment: c }),
            })
            .then((r) => r.json())
            .then((j) => {
                if (!j || !j.ok) throw new Error((j && j.error) ? j.error : 'Ошибка');
                const tr = btn.closest('tr');
                if (tr) {
                    tr.classList.add('row-hidden');
                    tr.setAttribute('data-hidden', '1');
                    tr.setAttribute('data-content', String(c).toLowerCase());
                    const td = tr.querySelector('td.col-sepay-content');
                    if (td) td.textContent = c;
                }
                selectedSepay.delete(sepayId);
                updateStats();
                applyHideLinked();
                drawLines();
                try { scheduleRelayoutBurst(); } catch (e) {}
            })
            .catch((e) => alert(e && e.message ? e.message : 'Ошибка'));
        });
    });
    document.querySelectorAll('form.finance-transfer').forEach((form) => {
        form.addEventListener('submit', (e) => {
            e.preventDefault();
            const btn = form.querySelector('button[type="submit"]');
            const statusEl = form.querySelector('.finance-status');
            if (btn && btn.disabled) return;
            const kind = String(form.getAttribute('data-kind') || '');
            const dateFrom = String(form.getAttribute('data-date-from') || '');
            const dateTo = String(form.getAttribute('data-date-to') || '');
            if (!kind || !dateFrom || !dateTo) return;
            const creatorEmail = String(window.__USER_EMAIL__ || '').trim();
            const commentBase = (kind === 'vietnam') ? 'Перевод чеков вьетнаской компании' : 'Перевод типсов';
            const comment = creatorEmail ? (commentBase + ' by ' + creatorEmail) : commentBase;
            const accFromName = String(form.getAttribute('data-account-from-name') || '#1');
            const accToName = String(form.getAttribute('data-account-to-name') || (kind === 'vietnam' ? '#9' : '#8'));
            const sumVnd = Number(form.getAttribute('data-sum-vnd') || 0);
            const sumTxt = sumVnd ? (Math.round(Number(sumVnd)).toLocaleString('en-US').replace(/,/g, '\u202F')) : '—';

            const openConfirm = () => new Promise((resolve) => {
                const backdrop = document.getElementById('financeConfirm');
                const text = document.getElementById('financeConfirmText');
                const cb = document.getElementById('financeConfirmChecked');
                const ok = document.getElementById('financeConfirmOk');
                const cancel = document.getElementById('financeConfirmCancel');
                if (!backdrop || !text || !cb || !ok || !cancel) return resolve(false);
                text.innerHTML =
                    `Будет создан перевод в Poster.<br>` +
                    `Счет списания: <b>${escapeHtml(accFromName)}</b><br>` +
                    `Счет зачисления: <b>${escapeHtml(accToName)}</b><br>` +
                    `Сумма: <b>${escapeHtml(sumTxt)}</b><br>` +
                    `Комментарий: <b>${escapeHtml(comment)}</b><br>` +
                    `Создатель: <b>${escapeHtml(creatorEmail || '—')}</b>`;
                cb.checked = false;
                ok.disabled = true;
                backdrop.style.display = 'flex';

                const close = (v) => {
                    backdrop.style.display = 'none';
                    cancel.removeEventListener('click', onCancel);
                    ok.removeEventListener('click', onOk);
                    cb.removeEventListener('change', onCb);
                    backdrop.removeEventListener('click', onBg);
                    document.removeEventListener('keydown', onEsc, true);
                    resolve(v);
                };
                const onCb = () => { ok.disabled = !cb.checked; };
                const onCancel = () => close(false);
                const onOk = () => close(true);
                const onBg = (ev) => { if (ev.target === backdrop) close(false); };
                const onEsc = (ev) => { if (ev.key === 'Escape' && backdrop.style.display === 'flex') close(false); };

                cb.addEventListener('change', onCb);
                cancel.addEventListener('click', onCancel);
                ok.addEventListener('click', onOk);
                backdrop.addEventListener('click', onBg);
                pd2on(document, 'keydown', onEsc, { capture: true });
                cancel.focus();
            });

            openConfirm().then((confirmed) => {
                if (!confirmed) return;
                if (btn) {
                    btn.classList.add('loading');
                    btn.disabled = true;
                }
                fetch('?ajax=create_transfer', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ kind, dateFrom, dateTo }),
                })
                .then((r) => r.json())
                .then((j) => {
                    if (!j || !j.ok) throw new Error((j && j.error) ? j.error : 'Ошибка');
                    if (btn) {
                        btn.classList.remove('loading');
                        btn.disabled = true;
                    }
                    if (typeof refreshFinanceForm === 'function') {
                        refreshFinanceForm(form, { showLoading: false });
                    }
                })
                .catch((err) => {
                    const msg = err && err.message ? err.message : 'Ошибка';
                    if (statusEl) statusEl.textContent = msg;
                    if (btn) {
                        btn.classList.remove('loading');
                        btn.disabled = false;
                    }
                });
            });
        });
    });
    const renderFinanceTable = (form, rows) => {
        const expectedSum = Number(form.getAttribute('data-sum-vnd') || 0);
        const statusEl = form.querySelector('.finance-status');
        if (!statusEl) return;

        const pad = (n) => String(n).padStart(2, '0');
        const fmtSum = (v) => Math.round(Number(v || 0)).toLocaleString('en-US').replace(/,/g, '\u202F');

        // Check if there is an exact amount match
        const exactMatchExists = rows.some(r => Math.abs(Number(r.sum || 0)) === expectedSum);

        const btnSubmit = form.querySelector('button[type="submit"]');
        if (exactMatchExists) {
            if (btnSubmit) btnSubmit.style.display = 'none';
        } else {
            if (btnSubmit) btnSubmit.style.display = '';
        }
        
        if (!rows.length) {
            statusEl.innerHTML = '<span style="color:var(--muted);">Транзакция не найдена</span>';
            return;
        }

        let html = '<div style="overflow-x:auto; max-width:100%;">';
        html += '<table class="table" style="margin-top:5px; font-size:12px; width:100%;">';
        html += '<thead><tr><th style="padding:2px 4px;">Дата<br><span style="font-weight:normal;">Время</span></th><th style="padding:2px 4px;">Сумма</th><th style="padding:2px 4px;">Счет</th><th style="padding:2px 4px;">Кто</th><th style="padding:2px 4px;">Комментарий</th></tr></thead><tbody>';
        rows.forEach((x) => {
            const ts = Number(x.ts || 0);
            const d = ts ? new Date(ts * 1000) : null;
            const dateStr = d ? `${pad(d.getDate())}.${pad(d.getMonth() + 1)}.${d.getFullYear()}` : '';
            const timeStr = d ? `${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}` : '';
            const typeRaw = String(x.type || '');
            const isOut = (typeRaw === '0' || typeRaw.toUpperCase() === 'O' || typeRaw.toLowerCase() === 'out');
            const sumSigned = isOut ? -Number(x.sum || 0) : Number(x.sum || 0);
            const comment = String(x.comment || '').trim();
            const user = String(x.user || '').trim();
            const account = String(x.account || '').trim();
            html += '<tr>';
            html += `<td style="padding:2px 4px; white-space:nowrap;">${escapeHtml(dateStr)}<br><span class="muted">${escapeHtml(timeStr)}</span></td>`;
            html += `<td class="sum" style="padding:2px 4px; white-space:nowrap;">${escapeHtml(fmtSum(sumSigned))}</td>`;
            html += `<td style="padding:2px 4px; white-space:nowrap;">${escapeHtml(account)}</td>`;
            html += `<td style="padding:2px 4px; white-space:nowrap;">${escapeHtml(user)}</td>`;
            html += `<td style="padding:2px 4px; line-height:1.2;">${escapeHtml(comment)}</td>`;
            html += '</tr>';
        });
        html += '</tbody></table></div>';
        statusEl.innerHTML = html;
    };

    window.refreshFinanceForm = (form, opts) => {
        const options = opts && typeof opts === 'object' ? opts : {};
        const showLoading = options.showLoading !== false;
        const kind = String(form.getAttribute('data-kind') || '');
        const dateFrom = String(form.getAttribute('data-date-from') || '');
        const dateTo = String(form.getAttribute('data-date-to') || '');
        const accountFrom = Number(form.getAttribute('data-account-from-id') || 0);
        const accountTo = Number(form.getAttribute('data-account-to-id') || 0);
        const statusEl = form.querySelector('.finance-status');
        if (!kind || !dateFrom || !dateTo || !accountFrom || !accountTo || !statusEl) return Promise.resolve();

        if (showLoading) statusEl.innerHTML = '<span style="color:var(--muted);">Обновление...</span>';
        return fetch('?ajax=refresh_finance_transfers', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ kind, dateFrom, dateTo, accountFrom, accountTo }),
        })
        .then((r) => r.json())
        .then((j) => {
            if (!j || !j.ok) throw new Error((j && j.error) ? j.error : 'Ошибка');
            const rows = Array.isArray(j.rows) ? j.rows : [];
            renderFinanceTable(form, rows);
        })
        .catch((e) => {
            statusEl.textContent = e && e.message ? e.message : 'Ошибка';
        });
    };

    const refreshAllBtn = document.getElementById('finance-refresh-all');
    if (refreshAllBtn) {
        refreshAllBtn.addEventListener('click', async (e) => {
            const btn = e.currentTarget;
            if (btn.disabled) return;
            const orig = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '...';
            try {
                const forms = document.querySelectorAll('form.finance-transfer');
                for (const form of forms) {
                    const statusEl = form.querySelector('.finance-status');
                    if (statusEl) statusEl.innerHTML = '<span style="color:var(--muted);">Обновление...</span>';
                }
                for (const form of forms) {
                    await window.refreshFinanceForm(form, { showLoading: false });
                }
            } finally {
                btn.disabled = false;
                btn.innerHTML = orig;
            }
        });
    }
    document.querySelectorAll('input.poster-cb').forEach((cb) => {
        cb.addEventListener('change', () => {
            const id = Number(cb.getAttribute('data-id') || 0);
            if (!id) return;
            if (cb.checked) selectedPoster.add(id);
            else selectedPoster.delete(id);
            updateLinkButtonState();
        });
    });

    if (linkMakeBtn) {
        linkMakeBtn.addEventListener('click', async () => {
            const sepayIds = Array.from(selectedSepay.values()).map((v) => Number(v)).filter((v) => v > 0);
            const posterIds = Array.from(selectedPoster.values()).map((v) => Number(v)).filter((v) => v > 0);
            if (!sepayIds.length || !posterIds.length) return;
            if (sepayIds.length > 1 && posterIds.length > 1) {
                alert('Нельзя: выбери 1 платеж и много чеков или 1 чек и много платежей.');
                return;
            }
            const restore = setBtnBusy(linkMakeBtn, { title: '🎯', pct: 0 });
            try {
                await sendManualLinks(sepayIds, posterIds);
                clearCheckboxes();
            } catch (e) {
                alert(e && e.message ? e.message : 'Ошибка');
            } finally {
                restore();
            }
        });
    }
    if (hideLinkedBtn) {
        hideLinkedBtn.addEventListener('click', () => {
            hideLinked = !hideLinked;
            updateHideButtonState();
            applyHideLinked();
            drawLines();
            setTimeout(() => { positionLines(); positionWidgets(); }, 0);
        });
        updateHideButtonState();
    }
    if (toggleVietnamBtn) {
        toggleVietnamBtn.addEventListener('click', () => {
            hideVietnam = !hideVietnam;
            try { localStorage.setItem('payday_hide_vietnam', hideVietnam ? '1' : '0'); } catch (e) {}
            updateVietnamButtonState();
            applyHideLinked();
            drawLines();
            setTimeout(() => { positionLines(); positionWidgets(); }, 0);
        });
        updateVietnamButtonState();
    }
    if (toggleSepayHiddenBtn) {
        toggleSepayHiddenBtn.addEventListener('click', () => {
            showSepayHidden = !showSepayHidden;
            try { localStorage.setItem('payday_show_sepay_hidden', showSepayHidden ? '1' : '0'); } catch (e) {}
            updateSepayHiddenButtonState();
            applyHideLinked();
            drawLines();
            setTimeout(() => { positionLines(); positionWidgets(); }, 0);
        });
        updateSepayHiddenButtonState();
    }
    if (linkAutoBtn) {
        linkAutoBtn.addEventListener('click', async () => {
            const restore = setBtnBusy(linkAutoBtn, { title: '🧩', pct: 0 });
            try {
                await sendAutoLinks();
                clearCheckboxes();
            } catch (e) {
                alert(e && e.message ? e.message : 'Ошибка');
            } finally {
                restore();
            }
        });
    }
    if (linkClearBtn) {
        linkClearBtn.addEventListener('click', async () => {
            if (!confirm('Удалить все связи за день?')) return;
            const restore = setBtnBusy(linkClearBtn, { title: '⛓️‍💥', pct: 0 });
            try {
                await sendClearLinks();
                clearCheckboxes();
            } catch (e) {
                alert(e && e.message ? e.message : 'Ошибка');
            } finally {
                restore();
            }
        });
    }
    updateLinkButtonState();

    pd2on(document, 'keydown', (e) => {
        if (e.key === 'Escape') clearCheckboxes();
    });

    const btnKashShift = document.getElementById('btnKashShift');
    const kashshiftModal = document.getElementById('kashshiftModal');
    const kashshiftClose = document.getElementById('kashshiftClose');
    const kashshiftBody = document.getElementById('kashshiftBody');

    if (btnKashShift && kashshiftModal) {
        btnKashShift.addEventListener('click', () => {
            kashshiftModal.style.display = 'flex';
            kashshiftBody.innerHTML = '<div style="text-align:center;">Загрузка...</div>';
            
            const dFrom = document.querySelector('input[name="dateFrom"]').value || '';
            const dTo = document.querySelector('input[name="dateTo"]').value || '';
            
            const url = '?ajax=kashshift&dateFrom=' + encodeURIComponent(dFrom) + '&dateTo=' + encodeURIComponent(dTo);
            fetchJsonSafe(url).then(res => {
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
            html += '<div style="display:flex; justify-content:flex-end; margin-top:10px;">';
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
        try {
            const url = '?ajax=poster_checks_list&date_from=' + encodeURIComponent(dFrom) + '&date_to=' + encodeURIComponent(dTo);
            const j = await fetchJsonSafe(url);
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
        } catch (e) {
            checkFinderShowError(e && e.message ? e.message : 'Ошибка');
        }
    };

    if (checkFinderBtn && checkFinderModal) {
        checkFinderBtn.addEventListener('click', () => { openCheckFinder(); loadChecks().catch(() => {}); });
        if (checkFinderClose) checkFinderClose.addEventListener('click', closeCheckFinder);
        checkFinderModal.addEventListener('click', (e) => { if (e.target === checkFinderModal) closeCheckFinder(); });
        document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && checkFinderModal.style.display === 'flex') closeCheckFinder(); });
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
    
    window.toggleShiftDetail = function(tr, shiftId) {
        const detailTr = document.getElementById('shift_detail_' + shiftId);
        if (!detailTr) return;
        if (detailTr.style.display === 'none') {
            detailTr.style.display = 'table-row';
            const contentDiv = detailTr.querySelector('.shift-detail-content');
            if (contentDiv && contentDiv.innerHTML === 'Загрузка...') {
                fetchJsonSafe('?ajax=kashshift_detail&shiftId=' + encodeURIComponent(shiftId))
                    .then(res => {
                        if (!res.ok) throw new Error(res.error || 'Ошибка загрузки транзакций смены');
                        const arr = res.data;
                        if (!Array.isArray(arr) || arr.length === 0) {
                            contentDiv.innerHTML = '<div style="color:var(--muted);">Нет транзакций в этой смене</div>';
                            return;
                        }
                        
                        let h = '<div style="overflow-x:auto;"><table style="width:100%; border-collapse:collapse; white-space:nowrap; font-size:13px; background:var(--card);"><thead><tr>';
                        h += '<th style="text-align:left; border-bottom:1px solid var(--border); padding:6px; width:1%;">Дата</th>';
                        h += '<th style="text-align:left; border-bottom:1px solid var(--border); padding:6px; width:1%;">Тип</th>';
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
                            // Если число небольшое, значит это секунды
                            if (!isNaN(ts) && ts > 0 && String(Math.floor(ts)).length === 10) {
                                ts = ts * 1000;
                            }
                            const d = new Date(ts);
                            if (isNaN(d.getTime())) return tsStr;
                            const p = n => String(n).padStart(2, '0');
                            return `${p(d.getDate())}.${p(d.getMonth()+1)}.${d.getFullYear()} ${p(d.getHours())}:${p(d.getMinutes())}`;
                        };

                        arr.forEach(tx => {
                            const isDeleted = Number(tx.delete) === 1;
                            const trStyle = isDeleted ? 'text-decoration: line-through;' : '';
                            h += `<tr style="${trStyle}">`;
                            h += '<td style="border-bottom:1px solid var(--border); padding:6px; width:1%;">' + escapeHtml(formatDate(tx.time)) + '</td>';
                            h += '<td style="border-bottom:1px solid var(--border); padding:6px; width:1%;">' + getTypeLabel(Number(tx.type)) + '</td>';
                            
                            // В API поле суммы называется tr_amount
                            const rawAmount = tx.tr_amount || tx.amount || 0;
                            h += '<td style="text-align:right; border-bottom:1px solid var(--border); padding:6px; width:1%; font-weight:bold;">' + fmtVnd0(posterMinorToVnd(rawAmount)) + '</td>';
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

    const btnSupplies = document.getElementById('btnSupplies');
    const suppliesModal = document.getElementById('suppliesModal');
    const suppliesClose = document.getElementById('suppliesClose');
    const suppliesBody = document.getElementById('suppliesBody');

    if (btnSupplies && suppliesModal) {
        btnSupplies.addEventListener('click', () => {
            suppliesModal.style.display = 'flex';
            suppliesBody.innerHTML = '<div style="text-align:center;">Загрузка...</div>';
            
            const dFrom = document.querySelector('input[name="dateFrom"]').value || '';
            const dTo = document.querySelector('input[name="dateTo"]').value || '';
            
            const url = '?ajax=supplies&dateFrom=' + encodeURIComponent(dFrom) + '&dateTo=' + encodeURIComponent(dTo);
            fetchJsonSafe(url).then(res => {
                if (!res.ok) throw new Error(res.error || 'Ошибка');
                
                const accMap = {};
                if (res.accounts) {
                    res.accounts.forEach(a => {
                        // Poster API finance.getAccounts возвращает account_name
                        accMap[a.account_id] = a.account_name || a.name;
                    });
                }
                
                if (!res.supplies || res.supplies.length === 0) {
                    suppliesBody.innerHTML = '<div style="text-align:center; padding:15px; color:var(--muted);">Нет данных за период</div>';
                    return;
                }
                
                // Сбор всех уникальных ключей для динамических колонок
                const allKeys = new Set();
                const ignoredKeys = ['supply_sum_netto', 'supplier_id', 'storage_id', 'delete', 'supply_comment'];
                res.supplies.forEach(row => {
                    Object.keys(row).forEach(k => {
                        if (!ignoredKeys.includes(k)) {
                            allKeys.add(k);
                        }
                    });
                });
                const keys = Array.from(allKeys);
                
                // Заменяем account_id на Название Счета
                const displayKeys = keys.map(k => k === 'account_id' ? 'Название Счета' : k);
                
                let html = '<div style="overflow-x:auto;"><table style="width:100%; border-collapse:collapse; white-space:nowrap; font-size:13px;"><thead><tr>';
                displayKeys.forEach(k => {
                    html += '<th style="text-align:left; border-bottom:1px solid var(--border); padding:6px; background:var(--card);">' + escapeHtml(k) + '</th>';
                });
                html += '</tr></thead><tbody>';
                
                res.supplies.forEach(row => {
                    html += '<tr>';
                    keys.forEach(k => {
                        let val = row[k];
                        if (val === null || val === undefined) val = '';
                        
                        // Специальная обработка для account_id
                        if (k === 'account_id') {
                            const accountId = row.account_id || (row.payed_sum && row.payed_sum.length > 0 ? row.payed_sum[0].account_id : null);
                            if (accountId && accMap[accountId]) {
                                val = accMap[accountId];
                            } else if (accountId) {
                                val = accountId;
                            }
                        }
                        
                        // Форматирование supply_sum и total_sum (в копейках -> без копеек с пробелами)
                        if ((k === 'supply_sum' || k === 'total_sum') && val !== '') {
                            val = fmtVnd0(posterMinorToVnd(val));
                        }
                        
                        // Если значение объект/массив, выводим как JSON
                        if (typeof val === 'object') {
                            val = JSON.stringify(val);
                        }
                        
                        html += '<td style="border-bottom:1px solid var(--border); padding:6px;">' + escapeHtml(val) + '</td>';
                    });
                    html += '</tr>';
                });
                
                html += '</tbody></table></div>';
                suppliesBody.innerHTML = html;
            }).catch(e => {
                suppliesBody.innerHTML = '<div class="error">' + escapeHtml(e.message) + '</div>';
            });
        });
        
        suppliesClose.addEventListener('click', () => {
            suppliesModal.style.display = 'none';
        });
        
        suppliesModal.addEventListener('click', (e) => {
            if (e.target === suppliesModal) {
                suppliesModal.style.display = 'none';
            }
        });
    }

    document.querySelectorAll('form.finance-transfer').forEach((form) => {
        if (window.refreshFinanceForm) window.refreshFinanceForm(form, { showLoading: false });
    });

    if (document.readyState === 'loading') {
        pd2on(document, 'DOMContentLoaded', () => {
            drawLines();
            applyRowClasses();
            updateStats();
            applyHideLinked();
            scheduleRelayoutBurst();
        });
    } else {
        drawLines();
        applyRowClasses();
        updateStats();
        applyHideLinked();
        scheduleRelayoutBurst();
    }
};
window.initPayday2();
