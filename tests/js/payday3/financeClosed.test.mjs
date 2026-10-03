import { test } from 'node:test';
import assert from 'node:assert/strict';
import { transferRowClosed, grabRowClosed, financeAllClosed } from '../../../payday3/assets/js/ui/financeClosed.js';

test('transfer row: zero sum is closed, missing data is not', () => {
    assert.equal(transferRowClosed({ total_vnd: 0, found: [] }), true);
    assert.equal(transferRowClosed({ total_vnd: null }), false);
    assert.equal(transferRowClosed(undefined), false);
    assert.equal(transferRowClosed({ total_vnd: 0, error: 'Poster down' }), false);
});

test('transfer row: closed only when a found tx equals the total', () => {
    assert.equal(transferRowClosed({ total_vnd: 103833, found: [] }), false);
    assert.equal(transferRowClosed({ total_vnd: 103833, found: [{ sum_minor: 100000 }] }), false);
    assert.equal(transferRowClosed({ total_vnd: 103833, found: [{ sum_minor: -103833 }] }), true);
});

test('grab row: found or no surplus is closed; pending top-up / not reconciled is not', () => {
    assert.equal(grabRowClosed({ found: [{ transaction_id: 1 }] }), true);
    assert.equal(grabRowClosed({ reason: 'no_surplus', found: [] }), true);
    assert.equal(grabRowClosed({ reason: 'ok', found: [] }), false);
    assert.equal(grabRowClosed({ reason: 'not_reconciled' }), false);
    assert.equal(grabRowClosed({ reason: 'no_fact' }), false);
});

test('all closed requires every row', () => {
    const ok = { vietnam: { total_vnd: 0 }, tips: { total_vnd: 5, found: [{ sum_minor: 5 }] }, grab: { reason: 'no_surplus' } };
    assert.equal(financeAllClosed(ok), true);
    assert.equal(financeAllClosed({ ...ok, tips: { total_vnd: 5, found: [] } }), false);
    assert.equal(financeAllClosed({ ...ok, grab: { reason: 'ok' } }), false);
});
