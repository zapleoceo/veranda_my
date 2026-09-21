# Implementation notes — TR3 architectural presentation

## Changed files
- `tr3/assets/plan-presentation.js`: isolated browser/CommonJS presentation helper; hall-scoped material and safe station classification; pure live-box scene derivation; inert DOM artwork.
- `tr3/assets/plan-presentation.css`: local natural tile/grass/wood/glass/gazebo/cushion/curtain textures and fountain/koi, reservation overlay stacking, stationary hover and visible keyboard focus, reduced motion.
- `tr3/assets/app.js`: collects existing computed button rectangles and invokes optional decorator after button insertion; existing fitting, minima, rotation, identity datasets, badge contents and event/availability calls remain unchanged.
- `tr3/assets/tr3.boot.js`: attempts optional artwork module before app, tolerates artwork load failure.
- `tr3/assets/tr3.css`, `tr3/index.php`: local import and changed asset cache versions.

## API
`TR3PlanPresentation` in browser, CommonJS exports in Node:
- `classify(hallId,item)` returns gazebo/glass/wood/room/bar/cashier/stage or null. Item contract is existing renderer metadata (`schemeNum,label,bookable`). Station roles require nonbookable exact known labels; Poster IDs never determine art.
- `deriveScene(hallId,items,width,height)` consumes final rendered `x,y,w,h` boxes without mutation. Terrace lower edge clears the complete terrace10–22/station bounds plus margin. An L-shaped lawn notch is derived from right edges10–16 and bottom edges17–22, gated by gazebo1 and overlap checks. Fountain is restricted to the notch above1/right10/below17 with clearance from all live boxes; no arbitrary distant-corner fallback. Extra shape fields are lawnNotch and terraceClip. No prototype coordinates.
- `decorate({hallId,tablesEl,decorEl,items,width,height,translate})` also consumes `element` references. Builds off-DOM, replaces legacy scene only once ready, and rolls back committed table art on failure. The app catches optional errors so booking still binds. CSS is scoped to generated classes; hall7 untouched.

## States and preservation
Default furniture, inherited busy/occupied/disabled states, existing booking selection/status output, hover, keyboard focus and reduced-motion are supported. Booking badge nodes and dataset values are retained verbatim; translated station plaques are aria-hidden while original labels remain accessible. Reservation text and prohibited icon stay above artwork. All art descendants ignore pointers. No new network calls, form handlers, table IDs, station booking changes, backend changes or production fixtures.

## Checks
`node --check` passed for plan-presentation.js, app.js and tr3.boot.js. `git diff --check` passed. Regression and rendered checks are owned by the orchestrator/test agent; this note does not claim runtime/browser validation.

## Deliberate adaptations
Prototype coordinates and demo interaction shell are not copied. Tile/lawn boundary and fountain placement follow actual transformed Poster rectangles. Existing 34×28 minimum stays intact as directed. Room remains generic. Source has no selected table class/aria-pressed styling contract: its selection is reflected in the existing output/dialog, preserved here; keyboard focus is separately explicit. Natural textures are procedural local CSS; station art and number badges share existing footprints. No global overrides or !important added.

## Terrain correction from rendered review
Live Poster GET geometry verified: table10–13 bottoms420; tile lower edge431.84; right lawn notch x578.84/y309.84; fountain x597.76/y315.76/diameter49.32 at logical820×620. Geometry unchanged. Added a matching stone rim along notch top/left. Browser rerender remains the orchestrator gate.

## Scoped material tokens
Core repeated stone/frame/wood/glass/plaque/label/equipment colors and furniture/surface shadows are CSS custom properties scoped to `.plan-ground, .plan-tables`. Unique illustration geometry and unique material details stay local to their selectors. No global theme variables or shell styles changed. The L-shaped scene contract above supersedes the original horizontal midpoint/largest-circle approach.

## Material refinement from visual review
Added local deterministic `plan-grass.svg` with subtle fractal grain and sparse irregular blades, replacing regular diagonal lawn hatching. Table8 retains wood classification with a local live-edge art variant and irregular plank grain. Table4 parasol uses eight faceted fabric segments and a central hub. No button geometry changes; no edge foliage added because avoiding overlap with live furniture remains the priority.

## Photo clarification: places10–13
User confirmed terrace-edge wooden counter photos apply to places10–13. Added hall2 counter illustration: horizontal live-edge timber slab and two wicker chairs on terrace side. Each existing Poster rectangle remains an independent button. No coordinate, capacity, availability or booking-handler changes. Focused16 tests and independent Sol review passed; rendered desktop confirms correct chair side and separate number/status overlays. Cache version0002.

## Photo clarification: garden tables4,5,7,8
User confirms all four share glass tabletops, wicker seating and pale parasols. Reuse existing glass illustration for all four; remove obsolete table8 wood variant. Poster geometry/capacity and booking behavior unchanged. Focused16 regressions and independent Sol diff review pass. Cache0003.

## Tree and scooter approach
Owner clarified tree between10/18 with canopy across10 and half11. Ground-layer tree anchors use those live boxes; number/status/buttons stay above decoration. Gray driveway begins at Room right edge and ends at lawn notch; P/scooter sign upper-right. Missing anchors omit corresponding decoration.17 focused tests and Sol review pass; desktop rendering inspected. Cache0004.

## Garden stairs
Four treads cross the terrace border in the actual gap between11 and12. Missing/blocked gap suppresses illustration. Ground-layer decoration only; native tables unchanged.18 focused tests and Sol review pass. Cache0005.

## Second garden stairs
Same four treads left of13, shared width derived from11/12 gap and shared collision/bounds guard. Both stair groups reuse existing artwork; native tables untouched. Review fallback finding fixed: no guessed width with missing reference anchors.18 focused tests pass. Cache0006.

## Garden landscaping and tree stone bed
Added deterministic shrubs and stepping stones from live scene bounds, excluding furniture, stairs and fountain. Rectangular black pebble bed surrounds the tree trunk and reaches the adjacent tile edge, with an ochre rock. Owner wording interpreted as tree trunk (clarification pending); all artwork remains inert ground decoration. No booking, capacity or Poster geometry changes. Desktop and 390px mobile inspected; Sol review PASS and 97 JavaScript tests pass (20 focused). Cache0007.

## Fountain corner alignment
Owner requested flush left/top placement. Anchor existing diameter to lawn notch origin with native-footprint collision guard.20 focused tests and Sol review pass. Cache0008.

## Owner path reference
Replace straight spurs with one terrace-parallel route and a cubic rise beside the pool, following owner screenshot. Preserve lawn containment and collisions.21 tests including topology pass; Sol review and desktop verification pass. Cache20260921_0001.

## Gazebo illustration preview
Built-in imagegen generated gazebo-preview-v1.png from owner gazebo photo. Prompt: professional architectural drawing of complete black steel gazebo, transparent corrugated roof with bamboo crossbeams, pale sheer curtains, olive cushions and low timber table, grass base, no people/text. Lazy viewport-clamped hover/focus portal only for gazebos1/2/3/6/9; touch and booking clicks unchanged.24 focused tests pass, Sol review PASS, browser preview and booking modal transition verified. Cache20260921_0002.

## Individual chairs17-22
Owner requested two opposite two. Replace long side seats with four wicker chair drawings with outer backrests for17-22 only. Preserve all native geometry/capacity/handlers.25 tests pass including each affected number; Sol review PASS, browser inspected. Cache20260921_0003.

## Correct parasol locations
Latest owner correction supersedes earlier all-garden parasols: only7 has a garden parasol;12/13 have mounts at center lower tabletop edge.26 tests pass, Sol review PASS and browser inspected. Booking untouched. Cache20260921_0004.

### Localized scheme labels (2026-09-21)
- Translate bar, stage, cashier, room and illustration caption for ru/en/vi, including live language changes.
- Keep raw Poster labels and booking payloads intact; translate visible booking headings only.
- Regression coverage includes dictionary parity, live-label hooks and source identity preservation.

- Follow-up: translate duration, until prefix, form placeholders and accessibility labels; preserve selected date during language animation. Cache0006.
