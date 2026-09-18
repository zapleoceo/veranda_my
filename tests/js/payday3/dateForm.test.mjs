// Кнопки ‹ / › возле выбора даты в payday3: шаг на день назад/вперёд.
// Ошибка в арифметике дат = оператор «перепрыгивает» день на стыке
// месяцев или уезжает на другой день из-за часового пояса браузера.
//
// Запуск: node --test "tests/js/**/*.test.mjs"

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { shiftYmd, initDateForm } from '../../../payday3/assets/js/ui/dateForm.js';

test('шаг на день внутри месяца', () => {
    assert.equal(shiftYmd('2026-09-18', -1), '2026-09-17');
    assert.equal(shiftYmd('2026-09-18', 1), '2026-09-19');
});

test('стыки месяцев и годов', () => {
    assert.equal(shiftYmd('2026-10-01', -1), '2026-09-30');
    assert.equal(shiftYmd('2026-09-30', 1), '2026-10-01');
    assert.equal(shiftYmd('2026-01-01', -1), '2025-12-31');
    assert.equal(shiftYmd('2026-12-31', 1), '2027-01-01');
});

test('февраль и високосный год', () => {
    assert.equal(shiftYmd('2026-03-01', -1), '2026-02-28');
    assert.equal(shiftYmd('2028-03-01', -1), '2028-02-29');
    assert.equal(shiftYmd('2028-02-28', 1), '2028-02-29');
});

test('часовой пояс браузера не сдвигает дату', () => {
    const tz = process.env.TZ;
    try {
        for (const zone of ['Asia/Ho_Chi_Minh', 'America/Los_Angeles', 'UTC']) {
            process.env.TZ = zone;
            assert.equal(shiftYmd('2026-03-29', 1), '2026-03-30', zone);
            assert.equal(shiftYmd('2026-11-01', -1), '2026-10-31', zone);
        }
    } finally {
        if (tz === undefined) delete process.env.TZ; else process.env.TZ = tz;
    }
});

test('мусор и несуществующие даты → пусто, а не случайный день', () => {
    for (const bad of ['', null, undefined, '18.09.2026', '2026-9-18', '2026-02-31', '2026-13-01']) {
        assert.equal(shiftYmd(bad, 1), '', String(bad));
    }
});

// ─── Поведение формы: минимальный DOM-стаб ──────────────────────────
function mountForm(value) {
    const listeners = new Map();
    const on = (el) => (type, fn) => { listeners.set(el.key + ':' + type, fn); };
    const dateFrom = { key: 'from', value };
    dateFrom.addEventListener = on(dateFrom);
    const dateTo = { key: 'to', value };
    const mk = (step) => {
        const b = { key: 'step' + step, dataset: { dateStep: String(step) } };
        b.addEventListener = on(b);
        return b;
    };
    const prev = mk(-1), next = mk(1);
    let submits = 0;
    const form = {
        key: 'form',
        querySelector: (sel) => (sel === 'input[name="dateFrom"]' ? dateFrom : sel === '.pd3-date--to' ? dateTo : null),
        querySelectorAll: (sel) => (sel === '[data-date-step]' ? [prev, next] : []),
        requestSubmit: () => { submits++; },
    };
    form.addEventListener = on(form);
    globalThis.document = { getElementById: (id) => (id === 'pd3DateForm' ? form : null) };
    initDateForm();
    const fire = (el, type) => listeners.get(el.key + ':' + type)?.();
    return { dateFrom, dateTo, prev, next, fire, submits: () => submits };
}

test('‹ уводит на вчера, › на завтра — и отправляет форму', () => {
    const f = mountForm('2026-10-01');
    f.fire(f.prev, 'click');
    assert.equal(f.dateFrom.value, '2026-09-30');
    assert.equal(f.dateTo.value, '2026-09-30', 'скрытый dateTo синхронизирован');
    assert.equal(f.submits(), 1);

    const g = mountForm('2026-12-31');
    g.fire(g.next, 'click');
    assert.equal(g.dateFrom.value, '2027-01-01');
    assert.equal(g.dateTo.value, '2027-01-01');
    assert.equal(g.submits(), 1);
});

test('ручной выбор даты синхронизирует dateTo и отправляет форму', () => {
    const f = mountForm('2026-09-18');
    f.dateFrom.value = '2026-09-02';
    f.fire(f.dateFrom, 'change');
    assert.equal(f.dateTo.value, '2026-09-02');
    assert.equal(f.submits(), 1);
});

test('при битой дате стрелка ничего не отправляет', () => {
    const f = mountForm('');
    f.fire(f.next, 'click');
    assert.equal(f.submits(), 0);
});
