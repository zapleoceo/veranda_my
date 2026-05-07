# TR4 + Reservations: MVC, Table Settings DB, Geometry (Design)

## Goals
- Keep TR3 production flow working without changes to `/tr3/*`.
- Isolate TR4 refactor inside `/tr4/*` (code + API contract can change).
- Remove hardcoded table allowlist/capacity defaults and move table configuration into DB.
- Refactor `/reservations` into clearer MVC boundaries and remove runtime schema changes from request path.
- Fix TR4 geometry so tables and decorations share the same coordinate space derived from Poster hall coordinates.
- Add automated regression tests before and after refactor.

## Non-Goals
- Rewriting TR3 booking UX or changing TR3 endpoints.
- Introducing a full frontend build pipeline/bundler.
- Changing Poster data model; we consume Poster tables as-is.

## Current State (Key Findings)
- TR3 and TR4 read table allowlist/capacity from `system_meta` keys:
  - `reservations_allowed_scheme_nums_hall_<hall_id>`
  - `reservations_table_caps_hall_<hall_id>`
  - defaults and numeric-only parsing live in both TR3/TR4 context.
- `/reservations/index.php` mixes:
  - environment + auth + db
  - runtime schema alterations
  - ajax routing
  - Poster calls
  - HTML rendering
- `/reservations` “allowed” checkbox currently means “appears as available for selection in TR pages”, but cannot model:
  - show/hide on canvas vs bookable
  - display name overrides
  - non-numeric Poster table titles
- TR4 geometry currently fits tables into a fixed `820×620` viewport while decorations are positioned in a different “pixel world”, producing scale mismatch.

## Proposed Data Model

### Table Settings (new)
Create a DB table (with suffix applied) to store per-table configuration.

`reservation_table_settings`
- `id` bigint PK auto increment
- `spot_id` int not null
- `hall_id` int not null
- `poster_table_id` int not null
- `scheme_num` int null
- `display_name` varchar(80) null
- `show_on_canvas` tinyint(1) not null default 1
- `bookable` tinyint(1) not null default 1
- `capacity` int not null default 0
- `updated_at` timestamp not null default current_timestamp on update current_timestamp
- unique key: `(spot_id, hall_id, poster_table_id)`
- index: `(spot_id, hall_id)`

Notes:
- `scheme_num` remains optional because Poster titles can be non-numeric; TR4 can show a `display_name` even without numeric mapping.
- `bookable` is separate from `show_on_canvas`, so management can show a table on the map but make it not bookable.

### Decorations (new)
Store decoration elements per hall so TR4 can render them in the same coordinate space as tables, without hardcoding.

`reservation_hall_decor_items`
- `id` bigint PK auto increment
- `spot_id` int not null
- `hall_id` int not null
- `decor_type` varchar(32) not null  (e.g. `fountain`, `bar_row`, `grass_corner_1_7`)
- `x` float not null
- `y` float not null
- `w` float not null
- `h` float not null
- `z` int not null default 1
- `props_json` text null (type-specific properties)
- `updated_at` timestamp not null default current_timestamp on update current_timestamp
- index: `(spot_id, hall_id)`

Seeding:
- Seed hall_id=2 decor based on current TR4 visual positions converted into Poster-world units after we confirm the mapping.

## Migration Strategy
- Add a lightweight migration runner under `/reservations/migrations/*` and execute it from `/reservations/index.php` (admin-only path) to create the tables if absent.
- Backfill:
  - Read existing `system_meta` allowlist/caps per hall.
  - Fetch Poster `spots.getTableHallTables` for a chosen `spot_id/hall_id`.
  - Upsert `reservation_table_settings` rows using `poster_table_id`, filling:
    - `scheme_num` from numeric extraction when possible
    - `capacity` from existing meta caps
    - `show_on_canvas/bookable` from meta allowlist

## TR3 Compatibility Plan
TR3 must remain unmodified.

- Treat `reservation_table_settings` as source of truth.
- Whenever `/reservations` updates table settings, also update legacy meta keys for that `hall_id`:
  - `reservations_allowed_scheme_nums_hall_<hall_id>` should mirror tables where `bookable=1` and `scheme_num` is not null.
  - `reservations_table_caps_hall_<hall_id>` should mirror `capacity` by `scheme_num`.
- TR3 continues reading meta keys unchanged.

## TR4 Refactor Plan (Isolated to `/tr4/*`)

### New TR4 Backend Contract
TR4 should stop reading `system_meta` directly and instead read from the new tables via DB access inside `/tr4/api_context.php`.

Proposed bootstrap payload (example fields):
- `tables_by_hall`: map keyed by `hall_id`, each is an array:
  - `poster_table_id`, `scheme_num`, `display_name`, `capacity`, `show_on_canvas`, `bookable`
- `decor_by_hall`: map keyed by `hall_id`, each is an array:
  - `decor_type`, `x`, `y`, `w`, `h`, `z`, `props`

TR4 may change freely; cache-busting must be used for deploy.

### Geometry & Rendering
Goal: One coordinate space derived from Poster world coordinates for each hall.

- Compute world bounds from Poster tables (and decor items if needed):
  - `minX/minY/maxX/maxY` using `x/y/w/h`.
- Compute base transform to fit world bounds into the current viewport (container size, not hardcoded):
  - `baseScale`, `offsetX`, `offsetY`
- Apply transform to a single `world-layer` DOM container that contains:
  - `decor-layer`
  - `tables-layer`
- Keep user zoom (`--map-scale`) as a multiplier on top of `baseScale`.
- This guarantees tables and decorations scale and move together.

### Performance
- Avoid rebuilding DOM on every state change; reuse nodes when possible:
  - render layout once per hall load
  - only update “busy/free/disabled” classes and labels when availability changes
- Reduce layout thrash:
  - set container transform once per load/resize
  - use CSS transforms rather than recalculating per-table pixel positions where feasible

## `/reservations` Refactor (MVC)

### Target Architecture
- `reservations/index.php`: thin front controller
  - loads env/auth/db
  - dispatches to controllers based on `ajax` or renders view
- `reservations/Controllers/*`
  - `TablesController` (hall data, save table settings, decor CRUD)
  - `ReservationsController` (existing list/actions)
- `reservations/Repositories/*`
  - `TableSettingsRepository`
  - `DecorRepository`
  - `LegacyMetaSync` (writes meta keys for TR3 compatibility)
- `reservations/Services/*`
  - `PosterTablesService` (fetch and normalize Poster tables)
- `reservations/views/*`
  - `reservations.php` page view
  - partials for tables editor/modal

### UI Changes
- Canvas should render from Poster table coordinates (as it already does), but:
  - checkbox 1: `show_on_canvas`
  - checkbox 2: `bookable`
  - click on table opens modal editor with:
    - display name
    - capacity
    - show_on_canvas
    - bookable
    - optional scheme_num override
- Allow non-numeric table titles by storing `poster_table_id`-keyed settings.

## Tests
No existing test framework is present; tests should be runnable via CLI.

### Contract / Regression Tests (Before Refactor)
- `php scripts/tests/tr3_meta_contract.php`
  - ensures meta keys exist or are valid JSON when present
  - ensures values stay within expected ranges
- `php scripts/tests/tr4_bootstrap_contract.php`
  - ensures `/tr4/api.php?ajax=bootstrap` returns `ok=true` and required fields

### After Refactor
- `php scripts/tests/reservations_table_settings_sync.php`
  - upsert sample settings into `reservation_table_settings`
  - runs legacy meta sync and asserts meta keys updated as expected
- `node scripts/tests/tr4_geometry_math.test.js`
  - tests pure functions used to compute world bounds and viewport transforms

## Rollout
- Implement tables + migration runner.
- Implement `/reservations` MVC refactor and switch UI to DB-backed settings.
- Implement TR4 bootstrap to read DB-backed settings and decor.
- Implement TR4 geometry transform based on Poster world size and decor items.
- Verify TR3 remains functional by ensuring legacy meta keys are updated and by running regression tests.

