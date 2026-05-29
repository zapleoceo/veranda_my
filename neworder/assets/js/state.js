// In-memory state with localStorage mirror for the persistent bits
// (cart, location, order comment). Read/write through methods so we
// can broadcast a single 'change' event to subscribers regardless of
// which field moved.
//
// SOLID: this class doesn't know anything about Poster or the DOM —
// it's a plain data hub. The UI modules subscribe via `on()`.

'use strict';

const LS_KEY = 'neworder.state.v1';

/** Public state slots; everything else is computed (totals etc.). */
const DEFAULT = {
    products:    [],       // MenuItem[]
    categories:  [],       // Category[]
    spots:       [],       // Spot[]
    halls:       [],       // Hall[]
    tables:      [],       // TableDef[]

    spotId:      0,
    hallId:      0,
    tableId:     0,
    spotTabletId:0,

    cart:        [],       // CartLine[] - same shape we POST
    comment:     '',

    openChecks:           [],   // OpenCheck[] for current table
    appendToTx:           0,    // 0 = new order, >0 = append to this transaction_id
    openCheckChoiceMade:  true, // false ⇒ open checks exist and operator
                                //          hasn't picked yet — submit blocked
                                //          until they tap one of the radios

    search:      '',
};

export class State {
    constructor() {
        this._s = { ...DEFAULT };
        this._subs = new Set();
    }

    /** Subscribe to ANY change; returns an unsubscribe fn. */
    on(fn) { this._subs.add(fn); return () => this._subs.delete(fn); }

    /** Internal — fire after a mutation. */
    _emit() {
        for (const fn of this._subs) try { fn(this._s); } catch (e) { console.error('[no:state]', e); }
        this._persist();
    }

    get s() { return this._s; }
    get cart()       { return this._s.cart; }
    get cartCount()  { return this._s.cart.reduce((n, l) => n + (Number(l.count) || 0), 0); }
    get cartTotal()  {
        return this._s.cart.reduce((sum, l) => sum + this._linePrice(l) * (Number(l.count) || 0), 0);
    }

    findProduct(id)  { return this._s.products.find((p) => p.id === Number(id)) || null; }
    findCategory(id) { return this._s.categories.find((c) => c.id === Number(id)) || null; }
    findHall(id)     { return this._s.halls.find((h) => h.id === Number(id))     || null; }
    findSpot(id)     { return this._s.spots.find((s) => s.id === Number(id))     || null; }
    findTable(id)    { return this._s.tables.find((t) => t.id === Number(id))    || null; }

    // ─── Menu ───────────────────────────────────────────────────
    setMenu(categories, products) {
        this._s.categories = categories || [];
        this._s.products   = products   || [];
        this._emit();
    }

    // ─── Locations ─────────────────────────────────────────────
    setLocations(spots, halls, tables) {
        this._s.spots  = spots  || [];
        this._s.halls  = halls  || [];
        this._s.tables = tables || [];
        // Reset spotTabletId if we now know the spot.
        if (this._s.spotId) {
            const sp = this.findSpot(this._s.spotId);
            this._s.spotTabletId = sp?.tablet_id || 0;
        } else if (this._s.spots.length === 1) {
            // Auto-pick the only spot.
            this._s.spotId       = this._s.spots[0].id;
            this._s.spotTabletId = this._s.spots[0].tablet_id || 0;
        }
        this._emit();
    }
    setSpot(spotId) {
        const sp = this.findSpot(spotId);
        this._s.spotId       = sp ? sp.id : 0;
        this._s.spotTabletId = sp ? sp.tablet_id : 0;
        // Reset hall/table if they don't belong to this spot anymore.
        if (!this._s.halls.some((h) => h.id === this._s.hallId && h.spot_id === this._s.spotId)) {
            this._s.hallId  = 0;
            this._s.tableId = 0;
        }
        this._emit();
    }
    setHall(hallId)   { this._s.hallId  = Number(hallId)  || 0; this._s.tableId = 0; this._emit(); }
    setTable(tableId) {
        this._s.tableId = Number(tableId) || 0;
        // Changing tables invalidates the open-check pick — caller
        // should refetch and reapply state.appendToTx.
        this._s.appendToTx = 0;
        this._s.openCheckChoiceMade = true;   // until openChecks land
        this._emit();
    }
    setOpenChecks(checks) {
        this._s.openChecks = checks || [];
        // If a table has at least one open check, the operator MUST
        // pick a mode (new vs append to which). Force a fresh choice
        // every time the open-check list comes back non-empty —
        // this is what makes the cart banner show «no default» radios.
        this._s.openCheckChoiceMade = (this._s.openChecks.length === 0);
        // Reset any stale "append-to" choice from the previous table
        // so neither radio appears pre-selected.
        if (this._s.openChecks.length > 0) this._s.appendToTx = 0;
        this._emit();
    }
    setAppendToTx(txId) {
        this._s.appendToTx = Number(txId) || 0;
        // Picking any radio (including «Новый отдельный заказ») counts
        // as «I saw the prompt and made a deliberate decision».
        this._s.openCheckChoiceMade = true;
        this._emit();
    }

    setComment(c) { this._s.comment = String(c || ''); this._emit(); }
    setSearch(q)  { this._s.search  = String(q || ''); this._emit(); }

    // ─── Cart ──────────────────────────────────────────────────
    /**
     * @param {object} line {product_id, count, modificator_id?, modifications?, comment?}
     * Lines with the same product+modifier+modifications combine; comments
     * are merged on a newline so the operator's notes aren't lost.
     */
    addLine(line) {
        const key = this._keyOf(line);
        const existing = this._s.cart.find((l) => this._keyOf(l) === key);
        if (existing) {
            existing.count = (Number(existing.count) || 0) + (Number(line.count) || 1);
            if (line.comment && !String(existing.comment || '').includes(line.comment)) {
                existing.comment = existing.comment ? existing.comment + '\n' + line.comment : line.comment;
            }
        } else {
            this._s.cart.push({
                product_id:     Number(line.product_id),
                count:          Number(line.count) || 1,
                modificator_id: Number(line.modificator_id) || 0,
                modifications:  Array.isArray(line.modifications) ? line.modifications.map((m) => ({ id: Number(m.id), count: Number(m.count) || 1 })) : [],
                comment:        String(line.comment || ''),
            });
        }
        this._emit();
    }
    /** Direct setter — used by cart UI's qty +/− buttons. */
    setLineCount(idx, count) {
        if (idx < 0 || idx >= this._s.cart.length) return;
        const c = Number(count);
        if (!Number.isFinite(c) || c <= 0) {
            this._s.cart.splice(idx, 1);
        } else {
            this._s.cart[idx].count = c;
        }
        this._emit();
    }
    setLineComment(idx, comment) {
        if (idx < 0 || idx >= this._s.cart.length) return;
        this._s.cart[idx].comment = String(comment || '');
        this._emit();
    }
    removeLine(idx) {
        if (idx < 0 || idx >= this._s.cart.length) return;
        this._s.cart.splice(idx, 1);
        this._emit();
    }
    clearCart() {
        this._s.cart = [];
        this._s.comment = '';
        this._s.appendToTx = 0;
        this._emit();
    }

    /** Per-line wall-clock price = chosen modifier (if any) or product base + sum(addons). */
    _linePrice(line) {
        const p = this.findProduct(line.product_id);
        if (!p) return 0;
        let base = p.price || 0;
        if (line.modificator_id) {
            for (const g of (p.modifier_groups || [])) {
                const opt = (g.options || []).find((o) => o.id === Number(line.modificator_id));
                if (opt) { base = opt.price; break; }
            }
        }
        let addons = 0;
        for (const m of (line.modifications || [])) {
            const ref = (p.modifications || []).find((x) => x.id === Number(m.id));
            if (ref) addons += (ref.price || 0) * (Number(m.count) || 1);
        }
        return base + addons;
    }

    /** Stable identity for combining like cart lines. */
    _keyOf(line) {
        const mods = (line.modifications || [])
            .map((m) => Number(m.id) + 'x' + Number(m.count))
            .sort()
            .join(',');
        return `${Number(line.product_id)}|${Number(line.modificator_id) || 0}|${mods}|${(line.comment || '').trim()}`;
    }

    // ─── Persistence ───────────────────────────────────────────
    _persist() {
        try {
            const slim = {
                cart:      this._s.cart,
                comment:   this._s.comment,
                spotId:    this._s.spotId,
                hallId:    this._s.hallId,
                tableId:   this._s.tableId,
                appendToTx:this._s.appendToTx,
            };
            localStorage.setItem(LS_KEY, JSON.stringify(slim));
        } catch (_) { /* private mode etc. — ignore */ }
    }
    restore() {
        try {
            const raw = localStorage.getItem(LS_KEY);
            if (!raw) return;
            const j = JSON.parse(raw);
            if (j && typeof j === 'object') {
                Object.assign(this._s, {
                    cart:       Array.isArray(j.cart) ? j.cart : [],
                    comment:    String(j.comment || ''),
                    spotId:     Number(j.spotId)  || 0,
                    hallId:     Number(j.hallId)  || 0,
                    tableId:    Number(j.tableId) || 0,
                    appendToTx: Number(j.appendToTx) || 0,
                });
            }
        } catch (_) { /* corrupted; reset */ }
    }
}
