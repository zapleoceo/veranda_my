// Date form behaviours:
//   * "range" button toggles the second date input visible.
//   * Auto-submit when the user changes a date in single-day mode.
//   * Submit button shows a spinner while navigating.

'use strict';

export function initDateForm() {
    const form    = document.getElementById('pd3DateForm');
    const rangeBtn = document.getElementById('pd3DateRangeToggle');
    const dateTo  = form?.querySelector('.pd3-date--to');
    const dateFrom = form?.querySelector('input[name="dateFrom"]');
    const spinner = document.getElementById('pd3DateSpinner');

    if (!form) return;

    rangeBtn?.addEventListener('click', () => {
        if (!dateTo) return;
        dateTo.classList.toggle('is-hidden');
        if (!dateTo.classList.contains('is-hidden')) dateTo.focus();
    });

    // Auto-submit when user changes the single-day input — matches payday2 behaviour.
    dateFrom?.addEventListener('change', () => {
        if (dateTo?.classList.contains('is-hidden')) form.submit();
    });

    form.addEventListener('submit', () => {
        spinner?.classList.remove('is-hidden');
    });
}
