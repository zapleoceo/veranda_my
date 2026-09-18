// Page-wide link panel — the one mid column.
//
// One set of buttons for all three pairs of tables:
//   incoming ↔ checks   (inLinks,     ui/linkActions.js)
//   incoming ↔ finance  (incomeLinks, ui/incomeLinks.js)
//   outgoing ↔ finance  (outLinks,    out/bootstrap.js)
// each an adapter of the same shape
//   { autoLink(), manualLink(leftIds, rightIds), clearLinks() }.
//
//   🧩 auto   — checks + expenses first (in parallel), THEN the incoming
//               rows still without a pair look among Poster incomes —
//               so a bank row never gets two counterparts;
//   🎯 manual — routed by what is ticked (selection.linkPlan);
//   ⛓️‍💥 clear  — every link of the day, all pairs, one confirmation.
// Pairs run independently: one failing never blocks the others.

'use strict';

const _i = (await import(new URL('./cacheBust.js' + new URL(import.meta.url).search, import.meta.url).href)).importer(import.meta.url);
const { linkPlan }       = await _i('./selection.js');
const { withBusy: busy } = await _i('./busy.js');

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

/** Busy button whose action resolves to a list of failures to alert. */
const withBusy = (btn, fn) => busy(btn, async () => {
    const errors = await fn();
    if (errors?.length) alert(errors.join('\n'));
});

/**
 * @param {{state:object, inLinks:object, incomeLinks:object|null, outLinks:object|null,
 *          selection:object, confirmFn?:(msg:string)=>boolean}} deps
 */
export function initLinkPanel({ state, inLinks, incomeLinks = null, outLinks, selection, confirmFn = (m) => confirm(m) }) {
    const $auto  = document.getElementById('pd3LinkAutoBtn');
    const $make  = document.getElementById('pd3LinkMakeBtn');
    const $clear = document.getElementById('pd3LinkClearBtn');

    $auto?.addEventListener('click', withBusy($auto, async () => [
        ...await runSides([
            ['Приходы ↔ чеки', inLinks?.autoLink],
            ['Расходы',        outLinks?.autoLink],
        ]),
        // After both: only the incoming rows left without a check look
        // among Poster incomes, and finance rows taken by expenses are gone.
        ...await runSides([['Приходы ↔ транзакции', incomeLinks?.autoLink]]),
    ]));

    $make?.addEventListener('click', withBusy($make, async () => {
        const plan = linkPlan(selection.counts());
        if (!plan.canLink) return [];
        const { sepay, poster, mail, finance } = selection.sets;
        const errors = await runSides([
            ['Приходы ↔ чеки',       plan.in     ? () => inLinks.manualLink([...sepay], [...poster])       : null],
            ['Приходы ↔ транзакции', plan.income ? () => incomeLinks.manualLink([...sepay], [...finance]) : null],
            ['Расходы',              plan.out    ? () => outLinks.manualLink([...mail], [...finance])     : null],
        ]);
        // Adapters repaint on success; a failure leaves the ticks in place
        // so the operator can retry without re-selecting.
        if (!errors.length) selection.reset();
        return errors;
    }));

    $clear?.addEventListener('click', withBusy($clear, async () => {
        const r = state.get('range') || {};
        const period = r.from === r.to ? r.from : `${r.from} — ${r.to}`;
        if (!confirmFn(`Снять ВСЕ связи за ${period} — и приходов, и расходов?\n\n`
            + 'Удалятся и авто-, и ручные связи. Отмеченные чекбоксы не учитываются.')) return [];
        return runSides([
            ['Приходы ↔ чеки',       inLinks?.clearLinks],
            ['Приходы ↔ транзакции', incomeLinks?.clearLinks],
            ['Расходы',              outLinks?.clearLinks],
        ]);
    }));
}
