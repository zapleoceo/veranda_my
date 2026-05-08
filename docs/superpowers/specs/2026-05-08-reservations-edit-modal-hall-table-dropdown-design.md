# Reservations: Edit Modal Hall/Table Dropdown + Persist Poster Table ID (Design)

## Goals
- Add `hall_id` dropdown to the edit reservation modal (`#editResModal`) before the table field.
- Replace the manual `table_num` input with a dropdown of tables filtered by selected `hall_id`.
- Persist selected `hall_id` and `poster_table_id` into the `reservations` table.
- Ensure “вPoster” action uses `poster_table_id` from DB (and not string matching on `table_num`) when available.

## Non-Goals
- Redesigning `/reservations` page layout outside the edit modal.
- Changing Poster push logic beyond ensuring it receives the correct `table_id`.
- Adding a rich search/autocomplete UI (can be added later if needed).

## Current State (Key Findings)
- Edit modal currently stores only a string `table_num` via `ajax=save_res`:
  - Modal markup: [view.php](file:///workspace/reservations/view.php#L348-L392)
  - Client save: [reservations.js](file:///workspace/reservations/assets/js/reservations.js#L748-L807)
  - Server save whitelist currently does not include `hall_id`, `spot_id`, `poster_table_id`: [index.php](file:///workspace/reservations/index.php#L105-L141)
- DB already has the needed columns in `reservations`:
  - `spot_id`, `hall_id`, `poster_table_id`: [Database::createReservationsTable](file:///workspace/src/classes/Database.php#L588-L668)
- Poster push already prefers `poster_table_id` if it is set in DB:
  - [PosterReservationHelper::pushToPoster](file:///workspace/src/classes/PosterReservationHelper.php#L59-L66)
- `/reservations` already has an admin-only API that returns hall tables with DB-enriched settings (`scheme_num`, `display_name`, etc.):
  - `ajax=res_hall_data`: [index.php](file:///workspace/reservations/index.php#L659-L701)

## Proposed UX (Edit Modal)

### Fields
- **Hall** (new): `<select id="editResHallId" name="hall_id">`
  - Options format: `"<hall_id> — <hall_name>"` (per your preference).
- **Table** (changed): replace `#editResTableNum` `<input>` with a `<select>`:
  - `<select id="editResTableId" name="poster_table_id">`
  - Each option `value` = `poster_table_id`.
  - Visible label preference order:
    - `display_name` (if set) else `scheme_num` else `table_title` else `table_num` else `#<poster_table_id>`.
- **Table label** (stored): keep storing a human-readable `table_num` string in DB so the table column stays readable.
  - When saving, server sets `table_num` = chosen label (derived from hall tables list).

### Behavior
- On opening edit modal:
  - Load halls list once (cached in JS).
  - Select hall:
    - prefer reservation’s stored `hall_id`, else URL `hall_id`, else default hall from server.
  - Load table list for the chosen hall.
  - Select table:
    - prefer reservation’s stored `poster_table_id`
    - otherwise try to match by existing `table_num` label.
- On hall change:
  - Reload table list (keep selection if possible, otherwise reset to empty).

## Proposed Backend Changes

### New AJAX endpoints
All endpoints remain under `/reservations?ajax=...` and require the same auth as the page.

1) `ajax=res_halls_list` (GET)
- Input:
  - `spot_id` (default from querystring/env)
- Output:
  - `[{ hall_id, hall_name }]`
- Source of truth:
  - Poster `spots.getSpotTablesHalls` (like in [PosterReservationHelper](file:///workspace/src/classes/PosterReservationHelper.php#L78-L109))
- Permissions:
  - Allow users who can press “вPoster” (`$hasPosterAccess`) and admins.

2) `ajax=res_hall_tables_list` (GET)
- Input:
  - `spot_id`, `hall_id`
- Output:
  - `[{ poster_table_id, label, scheme_num, display_name, table_title, table_num }]`
- Source of truth:
  - Prefer `$tablesController->hallData($spot_id, $hall_id)` (DB-enriched), falling back to Poster only if controller is unavailable.
- Permissions:
  - Same as `res_halls_list`.

### Extend `ajax=save_res` to persist hall/table
- Accept and persist:
  - `spot_id` (optional, default 1)
  - `hall_id` (required if `poster_table_id` provided)
  - `poster_table_id` (required for “вPoster correctness”)
  - `table_num` (server-derived label; client may omit)
- Validation:
  - `poster_table_id > 0`, `hall_id > 0`, `spot_id > 0`
  - Verify that `poster_table_id` exists within the chosen hall table list (to prevent mismatch / bad manual payload).
- Server-side label:
  - Find the selected table in the hall tables list and write `table_num` = computed label, so UI column stays consistent.

## “вPoster uses correct table_id” (Verification Plan)
- After edit/save:
  - Reservation row in DB has `poster_table_id` set.
  - `PosterReservationHelper::pushToPoster` uses `poster_table_id` directly (existing behavior).
- Add a lightweight regression test:
  - Ensure `PosterReservationHelper` selects `poster_table_id` when present (unit-level by reading code / or by a small PHP test with a stub row).

## Rollout Notes
- The page `/reservations` is already auth-protected; these endpoints should reuse existing permission checks and not be exposed publicly.
- The edit modal remains usable even if Poster API is down:
  - In that case, keep a fallback text input for `table_num` (or disable table dropdown with message).

