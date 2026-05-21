// ShiftUi — every DOM read/write the widget needs, behind a tiny
// class so widget.js stays a clean orchestrator. No business logic
// here, just element lookups and rendering.

'use strict';

const fmtTime = (iso) => {
    if (!iso) return '—';
    try {
        const d = new Date(iso.replace(' ', 'T') + 'Z');
        if (isNaN(d)) return iso;
        return d.toLocaleString('ru-RU', { hour12: false });
    } catch (_) { return iso; }
};

export class ShiftUi {
    constructor(doc) {
        this.$user      = doc.getElementById('paUserName');
        this.$state     = doc.getElementById('paShiftState');
        this.$startedRow = doc.getElementById('paShiftStartedRow');
        this.$started   = doc.getElementById('paShiftStarted');
        this.$open      = doc.getElementById('paOpenBtn');
        this.$close     = doc.getElementById('paCloseBtn');
        this.$log       = doc.getElementById('paLog');
    }

    setUser(user) {
        this.$user.textContent = user
            ? user.name + (user.admin ? ' (админ)' : '')
            : '—';
    }

    /** shift = WorkShift json or null */
    setShift(shift) {
        if (shift && !shift.ended_at) {
            this.$state.textContent = 'открыта';
            this.$state.className   = 'pa-value is-open';
            this.$startedRow.hidden = false;
            this.$started.textContent = fmtTime(shift.started_at);
        } else {
            this.$state.textContent = 'закрыта';
            this.$state.className   = 'pa-value is-closed';
            this.$startedRow.hidden = true;
            this.$started.textContent = '—';
        }
    }

    /** Enable / disable the two manual buttons. */
    allowManual({ open, close }) {
        this.$open.disabled  = !open;
        this.$close.disabled = !close;
    }

    onManualOpen(fn)  { this.$open .addEventListener('click', fn); }
    onManualClose(fn) { this.$close.addEventListener('click', fn); }

    log(kind, msg) {
        const line = document.createElement('div');
        line.className = 'pa-log__line is-' + kind;
        const t = new Date().toLocaleTimeString('ru-RU', { hour12: false });
        line.innerHTML = '<span class="pa-log__time">' + t + '</span>' +
            String(msg).replace(/[&<>]/g, (c) => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;' }[c]));
        this.$log.prepend(line);
        // Trim to last 50 lines.
        while (this.$log.children.length > 50) this.$log.lastChild.remove();
    }
}
