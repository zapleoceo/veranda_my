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
        if (!dateTo.classList.contains('is-hidden')) {
            // Pre-fill dateTo with the current dateFrom value so the
            // operator sees a same-day range by default — they can then
            // pick a different end date if needed.
            if (dateFrom?.value) dateTo.value = dateFrom.value;
            dateTo.focus();
        }
    });

    // Auto-submit on any date change — there's no "Открыть" button.
    // In range mode we still submit immediately because partially-filled
    // ranges are valid (the controller treats missing dateTo as
    // dateFrom and vice versa).
    const submit = () => form.submit();
    dateFrom?.addEventListener('change', () => {
        // Keep dateTo in sync when it's visible so changing the start
        // date doesn't accidentally leave a stale end date.
        if (dateTo && !dateTo.classList.contains('is-hidden') && dateFrom.value) {
            dateTo.value = dateFrom.value;
        }
        submit();
    });
    dateTo?.addEventListener('change', submit);

    form.addEventListener('submit', () => {
        spinner?.classList.remove('is-hidden');
    });
}
