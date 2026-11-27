// Итоговый баланс: снимок Telegram и сохранение ждут загрузку «Факт.»
// (payday3/assets/js/ui/balances.js + actualGate.js).
//
// Сценарии из отчёта Astra Max / Dot:
//  - ранний клик Telegram до загрузки не шлёт POST /balances с null;
//  - если сохранение упало — снимок не уходит, причина показана;
//  - после успешного повтора сохранения снимок снова доступен.
//
// Запуск: node --test "tests/js/**/*.test.mjs" (нужен jsdom из devDependencies).

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { JSDOM } from 'jsdom';

const ROWS = ['andrey', 'vietnam', 'cash', 'stash', 'total'];

function setupDom() {
    const rows = ROWS.map((k) => `
        <tr data-key="${k}">
            <td><span id="pd3BalPoster_${k}">—</span></td>
            <td><input type="text" class="pd3-bal-input" id="pd3BalActual_${k}" data-key="bal_${k}"></td>
            <td><span id="pd3BalDiff_${k}">—</span></td>
        </tr>`).join('');
    const dom = new JSDOM(`<!doctype html><body>
        <section id="pd3Balances">
            <button id="pd3BalancesUpldBtn" disabled>UPLD</button>
            <button id="pd3BalancesTelegramBtn">TG</button>
            <button id="pd3BalancesReloadBtn">R</button>
            <table>${rows}</table>
            <p id="pd3BalUnmapped" hidden></p>
            <div id="pd3BalAccountsWrap" hidden><tbody id="pd3BalAccountsTbody"></tbody></div>
            <span id="pd3BalancesStatus"></span>
        </section>
        <meta name="csrf-token" content="t">
    </body>`, { url: 'https://veranda.test/payday3/' });
    const w = dom.window;
    globalThis.window = w;
    globalThis.document = w.document;
    globalThis.location = w.location;
    globalThis.getComputedStyle = w.getComputedStyle.bind(w);
    globalThis.HTMLElement = w.HTMLElement;
    globalThis.HTMLInputElement = w.HTMLInputElement;
    globalThis.Event = w.Event;
    return dom;
}

/** Ручной fetch: каждый запрос пишется в calls, ответ решает тест. */
function stubFetch() {
    const calls = [];
    const handlers = [];
    globalThis.fetch = (url, init = {}) => {
        const call = { url: String(url), method: init.method || 'GET', body: init.body ? JSON.parse(init.body) : undefined };
        calls.push(call);
        return new Promise((resolve, reject) => {
            handlers.push({ call, resolve, reject });
        });
    };
    return { calls, handlers };
}

// Конверт как у сервера: { ok, data } / { ok:false, error }; api.js читает text().
const respond = (status, payload) => ({
    ok: status < 400,
    status,
    clone() { return this; },
    text: async () => JSON.stringify(payload),
    json: async () => payload,
});
const ok = (data) => respond(200, { ok: true, data });
const fail = (status, error) => respond(status, { ok: false, error });
const tick = () => new Promise((r) => setTimeout(r, 0));
const flush = async () => { for (let i = 0; i < 5; i++) await tick(); };

// Каждый тест — свежий экземпляр модуля: состояние (шлюз, coalesce) не течёт между тестами.
let bootCount = 0;
async function bootBalances(stub) {
    const { initBalances } = await import(`../../../payday3/assets/js/ui/balances.js?case=${++bootCount}`);
    const state = { get: () => ({ to: '2026-11-27' }) };
    initBalances({ state });
    return state;
}

const answer = async (stub, pred, response) => {
    // coalesce() запускает загрузчик через микротаск — дождаться запроса.
    await flush();
    const idx = stub.handlers.findIndex((h) => pred(h.call));
    assert.ok(idx >= 0, 'ожидался запрос, которого нет');
    const [h] = stub.handlers.splice(idx, 1);
    h.resolve(response);
    await flush();
};

test('ранний клик Telegram до загрузки не шлёт POST /balances и не снимает пустой кадр', async () => {
    setupDom();
    const stub = stubFetch();
    await bootBalances(stub);
    await flush();

    // Загрузка Факт. ещё висит; оператор жмёт Telegram.
    document.getElementById('pd3BalancesTelegramBtn').click();
    await flush();
    assert.equal(stub.calls.filter((c) => c.method === 'POST').length, 0, 'POST до загрузки недопустим');

    // Загрузка приходит — значения попадают в поля.
    await answer(stub, (c) => c.url.startsWith('/payday3/api/balances?'),
        ok({ bal_andrey: 1000, bal_vietnam: 0, bal_cash: null, bal_stash: 500, bal_total: 1500 }));

    assert.equal(document.getElementById('pd3BalActual_andrey').value, '1 000');
    assert.equal(document.getElementById('pd3BalActual_vietnam').value, '0', 'явный 0 — это значение, не пусто');
    // Ни одного POST с null: снимок после загрузки сохранять нечего.
    assert.equal(stub.calls.filter((c) => c.method === 'POST' && c.url === '/payday3/api/balances').length, 0);
});

test('сбой сохранения: снимок не отправляется, причина показана', async () => {
    setupDom();
    const stub = stubFetch();
    await bootBalances(stub);
    await answer(stub, (c) => c.url.startsWith('/payday3/api/poster/balances'), fail(502, 'poster down'));
    await answer(stub, (c) => c.url.startsWith('/payday3/api/balances?'),
        ok({ bal_andrey: 1000, bal_vietnam: 0, bal_cash: 0, bal_stash: 0, bal_total: 1000 }));

    // Оператор меняет Факт. — сохранение упадёт.
    const input = document.getElementById('pd3BalActual_andrey');
    input.value = '2 000';
    input.dispatchEvent(new window.Event('input', { bubbles: true }));
    input.dispatchEvent(new window.Event('blur'));
    await flush();
    await answer(stub, (c) => c.url === '/payday3/api/balances' && c.method === 'POST', fail(500, 'db write failed'));

    // Клик Telegram повторяет сохранение; и повтор тоже падает.
    document.getElementById('pd3BalancesTelegramBtn').click();
    await answer(stub, (c) => c.url === '/payday3/api/balances' && c.method === 'POST', fail(500, 'db write failed again'));
    const status = document.getElementById('pd3BalancesStatus').textContent;
    assert.match(status, /не сохранено/, 'причина сбоя должна быть видна');
    assert.equal(stub.calls.filter((c) => c.url.includes('/balances/telegram')).length, 0, 'снимок не должен уходить');
    assert.equal(document.querySelector('script[src*="html2canvas"]'), null, 'даже не грузим html2canvas');
});

test('повтор сохранения после сбоя проходит, и снимок снова доступен', async () => {
    setupDom();
    const stub = stubFetch();
    await bootBalances(stub);
    await answer(stub, (c) => c.url.startsWith('/payday3/api/poster/balances'), fail(502, 'poster down'));
    await answer(stub, (c) => c.url.startsWith('/payday3/api/balances?'),
        ok({ bal_andrey: 1000, bal_vietnam: 0, bal_cash: 0, bal_stash: 0, bal_total: 1000 }));

    const input = document.getElementById('pd3BalActual_andrey');
    input.value = '2 000';
    input.dispatchEvent(new window.Event('blur'));
    await answer(stub, (c) => c.url === '/payday3/api/balances' && c.method === 'POST', fail(500, 'db write failed'));

    // Второй клик: сохранение повторяется и на этот раз проходит.
    document.getElementById('pd3BalancesTelegramBtn').click();
    await flush();
    await answer(stub, (c) => c.url === '/payday3/api/balances' && c.method === 'POST', ok({ ok: true }));

    const posts = stub.calls.filter((c) => c.url === '/payday3/api/balances' && c.method === 'POST');
    assert.equal(posts.length, 2, 'сбой и успешный повтор');
    assert.equal(posts.at(-1).body.bal_andrey, 2000);
    assert.equal(document.getElementById('pd3BalancesStatus').textContent.includes('не сохранено'), false);
});
