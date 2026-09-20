# Implementation plan

## Authority and scope
CONFIRMED: live hall 2 geometry and table settings were read from public GET APIs on 2026-09-20. Hall 2 rotate_180=1. Display/booking settings remain authoritative. Table 4 is Poster25; 8 is158; gazebos are visible scheme numbers1/2/3/6/9 (not Poster IDs). Nonbookable stations:32 bar,38 stage,185 cashier. Identify station roles using existing labels/type only where explicitly safe, without mutating identity. Room49 remains generic.

## Visual-only implementation
1. Add feature-local `plan-presentation.js` module exposing pure geometry/decor classification and safe DOM decoration; plain browser script compatible with Node testing (existing vanilla architecture). No fetch, storage, form state, booking or authentication code.
2. Add `plan-presentation.css` and SVG assets if appropriate, with feature-scoped tokens; match accepted natural grass/tile/wood, gazebo cushions/drapes/frame, wicker chairs, subdued stations, water/koi. No roof/grass/fountain text. Preserve inherited status classes and reservation overlays.
3. Hook module into existing renderHallTables after real visible items are transformed. Derive terrace bounds from visible numbered terrace items10–22 and stations; derive lawn/fountain decoration from same transformed coordinates. Never copy mock hardcoded table layout. Decorative elements must be inert and pointer-events:none; IDs/dataset/capacity, .table-badge, .num, .cap untouched. Only main hall2 gets furniture styles; cinema retains existing behavior.
4. Existing fit/rotation math and button footprints stay unchanged for real current data. Existing min-size fallback can remain as legacy safeguard; tests document this. Do not refactor unrelated renderer or migrate state management.
5. Load module before app.js via existing bootstrap chain, import CSS after existing styles; bump relevant cache versions in index and bootstrap. Presentation failure must fall back to legacy rendering rather than prevent booking UI loading.

## Structural/logic/shared changes
Only decorative children, scope classes and asset loading are structural. No API, business state, validation, request, backend, permission, translation contract or shared-system changes. Station translated visual text may use existing localization keys. No production mock content.

## Verification
Add meaningful node:test tests under tests/js (existing CI/deploy test glob): hall isolation, role mapping by scheme_num, immutability, geometry/bounds relative to actual rows, malformed input safety, HTML injection safety, reduced-motion/DOM invariants. Add regression harness executing baseline and changed renderer with same fixtures to compare geometry, IDs/datasets, counts, handler binding and availability. Local browser preview serves real production HTML/API GET with local assets only (reject all writes); validate desktop and mobile, all halls, room/gazebo/normal table selection and validation without submitting. Run CI/PHPUnit before main; deploy existing workflow; verify real production assets and page. Independent diff/security/modularity review before deploy.
