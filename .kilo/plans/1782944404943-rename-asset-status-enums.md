# Plan: Rename asset `Status` enum values to snake_case (backend + frontend + shim)

## Goal

Eliminate the backend/API value collision where `operational_status = 'active'` and
`maintenance_status = 'Active'` both render as "Active" on the asset detail screen.
Rename the **values** of two enums to snake_case. No columns removed; no business logic,
gates, or access tiers change.

## Verified context

- 429 assets; 426 have `operational_status='active'` AND `maintenance_status='Active'`
  (live collision). `maintenance_status`: 429 `Active`, **0 `Inactive`**.
  `maintenance_sub_status`: **all 429 NULL**.
- `api_clients` table empty → **no external API clients**; default token ability is
  read-only. The frontend SPA is the sole consumer of these values.
- **Frontend is structurally coupled** (verified): `types/index.ts:18,20-22` (TS unions),
  `useAssetDetail.ts:83` (edit draft default `maintenance_status:'Active'`), `AssetDetailView.vue:73`
  (`=== 'Inactive'` branch + full `availableSubStatuses` PascalCase map), `displayHelpers.ts:87-103,105+`,
  `assetColumns.ts:94`. Stale cached SPA submitting an edit sends `'Active'` → backend 422.
- `maintenance_status` hard-gate consumers (5, behavior preserved): `CreateCorrectiveMaintenanceRequest`,
  `ApproveMaintenanceRequestAndCreateWorkOrder`, `AssignWorkOrder`, `EvaluatePmRulesJob`, `Asset::booted`.
- `maintenance_sub_status` has **0 constant references**; only cast + validation strings + 1 test line.

## Decisions

1. Rename **both** `MaintenanceStatus` and `MaintenanceSubStatus` values to snake_case.
2. **Frontend changes are REQUIRED** and in this same deploy (not optional labels).
3. **Add a temporary backend input-normalization shim** (expand-contract) so a stale cached
   SPA cannot break edit saves; remove after frontend migration confirmed + cache flushed.
4. `MaintenanceStatus` case names change too (`ACTIVE`→`ENROLLED`, `INACTIVE`→`WITHDRAWN`).
   `MaintenanceSubStatus` case names stay; values lower-case only.
5. Migration uses explicit SQL `ALTER TABLE ... SET DEFAULT` (no `->change()`).
6. Deployed migrations/seeder-migration are never edited; new migration converts their output.

## Enum mappings

- `MaintenanceStatus`: `ACTIVE='Active'`→`ENROLLED='enrolled'`; `INACTIVE='Inactive'`→`WITHDRAWN='withdrawn'`
- `MaintenanceSubStatus` (case names unchanged): `Installed→installed`, `Ready→ready`, `LIH→lih`,
  `DBR→dbr`, `Disposed→disposed`, `Scrapped→scrapped`, `Other→other`
- `OperationalStatus`, `AssetKind`, `RoleCode`: untouched

---

## Backend tasks

1. `app/Enums/MaintenanceStatus.php` — rename both cases (names + values).
2. `app/Enums/MaintenanceSubStatus.php` — lower-case the 7 values (names stay).
3. `app/Models/Asset.php` — `booted()` (L69): `MaintenanceStatus::INACTIVE` → `::WITHDRAWN`.
   (fillable L31, casts L54–55 unchanged — class names only.)
4. Gate constant sites — swap `::ACTIVE`→`::ENROLLED`, `::INACTIVE`→`::WITHDRAWN`:
   - `app/Actions/MaintenanceRequests/CreateCorrectiveMaintenanceRequest.php` (L26)
   - `app/Actions/MaintenanceRequests/ApproveMaintenanceRequestAndCreateWorkOrder.php` (L25)
   - `app/Actions/WorkOrders/AssignWorkOrder.php` (L20)
   - `app/Jobs/EvaluatePmRulesJob.php` (L33)
5. `app/Console/Commands/ImportErpAssetsCommand.php` (L157) — hardcoded `'Active'` → `'enrolled'`.
6. **Temporary shim in `AssetController`** (store + update):
   - Validation `in:` accepts BOTH sets:
     - `maintenance_status` → `in:enrolled,withdrawn,Active,Inactive`
     - `maintenance_sub_status` → `in:installed,ready,lih,dbr,disposed,scrapped,other,Installed,Ready,LIH,DBR,Disposed,Scrapped,Other`
   - After validation, normalize legacy→new via a localized map (e.g. private const maps +
     a small `normalize()` helper). Downstream always receives new values. Emits only new values.
   - **Mark every shim piece TEMPORARY** with a removal TODO referencing task 8.
7. New migration `database/migrations/2026_07_02_xxxxxx_normalize_asset_status_enum_values.php`:
   - `UPDATE assets SET maintenance_status='enrolled' WHERE maintenance_status='Active'`
   - `UPDATE assets SET maintenance_status='withdrawn' WHERE maintenance_status='Inactive'`
   - 7 `UPDATE` statements mapping each PascalCase `maintenance_sub_status` → lower-case
   - `DB::statement("ALTER TABLE assets ALTER COLUMN maintenance_status SET DEFAULT 'enrolled'")`
   - keep `maintenance_status_index` (no action)
   - `down()`: reverse all UPDATEs + `SET DEFAULT 'Active'`
   - run: `docker exec atms-api php artisan migrate --no-interaction`
   - **Note:** `down()` on PostgreSQL for the data UPDATEs must run in a transaction-safe order.

## Frontend tasks (REQUIRED, same deploy)

8. `frontend/src/types/index.ts` — `AssetMaintenanceStatus` → `'enrolled'|'withdrawn'`;
   `AssetMaintenanceSubStatus` values → lower-case.
9. `frontend/src/composables/useAssetDetail.ts` (L83) — draft default
   `maintenance_status:'Active'` → `'enrolled'`.
10. `frontend/src/views/assets/AssetDetailView.vue` (L72-89) — `=== 'Inactive'` → `=== 'withdrawn'`;
    `availableSubStatuses` option **values** → lower-case (`lih`,`dbr`,`disposed`,`scrapped`,`other`,
    `installed`,`ready`). Human-readable **labels** stay as-is.
11. `frontend/src/lib/displayHelpers.ts` — `assetMaintenanceStatusLabel`/`Class` map keys
    `Active`/`Inactive` → `enrolled`/`withdrawn`; `assetMaintenanceSubStatusLabel` map keys →
    lower-case. (Optional polish: map `enrolled`→"In maintenance program", `withdrawn`→"Withdrawn".)
12. `frontend/src/lib/assetColumns.ts` (L94) — filter option values `['Active','Inactive']`
    → `['enrolled','withdrawn']`. (Pre-existing note: backend `AssetIndexQuery` does not actually
    filter on `maintenance_status`, so this filter is currently a no-op server-side — out of scope
    to fix, but values still updated for display consistency.)
13. Frontend verify: `npm run typecheck` (TS union change ripples) + `npm run build` + frontend lint.
    Review against the `vue-frontend-guardrails` skill.

## Tests (P2 — explicit contract assertions)

Update existing string/constant refs, and ADD:
- `tests/Feature/ReadModels/AssetResourceTest.php` — assert default serialized
  `maintenance_status === 'enrolled'`; with a sub-status set, assert lower-case value emitted.
- `tests/Feature/MaintenanceStatus/MaintenanceStatusGuardTest.php`:
  - update constants/strings to `enrolled`/`withdrawn`; sub-status `'Disposed'`→`'disposed'`.
  - **ADD (shim):** PATCH legacy `'Active'` → 200, persisted as `enrolled`; PATCH legacy `'Inactive'`
    → 200, persisted as `withdrawn`.
  - **ADD (skipped/commented for post-shim):** legacy value → 422 once shim removed.
- `tests/Feature/Assets/AssetBookingTest.php` — update to `withdrawn`; **ADD** legacy `'Inactive'`
  PATCH clears booking (shim behavior).

## Validation

1. Targeted: `docker exec atms-api php artisan test --compact tests/Feature/MaintenanceStatus tests/Feature/Assets/AssetBookingTest.php tests/Feature/ReadModels/AssetResourceTest.php`
2. Regression: `docker exec atms-api php artisan test --compact tests/Feature/Assets tests/Feature/Pm tests/Feature/WorkOrders`
3. Full suite: `docker exec atms-api php artisan test --compact`
4. Pint: `docker exec atms-api php vendor/bin/pint --dirty --format agent`
5. Boost: `database-schema` (default changed), `database-query`
   `SELECT maintenance_status, maintenance_sub_status, COUNT(*) FROM assets GROUP BY 1,2`
   (expect only `enrolled`/NULL), `route:list --path=assets` (unchanged).
6. Frontend: `npm run typecheck && npm run build`.

## Rollout & risk

- **Atomic deploy** of backend + frontend together.
- **Shim protects the stale-SPA write window**: a cached old bundle submitting the old default
  `'Active'` is normalized → no 422. Output emits new values regardless.
- **Deploy ordering**: enum code + migration + frontend ship together; migration runs before
  serving new code (standard Laravel deploy). ERP import literal updated in same deploy.
- **Fresh `migrate:fresh`**: old seeder-migration inserts `'Active'`, new migration converts to
  `'enrolled'` (timestamp order) — verified safe.
- **Historical audit logs** keep old values in `before/after_state`; do NOT migrate `audit_logs`.
- **Shim removal (tracked follow-up)**: once frontend migration confirmed + cache flushed,
  remove legacy values from validation `in:` lists + the normalizer, enable the `legacy-rejected`
  test assertion. This is task 8 of the shim (distinct from frontend task 8).
- **PHP runs in Docker** via `docker exec atms-api php ...` (no local `php` on PATH).

## Out of scope (separate tickets)

- `CreateAsset::execute()` drops `maintenance_status`/`maintenance_sub_status`/`asset_kind` on
  create despite being validated + authorized (latent bug).
- `ApplyWorkOrderAssetStatusTransition` action now exists (WO status path refactored) —
  informational only; does not affect this rename.
- `erp_status` raw varchar (intentionally no enum; ERP-controlled).
- The `maintenance_status` server-side filter (currently a no-op in `AssetIndexQuery`).
- Backend API versioning / permanent dual-value emission.
