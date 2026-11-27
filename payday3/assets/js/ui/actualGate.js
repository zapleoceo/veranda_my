// Готовность полей «Факт.» (ui/balances.js).
//
// Пока сохранённые значения не загружены, пустое поле читается как null.
// Если такое значение уйдёт на сервер — оно затрёт реальные данные за день
// (и это уже случалось: ранний клик Telegram отправлял пустой снимок).
// Поэтому любой путь сохранения — blur, таймер, beforeunload и кнопка
// Telegram — спрашивает этот шлюз и не пишет, пока он не открыт.

'use strict';

/** @typedef {'idle'|'loading'|'ready'|'failed'} GateState */

export function createActualGate() {
    /** @type {GateState} */
    let state = 'idle';
    /** @type {Array<(ok: boolean) => void>} */
    let waiters = [];

    const settle = (next, ok) => {
        state = next;
        const pending = waiters;
        waiters = [];
        for (const resolve of pending) resolve(ok);
    };

    return {
        /** Сохранённые значения загружены — можно писать и снимать. */
        get ready()     { return state === 'ready'; },
        /** Загрузка идёт прямо сейчас. */
        get isLoading() { return state === 'loading'; },

        startLoading() { state = 'loading'; },
        markReady()    { settle('ready', true); },
        markFailed()   { settle('failed', false); },

        /**
         * Промис, который закрывается после текущей загрузки:
         * true — значения загружены, false — загрузка упала.
         * Если загрузки нет, вернёт результат последней (или false до первой).
         */
        whenSettled() {
            if (state === 'ready')  return Promise.resolve(true);
            if (state === 'failed' || state === 'idle') return Promise.resolve(false);
            return new Promise((resolve) => waiters.push(resolve));
        },
    };
}
