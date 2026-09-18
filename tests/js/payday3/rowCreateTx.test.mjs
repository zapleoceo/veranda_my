// Кнопка «+» на строке банка: разметка и обратное чтение данных.
// Модуль общий для OUT (расход) и IN (приход) — если он сломается,
// кнопка откроет модалку не с тем типом или суммой в обеих вкладках.
//
// Запуск: node --test "tests/js/**/*.test.mjs"

import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
    TX_TYPE, CREATE_TX_SELECTOR, createTxButtonHtml, readCreateTxTrigger, splitDateTime,
} from '../../../payday3/assets/js/ui/rowCreateTx.js';

/** data-* атрибуты из HTML кнопки → объект как у element.dataset. */
function datasetOf(html) {
    const ds = {};
    for (const [, name, value] of html.matchAll(/data-([a-z-]+)="([^"]*)"/g)) {
        const key = name.replace(/-([a-z])/g, (_, c) => c.toUpperCase());
        ds[key] = value
            .replaceAll('&quot;', '"').replaceAll('&#39;', "'")
            .replaceAll('&lt;', '<').replaceAll('&gt;', '>').replaceAll('&amp;', '&');
    }
    return ds;
}

test('селектор совпадает с классом кнопки', () => {
    const html = createTxButtonHtml({ amount: 1, date: '', type: TX_TYPE.INCOME });
    assert.equal(CREATE_TX_SELECTOR, '.pd3-row-create');
    assert.match(html, /class="pd3-row-create"/);
});

test('кнопка IN несёт тип «приход», сумму и время строки', () => {
    const html = createTxButtonHtml({ amount: 350000, date: '2026-09-18 14:05:09', type: TX_TYPE.INCOME });
    assert.equal(
        html,
        '<button type="button" class="pd3-row-create" title="Создать приход в Poster на эту сумму"'
        + ' data-tx-type="1" data-amount="350000" data-date="2026-09-18 14:05:09">+</button>',
        'разметка обязана совпадать с серверной (bank_table.php)',
    );
});

test('кнопка OUT несёт тип «расход»', () => {
    const html = createTxButtonHtml({ amount: 120000, date: '2026-09-18 09:00:00', type: TX_TYPE.EXPENSE });
    assert.match(html, /data-tx-type="2"/);
    assert.match(html, /title="Создать расход в Poster на эту сумму"/);
});

test('неизвестный тип рисуется как расход — прежнее поведение OUT', () => {
    for (const type of [undefined, 0, 3, 'x']) {
        assert.match(createTxButtonHtml({ amount: 1, date: '', type }), /data-tx-type="2"/);
    }
});

test('сумма округляется до целых донгов, мусор → 0', () => {
    assert.match(createTxButtonHtml({ amount: '1500.6', date: '', type: 1 }), /data-amount="1501"/);
    assert.match(createTxButtonHtml({ amount: 'abc', date: '', type: 1 }), /data-amount="0"/);
});

test('дата экранируется в атрибуте', () => {
    const html = createTxButtonHtml({ amount: 1, date: '2026 "<x>" & \'y\'', type: 1 });
    assert.match(html, /data-date="2026 &quot;&lt;x&gt;&quot; &amp; &#39;y&#39;"/);
    assert.doesNotMatch(html, /<x>/);
});

test('разметка → клик → те же данные (туда-обратно)', () => {
    for (const input of [
        { amount: 350000, date: '2026-09-18 14:05:09', type: TX_TYPE.INCOME },
        { amount: 42,     date: '2026-01-02',          type: TX_TYPE.EXPENSE },
    ]) {
        const html = createTxButtonHtml(input);
        assert.deepEqual(readCreateTxTrigger({ dataset: datasetOf(html) }), input);
    }
});

test('кнопка без data-tx-type (старая разметка из кеша) открывается расходом', () => {
    assert.deepEqual(
        readCreateTxTrigger({ dataset: { amount: '500', date: '2026-09-18 10:00:00' } }),
        { amount: 500, date: '2026-09-18 10:00:00', type: TX_TYPE.EXPENSE },
    );
    assert.deepEqual(readCreateTxTrigger(null), { amount: 0, date: '', type: TX_TYPE.EXPENSE });
});

test('время строки раскладывается на дату и время модалки', () => {
    const now = new Date(2030, 0, 5, 7, 8);
    assert.deepEqual(splitDateTime('2026-09-18 14:05:09', now), { date: '2026-09-18', time: '14:05' });
    assert.deepEqual(splitDateTime('2026-09-18T09:30', now),    { date: '2026-09-18', time: '09:30' });
    assert.deepEqual(splitDateTime('2026-09-18', now),          { date: '2026-09-18', time: '07:08' });
    assert.deepEqual(splitDateTime('', now),                    { date: '2030-01-05', time: '07:08' });
    assert.deepEqual(splitDateTime('мусор', now),               { date: '2030-01-05', time: '07:08' });
});
