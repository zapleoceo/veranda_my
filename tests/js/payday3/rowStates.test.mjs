// Цвет строк по ВСЕМ видам связей сразу.
// Поступление связано, если у него есть чек ИЛИ приход Poster; транзакция
// Poster — если у неё есть расход ИЛИ поступление. Раньше каждый модуль
// красил по своим связям, и «чужая» связь перекрашивалась в красный.
//
// Запуск: node --test "tests/js/**/*.test.mjs"

import { test } from 'node:test';
import assert from 'node:assert/strict';

class Row {
    constructor(data, cls = 'pd3-row row-red') {
        this.dataset = data;
        this.cls = new Set(cls.split(' '));
        this.classList = {
            contains: (c) => this.cls.has(c),
            remove: (...cs) => cs.forEach((c) => this.cls.delete(c)),
            add: (c) => this.cls.add(c),
        };
    }
    state() { return [...this.cls].find((c) => c.startsWith('row-')); }
}

const tables = {};
globalThis.document = { querySelectorAll: (sel) => tables[sel] ?? [] };
const { classify, edgesBy, paintRowStates } = await import('../../../payday3/assets/js/ui/rowStates.js');

test('classify: ручная важнее авто, жёлтая важнее зелёной', () => {
    assert.equal(classify([]), 'row-red');
    assert.equal(classify(undefined), 'row-red');
    assert.equal(classify([{ link_type: 'auto_green' }]), 'row-green');
    assert.equal(classify([{ link_type: 'auto_green' }, { link_type: 'auto_yellow' }]), 'row-yellow');
    assert.equal(classify([{ link_type: 'auto_yellow' }, { link_type: 'manual', is_manual: 1 }]), 'row-gray');
});

test('edgesBy: связи из нескольких списков по одному id', () => {
    const map = edgesBy([[[{ sepay_id: 7, a: 1 }], 'sepay_id'], [[{ sepay_id: '7', b: 1 }, { sepay_id: 8 }], 'sepay_id']]);
    assert.equal(map.get(7).length, 2, 'чек и приход Poster складываются у одного поступления');
    assert.equal(map.get(8).length, 1);
});

test('перекраска из объединения связей всех трёх пар', () => {
    const sepayCheck  = new Row({ sepayId: '1' });
    const sepayIncome = new Row({ sepayId: '2' });     // 16.09 SHIRIAEVA — без чека
    const sepayNone   = new Row({ sepayId: '3' }, 'pd3-row row-green');   // была связана — связь сняли
    const sepayHidden = new Row({ sepayId: '4' }, 'pd3-row row-hidden is-hidden');
    const check       = new Row({ posterId: '100' });
    const mail        = new Row({ mailUid: '50' });
    const finExpense  = new Row({ financeId: '900' });
    const finIncome   = new Row({ financeId: '901' });
    const finFree     = new Row({ financeId: '902' }, 'pd3-row row-gray');
    Object.assign(tables, {
        '#pd3SepayTbody tr.pd3-row': [sepayCheck, sepayIncome, sepayNone, sepayHidden],
        '#pd3PosterTable tr.pd3-row': [check],
        '#pd3OutMailTbody tr.pd3-row': [mail],
        '#pd3OutFinanceTable tr.pd3-row': [finExpense, finIncome, finFree],
    });

    paintRowStates({
        checkLinks:  [{ sepay_id: 1, poster_transaction_id: 100, link_type: 'auto_green' }],
        mailLinks:   [{ mail_uid: 50, finance_id: 900, link_type: 'manual', is_manual: 1 }],
        incomeLinks: [{ sepay_id: 2, finance_id: 901, link_type: 'auto_yellow' }],
    });

    assert.equal(sepayCheck.state(),  'row-green',  'поступление с чеком');
    assert.equal(sepayIncome.state(), 'row-yellow', 'поступление с приходом Poster — тоже связано');
    assert.equal(sepayNone.state(),   'row-red',    'связь сняли — снова красная');
    assert.ok(sepayHidden.cls.has('row-hidden') && !sepayHidden.cls.has('row-red'), 'скрытые не трогаем');
    assert.equal(check.state(),       'row-green');
    assert.equal(mail.state(),        'row-gray');
    assert.equal(finExpense.state(),  'row-gray',   'транзакция с расходом');
    assert.equal(finIncome.state(),   'row-yellow', 'транзакция с поступлением — тоже связана');
    assert.equal(finFree.state(),     'row-red');
});
