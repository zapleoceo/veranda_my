// Checkbox-driven row selection for the whole page.
//
// Four kinds of rows can be ticked:
//   left  «Деньги»: incoming SePay (.pd3-cb--sepay, data-sepay-id)
//                   outgoing mail  (.pd3-cb--out-mail, data-mail-uid)
//   right:          Poster checks  (.pd3-cb--poster, data-poster-id)
//                   Poster finance (.pd3-cb--out-finance, data-finance-id)
//
// Valid pairs: incoming ↔ checks, incoming ↔ finance, outgoing ↔ finance.
// linkPlan() turns the current selection into the link calls to make;
// the mid column shows the left/right sums and enables 🎯 only for a
// valid plan.

'use strict';

const fmt = (n) => {
    const v = Math.round(Number(n) || 0);
    try {
        return new Intl.NumberFormat('en-US', { maximumFractionDigits: 0 })
            .format(v).replace(/,/g, ' ');
    } catch (_) {
        return String(v).replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
    }
};

/** Checkbox dataset key → selection bucket. */
const KINDS = { sepayId: 'sepay', posterId: 'poster', mailUid: 'mail', financeId: 'finance' };

/** Selection bucket → its checkbox class. */
const CHECKBOX = {
    sepay: '.pd3-cb--sepay', poster: '.pd3-cb--poster',
    mail: '.pd3-cb--out-mail', finance: '.pd3-cb--out-finance',
};

/** Buckets owned by each side — a side re-render resets only its own. */
export const SIDE_KINDS = Object.freeze({ in: ['sepay', 'poster'], out: ['mail', 'finance'] });

/**
 * Which manual links the selection asks for.
 *   in     — incoming SePay rows ↔ Poster checks
 *   income — incoming SePay rows ↔ Poster finance transactions
 *   out    — outgoing mail rows  ↔ Poster finance transactions
 * Every ticked row must get exactly one kind of counterpart, otherwise
 * the click would silently ignore — or double-link — some ticks:
 *   - ticked mail: the finance ticks belong to the mail (out), and the
 *     SePay ticks then need checks (in) — both pairs in one click;
 *   - no mail, finance ticked: SePay ↔ finance (income); a check tick
 *     too would make the SePay side ambiguous → blocked;
 *   - neither: SePay ↔ checks (in).
 *
 * @param {{sepay:number, poster:number, mail:number, finance:number}} n  counts
 * @returns {{in:boolean, out:boolean, income:boolean, canLink:boolean}}
 */
export function linkPlan({ sepay = 0, poster = 0, mail = 0, finance = 0 } = {}) {
    if (mail > 0) {
        const ok = finance > 0 && (sepay > 0) === (poster > 0);
        return { in: ok && sepay > 0, out: ok, income: false, canLink: ok };
    }
    if (finance > 0) {
        const ok = sepay > 0 && poster === 0;
        return { in: false, out: false, income: ok, canLink: ok };
    }
    const ok = sepay > 0 && poster > 0;
    return { in: ok, out: false, income: false, canLink: ok };
}

/**
 * Mid-column match indicator for left vs right selected sums.
 * @returns {{state:'empty'|'ok'|'warn'|'err', glyph:string}}
 */
export function matchState(leftSum, rightSum, leftCount, rightCount) {
    if (!leftCount && !rightCount) return { state: 'empty', glyph: '·' };
    if (!leftCount || !rightCount) return { state: 'warn', glyph: '∙' };
    const diff = leftSum - rightSum;
    if (diff === 0) return { state: 'ok', glyph: '✅' };
    return { state: Math.abs(diff) > 1000 ? 'err' : 'warn', glyph: '⚠' };
}

export function initSelection() {
    const sets = { sepay: new Set(), poster: new Set(), mail: new Set(), finance: new Set() };

    const $left  = document.getElementById('pd3SelSepaySum');
    const $right = document.getElementById('pd3SelPosterSum');
    const $match = document.getElementById('pd3SelMatch');
    const $diff  = document.getElementById('pd3SelDiff');
    const $make  = document.getElementById('pd3LinkMakeBtn');

    // Finance amounts are signed (expenses negative) — compare magnitudes.
    const sumOf = (sel) => Array.from(document.querySelectorAll(sel))
        .filter((el) => el.checked)
        .reduce((acc, el) => acc + Math.abs(Number(el.dataset.sum) || 0), 0);

    const counts = () => ({
        sepay: sets.sepay.size, poster: sets.poster.size,
        mail: sets.mail.size,   finance: sets.finance.size,
    });

    const recompute = () => {
        const left  = sumOf('.pd3-cb--sepay') + sumOf('.pd3-cb--out-mail');
        const right = sumOf('.pd3-cb--poster') + sumOf('.pd3-cb--out-finance');
        const n = counts();
        if ($left)  $left.textContent  = fmt(left);
        if ($right) $right.textContent = fmt(right);
        if ($diff)  $diff.textContent  = fmt(left - right);
        if ($match) {
            const m = matchState(left, right, n.sepay + n.mail, n.poster + n.finance);
            $match.dataset.state = m.state;
            $match.textContent   = m.glyph;
        }
        if ($make) $make.toggleAttribute('disabled', !linkPlan(n).canLink);
    };

    /**
     * Untick the given buckets (all by default). Programmatic
     * .checked=false fires no change event, so the sets are cleared here.
     * A side that re-renders passes its own kinds (SIDE_KINDS) so ticks
     * on the other side survive — e.g. the outgoing rows arriving ~2 s
     * after page load don't wipe ticks already made on incoming rows.
     */
    const reset = (kinds = Object.keys(sets)) => {
        for (const kind of kinds) {
            document.querySelectorAll(CHECKBOX[kind]).forEach((cb) => { cb.checked = false; });
            sets[kind].clear();
        }
        recompute();
    };

    document.body.addEventListener('change', (e) => {
        const t = e.target;
        if (!(t instanceof HTMLInputElement) || !t.classList.contains('pd3-cb')) return;
        for (const [key, kind] of Object.entries(KINDS)) {
            if (t.dataset[key] === undefined) continue;
            const id = Number(t.dataset[key]);
            t.checked ? sets[kind].add(id) : sets[kind].delete(id);
        }
        recompute();
    });

    recompute();
    return { sets, counts, recompute, reset };
}
