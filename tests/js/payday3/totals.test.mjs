// Верхняя карточка итогов (Sepay / Poster / VC / Δ) после AJAX-обновления.
//
// Раньше JS читал «Итого» из футера панели чеков, а футер пропускает
// строки, скрытые 👁 — и после синка Poster/Δ прыгали вместе с глазами.
// Плюс Vietnam Company JS узнавал по имени метода, а сервер — по id 11.
// Теперь карточка считается из данных, как в totals.php.
//
// Запуск: node --test "tests/js/**/*.test.mjs"

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { topTotals, diffClass } from '../../../payday3/assets/js/in/totals.js';
import { isVietnam, isBybit, methodOfData, methodOfRow } from '../../../payday3/assets/js/ui/paymentMethods.js';

const check = (total, payment_method_id, payment_method = 'Card') => ({ total, payment_method_id, payment_method });

test('Vietnam Company — по id метода, а не по названию', () => {
    assert.equal(isVietnam({ id: 11, name: 'Card' }), true);
    assert.equal(isVietnam({ id: '11' }), true);
    assert.equal(isVietnam({ id: 3, name: 'Vietnam Company' }), false, 'есть id — имя не решает');
    assert.equal(isBybit({ id: 12 }), true);
    // Разметка без id (старый кэш) — запасной вариант по имени.
    assert.equal(isVietnam({ id: '', name: 'Vietnam Company' }), true);
    assert.equal(isVietnam(methodOfRow({ dataset: { methodId: '11', method: 'X' } })), true);
    assert.equal(isVietnam(methodOfData({ poster_payment_method_id: 11 })), true);
});

test('итоги считаются по всем строкам, VC отдельно, Δ = Sepay − Poster − VC', () => {
    const t = topTotals({
        sepayOpen:   [{ amount: 500000 }, { amount: 200000 }],
        sepayHidden: [{ amount: 100000 }],          // скрытое поступление всё равно деньги в банке
        poster: [
            check(400000, 1),
            check(250000, 12, 'Bybit'),             // BB входит в Poster
            check(300000, 11, 'Vietnam Company'),
        ],
    });
    assert.deepEqual(t, { sepay: 800000, poster: 650000, vietnam: 300000, diff: -150000 });
    assert.equal(diffClass(t.diff), 'danger');
    assert.equal(diffClass(0), 'ok');
    assert.equal(diffClass(1), 'warn');
});

test('пустой день — нули', () => {
    assert.deepEqual(topTotals({}), { sepay: 0, poster: 0, vietnam: 0, diff: 0 });
});
