# Public menu search and TR3 performance

## State
2026-10-30 Asia/Saigon. Scope: live search /menu; read-only TR3 performance audit.
Worktree: C:/Users/work_zaporozhets/.codex/worktrees/veranda-menu-search-tr3-audit/Veranda site 2
Branch codex/menu-search-tr3-audit; base d938f6b4a8fd8e209c375f5f92a24cfaa097dce4.
Room lease veranda-menu-search-tr3-audit-20261030 fence 1. No conflicting active task found among 45 open tasks.
Shared workflow read; adapter unregistered; no repository AGENTS/CLAUDE policy found. Main untracked files untouched.
Next: implement client filter and language-swap regression tests, then rendered QA and TR3 measurements.
Unverified: PHP runtime unavailable in PATH; browser cold-cache/mobile timing capability; independent review; release permission.

## Контекст з KB
Vera search `Veranda menu живой поиск TR3 быстродействие`: no relevant results.
Memory identifies previous WebP preview optimization; verify current assets rather than repeating it.

## Plan
1. Search existing rendered title/description/category text; normalize accents/case, all query tokens must match. No API calls per input.
2. Hide nonmatching items/categories/sections; open matches; restore original expansion after clearing. Localized label, clear, count/empty; Escape clears, Enter does not submit.
3. Reinitialize after language swap and preserve query. Correct existing detached-node animation replacement ordering with regression test.
4. Test filtering, restore, Unicode, language swaps, empty query, literal unsafe text; inspect desktop/mobile render.
5. Inventory TR3 states; bounded cold/warm UI and HTTP observations separately; fix only reproduced bottlenecks. No booking/auth integration writes.
6. Independent review, documentation, exact release gate. No production deployment implied by delegation.

## Evidence and limits
2026-10-30 20:25 Asia/Saigon. Implementation complete locally; SOL review PASS after fixing overlapping language transitions. JS 126/126 pass; PHP 8.2.35 syntax passes for both views and added view test. Composer download timed out, so PHPUnit awaits CI. PHP runtime is isolated at D:/Temp/veranda-qa-php/runtime, not installed globally.

Rendered QA used real menu_public.php, full shipped CSS/JS, synthetic public menu data (no DB). Screenshots observed: tmp/menu-qa/desktop-search.jpg (1280x720), mobile-vi-search.jpg and mobile-empty.jpg (390x844). RU/EN/VI search, accent folding, empty/clear/Escape, language change and category restoration checked. KO covered by DOM/PHP view tests; native mobile keyboard and real production dataset remain unverified. No production deployment performed.

### TR3 actual state scope
TR3 is one document /tr3/ (also /tr3), with lang query and #modal-* history, not a multi-page router.
- Initial main hall: bootstrap -> artwork/app -> hall_tables hall2 -> free_tables -> reservations; events_for_day separately.
- Date picker: month navigation/day/OK/cancel; date changes reload free/reservations/events. Calendar open and next day selected live.
- Main/cinema toggle: hall2/hall7; cinema layout first loaded on demand, returning reuses layout. Both directions verified live.
- Table selection opens #modal-reqModal; main and cinema forms, Close, duration and guest count verified. End time updated correctly (12:00 + 3h =15:00).
- Live language switch verified RU->EN; preview keyboard/hover, zoom and other supported states remain covered by existing JS regressions, not all newly timed live.
- Menu preorder code exists but control not exposed in observed configuration. No invented timing.
- TG/WA linking, restoration with a real state token, final booking confirmation/submission and success/error after writes deliberately excluded: would create external state or require real credentials. No bookings/orders/payments created.

### Baseline methodology
IAB Chromium on owner's Windows host, normal network, no CPU/network throttling. Cache disabled via Network.setCacheDisabled for cold, enabled for warm; 2 reloads per requested desktop/mobile size. Mobile is responsive viewport, not a physical device. Raw source samples tmp/menu-qa/tr3-baseline.json; first cold desktop sample retained below from tool output. Observations have substantial host/network variance. DOMContentLoaded/load do not indicate booking readiness. Final reservations response is only an API completion proxy, not UI responsiveness/INP. High-level locator waits timed out on cold runs; automatic UI-ready observer unavailable (CDP addScriptToEvaluateOnNewDocument unsupported), so no exact paint/INP claim.

| Requested viewport/cache | TTFB ms | DOM ms | final reservations response end ms from navigation |
|---|---:|---:|---:|
|desktop cold1|782|3765|16461|
|desktop cold2|473|1313|9059|
|desktop warm1|992|1171|3414|
|desktop warm2|532|634|2528|
|mobile cold1|583|1608|4719|
|mobile cold2|742|1977|4981|
|mobile warm1|672|841|2641|
|mobile warm2|556|761|3097|

Observed mobile viewport confirmed390x844; desktop requested1280x900 (not independently saved at each baseline). No percentile or universal performance claim from these samples.
Tool action+AX roundtrips (upper observations incl automation overhead, NOT handler/render latency): calendar915ms; cinema form402ms; language690ms; main form537ms. Date-change free_tables3797ms + reservations631ms; first cinema hall733ms + free430ms + reservations463ms. These are network API durations, not UI timings.

### Narrow improvement
Only TR3 change: preload app.js and plan-presentation.js in HTML. Execution remains controlled by existing boot chain; no booking/API/capacity/coordinate behavior changed. Current production cold waterfall downloads these scripts only after bootstrap; local real template confirms preload initiated256/257ms, complete314/319ms, bootstrap complete1484ms. Local network is different, so this verifies dependency overlap, NOT measured production speedup. Runtime images already use WebP from previous optimization.
A duplicate events_for_day request and serial free/reservations requests were observed; left unchanged to avoid expanding data-flow risk before stronger measurements.

## Release gate
Candidate only. Need exact-source CI including PHPUnit; then applicable direct owner approval to merge/deploy this new release (prior September approval is not reused for October feature). Following release, verify real production search dataset and compare same-device timings. Full all-state UI responsiveness audit is not complete: precise UI/paint metrics and authenticated/write paths are unavailable/excluded as listed above.
