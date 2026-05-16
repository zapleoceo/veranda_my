// Checkbox-driven row selection.
// Maintains two Sets (sepayIds, posterIds), updates the mid-col sums,
// enables/disables the link/clear buttons. Pure DOM, no fetch yet.

'use strict';

const fmt = (n) => {
    const v = Math.round(Number(n) || 0);
    try {
        return new Intl.NumberFormat('en-US', { maximumFractionDigits: 0 })
            .format(v).replace(/,/g, ' ');
    } catch (_) {
        return String(v).replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
    }
};

export function initSelection() {
    const sepayIds  = new Set();
    const posterIds = new Set();

    const $selSepaySum  = document.getElementById('pd3SelSepaySum');
    const $selPosterSum = document.getElementById('pd3SelPosterSum');
    const $selMatch     = document.getElementById('pd3SelMatch');
    const $selDiff      = document.getElementById('pd3SelDiff');
    const $linkMake     = document.getElementById('pd3LinkMakeBtn');
    const $linkClear    = document.getElementById('pd3LinkClearBtn');

    const sumOf = (sel) => Array.from(document.querySelectorAll(sel))
        .filter((el) => el.checked)
        .reduce((acc, el) => acc + (Number(el.dataset.sum) || 0), 0);

    const recompute = () => {
        const s = sumOf('.pd3-cb--sepay');
        const p = sumOf('.pd3-cb--poster');
        const diff = s - p;
        if ($selSepaySum)  $selSepaySum.textContent  = fmt(s);
        if ($selPosterSum) $selPosterSum.textContent = fmt(p);
        if ($selDiff)      $selDiff.textContent      = fmt(diff);
        if ($selMatch) {
            if (!sepayIds.size && !posterIds.size) {
                $selMatch.dataset.state = 'empty';
                $selMatch.textContent   = '·';
            } else if (diff === 0 && sepayIds.size && posterIds.size) {
                $selMatch.dataset.state = 'ok';
                $selMatch.textContent   = '✅';
            } else if (sepayIds.size && posterIds.size) {
                $selMatch.dataset.state = Math.abs(diff) > 1000 ? 'err' : 'warn';
                $selMatch.textContent   = '⚠';
            } else {
                $selMatch.dataset.state = 'warn';
                $selMatch.textContent   = '∙';
            }
        }
        const canLink = sepayIds.size > 0 && posterIds.size > 0;
        if ($linkMake)  $linkMake.toggleAttribute('disabled',  !canLink);
        if ($linkClear) $linkClear.toggleAttribute('disabled', !(sepayIds.size + posterIds.size));
    };

    document.body.addEventListener('change', (e) => {
        const t = e.target;
        if (!(t instanceof HTMLInputElement)) return;
        if (!t.classList.contains('pd3-cb')) return;
        const sid = t.dataset.sepayId;
        const pid = t.dataset.posterId;
        if (sid !== undefined) (t.checked ? sepayIds.add(Number(sid))  : sepayIds.delete(Number(sid)));
        if (pid !== undefined) (t.checked ? posterIds.add(Number(pid)) : posterIds.delete(Number(pid)));
        recompute();
    });

    recompute();
    return { sepayIds, posterIds, recompute };
}
