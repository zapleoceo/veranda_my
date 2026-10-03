// «Пополнить Grab»: клиентский гейт кнопки и тексты статуса.
// Запуск: node --test "tests/js/**/*.test.mjs"

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { grabCanCreate, grabRowState } from '../../../payday3/assets/js/ui/grabTopUp.js';

const ok = { surplus_vnd: 736170, vietnam_ok: true, tips_ok: true, found: [], reason: 'ok', can_create: true };

test('enabled only when surplus > 0, both reconciled, nothing found and server allows', () => {
    assert.equal(grabCanCreate(ok), true);
    assert.equal(grabCanCreate({ ...ok, can_create: false }), false);
    assert.equal(grabCanCreate({ ...ok, surplus_vnd: 0 }), false);
    assert.equal(grabCanCreate({ ...ok, surplus_vnd: null }), false);
    assert.equal(grabCanCreate({ ...ok, vietnam_ok: false }), false);
    assert.equal(grabCanCreate({ ...ok, tips_ok: false }), false);
    assert.equal(grabCanCreate({ ...ok, found: [{ transaction_id: 1, sum_minor: 5 }] }), false);
    assert.equal(grabCanCreate(null), false);
});

test('status texts', () => {
    assert.deepEqual(grabRowState(ok), { total: '736 170', disabled: false, showFound: false,
        text: 'Излишек 736 170 — можно пополнить' });
    assert.equal(grabRowState({ ...ok, reason: 'not_reconciled', can_create: false }).text, 'Сначала сведите Vietnam и Tips');
    const none = grabRowState({ ...ok, surplus_vnd: -5000, reason: 'no_surplus', can_create: false });
    assert.equal(none.text, 'Излишка нет');
    assert.equal(none.disabled, true);
    const found = grabRowState({ ...ok, reason: 'exists', can_create: false,
        found: [{ transaction_id: 77, sum_minor: 736170 }] });
    assert.equal(found.text, 'Найдена: #77 736 170');
    assert.equal(found.disabled, true);
    assert.equal(found.showFound, true);
    assert.equal(grabRowState({ reason: 'no_fact', message: 'Факт. не сохранён', surplus_vnd: null }).text, 'Факт. не сохранён');
    assert.equal(grabRowState({ reason: 'no_fact', surplus_vnd: null }).total, '—');
});
