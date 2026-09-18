// Рендер строк банка в обеих вкладках payday3.
//
// Строки SePay (IN) рисуются дважды: сервером (sepay_table.php) и этим
// JS после синка. Если они разъедутся по колонкам, таблица «поедет», а
// кнопка «+» пропадёт у половины строк. Здесь JS-половина контракта и
// сверка с шаблоном; серверную держит tests/Unit/Payday3/SepayTablePartialTest.php.
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

const { renderSepay, SEPAY_COLUMNS } = await import('../../../payday3/assets/js/in/renderTables.js');
const { renderOutMail } = await import('../../../payday3/assets/js/out/renderTables.js');

const sepay = (id, amount, date) => ({
    id, transaction_date: date, time: date.slice(11, 19), amount,
    amount_fmt: String(amount), content: 'CK ' + id,
});
const rowsOf = (html, idPrefix) => [...html.matchAll(new RegExp(`<tr id="${idPrefix}\\d+"[\\s\\S]*?</tr>`, 'g'))].map((m) => m[0]);

test('строка SePay: кнопка «+» создаёт приход на сумму и время строки', () => {
    renderSepay([sepay(62165230, 350000, '2026-09-18 14:05:09')], [], []);
    const [row] = rowsOf(document.querySelector('#pd3SepayTable tbody').innerHTML, 'pd3-sepay-');

    assert.match(row, /class="pd3-row-create"/);
    assert.match(row, /data-tx-type="1"/);
    assert.match(row, /data-amount="350000"/);
    assert.match(row, /data-date="2026-09-18 14:05:09"/);
    assert.match(row, /class="pd3-row row-red"/, 'без связей строка красная — CSS покажет «+»');
});

test('связанная строка SePay не красная — «+» скрыт CSS', () => {
    renderSepay([sepay(7, 1000, '2026-09-18 10:00:00')], [], [{ sepay_id: 7, link_type: 'auto', is_manual: 0 }]);
    const [row] = rowsOf(document.querySelector('#pd3SepayTable tbody').innerHTML, 'pd3-sepay-');
    assert.match(row, /row-green/);
    assert.doesNotMatch(row, /row-red/);
});

test('ячеек в строке SePay столько же, сколько колонок', () => {
    renderSepay([sepay(1, 1000, '2026-09-18 10:00:00')], [sepay(2, 500, '2026-09-18 11:00:00')], []);
    const rows = rowsOf(document.querySelector('#pd3SepayTable tbody').innerHTML, 'pd3-sepay-');
    assert.equal(rows.length, 2);
    for (const row of rows) {
        assert.equal((row.match(/<td /g) || []).length, SEPAY_COLUMNS);
    }
});

test('пустая таблица SePay растягивается на все колонки', () => {
    renderSepay([], [], []);
    assert.match(
        document.querySelector('#pd3SepayTable tbody').innerHTML,
        new RegExp(`<td colspan="${SEPAY_COLUMNS}">`),
    );
});

test('JS и серверный шаблон SePay согласованы по колонкам и кнопке', () => {
    const tpl = readFileSync(new URL('../../../src/Views/payday3/partials/sepay_table.php', import.meta.url), 'utf8');
    const thead = tpl.match(/<thead>([\s\S]*?)<\/thead>/)[1];

    assert.equal((thead.match(/<th /g) || []).length, SEPAY_COLUMNS, 'число <th> в шаблоне');
    assert.match(tpl, new RegExp(`<td colspan="${SEPAY_COLUMNS}">`), 'colspan пустой строки в шаблоне');
    assert.match(
        tpl,
        /<button type="button" class="pd3-row-create" title="Создать приход в Poster на эту сумму" data-tx-type="1" data-amount="/,
        'серверная кнопка совпадает с createTxButtonHtml для прихода',
    );
});

test('строка письма OUT: кнопка «+» по-прежнему создаёт расход', () => {
    renderOutMail([{
        mail_uid: 11, date: '2026-09-18 09:15:00', amount: 120000, amount_fmt: '120 000', content: 'BIDV',
    }], []);
    const [row] = rowsOf(document.querySelector('#pd3OutMailTable tbody').innerHTML, 'pd3-out-mail-');

    assert.match(row, /class="pd3-row-create"/);
    assert.match(row, /data-tx-type="2"/);
    assert.match(row, /data-amount="120000"/);
    assert.match(row, /data-date="2026-09-18 09:15:00"/);
});
