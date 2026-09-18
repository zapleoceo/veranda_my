// REST-клиент payday3: CSRF на каждом изменяющем запросе.
// Сервер требует X-CSRF-Token на всех POST/DELETE под /payday3/api;
// сохранение балансов при уходе со страницы идёт fetch keepalive
// (sendBeacon не умеет заголовки).
//
// Запуск: node --test "tests/js/**/*.test.mjs"

import { test } from 'node:test';
import assert from 'node:assert/strict';

const sent = [];
globalThis.fetch = async (url, init) => {
    sent.push({ url, init });
    return new Response(JSON.stringify({ ok: true, data: { fine: 1 } }), { status: 200 });
};
const { api, setCsrf } = await import('../../../payday3/assets/js/api.js');

test('POST и DELETE несут X-CSRF-Token', async () => {
    sent.length = 0;
    setCsrf('tok-123');
    assert.deepEqual(await api.post('/payday3/api/links/auto', { a: 1 }), { fine: 1 });
    await api.delete('/payday3/api/links/1/2');
    await api.get('/payday3/api/data');
    for (const s of sent) assert.equal(s.init.headers['X-CSRF-Token'], 'tok-123', s.init.method);
    assert.equal(sent[0].init.body, '{"a":1}');
    assert.equal(sent[0].init.keepalive, undefined, 'обычный запрос — без keepalive');
});

test('keepalive-запрос при уходе со страницы тоже с токеном', async () => {
    sent.length = 0;
    setCsrf('tok-9');
    await api.post('/payday3/api/balances', { bal_cash: 1 }, { keepalive: true });
    assert.equal(sent[0].init.keepalive, true);
    assert.equal(sent[0].init.headers['X-CSRF-Token'], 'tok-9');
});

