# Validation — TR3 architectural presentation

2026-09-20. Real production HTML/bootstrap/Poster data with local assets through a read-only proxy. No reservation or messaging submissions.

- Full JS suite: 93/93 pass, including16 TR3 behavior tests; node syntax and git diff checks pass.
- Existing renderer baseline versus presentation hook: four hall/rotation scenarios preserve button rectangles, IDs, capacity badges and callbacks.
- Browser:1440x1080,390x844,640x900,641x900 rendered. Desktop/mobile gazebo1 selection opens unchanged request modal; submit disabled without required data. Cinema toggle retains original unstyled hall. No console errors. Computed artwork pointer-events:none.
- Reduced-motion CSS disables artwork animation; not emulated in browser, structural review only.
-641px legacy cover scaling crops map and permits pan; geometry/zoom implementation unchanged. Fresh390/640 loads show entire map. Runtime viewport resizing can retain previous zoom, pre-existing behavior.
- Exact image diff against standalone prototype is inapplicable: user requires live Poster geometry and existing production shell. Rendered material review performed. Perimeter plants/stepping paths omitted to avoid inventing real spatial features or obscuring Poster footprints. No exact pixel parity claim.
- PHP lint/PHPUnit are deferred to required GitHub CI before merge/deploy; local PHP unavailable.

Evidence is local under docs/ai-ui/evidence/tr3-architectural. Production smoke verification follows deploy.
