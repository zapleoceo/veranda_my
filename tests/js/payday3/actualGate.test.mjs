// Шлюз готовности полей «Факт.» (payday3/assets/js/ui/actualGate.js).
//
// Пока значения не загружены, сохранение и снимок Telegram не должны
// уходить на сервер: пустые поля читаются как null и затирают данные.
//
// Запуск: node --test "tests/js/**/*.test.mjs"

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createActualGate } from '../../../payday3/assets/js/ui/actualGate.js';

test('до загрузки шлюз закрыт и ждать нечего', async () => {
    const gate = createActualGate();
    assert.equal(gate.ready, false);
    assert.equal(gate.isLoading, false);
    assert.equal(await gate.whenSettled(), false);
});

test('во время загрузки whenSettled ждёт и открывается по markReady', async () => {
    const gate = createActualGate();
    gate.startLoading();
    assert.equal(gate.isLoading, true);
    const waiting = gate.whenSettled();
    gate.markReady();
    assert.equal(await waiting, true);
    assert.equal(gate.ready, true);
    assert.equal(gate.isLoading, false);
});

test('упавшая загрузка не открывает шлюз и будит ждущих с false', async () => {
    const gate = createActualGate();
    gate.startLoading();
    const first = gate.whenSettled();
    const second = gate.whenSettled();
    gate.markFailed();
    assert.equal(await first, false);
    assert.equal(await second, false);
    assert.equal(gate.ready, false);
});

test('после успешной загрузки whenSettled сразу true', async () => {
    const gate = createActualGate();
    gate.startLoading();
    gate.markReady();
    assert.equal(await gate.whenSettled(), true);
});

test('повторная загрузка после сбоя открывает шлюз', async () => {
    const gate = createActualGate();
    gate.startLoading();
    gate.markFailed();
    gate.startLoading();
    const waiting = gate.whenSettled();
    gate.markReady();
    assert.equal(await waiting, true);
});
