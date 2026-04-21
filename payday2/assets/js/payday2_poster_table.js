window.initPayday2_PosterTable = function() {
    const outPosterTable = document.getElementById('outPosterTable');
    if (!outPosterTable) return;

    const escapeHtml = (s) => String(s)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');

    const posterMinorToVnd = (n) => {
        const val = Number(n);
        return isNaN(val) ? 0 : val / 100;
    };

    const fmtVnd0 = (n) => {
        const val = Number(n);
        if (isNaN(val)) return '0';
        return val.toLocaleString('ru-RU', { minimumFractionDigits: 0, maximumFractionDigits: 0 }).replace(/,/g, ' ');
    };

    const formatOutDT = (tsStr, dateStr) => {
        if (dateStr) {
            const parts = dateStr.split(' ');
            if (parts.length === 2) return { date: parts[0], time: parts[1].substring(0, 5) };
            return { date: dateStr, time: '' };
        }
        let ts = Number(tsStr);
        if (isNaN(ts) || ts <= 0) return { date: '', time: '' };
        if (String(Math.floor(ts)).length === 10) ts *= 1000;
        const d = new Date(ts);
        if (isNaN(d.getTime())) return { date: '', time: '' };
        const p = (n) => String(n).padStart(2, '0');
        return {
            date: `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`,
            time: `${p(d.getHours())}:${p(d.getMinutes())}`
        };
    };

    const getDateRange = () => {
        const dFromEl = document.querySelector('input[name="dateFrom"]');
        const dToEl = document.querySelector('input[name="dateTo"]');
        const dFrom = (dFromEl && dFromEl.value) ? String(dFromEl.value) : String(window.PAYDAY_CONFIG?.dateFrom || '');
        const dToRaw = (dToEl && dToEl.value) ? String(dToEl.value) : String(window.PAYDAY_CONFIG?.dateTo || '');
        const dTo = dToRaw || dFrom;
        return { dateFrom: dFrom, dateTo: dTo };
    };

    let employeesMap = null;
    let categoriesMap = null;

    const fetchJsonSafe = (url) => fetch(url).then(async (r) => { const txt = await r.text(); let j; try { j = JSON.parse(txt); } catch (e) { throw new Error('Bad JSON: ' + (txt || '(empty)')); } return j; });

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

    window.loadOutFinance = function(onProgress) {
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
            
            const tbody = outPosterTable.tBodies[0]; 
            tbody.innerHTML = '';
            
            (j.rows || []).forEach((row) => {
                const rawAmount = Number(row.amount || 0);
                const sign = rawAmount > 0 ? '+' : (rawAmount < 0 ? '−' : '');
                const amountVnd = posterMinorToVnd(Math.abs(rawAmount));
                const balanceVnd = posterMinorToVnd(Math.abs(Number(row.balance || 0)));
                const amountInt = Math.round(amountVnd);
                const balanceInt = Math.round(balanceVnd);
                const userName = String(emps && emps[Number(row.user_id || 0)] ? emps[Number(row.user_id || 0)] : row.user_id || '');
                
                let catName = '';
                const catId = Number(row.category_id || 0);
                const catObj = cats && cats[catId] ? cats[catId] : null;
                
                // Get custom names from localSettings directly to be robust
                const ls = window.PAYDAY_CONFIG?.localSettings || {};
                const customCatNames = ls.custom_category_names || window.PAYDAY_CONFIG?.catNames || {};
                
                if (customCatNames[catId] || customCatNames[String(catId)]) {
                    catName = String(customCatNames[catId] || customCatNames[String(catId)]);
                } else if (catObj && typeof catObj === 'object' && catObj.name) {
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
            
            if (typeof window.applyOutRowClasses === 'function') window.applyOutRowClasses();
            if (typeof window.applyOutHideLinked === 'function') window.applyOutHideLinked();
            if (typeof onProgress === 'function') onProgress(100, 'Poster: готово');
        });
    };
};