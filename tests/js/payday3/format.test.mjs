// Общие хелперы payday3: формат VND, разбор ввода, экранирование,
// query-string периода, cache-bust. Раньше у каждого модуля была своя
// копия (до 7 штук) с разным поведением на пустом значении.
//
// Запуск: node --test "tests/js/**/*.test.mjs"

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { fmtVnd, parseVnd, esc, rangeQuery, withRange } from '../../../payday3/assets/js/ui/format.js';
import { versionQuery, versionedUrl } from '../../../payday3/assets/js/ui/cacheBust.js';
import { withBusy, setStatus } from '../../../payday3/assets/js/ui/busy.js';

test('fmtVnd: пробелы-разделители, округление, пустое значение', () => {
    assert.equal(fmtVnd(1234567), '1 234 567');
    assert.equal(fmtVnd(-5000.6), '-5 001');
    assert.equal(fmtVnd('abc'), '0');
    assert.equal(fmtVnd(null), '0', 'по умолчанию пусто = 0 (таблицы)');
    assert.equal(fmtVnd(undefined, { empty: '—' }), '—');
    assert.equal(fmtVnd('', { empty: '' }), '');
    assert.equal(fmtVnd(0, { empty: '' }), '0', 'ноль — это число, не пустота');
});

test('parseVnd: цифры и минус, пустое → null', () => {
    assert.equal(parseVnd('1 234 567 ₫'), 1234567);
    assert.equal(parseVnd('-5 000'), -5000);
    assert.equal(parseVnd(''), null);
    assert.equal(parseVnd('-'), null);
    assert.equal(parseVnd(undefined), null);
});

test('esc: текст и атрибуты', () => {
    assert.equal(esc(`<a href="x">'&'</a>`), '&lt;a href=&quot;x&quot;&gt;&#39;&amp;&#39;&lt;/a&gt;');
    assert.equal(esc(null), '');
    assert.equal(esc(42), '42');
});

test('withRange: период в query, с учётом уже имеющегося ?', () => {
    const r = { from: '2026-09-18', to: '2026-09-18' };
    assert.equal(rangeQuery(r), 'dateFrom=2026-09-18&dateTo=2026-09-18');
    assert.equal(withRange('/api/data', r), '/api/data?dateFrom=2026-09-18&dateTo=2026-09-18');
    assert.equal(withRange('/api/out/mail?include_hidden=1', r),
        '/api/out/mail?include_hidden=1&dateFrom=2026-09-18&dateTo=2026-09-18');
    assert.equal(withRange('/api/data', null), '/api/data', 'без периода URL не портится');
});

test('cacheBust: v= из URL модуля переносится на импорт', () => {
    assert.equal(versionQuery('https://x/payday3/assets/js/index.js?v=123'), '?v=123');
    assert.equal(versionQuery('file:///a/b.js'), '');
    assert.equal(versionedUrl('../api.js', 'https://x/js/ui/a.js?v=9'), 'https://x/js/api.js?v=9');
});

function fakeBtn() {
    const cls = new Set();
    const attrs = new Map();
    return {
        disabled: false,
        classList: { add: (c) => cls.add(c), remove: (c) => cls.delete(c), has: (c) => cls.has(c) },
        getAttribute: (a) => attrs.get(a) ?? null,
        setAttribute: (a, v) => attrs.set(a, v),
        removeAttribute: (a) => attrs.delete(a),
        cls,
    };
}

test('withBusy: кнопка занята на время действия, повторный клик игнорируется', async () => {
    const btn = fakeBtn();
    let runs = 0;
    let release;
    const run = withBusy(btn, () => { runs++; return new Promise((r) => { release = r; }); }, { label: 'Loading' });
    const p = run();
    assert.equal(btn.disabled, true);
    assert.ok(btn.cls.has('is-busy'));
    assert.equal(btn.getAttribute('aria-label'), 'Loading');
    await run();                                // клик по занятой кнопке
    assert.equal(runs, 1);
    release('ok');
    assert.equal(await p, 'ok');
    assert.equal(btn.disabled, false);
    assert.ok(!btn.cls.has('is-busy'));
    assert.equal(btn.getAttribute('aria-label'), null);
});

test('withBusy: ошибка уходит в onError, кнопка освобождается', async () => {
    const btn = fakeBtn();
    const errors = [];
    await withBusy(btn, async () => { throw new Error('boom'); }, { onError: (e) => errors.push(e.message) })();
    assert.deepEqual(errors, ['boom']);
    assert.equal(btn.disabled, false);
    await assert.rejects(withBusy(btn, async () => { throw new Error('x'); })(), /x/, 'без onError — пробрасывается');
});

test('setStatus: текст и цвет', () => {
    const cls = new Set(['is-error']);
    const el = { textContent: '', classList: { add: (c) => cls.add(c), remove: (...c) => c.forEach((x) => cls.delete(x)) } };
    setStatus(el, 'Сохранено', 'ok');
    assert.equal(el.textContent, 'Сохранено');
    assert.deepEqual([...cls], ['is-ok']);
    setStatus(null, 'x');                       // нет элемента — не падаем
});
