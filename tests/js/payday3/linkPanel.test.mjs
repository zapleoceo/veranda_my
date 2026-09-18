// Единая панель связей: одна кнопка на обе пары таблиц.
//   🧩 авто — оба алгоритма; 🎯 ручная — по тому, что отмечено;
//   ⛓️‍💥 снять все — одно подтверждение, обе стороны.
// Сбой одной стороны не должен мешать второй.
//
// Запуск: node --test "tests/js/**/*.test.mjs"

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { runSides, initLinkPanel } from '../../../payday3/assets/js/ui/linkPanel.js';

test('runSides: выполняет обе стороны и возвращает только сбои', async () => {
    const calls = [];
    const errors = await runSides([
        ['Приходы', async () => { calls.push('in'); }],
        ['Расходы', async () => { calls.push('out'); throw new Error('IMAP down'); }],
        ['Пусто',   null],
    ]);
    assert.deepEqual(calls.sort(), ['in', 'out']);
    assert.deepEqual(errors, ['Расходы: IMAP down']);
});

test('runSides: синхронная ошибка одной стороны не обрывает вторую', async () => {
    let otherDone = false;
    const errors = await runSides([
        ['Расходы', () => { throw new TypeError('outLinks is null'); }],
        ['Приходы', async () => { await new Promise((r) => setTimeout(r, 5)); otherDone = true; }],
    ]);
    assert.equal(otherDone, true, 'приходы довыполнились');
    assert.deepEqual(errors, ['Расходы: outLinks is null']);
});

test('🎯 без адаптера расходов: приходы связываются, ошибка показывается', async () => {
    const handlers = {};
    const btn = (id) => ({ id, disabled: false, classList: { add() {}, remove() {} },
        addEventListener: (type, fn) => { handlers[id] = fn; } });
    const buttons = Object.fromEntries(['pd3LinkAutoBtn', 'pd3LinkMakeBtn', 'pd3LinkClearBtn'].map((i) => [i, btn(i)]));
    globalThis.document = { getElementById: (id) => buttons[id] ?? null };
    const alerts = [];
    globalThis.alert = (m) => alerts.push(m);
    const log = [];
    initLinkPanel({
        state: { get: () => ({ from: '2026-09-18', to: '2026-09-18' }) },
        inLinks: adapter('in', log),
        outLinks: null,                                   // initOutMode() вернул null
        selection: {
            counts: () => ({ sepay: 1, poster: 1, mail: 1, finance: 1 }),
            sets: { sepay: new Set([1]), poster: new Set([2]), mail: new Set([3]), finance: new Set([4]) },
            reset() {},
        },
        confirmFn: () => true,
    });
    await handlers.pd3LinkMakeBtn();
    assert.deepEqual(log, [['in', 'manual', [1], [2]]]);
    assert.equal(alerts.length, 1);
    assert.match(alerts[0], /^Расходы: /);
});

function adapter(name, log, { failOn = '' } = {}) {
    const rec = (op) => async (...args) => {
        log.push([name, op, ...args]);
        if (op === failOn) throw new Error(name + ' ' + op + ' failed');
        return {};
    };
    return { autoLink: rec('auto'), manualLink: rec('manual'), clearLinks: rec('clear') };
}

function mount({ counts, failOn = {}, confirmAnswer = true } = {}) {
    const handlers = {};
    const btn = (id) => ({ id, disabled: false, classList: { add() {}, remove() {} },
        addEventListener: (type, fn) => { handlers[id] = fn; } });
    const buttons = Object.fromEntries(['pd3LinkAutoBtn', 'pd3LinkMakeBtn', 'pd3LinkClearBtn'].map((i) => [i, btn(i)]));
    globalThis.document = { getElementById: (id) => buttons[id] ?? null };
    const alerts = [];
    globalThis.alert = (m) => alerts.push(m);

    const log = [];
    const resets = [];
    const n = { sepay: 0, poster: 0, mail: 0, finance: 0, ...counts };
    const selection = {
        counts: () => n,
        sets: {
            sepay: new Set(n.sepay ? [11] : []), poster: new Set(n.poster ? [22] : []),
            mail: new Set(n.mail ? [33] : []),   finance: new Set(n.finance ? [44] : []),
        },
        reset: () => resets.push('all'),
    };
    const confirms = [];
    initLinkPanel({
        state: { get: () => ({ from: '2026-09-18', to: '2026-09-18' }) },
        inLinks: adapter('in', log, { failOn: failOn.in }),
        outLinks: adapter('out', log, { failOn: failOn.out }),
        selection,
        confirmFn: (m) => { confirms.push(m); return confirmAnswer; },
    });
    return { click: (id) => handlers[id](), log, alerts, resets, confirms };
}

test('🧩 запускает авто-связи и приходов, и расходов', async () => {
    const p = mount();
    await p.click('pd3LinkAutoBtn');
    assert.deepEqual(p.log.map((l) => l.slice(0, 2)).sort(), [['in', 'auto'], ['out', 'auto']]);
    assert.deepEqual(p.alerts, []);
});

test('🧩 сбой расходов не мешает приходам и показывается', async () => {
    const p = mount({ failOn: { out: 'auto' } });
    await p.click('pd3LinkAutoBtn');
    assert.ok(p.log.some(([s, op]) => s === 'in' && op === 'auto'), 'приходы связались');
    assert.deepEqual(p.alerts, ['Расходы: out auto failed']);
});

test('🎯 отправляет каждую пару своей стороне', async () => {
    const p = mount({ counts: { sepay: 1, poster: 1, mail: 1, finance: 1 } });
    await p.click('pd3LinkMakeBtn');
    assert.deepEqual(
        p.log.filter(([, op]) => op === 'manual').sort(),
        [['in', 'manual', [11], [22]], ['out', 'manual', [33], [44]]],
    );
    assert.deepEqual(p.resets, ['all'], 'после успеха галочки сняты');
});

test('🎯 только пара расходов — приходы не трогаются', async () => {
    const p = mount({ counts: { mail: 1, finance: 1 } });
    await p.click('pd3LinkMakeBtn');
    assert.deepEqual(p.log, [['out', 'manual', [33], [44]]]);
});

test('🎯 неполная пара ничего не отправляет', async () => {
    const p = mount({ counts: { sepay: 1, finance: 1 } });
    await p.click('pd3LinkMakeBtn');
    assert.deepEqual(p.log, []);
    assert.deepEqual(p.resets, []);
});

test('🎯 при сбое галочки остаются для повтора', async () => {
    const p = mount({ counts: { sepay: 1, poster: 1 }, failOn: { in: 'manual' } });
    await p.click('pd3LinkMakeBtn');
    assert.deepEqual(p.resets, []);
    assert.equal(p.alerts.length, 1);
});

test('⛓️‍💥 одно подтверждение — снимаются связи обеих сторон', async () => {
    const p = mount();
    await p.click('pd3LinkClearBtn');
    assert.equal(p.confirms.length, 1);
    assert.match(p.confirms[0], /2026-09-18/);
    assert.deepEqual(p.log.map((l) => l.slice(0, 2)).sort(), [['in', 'clear'], ['out', 'clear']]);
});

test('⛓️‍💥 отказ в подтверждении — ничего не снимается', async () => {
    const p = mount({ confirmAnswer: false });
    await p.click('pd3LinkClearBtn');
    assert.deepEqual(p.log, []);
});
