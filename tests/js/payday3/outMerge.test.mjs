// Расходы: частичный сбой загрузки (429 / IMAP) не должен стирать
// таблицу. Раньше упавшая часть превращалась в [] — строки писем
// пропадали, если «Деньги» ↻ нажали дважды за 5 с.
//
// Запуск: node --test "tests/js/**/*.test.mjs"

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mergeOutResults } from '../../../payday3/assets/js/out/bootstrap.js';

const ok  = (value) => ({ status: 'fulfilled', value });
const bad = (status) => ({ status: 'rejected', reason: Object.assign(new Error('HTTP ' + status), { status }) });

test('упавшая часть оставляет прежние строки, остальные обновляются', () => {
    const prev = { mail: [{ mail_uid: 1 }], finance: [{ transaction_id: 5 }], links: [{ mail_uid: 1, finance_id: 5 }] };
    const m = mergeOutResults(prev, {
        mail:    bad(429),
        finance: ok({ finance: [{ transaction_id: 5 }, { transaction_id: 6 }] }),
        links:   ok({ links: [] }),
    });
    assert.deepEqual(m.mail, prev.mail, 'письма остались на экране');
    assert.equal(m.finance.length, 2);
    assert.deepEqual(m.links, []);
    assert.deepEqual(m.failed, ['mail']);
});

test('всё загрузилось — всё свежее, пустой ответ = пустой список', () => {
    const m = mergeOutResults({ mail: [{}], finance: [{}], links: [{}] }, {
        mail: ok({ mail: [] }), finance: ok(null), links: ok({ links: [{ a: 1 }] }),
    });
    assert.deepEqual(m, { mail: [], finance: [], links: [{ a: 1 }], failed: [] });
});
