// Page-wide link panel — the one mid column.
//
// One set of buttons for both kinds of links:
//   🧩 auto   — incoming↔checks AND outgoing↔finance matchers, one click
//   🎯 manual — routed by what is ticked (selection.linkPlan)
//   ⛓️‍💥 clear  — every link of the day, both kinds, one confirmation
// Each side is an adapter with the same shape
//   { autoLink(), manualLink(leftIds, rightIds), clearLinks() }:
//   incoming — ui/linkActions.js, outgoing — out/bootstrap.js.
// The two sides run independently: one failing never blocks the other.

'use strict';

// Cache-bust cross-module imports — see comment in out/bootstrap.js.
const _v = new URL(import.meta.url).searchParams.get('v') || '';
const _qs = _v ? '?v=' + encodeURIComponent(_v) : '';
const { linkPlan } = await import(new URL('./selection.js' + _qs, import.meta.url).href);

/**
 * Run labelled side-effects side by side; resolve to the failures only.
 * @param {Array<[string, (() => Promise<unknown>) | null | undefined]>} tasks
 * @returns {Promise<string[]>}  "label: message" for every rejected task
 */
export async function runSides(tasks) {
    const live = tasks.filter(([, fn]) => typeof fn === 'function');
    // Promise.resolve().then(fn): a side that throws synchronously (e.g. a
    // missing adapter method) becomes a rejection of ITS OWN promise —
    // it can't abort the map and orphan the other side's in-flight request.
    const results = await Promise.allSettled(live.map(([, fn]) => Promise.resolve().then(fn)));
    return results.flatMap((r, i) =>
        r.status === 'rejected' ? [`${live[i][0]}: ${r.reason?.message || r.reason || 'ошибка'}`] : []);
}

function withBusy(btn, fn) {
    return async () => {
        if (!btn || btn.disabled) return;
        btn.disabled = true;
        btn.classList.add('is-busy');
        try {
            const errors = await fn();
            if (errors?.length) alert(errors.join('\n'));
        } finally {
            btn.classList.remove('is-busy');
            btn.disabled = false;
        }
    };
}

/**
 * @param {{state:object, inLinks:object, outLinks:object|null, selection:object,
 *          confirmFn?:(msg:string)=>boolean}} deps
 */
export function initLinkPanel({ state, inLinks, outLinks, selection, confirmFn = (m) => confirm(m) }) {
    const $auto  = document.getElementById('pd3LinkAutoBtn');
    const $make  = document.getElementById('pd3LinkMakeBtn');
    const $clear = document.getElementById('pd3LinkClearBtn');

    $auto?.addEventListener('click', withBusy($auto, () => runSides([
        ['Приходы', inLinks?.autoLink],
        ['Расходы', outLinks?.autoLink],
    ])));

    $make?.addEventListener('click', withBusy($make, async () => {
        const plan = linkPlan(selection.counts());
        if (!plan.canLink) return [];
        const { sepay, poster, mail, finance } = selection.sets;
        const errors = await runSides([
            ['Приходы', plan.in  ? () => inLinks.manualLink([...sepay], [...poster])   : null],
            ['Расходы', plan.out ? () => outLinks.manualLink([...mail], [...finance]) : null],
        ]);
        // Both adapters re-render on success; a failure leaves the ticks
        // in place so the operator can retry without re-selecting.
        if (!errors.length) selection.reset();
        return errors;
    }));

    $clear?.addEventListener('click', withBusy($clear, async () => {
        const r = state.get('range') || {};
        const period = r.from === r.to ? r.from : `${r.from} — ${r.to}`;
        if (!confirmFn(`Снять ВСЕ связи за ${period} — и приходов, и расходов?\n\n`
            + 'Удалятся и авто-, и ручные связи. Отмеченные чекбоксы не учитываются.')) return [];
        return runSides([
            ['Приходы', inLinks?.clearLinks],
            ['Расходы', outLinks?.clearLinks],
        ]);
    }));
}
