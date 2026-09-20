import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

// Deliberately small DOM: records real renderer output without a browser or API.
export class Element {
    constructor(tag = 'div') {
        this.tagName = tag.toUpperCase();
        this.children = [];
        this.dataset = {};
        this.attributes = {};
        this.ownerDocument = { createElement: tag => new Element(tag) };
        this.style = { setProperty(name, value) { this[name] = value; } };
        this.className = '';
        this.classList = {
            add: (...names) => { this.className = [...new Set([...this.className.split(/\s+/).filter(Boolean), ...names])].join(' '); },
            remove: (...names) => { this.className = this.className.split(/\s+/).filter(name => !names.includes(name)).join(' '); },
            contains: name => this.className.split(/\s+/).includes(name),
            toggle: (name, force) => { const active = force ?? !this.classList.contains(name); this.classList[active ? 'add' : 'remove'](name); return active; },
        };
    }
    set innerHTML(value) { this.html = value; this.children = []; }
    get innerHTML() { return this.html || ''; }
    appendChild(child) { this.children.push(child); child.parentNode = this; return child; }
    append(...children) { children.forEach(child => this.appendChild(child)); }
    get childNodes() { return this.children; }
    replaceChildren(...children) { this.children = []; this.append(...children); }
    prepend(child) { this.children.unshift(child); child.parentNode = this; }
    setAttribute(name, value) { this.attributes[name] = String(value); }
    getAttribute(name) { return this.attributes[name] ?? null; }
    removeAttribute(name) { delete this.attributes[name]; }
    closest() { return this.canvas || null; }
    querySelectorAll(selector) { return this.children.flatMap(child => [...(selector.startsWith('.') && child.classList.contains(selector.slice(1)) ? [child] : []), ...child.querySelectorAll(selector)]); }
    querySelector(selector) { return this.querySelectorAll(selector)[0] || null; }
    remove() { if (this.parentNode) this.parentNode.children = this.parentNode.children.filter(child => child !== this); }
}

const app = readFileSync(new URL('../../tr3/assets/app.js', import.meta.url), 'utf8');
const start = app.indexOf('const renderHallTables =');
const end = app.indexOf('const loadCinemaLayout =', start);
assert.ok(start >= 0 && end > start, 'renderer seam must exist');
export const presentationSource = () => readFileSync(new URL('../../tr3/assets/plan-presentation.js', import.meta.url), 'utf8');
export function presentation() {
    const sandbox = { module: { exports: {} }, document: { createElement: tag => new Element(tag) } };
    sandbox.window = sandbox;
    runInNewContext(presentationSource(), sandbox);
    return sandbox.TR3PlanPresentation || sandbox.module.exports;
}

const row = (id, x, y, w, h) => ({ table_id: id, table_x: x, table_y: y, table_width: w, table_height: h, table_shape: 'square', table_title: `Poster ${id}` });
const rows = [row(101, -50, -20, 100, 60), row(202, 170, 90, 70, 80), row(303, 10, 10, 1, 1), row(404, -900, -900, 5000, 5000), row(505, 0, 0, 20, 20)];
const settings = {
    101: { scheme_num: '1', capacity: 8, bookable: 1, show_on_canvas: 1 },
    202: { scheme_num: '10', display_name: 'Custom table', capacity: 4, bookable: 1, show_on_canvas: 1 },
    303: { display_name: 'Bar', capacity: 99, bookable: 0, show_on_canvas: 1 },
    404: { scheme_num: '22', capacity: 20, bookable: 1, show_on_canvas: 0 },
};
export function render({ hallId = 2, rotate = 0, source = app.slice(start, end), helper = presentation(), tableRows = rows, tableSettings = settings } = {}) {
    const tables = new Element();
    tables.canvas = new Element();
    tables.canvas.clientWidth = 820;
    tables.canvas.clientHeight = 620;
    const decor = new Element();
    const calls = [];
    const sandbox = {
        document: { createElement: tag => new Element(tag) },
        tableSettingsByHall: { [hallId]: tableSettings }, decorByHall: {}, hallSettingsByHall: { [hallId]: { rotate_180: rotate } },
        t: value => value, esc: value => String(value).replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;'),
        showSystemModal: value => calls.push(value),
        applyCapsToActiveTables: () => calls.push('caps'), requestBindTables: () => calls.push('bind'), applyAvailabilityStyles: () => calls.push('availability'),
        TR3PlanPresentation: helper, console,
    };
    sandbox.window = sandbox;
    runInNewContext(`${source}\nglobalThis.render = renderHallTables;`, sandbox);
    sandbox.render(tables, decor, hallId, tableRows);
    return { tables, decor, calls };
}

for (const hallId of [2, 7]) for (const rotate of [0, 1]) {
    test(`hall ${hallId}, rotation ${rotate}: Poster geometry, hidden filtering and booking identity survive decoration`, () => {
        const before = JSON.stringify({ rows, settings });
        const { tables, calls } = render({ hallId, rotate });
        assert.equal(tables.children.length, 3);
        const scale = 764 / 290;
        const expected = rows.slice(0, 3).map(item => {
            const x = rotate ? 190 - item.table_x - item.table_width : item.table_x;
            const y = rotate ? 150 - item.table_y - item.table_height : item.table_y;
            return [Math.round((x + 50) * scale + 28), Math.round((y + 20) * scale + 28), Math.max(34, Math.round(item.table_width * scale)), Math.max(28, Math.round(item.table_height * scale))].map(value => `${value}px`);
        });
        assert.deepEqual(tables.children.map(element => ['left', 'top', 'width', 'height'].map(key => element.style[key])), expected);
        assert.deepEqual(tables.children.map(element => ({ ...element.dataset })), [
            { bookable: '1', cap: '8', posterTableId: '101', tableLabel: '1' },
            { bookable: '1', cap: '4', posterTableId: '202', tableLabel: 'Custom table' },
            { bookable: '0', cap: '', posterTableId: '303', tableLabel: 'Bar' },
        ]);
        assert.ok(tables.children.every(element => element.tagName === 'BUTTON' && element.classList.contains('table') && element.innerHTML.includes('table-badge')));
        assert.ok(tables.children[2].classList.contains('disabled'));
        assert.equal(tables.children[0].classList.contains('plan-gazebo'), hallId === 2);
        assert.deepEqual(calls, ['caps', 'bind', 'availability']);
        assert.equal(JSON.stringify({ rows, settings }), before, 'input rows and settings are immutable');
    });
}

test('empty settings clear stale tables and retain the existing unavailable modal', () => {
    const result = render({ tableSettings: {} });
    assert.equal(result.tables.children.length, 0);
    assert.deepEqual(result.calls, ['settings_unavailable']);
});

test('no visible rows produce no interactive buttons', () => {
    const result = render({ tableRows: [rows[3], rows[4]] });
    assert.equal(result.tables.children.length, 0);
    assert.deepEqual(result.calls, []);
});

test('missing presentation helper retains geometry and availability hooks', () => {
    const result = render({ helper: null });
    assert.equal(result.tables.children.length, 3);
    assert.deepEqual(result.calls, ['caps', 'bind', 'availability']);
});

test('a throwing presentation helper cannot prevent binding or availability refresh', () => {
    const result = render({ helper: { decorate() { throw new Error('presentation unavailable'); } } });
    assert.equal(result.tables.children.length, 3);
    assert.deepEqual(result.calls, ['caps', 'bind', 'availability']);
});

test('furniture roles follow hall and scheme number, never Poster identity or display label', () => {
    const api = presentation();
    for (const schemeNum of ['1', '2', '3', '6', '9']) assert.equal(api.classify(2, { schemeNum, posterId: 999, label: 'renamed', bookable: true }), 'gazebo');
    assert.equal(api.classify(2, { schemeNum: '4', posterId: 1, bookable: true }), 'glass');
    for (const schemeNum of ['5', '7', '8']) assert.equal(api.classify(2, { schemeNum, posterId: 4, bookable: true }), 'glass');
    for (const schemeNum of ['10', '11', '12', '13']) assert.equal(api.classify(2, { schemeNum, posterId: 999, label: 'renamed', bookable: true }), 'counter');
    assert.equal(api.classify(2, { schemeNum: '', posterId: 1, label: 'Room', bookable: true }), 'room');
    assert.equal(api.classify(7, { schemeNum: '1', bookable: true }), null);
    assert.equal(api.classify(2, { schemeNum: '1', bookable: false, label: 'unknown' }), null);
    assert.equal(api.classify(2, { label: 'Cashier', bookable: false }), 'cashier');
});

const sceneItems = () => [
    { schemeNum: '10', bookable: true, x: 30, y: 20, w: 80, h: 50, element: new Element('button') },
    { schemeNum: '1', bookable: true, x: 40, y: 250, w: 100, h: 80, element: new Element('button') },
    { schemeNum: '', label: 'Bar', bookable: false, x: 400, y: 10, w: 100, h: 40, element: new Element('button') },
];

test('terrain follows table geometry and omits fountain when its terrace anchors are absent', () => {
    const api = presentation();
    const items = sceneItems().map(({ element, ...item }) => Object.freeze(item));
    const scene = api.deriveScene(2, Object.freeze(items), 820, 620);
    assert.ok(scene.terrace.h > 70 && scene.terrace.h < 250);
    assert.equal(scene.lawn.y, scene.terrace.h);
    assert.equal(scene.lawn.h + scene.terrace.h, 620);
    assert.equal(scene.fountain, null);
    const moved = items.map(item => ({ ...item, y: item.y + 30 }));
    assert.equal(api.deriveScene(2, moved, 820, 620).lawn.y, scene.lawn.y + 30);
});

// Sanitized public hall-2 rendered bounds, rotate_180=1, captured 2026-09-20.
// Geometry only; no reservations, customers or availability records.
const liveBounds = [
    ['10',469,371,98,49], ['11',371,371,98,49], ['12',224,371,98,49], ['13',126,371,98,49],
    ['14',396,298,49,49], ['15',298,298,49,49], ['16',200,298,49,49],
    ['17',592,175,49,98], ['18',494,175,49,98], ['19',396,175,49,98], ['20',298,175,49,98], ['21',200,175,49,98], ['22',53,200,98,98],
    ['1',666,371,98,74], ['2',666,445,98,74], ['3',666,518,98,74], ['4',567,518,74,74], ['5',469,469,74,74],
    ['6',347,518,98,74], ['7',249,469,74,74], ['8',151,518,74,74], ['9',28,494,98,74],
    ['Room',592,102,98,49], ['Bar',175,28,270,74], ['Stage',28,53,123,74], ['Cashier',469,28,98,74],
].map(([number,x,y,w,h]) => Object.freeze({ schemeNum: /^\d+$/.test(number) ? number : '', label: number, bookable: !['Bar','Stage','Cashier'].includes(number), x,y,w,h }));
const overlaps = (a,b) => a.x < b.x+b.w && a.x+a.w > b.x && a.y < b.y+b.h && a.y+a.h > b.y;

test('actual rotated hall keeps all tables 10–22 tiled and raises the lawn in the right-hand notch', () => {
    const scene = presentation().deriveScene(2, Object.freeze(liveBounds), 820, 620);
    const numbered = n => liveBounds.find(item => item.schemeNum === String(n));
    assert.ok(scene.lawnNotch && scene.terraceClip);
    assert.ok(scene.lawnNotch.x > numbered(10).x + numbered(10).w);
    assert.ok(scene.lawnNotch.y > numbered(17).y + numbered(17).h);
    assert.ok(scene.lawnNotch.y < numbered(1).y);
    assert.equal(scene.lawnNotch.y + scene.lawnNotch.h, scene.lawn.y);
    assert.equal(scene.lawnNotch.x + scene.lawnNotch.w, 820);
    for (let number=10; number<=22; number++) {
        const item = numbered(number);
        assert.ok(item.y + item.h <= scene.terrace.h, `table ${number} fully on tile`);
        assert.ok(!overlaps(item,scene.lawn) && !overlaps(item,scene.lawnNotch), `no grass under table ${number}`);
    }
    const f = scene.fountain;
    assert.ok(f, 'required fountain remains visible in the live pocket');
    assert.equal(f.x, scene.lawnNotch.x);
    assert.equal(f.y, scene.lawnNotch.y);
    assert.ok(f.x > numbered(10).x + numbered(10).w);
    assert.ok(f.y > numbered(17).y + numbered(17).h);
    assert.ok(f.y + f.h < numbered(1).y);
    assert.ok(f.x >= scene.lawnNotch.x && f.y >= scene.lawnNotch.y);
    const cx=f.x+f.w/2, cy=f.y+f.h/2;
    for (const item of liveBounds) assert.ok(Math.hypot(Math.max(item.x-cx,0,cx-item.x-item.w),Math.max(item.y-cy,0,cy-item.y-item.h)) > f.w/2, `fountain clear of ${item.label}`);
});

test('an occupied notch suppresses the fountain rather than overlaying another native footprint', () => {
    const scene = presentation().deriveScene(2, [...liveBounds, { schemeNum:'',label:'Room',bookable:true,x:570,y:300,w:100,h:70 }],820,620);
    assert.equal(scene.fountain,null);
});

test('malformed and other-hall scenes fail closed without manufacturing geometry', () => {
    const api = presentation();
    for (const items of [null, [], [{ x: NaN, y: 0, w: 20, h: 20, schemeNum: '1' }]]) assert.equal(api.deriveScene(2, items, 820, 620), null);
    assert.equal(api.deriveScene(7, sceneItems(), 820, 620), null);
    assert.equal(api.deriveScene(2, sceneItems(), Infinity, 620), null);
});

test('decoration preserves booking elements and safely renders adversarial translation as inert text', () => {
    const api = presentation();
    const tablesEl = new Element(), decorEl = new Element();
    const items = sceneItems();
    items.forEach((item, index) => {
        item.element.dataset.posterTableId = String(100 + index);
        item.element.style.left = `${item.x}px`;
        item.element.innerHTML = '<span class="table-badge">original</span>';
        tablesEl.appendChild(item.element);
    });
    const before = items.map(item => JSON.stringify([item.element.dataset, item.element.style, item.element.innerHTML]));
    const attack = '<img src=x onerror=alert(1)>';
    assert.equal(api.decorate({ hallId: 2, tablesEl, decorEl, items, width: 820, height: 620, translate: () => attack }), true);
    assert.equal(tablesEl.children.length, 3);
    items.forEach((item, index) => assert.equal(JSON.stringify([item.element.dataset, item.element.style, item.element.innerHTML]), before[index]));
    assert.equal(items[2].element.querySelector('.plan-plaque').textContent, attack);
    const walk = element => element.children.flatMap(child => [child, ...walk(child)]);
    const additions = [...walk(decorEl), ...items.flatMap(item => walk(item.element))];
    assert.ok(additions.length > 10);
    assert.ok(additions.every(element => element.tagName === 'SPAN' && element.getAttribute('aria-hidden') === 'true' && !element.innerHTML));
});

test('decoration is isolated from network/storage and rolls back when the DOM commit fails', () => {
    const sandbox = { module: { exports: {} } };
    for (const name of ['fetch', 'XMLHttpRequest', 'localStorage', 'sessionStorage']) Object.defineProperty(sandbox, name, { get() { throw new Error(`unexpected side effect: ${name}`); } });
    runInNewContext(presentationSource(), sandbox);
    const tablesEl = new Element(), decorEl = new Element(), old = new Element();
    decorEl.appendChild(old);
    const items = sceneItems();
    const original = decorEl.replaceChildren.bind(decorEl);
    let first = true;
    decorEl.replaceChildren = (...children) => { if (first) { first = false; throw new Error('simulated DOM failure'); } original(...children); };
    assert.equal(sandbox.module.exports.decorate({ hallId: 2, tablesEl, decorEl, items, width: 820, height: 620 }), false);
    assert.equal(decorEl.children[0], old);
    assert.ok(items.every(item => item.element.children.length === 0 && !item.element.classList.contains('plan-table')));
});

test('adversarial display label stays escaped in native badge and literal in booking dataset', () => {
    const attack = '<img src=x onerror=alert(1)>';
    const result = render({ tableRows: [rows[0]], tableSettings: { 101: { ...settings[101], display_name: attack } } });
    const button = result.tables.children[0];
    assert.equal(button.dataset.tableLabel, attack);
    assert.ok(!button.innerHTML.includes('<img'));
    assert.ok(button.innerHTML.includes('&lt;img'));
});

test('tree and scooter approach follow room and table anchors without consuming the lawn', () => {
    const api = presentation();
    const scene = api.deriveScene(2, liveBounds, 820, 620);
    assert.equal(scene.driveway.x, 690);
    assert.equal(scene.driveway.w, 130);
    assert.equal(scene.driveway.h, scene.lawnNotch.y);
    assert.equal(scene.tree.x, 420);
    assert.equal(scene.tree.x + scene.tree.w, 567);
    assert.ok(scene.tree.y + scene.tree.trunkY > 273);
    assert.ok(scene.tree.y + scene.tree.trunkY < 371);
    const missing = api.deriveScene(2, liveBounds.filter(i => i.label !== 'Room' && i.schemeNum !== '18'), 820, 620);
    assert.equal(missing.driveway, null);
    assert.equal(missing.tree, null);
});

test('four garden steps occupy only the gap between11 and12 and fail closed if blocked', () => {
    const api = presentation();
    const scene = api.deriveScene(2, liveBounds, 820, 620);
    assert.ok(scene.steps.x > 322 && scene.steps.x + scene.steps.w < 371);
    assert.ok(scene.steps.y < scene.lawn.y && scene.steps.y + scene.steps.h > scene.lawn.y);
    assert.ok(liveBounds.every(i => !overlaps(scene.steps, i)));
    assert.equal(api.deriveScene(2, [...liveBounds, { ...scene.steps, label: 'Obstacle', bookable: false }], 820, 620).steps, null);
    assert.equal(api.deriveScene(2, liveBounds.filter(i => i.schemeNum !== '12'), 820, 620).steps, null);
    assert.equal(api.deriveScene(2, liveBounds.filter(i => i.schemeNum !== '12'), 820, 620).stepsAfter13, null);
    assert.equal(api.deriveScene(2, [...liveBounds, { ...scene.steps, label:'Obstacle', bookable:false }], 820, 620).stepsAfter13.w, scene.steps.w);
    const items = liveBounds.map(i => ({ ...i, element: new Element('button') }));
    const tablesEl = new Element();
    const decorEl = new Element();
    api.decorate({ hallId:2, items, tablesEl, decorEl, width:820, height:620 });
    assert.equal(decorEl.querySelectorAll('.plan-steps').length,2);
    assert.equal(decorEl.querySelectorAll('.plan-step').length,8);
    assert.equal(scene.stepsAfter13.w, scene.steps.w);
    assert.equal(scene.stepsAfter13.h, scene.steps.h);
    assert.ok(scene.stepsAfter13.x + scene.stepsAfter13.w < 126);
    assert.ok(liveBounds.every(i => !overlaps(scene.stepsAfter13, i)));
    assert.equal(api.deriveScene(2, liveBounds.filter(i => i.schemeNum !== '13'), 820, 620).stepsAfter13, null);
    assert.equal(api.deriveScene(2, [...liveBounds, { ...scene.stepsAfter13, label:'Obstacle', bookable:false }], 820, 620).stepsAfter13, null);
});

test('landscaping stays on lawn and preserves all tables, fountain and stair openings', () => {
    const api = presentation();
    const scene = api.deriveScene(2, liveBounds, 820, 620);
    const { shrubs, stones } = scene.landscape;
    assert.ok(shrubs.length > 0 && stones.length > 0);
    const obstacles = [...liveBounds, scene.fountain, scene.steps, scene.stepsAfter13].filter(Boolean);
    const inside = (box, area) => area && box.x >= area.x && box.y >= area.y && box.x + box.w <= area.x + area.w && box.y + box.h <= area.y + area.h;
    for (const box of [...shrubs, ...stones]) {
        assert.ok(inside(box, scene.lawn) || inside(box, scene.lawnNotch));
        assert.ok(obstacles.every(obstacle => !overlaps(box, obstacle)));
    }
    assert.ok(shrubs.every(shrub => stones.every(stone => !overlaps(shrub, stone))));
    assert.equal(JSON.stringify(scene.landscape), JSON.stringify(api.deriveScene(2, liveBounds, 820, 620).landscape));
});

test('tree stone bed reaches tile edge and stays clear of furniture', () => {
    const scene = presentation().deriveScene(2, liveBounds, 820, 620);
    assert.ok(scene.stoneBed);
    assert.equal(scene.stoneBed.x + scene.stoneBed.w, scene.lawnNotch.x);
    assert.ok(liveBounds.every(i => !overlaps(scene.stoneBed,i)));
    const trunkX = scene.tree.x + scene.tree.trunkX;
    const trunkY = scene.tree.y + scene.tree.trunkY;
    assert.ok(trunkX > scene.stoneBed.x && trunkX < scene.stoneBed.x + scene.stoneBed.w);
    assert.ok(trunkY > scene.stoneBed.y && trunkY < scene.stoneBed.y + scene.stoneBed.h);
});
