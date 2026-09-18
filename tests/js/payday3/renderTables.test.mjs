// Рендер строк банка в таблицу «Деньги» единой страницы payday3.
//
// Поступления (SePay) рисуются дважды: сервером (bank_table.php) и JS
// после синка; расходы (письма BIDV) — только JS. Все они живут в одной
// таблице, так что число колонок обязано совпадать у обоих видов строк
// и с шаблоном — иначе таблица «поедет», а «+» пропадёт у части строк.
// Серверную половину держит tests/Unit/Payday3/BankTablePartialTest.php.
//
// Запуск: node --test "tests/js/**/*.test.mjs"

import { test, beforeEach } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

// Минимальная подмена document: renderers только ищут tbody и пишут innerHTML.
const tbodies = new Map();
globalThis.document = {
    querySelector: (sel) => {
        if (!tbodies.has(sel)) tbodies.set(sel, { innerHTML: '' });
        return tbodies.get(sel);
    },
};
beforeEach(() => tbodies.clear());

const { renderSepay }   = await import('../../../payday3/assets/js/in/renderTables.js');
const { renderOutMail } = await import('../../../payday3/assets/js/out/renderTables.js');
const { BANK_COLUMNS, SEPAY_TBODY, MAIL_TBODY } = await import('../../../payday3/assets/js/ui/bankTable.js');

const sepay = (id, amount, date) => ({
    id, transaction_date: date, time: date.slice(11, 19), amount,
    amount_fmt: String(amount), content: 'CK ' + id,
});
const mail = (uid, amount, date) => ({
    mail_uid: uid, date, amount, amount_fmt: String(amount), content: 'BIDV ' + uid,
});
const rowsOf = (html, idPrefix) => [...html.matchAll(new RegExp(`<tr id="${idPrefix}\\d+"[\\s\\S]*?</tr>`, 'g'))].map((m) => m[0]);
const cellsOf = (row) => (row.match(/<td /g) || []).length;

test('поступление рисуется в блок поступлений, «+» создаёт приход', () => {
    renderSepay([sepay(62165230, 350000, '2026-09-18 14:05:09')], [], []);
    const [row] = rowsOf(document.querySelector(SEPAY_TBODY).innerHTML, 'pd3-sepay-');

    assert.match(row, /class="pd3-row-create"/);
    assert.match(row, /data-tx-type="1"/);
    assert.match(row, /data-amount="350000"/);
    assert.match(row, /data-date="2026-09-18 14:05:09"/);
    assert.match(row, /class="pd3-row row-red"/, 'без связей строка красная — CSS покажет «+»');
});

test('расход рисуется в блок расходов, «+» создаёт расход', () => {
    renderOutMail([mail(11, 120000, '2026-09-18 09:15:00')], []);
    const [row] = rowsOf(document.querySelector(MAIL_TBODY).innerHTML, 'pd3-out-mail-');

    assert.match(row, /class="pd3-row-create"/);
    assert.match(row, /data-tx-type="2"/);
    assert.match(row, /data-amount="120000"/);
    assert.match(row, /data-date="2026-09-18 09:15:00"/);
});

test('расходы идут по времени: самый ранний — сразу под разделителем', () => {
    // IMAP отдаёт письма от новых к старым — порядок должен развернуться.
    renderOutMail([
        mail(3, 300, '2026-09-18 21:00:00'),
        mail(1, 100, '2026-09-18 08:30:00'),
        mail(2, 200, '2026-09-18 12:45:00'),
    ], []);
    const ids = rowsOf(document.querySelector(MAIL_TBODY).innerHTML, 'pd3-out-mail-')
        .map((r) => r.match(/data-mail-uid="(\d+)"/)[1]);
    assert.deepEqual(ids, ['1', '2', '3']);
});

test('связанная строка не красная — «+» скрыт CSS', () => {
    renderSepay([sepay(7, 1000, '2026-09-18 10:00:00')], [], [{ sepay_id: 7, link_type: 'auto', is_manual: 0 }]);
    const [row] = rowsOf(document.querySelector(SEPAY_TBODY).innerHTML, 'pd3-sepay-');
    assert.match(row, /row-green/);
    assert.doesNotMatch(row, /row-red/);
});

test('у поступлений и расходов одинаковое число ячеек', () => {
    renderSepay([sepay(1, 1000, '2026-09-18 10:00:00')], [sepay(2, 500, '2026-09-18 11:00:00')], []);
    renderOutMail([mail(5, 700, '2026-09-18 12:00:00')], []);
    const rows = [
        ...rowsOf(document.querySelector(SEPAY_TBODY).innerHTML, 'pd3-sepay-'),
        ...rowsOf(document.querySelector(MAIL_TBODY).innerHTML, 'pd3-out-mail-'),
    ];
    assert.equal(rows.length, 3);
    for (const row of rows) assert.equal(cellsOf(row), BANK_COLUMNS);
});

test('пустые блоки растягиваются на все колонки', () => {
    renderSepay([], [], []);
    renderOutMail([], []);
    assert.match(document.querySelector(SEPAY_TBODY).innerHTML, new RegExp(`<td colspan="${BANK_COLUMNS}">Нет поступлений`));
    assert.match(document.querySelector(MAIL_TBODY).innerHTML, new RegExp(`<td colspan="${BANK_COLUMNS}">Расходов за период нет`));
});

test('JS и серверный шаблон «Деньги» согласованы по колонкам, блокам и кнопке', () => {
    const tpl = readFileSync(new URL('../../../src/Views/payday3/partials/bank_table.php', import.meta.url), 'utf8');
    const thead = tpl.match(/<thead>([\s\S]*?)<\/thead>/)[1];

    assert.equal((thead.match(/<th /g) || []).length, BANK_COLUMNS, 'число <th> в шаблоне');
    assert.match(tpl, new RegExp(`\\$bankColumns = ${BANK_COLUMNS};`), 'colspan-ы шаблона берутся из того же числа');
    assert.match(tpl, new RegExp(`<tbody id="${SEPAY_TBODY.slice(1)}">`), 'блок поступлений');
    assert.match(tpl, new RegExp(`<tbody id="${MAIL_TBODY.slice(1)}">`), 'блок расходов');
    assert.ok(tpl.indexOf(SEPAY_TBODY.slice(1)) < tpl.indexOf('pd3-bank-split')
        && tpl.indexOf('pd3-bank-split') < tpl.indexOf(MAIL_TBODY.slice(1)), 'поступления → разделитель → расходы');
    assert.match(
        tpl,
        /<button type="button" class="pd3-row-create" title="Создать приход в Poster на эту сумму" data-tx-type="1" data-amount="/,
        'серверная кнопка совпадает с createTxButtonHtml для прихода',
    );
});
