# Plan (FINAL): Frontend-coordinated — MaintenanceSubStatus normalization + frontend value updates (+ sub-status shim)

> **Dependency:** Plan 1 (backend `MaintenanceStatus` rename + maintenance_status shim) must be
> deployed first — this plan's frontend consumes the `enrolled`/`withdrawn` values it emits.
> Plan 1 file: `1782944404943-rename-asset-status-enums.md`.
> **Shim removal is OUT of this plan** → separate Plan 3.
>
> ⚠️ **CRITICAL ordering (Flag D — atomicity hazard):** the frontend naturally bundles the
> `maintenance_status` **and** `maintenance_sub_status` value changes in the same files
> (`types/index.ts`, `AssetDetailView.vue`, `displayHelpers.ts`). The `maintenance_status` half is
> safe now (Plan 1 is deployed + shimmed), but the **`maintenance_sub_status` half is NOT** until
> this plan's backend tasks 1–3 land: the backend enum is still PascalCase and validation is
> `in:Installed,Ready,LIH,DBR,Disposed,Scrapped,Other`, so a frontend sending lowercase
> `lih`/`dbr`/… gets **422** on every sub-status save.
> **Therefore this plan is NOT atomic frontend+backend — it is backend-shim-first.** Deploy backend
> tasks 1–3 (enum lowering + migration + sub-status shim) FIRST. Once the sub-status shim is live
> (accepts both cases), the frontend can deploy anytime — the same pattern that made Plan 1 safe.

## Goal

Complete the asset-status value work: (1) frontend consumes the new `maintenance_status` values
from Plan 1; (2) normalize `MaintenanceSubStatus` values to snake_case (backend + frontend);
(3) improve display labels. **Backend sub-status half (tasks 1–3) deploys FIRST; frontend can
follow anytime** once the sub-status shim is live (see Flag D). Both status shims stay active so
open-tab SPA sessions can't 422.

## Verified context

- `maintenance_sub_status` live data: **all 429 rows NULL** → migration UPDATEs affect 0 rows on
  live DB (included for seeds/correctness).
- Frontend is structurally coupled to BOTH `maintenance_status` (Plan 1 values) and the PascalCase
  sub-status values. Complete footprint confirmed by repo-wide grep.
- **Trap to avoid:** ~10 unrelated `'Active'`/`'Inactive'` literals in the frontend are `is_active`
  (soft-delete) for Users, PmRules, Locations, WoForms, MasterData — MUST NOT be touched.
- `MaintenanceSubStatus` has 0 backend constant references; only enum + cast + validation strings
  + 1 test line.

## Decisions

1. Normalize `MaintenanceSubStatus` values → snake_case (case names unchanged). `OperationalStatus`
   and `is_active` untouched.
2. **Keep** Plan 1's maintenance_status shim; **add** a temporary sub-status shim (accept PascalCase
   + lowercase on input, normalize→lowercase before model assignment). Open-tab-safe.
   - **Flag A:** rename Plan 1's class `LegacyMaintenanceStatusNormalizer` →
     `LegacyAssetStatusNormalizer`, and add a static `normalizeSubStatus(?string): ?string` to it
     (same class as the existing `normalize(?string): ?string`). Single-file removal in Plan 3.
   - **Flag B:** also fix the existing class docblock/comment (currently "REMOVE with follow-up
     frontend plan") → "remove in **Plan 3**"; this is an explicit edit in this task.
3. **Deploy backend tasks 1–3 BEFORE any frontend sub-status change ships (Flag D).** The sub-status
   shim is what decouples frontend timing — same as Plan 1. Do NOT rely on atomic frontend+backend
   deploy. If the frontend is already sending lowercase sub-status in-tree, either hold its deploy
   or temporarily keep it on PascalCase until backend 1–3 land; if it is already deployed and 422ing,
   backend 1–3 is the immediate hotfix to unblock.
4. **Shim removal is NOT in this plan** — deferred to Plan 3 (fixed 14-day window after this plan
   deploys; gated on open-tab sessions cycling out).
5. Frontend display = **human-readable labels** (`enrolled`→"In maintenance program", etc.).
6. `maintenance_status` filter left non-functional (rename values for consistency only); wiring
   server-side filtering = separate ticket.
7. Migration uses explicit `UPDATE` statements (nullable column, no default change).

## Enum mapping

- `MaintenanceSubStatus` (case names unchanged): `Installed→installed`, `Ready→ready`, `LIH→lih`,
  `DBR→dbr`, `Disposed→disposed`, `Scrapped→scrapped`, `Other→other`

## Backend tasks

1. `app/Enums/MaintenanceSubStatus.php` — lower-case the 7 values (names stay).
2. `app/Http/Controllers/AssetController.php` (store + update) — **temporary sub-status shim**:
   - validation `maintenance_sub_status` → `in:` accepting BOTH PascalCase + lower-case
   - normalizer maps PascalCase→lower-case **before** model assignment (so any future `booted`
     logic sees the canonical value). Add a static `normalizeSubStatus(?string): ?string` to the
     SAME class Plan 1 created — `App\Support\LegacyAssetStatusNormalizer` — alongside the existing
     `normalize(?string): ?string` (Flag A). **Rename** Plan 1's `LegacyMaintenanceStatusNormalizer`
     → `LegacyAssetStatusNormalizer` here if not already renamed.
     - **Null-safety (Flag 3):** `maintenance_sub_status` validation is `nullable`, so the value
       can be `null` — the method signature MUST be `(?string): ?string` (return `null` unchanged),
       matching the status normalizer. Alternatively guard at the call site with
       `array_key_exists('maintenance_sub_status', $validated) && $validated['maintenance_sub_status'] !== null`
       before normalizing. Pick one; keep both shims parallel and null-safe.
   - Update the class docblock/comment: remove "REMOVE with follow-up frontend plan" wording →
     "TEMPORARY — remove in **Plan 3**" (Flag B).
   - **Mark TEMPORARY**; removal tied to **Plan 3** (`1782944404945-plan3-remove-status-shims.md`).
   - (No change to the maintenance_status shim logic — it stays as Plan 1 left it, just possibly
     renamed per Flag A.)
3. New migration `database/migrations/2026_07_02_xxxxxx_normalize_maintenance_sub_status_values.php`:
   - 7 `UPDATE assets SET maintenance_sub_status='<lower>' WHERE maintenance_sub_status='<Pascal>'`
   - no default change (column is nullable, default null)
   - `down()`: reverse the 7 UPDATEs
   - run: `docker exec atms-api php artisan migrate --no-interaction`

## Frontend tasks

4. `frontend/src/types/index.ts` — `AssetMaintenanceStatus` → `'enrolled'|'withdrawn'`;
   `AssetMaintenanceSubStatus` → lower-case union (`installed|ready|lih|dbr|disposed|scrapped|other`).
5. `frontend/src/composables/useAssetDetail.ts` — L83 draft default `'Active'`→`'enrolled'`;
   **L227 hydration fallback** `'Active'`→`'enrolled'` (two literals, not one).
6. `frontend/src/views/assets/AssetDetailView.vue` (L72-89) — `=== 'Inactive'`→`=== 'withdrawn'`;
   `availableSubStatuses` option **values**→lower-case (`lih`,`dbr`,`disposed`,`scrapped`,`other`,
   `installed`,`ready`). Human **labels** stay ("Lost in Hole", "Damaged Beyond Repair", etc.).
7. `frontend/src/lib/displayHelpers.ts` (L87-113) — map keys → new values with **human labels**:
   - `assetMaintenanceStatusLabel`/`Class`: `enrolled`→"In maintenance program", `withdrawn`→"Withdrawn"
   - `assetMaintenanceSubStatusLabel`: `installed`→"Installed", `ready`→"Ready", `lih`→"Lost in Hole",
     `dbr`→"Damaged Beyond Repair", `disposed`→"Disposed", `scrapped`→"Scrapped", `other`→"Other"
8. `frontend/src/lib/assetColumns.ts` (L94) — filter option values `['Active','Inactive']`
   → `['enrolled','withdrawn']` (labels via displayHelpers). Filter remains non-functional server-side.
9. Verify `AssetsView.vue` (L158-159) and `AssetLocationUpdateView.vue` (L135-137) render via the
   helper functions → **no literal edits needed**; confirm after the helper change.
10. Frontend verify: `npm run typecheck && npm run build` + frontend lint. Review against the
    `vue-frontend-guardrails` skill.

## Tests

- `tests/Feature/MaintenanceStatus/MaintenanceStatusGuardTest.php` — Plan 1's maintenance_status
  shim tests stay green; **ADD (sub-status shim)** PATCH legacy PascalCase sub-status (e.g. `'Disposed'`)
  → 200, persisted lower-case; ADD a skipped/commented `legacy-sub-status→422` stub for Plan 3.
- `tests/Feature/ReadModels/AssetResourceTest.php` — assert a set sub-status is emitted lower-case.
- (No change to gate-action tests — `MaintenanceSubStatus` has no gate consumers.)

## Validation

1. Targeted: `docker exec atms-api php artisan test --compact tests/Feature/MaintenanceStatus tests/Feature/ReadModels/AssetResourceTest.php`
2. Regression: `docker exec atms-api php artisan test --compact tests/Feature/Assets tests/Feature/Pm tests/Feature/WorkOrders`
3. Full suite: `docker exec atms-api php artisan test --compact`
4. Pint: `docker exec atms-api php vendor/bin/pint --dirty --format agent`
5. Boost: `database-query SELECT maintenance_sub_status, COUNT(*) FROM assets GROUP BY 1`
   (expect only NULL after migration); `route:list --path=assets` (unchanged).
6. Frontend: `npm run typecheck && npm run build`.

## Rollout & risk

- **Backend-first, not atomic (Flag D).** Deploy backend tasks 1–3 (enum lowering + migration +
  sub-status shim) FIRST. Once the sub-status shim is live, the backend accepts BOTH PascalCase and
  lower-case on input → frontend can deploy any time after, no 422 risk. This is the same pattern
  that made Plan 1 safe; it removes the frontend/backend timing coupling entirely.
- **If a frontend sub-status change lands in-tree sending lowercase:** hold its deploy (or keep
  PascalCase) until backend 1–3 ship. **If such a frontend is ever deployed before 1–3:** it will
  422 on every sub-status save, and backend 1–3 is the immediate hotfix. (Verified current state:
  backend sub-status is still PascalCase-only; the current SPA still sends PascalCase, so nothing
  is broken today — this is forward-looking guidance.)
- Output emits new values; frontend renders human labels → resolves the confusing status display.
- Deploy ordering: backend enum/migration/code ship first (migration runs before serving new code).
  ERP import literal unaffected (it sets maintenance_status only).
- Fresh `migrate:fresh`: the only seeder (`2026_06_28_..._seed_baseline_real_data.php`) inserts NULL
  for `maintenance_sub_status` on every row — the "Installed"/"Ready" strings there are inside asset
  NAMES (e.g. "No Battery Installed"), not the column; no PascalCase sub-status data exists to
  normalize (Flag C precision). Safe.
- Historical audit logs keep old values; do NOT migrate `audit_logs`.
- **Shim removal = Plan 3 (separate, later):** once open-tab sessions have cycled out / a
  forced-refresh is confirmed, remove BOTH shims (maintenance_status + sub-status) from validation
  + the (single) `LegacyAssetStatusNormalizer` class, enable the `legacy→422` test stubs.
- PHP runs in Docker via `docker exec atms-api php ...`.

## Out of scope

- Shim removal for maintenance_status and sub-status → **Plan 3**.
- Server-side `maintenance_status` filtering in `AssetIndexQuery` (the frontend filter stays a no-op).
- `CreateAsset::execute()` field-drop bug (separate ticket).
- `erp_status` raw varchar.
- `OperationalStatus` / `is_active` (untouched — and the unrelated `is_active` 'Active'/'Inactive'
  display literals must not be changed).
