# TR3 architectural regression tests

Date: 2026-09-20. Scope: changed `renderHallTables` presentation hook and new `plan-presentation.js`. No application files changed by this test task.

## Executed

`node --test tests/js/tr3-renderer.test.mjs`

```text
ℹ tests 16
ℹ suites 0
ℹ pass 16
ℹ fail 0
ℹ cancelled 0
ℹ skipped 0
ℹ todo 0
```

`node --test "tests/js/**/*.test.mjs"` (entire CI JavaScript suite)

```text
ℹ tests 93
ℹ suites 0
ℹ pass 93
ℹ fail 0
ℹ cancelled 0
ℹ skipped 0
ℹ todo 0
ℹ duration_ms 381.5137
```

No JavaScript failures. Existing Node MODULE_TYPELESS_PACKAGE_JSON warning remains in payday3 modules.

## Behavioral evidence

Tests execute the actual renderer function extracted from app.js in a Node VM with a lightweight DOM. Assertions compare concrete expected geometry computed independently from fixture bounds, with negative origins, hidden oversized table, unconfigured table, tiny legacy minimum dimensions, nonbookable station, custom label, and Poster IDs distinct from scheme numbers. Both hall 2 and hall 7 run with rotation enabled and disabled. Native buttons, datasets, badge markup, capacities and binding/availability callback order remain intact. Hall 2 receives furniture; hall 7 does not.

An additional read-only validation executed the original renderer obtained through `git show origin/main:tr3/assets/app.js` in the same harness and compared its native output with the changed renderer:

```text
BASELINE COMPARISON: 4/4 hall/rotation fixtures match origin/main native buttons, geometry, datasets, badges and callbacks
```

Pure helper tests prove hall/scheme classification, input immutability, malformed-input rejection, terrain movement with changed table coordinates, and fountain clearance from every fixture table footprint. DOM tests prove retained booking elements, aria-hidden span-only decoration, adversarial translations written as literal text, native badge escaping, rollback after simulated DOM failure, and absent/throwing helper fallback. A VM denies network and storage global access during decoration.

Fixtures include synthetic edge cases and sanitized actual rotated hall-2 rendered bounds captured from public geometry on 2026-09-20; they contain no reservation/customer data. Tests make no external requests and perform no booking actions. This is a visual enhancement, not a reported production bug; bugfix repro requirements do not apply.

## Explicit limits / remaining independent gates

- This lightweight DOM does not compute CSS, pointer hit-testing, animation, browser accessibility trees, or real click handlers. Computed pointer-events, reduced-motion, native focus/selection, mobile rendering and actual availability interaction require the parent task's browser validation.
- PHP/PHPUnit suite could not run in this worktree: php/composer are absent from PATH and vendor/bin/phpunit is absent. The parent task must execute the existing full PHP CI/deploy gate. JavaScript success is not a claim that the full project suite or production deployment passed.
- Baseline comparison is an executed validation step, not a permanent test dependency on Git history. Persistent tests have independent expected formulas and remain runnable in shallow CI checkouts.


## L-shaped terrain correction

The final 16 TR3 tests include all actual rotated hall-2 bounds. They assert every table 10–22 stays completely on tile, the lawn rises at the right of the lower terrace and below the upper terrace, and the fountain remains above gazebo 1 / right of table 10 / below table 17 while clearing every native footprint. A blocked pocket suppresses the fountain. Minimal geometry without anchors produces no fabricated fountain. The full final JavaScript suite passes 93/93.

