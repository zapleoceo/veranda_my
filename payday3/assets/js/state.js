// Tiny event-bus + central state. Replaces payday2's window globals.
// Every module subscribes to the keys it cares about; nothing else
// reaches across modules.

'use strict';

export class State extends EventTarget {
    #data;
    constructor(initial = {}) {
        super();
        this.#data = { ...initial };
    }
    get(key)        { return this.#data[key]; }
    snapshot()      { return { ...this.#data }; }
    set(key, value) {
        if (this.#data[key] === value) return;
        this.#data[key] = value;
        this.dispatchEvent(new CustomEvent('change', { detail: { key, value } }));
        this.dispatchEvent(new CustomEvent(`change:${key}`, { detail: value }));
    }
    on(event, fn)  { this.addEventListener(event, fn); return () => this.removeEventListener(event, fn); }
}
