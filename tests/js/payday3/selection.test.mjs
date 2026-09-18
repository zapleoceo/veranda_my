// Выделение строк на единой странице payday3: четыре таблицы, две пары.
//   поступления ↔ чеки Poster,  расходы ↔ транзакции Poster.
// Ошибка здесь = 🎯 «Связать» активна для бессмысленной пары или молча
// теряет часть отмеченных строк.
//
// Запуск: node --test "tests/js/**/*.test.mjs"

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { linkPlan, matchState, initSelection, SIDE_KINDS } from '../../../payday3/assets/js/ui/selection.js';

test('linkPlan: полные пары связываются, каждая своим запросом', () => {
    assert.deepEqual(linkPlan({ sepay: 2, poster: 1 }), { in: true, out: false, canLink: true });
    assert.deepEqual(linkPlan({ mail: 1, finance: 3 }), { in: false, out: true, canLink: true });
    assert.deepEqual(linkPlan({ sepay: 1, poster: 1, mail: 1, finance: 1 }), { in: true, out: true, canLink: true });
});

test('linkPlan: строка без пары своего вида блокирует связывание', () => {
    // приход с транзакцией / расход с чеком — не пары
    assert.equal(linkPlan({ sepay: 1, finance: 1 }).canLink, false);
    assert.equal(linkPlan({ mail: 1, poster: 1 }).canLink, false);
    // полная пара + «висящая» галочка — не молчим про лишнюю строку
    assert.equal(linkPlan({ sepay: 1, poster: 1, mail: 1 }).canLink, false);
    assert.equal(linkPlan({ mail: 1, finance: 1, poster: 2 }).canLink, false);
    assert.equal(linkPlan({}).canLink, false);
});

test('matchState: сравнение сумм слева и справа', () => {
    assert.deepEqual(matchState(0, 0, 0, 0), { state: 'empty', glyph: '·' });
    assert.deepEqual(matchState(500, 0, 1, 0), { state: 'warn', glyph: '∙' });
    assert.deepEqual(matchState(500, 500, 1, 1), { state: 'ok', glyph: '✅' });
    assert.deepEqual(matchState(500, 400, 1, 1), { state: 'warn', glyph: '⚠' });
    assert.deepEqual(matchState(5000, 1000, 1, 2), { state: 'err', glyph: '⚠' });
});

// ─── initSelection на заглушке DOM ────────────────────────────────────
class FakeInput {
    constructor(cls, data, sum) {
        this.cls = new Set(['pd3-cb', cls]);
        this.classList = { contains: (c) => this.cls.has(c) };
        this.dataset = { ...data, sum: String(sum) };
        this.checked = false;
    }
}
globalThis.HTMLInputElement = FakeInput;

function mount() {
    const boxes = [
        new FakeInput('pd3-cb--sepay',       { sepayId: '1' },   1000),
        new FakeInput('pd3-cb--poster',      { posterId: '9' },  1000),
        new FakeInput('pd3-cb--out-mail',    { mailUid: '5' },   700),
        new FakeInput('pd3-cb--out-finance', { financeId: '8' }, -700),   // расходы в Poster со знаком «−»
    ];
    const el = (id) => ({ id, textContent: '', dataset: {}, attrs: new Set(),
        toggleAttribute(a, on) { on ? this.attrs.add(a) : this.attrs.delete(a); } });
    const ids = Object.fromEntries(['pd3SelSepaySum', 'pd3SelPosterSum', 'pd3SelMatch', 'pd3SelDiff', 'pd3LinkMakeBtn'].map((i) => [i, el(i)]));
    let onChange = null;
    globalThis.document = {
        getElementById: (id) => ids[id] ?? null,
        querySelectorAll: (sel) => boxes.filter((b) => sel === '.pd3-cb' || b.cls.has(sel.slice(1))),
        body: { addEventListener: (type, fn) => { if (type === 'change') onChange = fn; } },
    };
    const sel = initSelection();
    const tick = (i, on = true) => { boxes[i].checked = on; onChange({ target: boxes[i] }); };
    return { sel, ids, boxes, tick, make: ids.pd3LinkMakeBtn };
}

test('суммы слева/справа и 🎯 для пары «расход ↔ транзакция»', () => {
    const { sel, ids, tick, make } = mount();
    assert.ok(make.attrs.has('disabled'), 'пока ничего не выбрано — 🎯 выключена');

    tick(2);                                  // расход 700
    assert.equal(ids.pd3SelSepaySum.textContent, '700');
    assert.ok(make.attrs.has('disabled'), 'расход без транзакции — не пара');

    tick(3);                                  // транзакция −700
    assert.equal(ids.pd3SelPosterSum.textContent, '700', 'справа сравниваем модуль суммы');
    assert.equal(ids.pd3SelDiff.textContent, '0');
    assert.equal(ids.pd3SelMatch.textContent, '✅');
    assert.ok(!make.attrs.has('disabled'), 'пара собрана — 🎯 включена');
    assert.deepEqual([...sel.sets.mail], [5]);
    assert.deepEqual([...sel.sets.finance], [8]);
});

test('reset одной стороны не трогает галочки другой', () => {
    const { sel, boxes, tick } = mount();
    tick(0); tick(1); tick(2); tick(3);
    sel.reset(SIDE_KINDS.out);                // расходы перерисовались
    assert.deepEqual(sel.counts(), { sepay: 1, poster: 1, mail: 0, finance: 0 });
    assert.equal(boxes[0].checked, true, 'галочка на поступлении жива');
    assert.equal(boxes[2].checked, false);

    sel.reset();                              // полный сброс
    assert.deepEqual(sel.counts(), { sepay: 0, poster: 0, mail: 0, finance: 0 });
    assert.ok(boxes.every((b) => !b.checked));
});
