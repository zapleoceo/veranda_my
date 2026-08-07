/* /cashflowreport — drill-down: клик по ячейке → категории/чеки/позиции/формула.
   Ваниль, без зависимостей. Данные берутся с того же роута ?ajax=… (JSON). */
(function () {
    'use strict';

    var BASE = '/cashflowreport';

    function fmt(n) {
        n = Math.round(Number(n) || 0);
        var neg = n < 0, s = Math.abs(n).toString(), out = '';
        while (s.length > 3) { out = ' ' + s.slice(-3) + out; s = s.slice(0, -3); }
        return (neg ? '-' : '') + s + out;
    }
    function ruDate(iso) {
        var p = String(iso || '').split('-');
        return p.length === 3 ? p[2] + '.' + p[1] + '.' + p[0] : iso;
    }
    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
        });
    }
    function elp(tag, cls, html) {
        var e = document.createElement(tag);
        if (cls) e.className = cls;
        if (html != null) e.innerHTML = html;
        return e;
    }

    var overlay, titleEl, bodyEl, backBtn, backFn = null;
    function ensureModal() {
        if (overlay) return;
        overlay = elp('div', 'cf-modal-overlay');
        var panel = elp('div', 'cf-modal');
        var head = elp('div', 'cf-modal-head');
        backBtn = elp('button', 'cf-modal-back', '&larr;');
        backBtn.style.visibility = 'hidden';
        titleEl = elp('div', 'cf-modal-title', '');
        var close = elp('button', 'cf-modal-close', '&#10005;');
        head.appendChild(backBtn); head.appendChild(titleEl); head.appendChild(close);
        bodyEl = elp('div', 'cf-modal-body', '');
        panel.appendChild(head); panel.appendChild(bodyEl);
        overlay.appendChild(panel);
        document.body.appendChild(overlay);
        overlay.addEventListener('click', function (e) { if (e.target === overlay) hide(); });
        close.addEventListener('click', hide);
        backBtn.addEventListener('click', function () { if (backFn) backFn(); });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') hide(); });
    }
    function show(title, back) {
        ensureModal();
        titleEl.textContent = title;
        backFn = back || null;
        backBtn.style.visibility = back ? 'visible' : 'hidden';
        overlay.classList.add('open');
    }
    function hide() {
        if (!overlay) return;
        overlay.classList.remove('open');
        bodyEl.innerHTML = '';
        backFn = null;
    }
    function loading() { bodyEl.innerHTML = '<div class="cf-modal-loading">Загрузка…</div>'; }
    function errBox(m) { bodyEl.innerHTML = '<div class="cf-modal-err">' + esc(m) + '</div>'; }

    function api(params) {
        var qs = Object.keys(params).map(function (k) {
            return encodeURIComponent(k) + '=' + encodeURIComponent(params[k]);
        }).join('&');
        return fetch(BASE + '?' + qs, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json().catch(function () { throw new Error('Ошибка ответа сервера'); }); })
            .then(function (j) { if (!j || !j.ok) throw new Error((j && j.error) || 'Ошибка'); return j; });
    }

    // L1 — ячейка выручки → формула + категории дня
    function openRevenue(date, key, label) {
        show(label + ' · ' + ruDate(date));
        loading();
        api({ ajax: 'day', date: date }).then(function (j) {
            var h = '';
            if (key === 'hookah') {
                h += '<div class="cf-formula">Кальяны за день: <b>' + fmt(j.hookah) + ' ₫</b>' +
                     (j.hookahCount ? ' · ' + j.hookahCount + ' шт' : '') + '</div>';
            } else {
                h += '<div class="cf-formula">Итого по Poster <b>' + fmt(j.total) + '</b> − Кальяны <b>' +
                     fmt(j.hookah) + '</b> = Еда <b>' + fmt(j.food) + '</b> ₫</div>';
            }
            h += '<table class="cf-mtable"><thead><tr><th>Категория</th><th class="r">Выручка</th><th class="r">Поз.</th></tr></thead><tbody>';
            (j.categories || []).forEach(function (c) {
                h += '<tr' + (c.hookah ? ' class="cf-hl"' : '') + '><td>' + esc(c.name) +
                     '</td><td class="r">' + fmt(c.revenue) + '</td><td class="r">' + c.count + '</td></tr>';
            });
            h += '</tbody></table><button class="btn btn-primary btn-sm cf-more">Показать чеки дня →</button>';
            bodyEl.innerHTML = h;
            var more = bodyEl.querySelector('.cf-more');
            if (more) more.addEventListener('click', function () {
                openChecks(date, function () { openRevenue(date, key, label); });
            });
        }).catch(function (e) { errBox(e.message); });
    }

    // L2 + L3 — чеки дня, позиции по клику на чек
    function openChecks(date, back) {
        show('Чеки · ' + ruDate(date), back);
        loading();
        api({ ajax: 'checks', date: date }).then(function (j) {
            var checks = j.checks || [];
            if (!checks.length) { bodyEl.innerHTML = '<div class="cf-modal-empty">Чеков нет</div>'; return; }
            var h = '<table class="cf-mtable cf-checks"><thead><tr><th>Время</th><th>№</th><th>Официант</th><th>Стол</th><th class="r">Сумма</th><th>Оплата</th></tr></thead><tbody>';
            checks.forEach(function (c, i) {
                var pay = c.card > 0 && c.cash > 0 ? 'нал+карта' : (c.card > 0 ? 'карта' : (c.cash > 0 ? 'нал' : '—'));
                h += '<tr class="cf-check-row" data-i="' + i + '"><td>' + esc(c.time) + '</td><td>' + c.id +
                     '</td><td>' + esc(c.waiter) + '</td><td>' + esc(c.table) + '</td><td class="r">' + fmt(c.sum) +
                     '</td><td>' + pay + '</td></tr>';
                h += '<tr class="cf-items-row" data-i="' + i + '" hidden><td colspan="6"><div class="cf-items"></div></td></tr>';
            });
            h += '</tbody></table>';
            bodyEl.innerHTML = h;
            bodyEl.querySelectorAll('.cf-check-row').forEach(function (row) {
                row.addEventListener('click', function () {
                    var i = row.getAttribute('data-i');
                    var ir = bodyEl.querySelector('.cf-items-row[data-i="' + i + '"]');
                    if (!ir) return;
                    if (!ir.hasAttribute('hidden')) { ir.setAttribute('hidden', ''); return; }
                    var box = ir.querySelector('.cf-items');
                    if (!box.dataset.filled) {
                        var items = checks[i].items || [];
                        box.innerHTML = items.length
                            ? '<table class="cf-itable"><tbody>' + items.map(function (it) {
                                return '<tr><td>' + esc(it.name) + '</td><td class="r">' + esc(it.qty) +
                                       '</td><td class="r">' + fmt(it.sum) + '</td></tr>';
                              }).join('') + '</tbody></table>'
                            : '<div class="cf-modal-empty">Нет позиций</div>';
                        box.dataset.filled = '1';
                    }
                    ir.removeAttribute('hidden');
                });
            });
        }).catch(function (e) { errBox(e.message); });
    }

    // L1′ — ячейка расхода/дохода → строки финмодуля
    function openExpenses(date, key, label) {
        show(label + ' · ' + ruDate(date));
        loading();
        api({ ajax: 'expenses', date: date, column: key }).then(function (j) {
            var rows = j.rows || [];
            if (!rows.length) {
                bodyEl.innerHTML = '<div class="cf-modal-empty">В этот день по колонке «' + esc(label) + '» операций нет</div>';
                return;
            }
            var h = '<table class="cf-mtable"><thead><tr><th>Время</th><th>Категория</th><th class="r">Сумма</th><th>Комментарий</th></tr></thead><tbody>';
            rows.forEach(function (r) {
                h += '<tr><td>' + esc(r.time) + '</td><td>' + esc(r.category) + '</td><td class="r' +
                     (r.amount < 0 ? ' cf-neg' : '') + '">' + fmt(r.amount) + '</td><td>' + esc(r.comment) + '</td></tr>';
            });
            h += '</tbody></table><div class="cf-modal-note">Источник: финмодуль Poster. Отрицательные — расход.</div>';
            bodyEl.innerHTML = h;
        }).catch(function (e) { errBox(e.message); });
    }

    // L1″ — ячейка прибыли → формула по значениям строки (без запроса)
    function openProfit(rowEl, date) {
        show('Прибыль · ' + ruDate(date));
        var inc = [], exp = [], profit = 0;
        rowEl.querySelectorAll('td.cf-clickable').forEach(function (td) {
            var kind = td.getAttribute('data-kind'),
                key = td.getAttribute('data-key'),
                label = td.getAttribute('data-label'),
                val = parseInt(td.getAttribute('data-val'), 10) || 0;
            if (key === 'profit') { profit = val; return; }
            if (kind === 'revenue' || kind === 'income') inc.push({ label: label, val: val });
            else if (kind === 'expense') exp.push({ label: label, val: val });
        });
        var h = '<table class="cf-mtable"><tbody>';
        inc.forEach(function (x) { h += '<tr><td>+ ' + esc(x.label) + '</td><td class="r">' + fmt(x.val) + '</td></tr>'; });
        exp.forEach(function (x) { h += '<tr><td>− ' + esc(x.label) + '</td><td class="r cf-neg">' + fmt(x.val) + '</td></tr>'; });
        h += '<tr class="cf-formula-total"><td><b>= Прибыль</b></td><td class="r"><b' +
             (profit < 0 ? ' class="cf-neg"' : '') + '>' + fmt(profit) + ' ₫</b></td></tr></tbody></table>';
        bodyEl.innerHTML = h;
    }

    document.addEventListener('DOMContentLoaded', function () {
        var grid = document.querySelector('.cf-grid');
        if (!grid) return;
        grid.addEventListener('click', function (e) {
            var td = e.target.closest ? e.target.closest('td.cf-clickable') : null;
            if (!td) return;
            var tr = td.parentNode, date = tr.getAttribute('data-date');
            if (!date) return;
            var kind = td.getAttribute('data-kind'),
                key = td.getAttribute('data-key'),
                label = td.getAttribute('data-label');
            if (kind === 'revenue') openRevenue(date, key, label);
            else if (kind === 'income' || kind === 'expense') openExpenses(date, key, label);
            else if (kind === 'profit') openProfit(tr, date);
        });
    });
})();
