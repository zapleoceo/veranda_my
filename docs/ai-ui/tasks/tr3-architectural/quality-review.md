# Quality review — TR3 architectural presentation

Date: 2026-09-20  
Scope: final working-tree diff and untracked TR3 presentation assets/tests. Application code was reviewed read-only.

## Change separation

`git status --short` contains only the reviewed workflow files:

- modified: `tr3/assets/app.js`, `tr3/assets/tr3.boot.js`, `tr3/assets/tr3.css`, `tr3/index.php`
- untracked: `tr3/assets/plan-presentation.js`, `tr3/assets/plan-presentation.css`, `tr3/assets/plan-grass.svg`, `tests/js/tr3-renderer.test.mjs`

No unrelated tracked modification was present. Task documentation/evidence are ignored by Git in this checkout and were reviewed separately.

## Gates C–F

| Gate | Verdict | Evidence |
|---|---|---|
| C — Implementation | **PASS** | Scope remains presentation-only. No backend/API, booking, availability, validation, authentication, or form code changed. Poster-derived button coordinates/dimensions, table identity, capacity, native button semantics, badges, and callback sequence remain owned by the existing renderer. The helper receives final rectangles and appends inert art only. Missing/malformed scene anchors fail closed rather than inventing coordinates. JS syntax and diff checks pass. |
| D — Functional validation | **PASS with recorded limit** | Fresh reviewer execution: focused TR3 suite 16/16 and complete JS suite 93/93. Tests cover both halls and rotations, baseline geometry/data/callback equivalence, hall isolation, actual rotated hall-2 terrain, occupied-pocket fountain suppression, malformed input, XSS payloads, no network/storage side effects, decorator rollback, and optional-helper failure. PHP/PHPUnit is unavailable in this worktree and remains an explicit limitation; the diff contains no PHP behavior change beyond asset version URLs. |
| E — Visual validation | **PENDING parent matrix** | Fresh desktop/mobile plan and form screenshots exist and show a coherent current render with preserved table layout and form flow. The 640/641 breakpoint boundary and final desktop validation were still running when this review closed. Do not claim the quantitative visual gate, complete viewport matrix, or pixel-perfect parity from this code review. The accepted goal is a qualitative architectural/material adaptation around authoritative live Poster geometry, not an exact clone of the mock shell. |
| F — Quality | **PASS for the changed seam** | The visual helper is a narrow feature-local module with pure classification/scene derivation plus one DOM decorator. CSS is scoped to generated `.plan-*` classes; repeated semantic material values are feature-scoped custom properties, while unique illustration values trace to the exact prototype material spec or documented local adaptations. No `!important`, global theme override, custom form/control replacement, unsafe HTML insertion, business-state mutation, remote dependency, dead import, debug output, `any`, or unsupported assertion was added. This verdict does not claim that the pre-existing 3,000-line legacy `app.js` or the wider application satisfies SOLID/DRY. |

## Findings

### Blockers

None in the reviewed code/test seam. Gate E remains owned by the parent visual matrix and cannot be approved here until that evidence is recorded.

### Should-fix

1. **Optional presentation failures remain operationally silent.**  
   `tr3/assets/tr3.boot.js:36`, `tr3/assets/app.js:3014-3018`  
   This is customer-safe because it preserves legacy rendering and booking binding, but it limits production diagnosis. If the project has a privacy-safe client diagnostic channel, report the helper-load/decorate failure once without table/customer data. This is not a deployment blocker for the optional artwork.

2. **Keep the geometry ratios documented with the scene contract.**  
   `tr3/assets/plan-presentation.js:30-58`  
   The ratios `0.16`, `0.65`, and `0.20` are visual geometry policy. They are confined to the pure helper and covered by actual-layout tests, so they are acceptable here; named constants would make later Poster-layout tuning easier to audit.

### Nice-to-have

1. Split the compact stylesheet into terrain, furniture, stations, state, and motion sections when it next changes. The current selectors are feature-scoped and contain no unsafe shared override, so this is readability work only.

## Security, accessibility, and behavior assessment

- `plan-presentation.js` performs no fetch, storage, booking, authentication, or form operation. Tests actively deny network/storage globals.
- Translated station plaques use `textContent`; native table labels continue through the existing escaping path. Adversarial strings are tested.
- Decoration uses `span` descendants marked `aria-hidden`; pointer events are disabled on generated art. Native table buttons, datasets, badges, focusability, and booking callbacks remain intact.
- A visible `:focus-visible` outline and `prefers-reduced-motion` override are present. Final browser keyboard/reduced-motion confirmation belongs in the pending visual/interaction matrix.
- Helper load failure and decorator exceptions preserve the legacy renderer, binding callback, and availability refresh.
- Hall 7 receives no presentation classification or scene. Hall 2 roles use hall + live scheme identity, never Poster ID substitution.
- The local SVG is deterministic, contains no script, event attribute, external reference, embedded HTML, or remote resource.

## Deliberate visual adaptation

Perimeter plants, prototype paths, and steps are intentionally omitted. Their mock coordinates do not describe the live Poster layout, and adding them could obscure or overlap native booking hitboxes. The implemented grass, L-shaped tile/lawn boundary, fountain, gazebo/wood/glass materials, live-edge table 8, and faceted table-4 parasol adapt the visual language to real Poster-owned positions and dimensions. This is a documented fidelity tradeoff rather than a missing business state.

## Regression risks for the final report

- A future Poster renumbering or rearrangement can change terrain/furniture classification; current malformed/partial layouts fail closed, and the actual hall geometry is covered by tests.
- Decorative seats/parasol and focus outlines may clip at boundary viewports; resolve from the parent 640/641 and final desktop/browser evidence.
- Script/decorator failures are customer-safe but operationally invisible.
- PHP/PHPUnit was not executed locally; do not broaden the 93/93 JavaScript result into a full legacy application validation claim.

## Reviewer conclusion

The changed production seam passes code quality, security, modularity, behavior-preservation, and JavaScript regression review. It is a narrow visual module attached after the existing renderer and does not change business logic. Gate E remains pending until the parent records the complete viewport/interaction matrix; no claim of exact mock-shell parity, pixel-perfect output, production deployment, or whole-application SOLID/DRY compliance is supported by this review.
