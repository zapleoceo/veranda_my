// Date form: single-day mode only.
//   * Auto-submit when the user changes the visible date.
//   * Hidden dateTo input is kept in sync with dateFrom so the
//     controller's DateRange::fromQuery still receives both bounds.
//   * Submit shows a spinner while navigating.

'use strict';

export function initDateForm() {
    const form     = document.getElementById('pd3DateForm');
    const dateFrom = form?.querySelector('input[name="dateFrom"]');
    const dateTo   = form?.querySelector('.pd3-date--to');
    const spinner  = document.getElementById('pd3DateSpinner');

    if (!form) return;

    dateFrom?.addEventListener('change', () => {
        // Mirror the visible date into the hidden dateTo so the
        // server sees a complete one-day range.
        if (dateTo && dateFrom.value) dateTo.value = dateFrom.value;
        form.submit();
    });

    form.addEventListener('submit', () => {
        spinner?.classList.remove('is-hidden');
    });
}
