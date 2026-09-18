// Порядок строк в таблице «Деньги» и сортировка по колонкам.
// Поступления и расходы — отдельные блоки одной таблицы: сортировка по
// любой колонке не должна перемешать их между собой.
//
// Запуск: node --test "tests/js/**/*.test.mjs"

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { byTimeAsc } from '../../../payday3/assets/js/ui/bankTable.js';
import { sortTbody } from '../../../payday3/assets/js/ui/sort.js';

test('byTimeAsc: ранние выше, исходный массив не меняется', () => {
    const rows = [{ id: 3, d: '2026-09-18 21:00:00' }, { id: 1, d: '2026-09-18 08:30:00' }, { id: 2, d: '2026-09-18 12:45:00' }];
    assert.deepEqual(byTimeAsc(rows, 'd').map((r) => r.id), [1, 2, 3]);
    assert.deepEqual(rows.map((r) => r.id), [3, 1, 2], 'не мутирует вход');
});

test('byTimeAsc: равное время сохраняет порядок, строки без времени — в конец', () => {
    const rows = [{ id: 'a', d: '' }, { id: 'b', d: '2026-09-18 10:00:00' }, { id: 'c', d: '2026-09-18 10:00:00' }, { id: 'd' }];
    assert.deepEqual(byTimeAsc(rows, 'd').map((r) => r.id), ['b', 'c', 'a', 'd']);
});

/** tbody-заглушка: appendChild переставляет строку в конец. */
function tbody(rows) {
    const list = rows.map(([id, sum]) => ({ id, dataset: { sum: String(sum) } }));
    return {
        list,
        querySelectorAll: () => [...list],
        appendChild(r) { list.splice(list.indexOf(r), 1); list.push(r); },
        ids() { return list.map((r) => r.id); },
    };
}

test('sortTbody сортирует блок по возрастанию/убыванию и возвращает исходный порядок', () => {
    const tb = tbody([['x', 300], ['y', 100], ['z', 200]]);
    sortTbody(tb, 'sum', 'asc');
    assert.deepEqual(tb.ids(), ['y', 'z', 'x']);
    sortTbody(tb, 'sum', 'desc');
    assert.deepEqual(tb.ids(), ['x', 'z', 'y']);
    sortTbody(tb, 'sum', '');
    assert.deepEqual(tb.ids(), ['x', 'y', 'z'], 'третий клик — порядок документа');
});

test('сортировка идёт внутри каждого блока — поступления и расходы не смешиваются', () => {
    const income  = tbody([['in-big', 900], ['in-small', 10]]);
    const expense = tbody([['out-mid', 500], ['out-tiny', 1]]);
    for (const tb of [income, expense]) sortTbody(tb, 'sum', 'asc');
    assert.deepEqual(income.ids(), ['in-small', 'in-big']);
    assert.deepEqual(expense.ids(), ['out-tiny', 'out-mid']);
});

test('sortTbody не падает на пустом блоке (разделитель без строк)', () => {
    const empty = tbody([]);
    assert.doesNotThrow(() => sortTbody(empty, 'sum', 'asc'));
});
