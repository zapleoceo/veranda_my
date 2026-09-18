// Date form: single-day mode only.
//   * Auto-submit when the user changes the visible date.
//   * ‹ / › buttons step one day back / forward and submit.
//   * Hidden dateTo input is kept in sync with dateFrom so the
//     controller's DateRange::fromQuery still receives both bounds.
//   * Submit shows a spinner while navigating.

'use strict';

/**
 * Shift a 'Y-m-d' date by N days. Pure calendar arithmetic in UTC, so
 * month/year boundaries and the browser's timezone/DST never skew it.
 * Returns '' for anything that isn't a real 'Y-m-d' date.
 *
 * @param {string} ymd
 * @param {number} days
 * @returns {string}
 */
export function shiftYmd(ymd, days) {
    const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(ymd ?? ''));
    if (!m) return '';
    const d = new Date(Date.UTC(+m[1], +m[2] - 1, +m[3]));
    // Reject rolled-over input like 2026-02-31 instead of silently moving it.
    if (d.getUTCMonth() !== +m[2] - 1 || d.getUTCDate() !== +m[3]) return '';
    d.setUTCDate(d.getUTCDate() + (Number(days) || 0));
    return d.toISOString().slice(0, 10);
}

export function initDateForm() {
    const form     = document.getElementById('pd3DateForm');
    const dateFrom = form?.querySelector('input[name="dateFrom"]');
    const dateTo   = form?.querySelector('.pd3-date--to');
    const spinner  = document.getElementById('pd3DateSpinner');

    if (!form || !dateFrom) return;

    // Single path for every way of picking a day: mirror the visible
    // date into the hidden dateTo (complete one-day range), then submit.
    const go = (ymd) => {
        if (!ymd) return;
        dateFrom.value = ymd;
        if (dateTo) dateTo.value = ymd;
        form.requestSubmit();
    };

    dateFrom.addEventListener('change', () => go(dateFrom.value));

    form.querySelectorAll('[data-date-step]').forEach((btn) => {
        btn.addEventListener('click', () => go(shiftYmd(dateFrom.value, Number(btn.dataset.dateStep))));
    });

    form.addEventListener('submit', () => {
        spinner?.classList.remove('is-hidden');
    });
}
