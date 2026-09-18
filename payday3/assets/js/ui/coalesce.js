// "Latest wins" wrapper for loaders.
//
// A call that arrives while a run is in flight must not be dropped (the
// caller wants data fetched AFTER its own write) nor handed the stale
// in-flight promise. Instead it marks the loader dirty: exactly one more
// run starts when the current one settles, with the latest arguments,
// and every caller that arrived meanwhile gets THAT run's result.
//
//   const load = coalesce(fetchAndRender);
//   load(); load(); load();   // → 2 runs; calls 2 and 3 share run #2

'use strict';

/**
 * @template {(...args:any[]) => any} F
 * @param {F} fn
 * @returns {(...args:Parameters<F>) => Promise<Awaited<ReturnType<F>>>}
 */
export function coalesce(fn) {
    let running  = null;   // promise of the current run
    let queued   = null;   // promise of the one rerun, shared by late callers
    let nextArgs = [];

    const start = (args) => {
        running = Promise.resolve()
            .then(() => fn(...args))
            .finally(() => { running = null; });
        return running;
    };

    return function run(...args) {
        if (!running) return start(args);
        nextArgs = args;
        if (!queued) {
            // A failure of the current run doesn't cancel the rerun.
            queued = running.catch(() => {}).then(() => {
                queued = null;
                return start(nextArgs);
            });
        }
        return queued;
    };
}
