// «Последний выигрывает» для загрузчиков (ui/coalesce.js).
//
// Раньше вызов во время загрузки либо выбрасывался (OUT, финкарточка,
// сохранение балансов), либо получал УЖЕ летящий промис (IN) — и данные,
// записанные вторым синком, не появлялись до ручного обновления.
// Теперь: вызов во время загрузки = ровно ещё один прогон после неё,
// и все опоздавшие получают его свежий результат.
//
// Запуск: node --test "tests/js/**/*.test.mjs"

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { coalesce } from '../../../payday3/assets/js/ui/coalesce.js';

/** Загрузчик, который отвечает «версией данных» и ждёт ручного release. */
function fakeLoader() {
    let version = 0;
    const gates = [];
    const calls = [];
    const fn = (...args) => {
        calls.push(args);
        const snapshot = ++version;
        return new Promise((resolve) => gates.push(() => resolve(snapshot)));
    };
    const release = async () => { gates.shift()(); await new Promise((r) => setTimeout(r, 0)); };
    return { fn, calls, release, pending: () => gates.length };
}

test('вызов во время загрузки не теряется и получает свежий прогон', async () => {
    const l = fakeLoader();
    const load = coalesce(l.fn);

    const first  = load();
    await new Promise((r) => setTimeout(r, 0));
    const second = load();                    // пришёл, пока первый летит
    const third  = load();                    // и ещё один

    assert.equal(l.calls.length, 1, 'пока первый не закончился — второй не стартует');
    await l.release();                        // первый завершился
    assert.equal(await first, 1);
    assert.equal(l.calls.length, 2, 'ровно ОДИН дополнительный прогон на всех опоздавших');

    await l.release();
    assert.equal(await second, 2, 'опоздавший получает НОВЫЕ данные, а не старый промис');
    assert.equal(await third, 2, 'все опоздавшие делят один прогон');
});

test('без наложений — каждый вызов отдельный прогон', async () => {
    const l = fakeLoader();
    const load = coalesce(l.fn);
    const a = load(); await new Promise((r) => setTimeout(r, 0)); await l.release();
    const b = load(); await new Promise((r) => setTimeout(r, 0)); await l.release();
    assert.deepEqual([await a, await b], [1, 2]);
});

test('повторный прогон берёт аргументы последнего вызова', async () => {
    const l = fakeLoader();
    const load = coalesce(l.fn);
    load('a');
    await new Promise((r) => setTimeout(r, 0));
    load('b');
    load('c');
    await l.release();
    assert.deepEqual(l.calls, [['a'], ['c']]);
    await l.release();
});

test('ошибка текущего прогона не отменяет повторный', async () => {
    let n = 0;
    const load = coalesce(async () => {
        n++;
        await new Promise((r) => setTimeout(r, 1));
        if (n === 1) throw new Error('429');
        return 'fresh';
    });
    const first = load();
    const second = load();
    await assert.rejects(first, /429/);
    assert.equal(await second, 'fresh');
    assert.equal(n, 2);
});
