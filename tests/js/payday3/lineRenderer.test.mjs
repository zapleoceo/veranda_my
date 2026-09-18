// Отрисовка связей (ui/lineRenderer.js).
//
// 1) Один rAF на все три рендерера и две фазы: сначала ВСЕ чтения
//    (rect'ы), потом ВСЕ записи (пути, ×). Раньше чтение и запись
//    чередовались на каждой связи — принудительный layout на каждую.
// 2) SVG-пути переиспользуются по ключу связи, а не создаются заново
//    на каждом кадре прокрутки.
//
// Запуск: node --test "tests/js/**/*.test.mjs"

import { test } from 'node:test';
import assert from 'node:assert/strict';

// ── минимальный DOM ────────────────────────────────────────────────
class El {
    constructor(tag) {
        this.tag = tag; this.attrs = new Map(); this.children = []; this.style = {};
        this.parent = null; this.listeners = {};
    }
    setAttribute(k, v) { this.attrs.set(k, String(v)); log.push('write'); }
    getAttribute(k) { return this.attrs.has(k) ? this.attrs.get(k) : null; }
    appendChild(c) { c.parent = this; this.children.push(c); return c; }
    remove() { if (this.parent) this.parent.children = this.parent.children.filter((c) => c !== this); this.parent = null; }
    addEventListener(t, fn) { this.listeners[t] = fn; }
}
const log = [];
const anchors = new Map();
const rect = (top, left = 0) => ({ top, bottom: top + 10, left, width: 10, height: 10 });
const anchor = (id, top, left) => {
    anchors.set(id, { getClientRects: () => [1], getBoundingClientRect: () => { log.push('read'); return rect(top, left); } });
};
globalThis.document = {
    createElementNS: (_, tag) => new El(tag),
    createElement: (tag) => new El(tag),
    getElementById: (id) => anchors.get(id) ?? null,
    documentElement: { className: '' },
};

const { LineRenderer, createFrameScheduler } = await import('../../../payday3/assets/js/ui/lineRenderer.js');

function makeRenderer() {
    const container = { getBoundingClientRect: () => { log.push('read'); return { top: 0, left: 0, width: 800, height: 600 }; },
                        scrollLeft: 0, scrollTop: 0, addEventListener() {} };
    const layer = new El('div');
    return { r: new LineRenderer({ container, layer }), layer };
}
const pathsOf = (r) => r._group.children;

test('планировщик: сначала все чтения, потом все записи — для всех рендереров', () => {
    const order = [];
    let flush = null;
    const frames = createFrameScheduler((cb) => { flush = cb; });
    const fake = (name) => ({
        _measure: () => { order.push('measure ' + name); return { name }; },
        _apply:   (p) => order.push('apply ' + p.name),
    });
    const a = fake('in'), b = fake('out'), c = fake('income');
    frames.schedule(a); frames.schedule(b); frames.schedule(a); frames.schedule(c);
    flush();
    assert.deepEqual(order, ['measure in', 'measure out', 'measure income', 'apply in', 'apply out', 'apply income']);
});

test('перерисовка не пишет между чтениями и переиспользует пути', () => {
    anchor('pd3-sepay-anchor-1', 100, 10);
    anchor('pd3-poster-anchor-9', 200, 500);
    anchor('pd3-sepay-anchor-2', 150, 10);
    anchor('pd3-poster-anchor-8', 250, 500);
    const { r } = makeRenderer();
    r._links = [{ sepay_id: 1, poster_transaction_id: 9 }, { sepay_id: 2, poster_transaction_id: 8 }];

    log.length = 0;
    const plan = r._measure();
    assert.ok(log.every((x) => x === 'read'), 'фаза измерения ничего не пишет');
    r._apply(plan);
    const first = [...pathsOf(r)];
    assert.equal(first.length, 4, 'по два пути (ореол + линия) на связь');
    assert.equal(r._buttons.size, 2);

    // Прокрутка: якоря сдвинулись — те же узлы, новый d.
    anchor('pd3-sepay-anchor-1', 120, 10);
    const d0 = first[0].getAttribute('d');
    r._apply(r._measure());
    assert.deepEqual(pathsOf(r), first, 'пути не пересоздаются');
    assert.notEqual(first[0].getAttribute('d'), d0);

    // Связь сняли — её пути и × уходят.
    r._links = [{ sepay_id: 2, poster_transaction_id: 8 }];
    r._apply(r._measure());
    assert.equal(pathsOf(r).length, 2);
    assert.equal(r._buttons.size, 1);
});

test('× вызывает setOnUnlink с актуальной записью связи', () => {
    const { r } = makeRenderer();
    const got = [];
    r.setOnUnlink((l) => got.push(l));
    r._links = [{ sepay_id: 2, poster_transaction_id: 8, v: 1 }];
    r._apply(r._measure());
    r._links = [{ sepay_id: 2, poster_transaction_id: 8, v: 2 }];
    r._apply(r._measure());
    const btn = [...r._buttons.values()][0];
    btn.listeners.click({ preventDefault() {} });
    assert.equal(got[0].v, 2);
});
