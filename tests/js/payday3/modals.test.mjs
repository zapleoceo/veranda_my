// Чистые части модалок после разбиения modals.js на модули:
// дерево категорий (общее с «+ транзакция»), сортировка/фильтр поиска
// чеков, разбор ссылки t.me в настройках.
//
// Запуск: node --test "tests/js/**/*.test.mjs"

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { buildCategoryTree, walkCategories, customName } from '../../../payday3/assets/js/ui/categoryTree.js';
import { sortChecks, filterChecks } from '../../../payday3/assets/js/ui/modal/checkFinder.js';
import { parseTmeLink } from '../../../payday3/assets/js/ui/modal/settings.js';
import { fmtShiftTs } from '../../../payday3/assets/js/ui/modal/kashShift.js';

test('дерево категорий: дети под родителем, сирота — корень', () => {
    const { roots, byId } = buildCategoryTree({
        1: { name: 'Расходы', parent_id: 0 },
        2: { name: 'Аренда', parent_id: 1 },
        3: { name: 'Свет', parent_id: 2 },
        7: { name: 'Сирота', parent_id: 99 },
    });
    assert.deepEqual(roots.map((r) => r.id), [1, 7]);
    const seen = [];
    walkCategories(roots, (n, depth) => seen.push(`${depth}:${n.name}`));
    assert.deepEqual(seen, ['0:Расходы', '1:Аренда', '2:Свет', '0:Сирота']);
    assert.equal(byId[3].parent_id, 2);
    assert.equal(customName({ 2: 'Rent' }, 2), 'Rent');
    assert.equal(customName({ '2': 'Rent' }, '2'), 'Rent');
    assert.equal(customName({}, 5), '');
});

test('поиск чеков: фильтр по номеру/официанту/столу и сортировка', () => {
    const rows = [
        { transaction_id: 10, receipt_number: 3, sum: 500, waiter_name: 'Anna', table_title: 'T1', date_close: '2026-09-18 10:00' },
        { transaction_id: 11, receipt_number: 1, sum: 900, waiter_name: 'Bob',  table_title: 'VIP', date_close: '2026-09-18 12:00' },
    ];
    assert.deepEqual(filterChecks(rows, 'vip').map((r) => r.transaction_id), [11]);
    assert.deepEqual(filterChecks(rows, ' anna ').map((r) => r.transaction_id), [10]);
    assert.equal(filterChecks(rows, '').length, 2);
    assert.deepEqual(sortChecks(rows, 'sum', true).map((r) => r.sum), [500, 900]);
    assert.deepEqual(sortChecks(rows, 'date_close', false).map((r) => r.transaction_id), [11, 10]);
    assert.deepEqual(rows.map((r) => r.transaction_id), [10, 11], 'исходный список не мутируется');
});

test('t.me/c/… → chat_id и тред', () => {
    assert.deepEqual(parseTmeLink('https://t.me/c/123456/78'), { chatId: '-100123456', threadId: '78' });
    assert.deepEqual(parseTmeLink('https://t.me/c/123456'), { chatId: '-100123456', threadId: '' });
    assert.equal(parseTmeLink('-100123'), null);
});

test('время кассовой смены: unix-секунды и мусор', () => {
    const d = new Date(2026, 8, 18, 9, 5);
    assert.equal(fmtShiftTs(Math.floor(d.getTime() / 1000)), '18.09 09:05');
    assert.equal(fmtShiftTs('вчера'), 'вчера');
    assert.equal(fmtShiftTs(''), '');
});
