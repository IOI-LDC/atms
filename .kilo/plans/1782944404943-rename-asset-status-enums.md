# Plan (FINAL): Backend — rename MaintenanceStatus values (+ temporary input shim)

> Scope is BACKEND-ONLY. `MaintenanceSubStatus` normalization and all frontend value
> updates are deferred to the **frontend-coordinated plan (Plan 2)**. Removal of this
> shim is deferred to **Plan 3** (`1782944404945-plan3-remove-status-shims.md`) — NOT Plan 2.

## Goal

Eliminate the `active`/`Active` collision by renaming `MaintenanceStatus` values to
snake_case (`Active`/`Inactive` → `enrolled`/`withdrawn`). Backend ships independently.
A **temporary input shim** keeps the SPA's legacy write-path (`maintenance_status:'Active'`
on PATCH) working until Plan 2 lands and (later) Plan 3 removes the shim.

## Verified context

- 429 assets; 426 have `operational_status='active'` AND `maintenance_status='Active'`.
  `maintenance_status`: 429 `Active`, **0 `Inactive`**.
- `api_clients` empty → no external API clients; the SPA is the sole consumer.
- SPA submits `maintenance_status` on PATCH (edit draft defaults to `'Active'`,
  `useAssetDetail.ts:83`) → without a shim, the strict backend would **422 every edit save**.
- 5 gate consumers (behavior preserved): `CreateCorrectiveMaintenanceRequest`,
  `ApproveMaintenanceRequestAndCreateWorkOrder`, `AssignWorkOrder`, `EvaluatePmRulesJob`,
  `Asset::booted`.

## Decisions

1. Rename `MaintenanceStatus` values + case names (`ACTIVE`→`ENROLLED`, `INACTIVE`→`WITHDRAWN`).
   `MaintenanceSubStatus` and `OperationalStatus` untouched.
2. Backend-only; deploys independently. Shim makes it non-breaking for the SPA write path.
3. Shim = expand-contract on **input only**: validation accepts legacy + new; a normalizer maps
   legacy→new **before model assignment** (so `Asset::booted`'s booking-clear on `WITHDRAWN` fires).
   Output emits only new values.
4. Shim removal is deferred to **Plan 3** (`1782944404945-plan3-remove-status-shims.md`, a
   fixed-window removal), NOT Plan 2 (the frontend-coordinated plan keeps this shim active).
   Trigger: 14 days after Plan 2 deploys.
5. Migration uses explicit `ALTER TABLE ... SET DEFAULT` (no `->change()`).
6. Deployed migrations/seeder-migration are never edited; the new migration converts their output.

### Risk flagged (reviewer's choice accepted)
The shim keeps `'Active'`/`'Inactive'` alive in the API **input** contract during the window,
creating a temporary asymmetric contract (input accepts old+new, output emits new). If Plan 3
slips, the shim risks becoming permanent debt and can perpetuate the collision.
Mitigation: removal is an explicit, tracked dependency of **Plan 3** + a `legacy→422`
test stub to enable on removal.

## Enum mapping

- `MaintenanceStatus`: `ACTIVE='Active'` → `ENROLLED='enrolled'`; `INACTIVE='Inactive'` → `WITHDRAWN='withdrawn'`

## Backend tasks

1. `app/Enums/MaintenanceStatus.php` — rename both cases (names + values).
2. `app/Models/Asset.php` — `booted()` (L69): `MaintenanceStatus::INACTIVE` → `::WITHDRAWN`.
   (`$fillable` L31, casts L54 unchanged — class name only.)
3. Gate constant sites — swap `::ACTIVE`→`::ENROLLED`, `::INACTIVE`→`::WITHDRAWN`:
   - `app/Actions/MaintenanceRequests/CreateCorrectiveMaintenanceRequest.php` (L26)
   - `app/Actions/MaintenanceRequests/ApproveMaintenanceRequestAndCreateWorkOrder.php` (L25)
   - `app/Actions/WorkOrders/AssignWorkOrder.php` (L20)
   - `app/Jobs/EvaluatePmRulesJob.php` (L33)
4. `app/Console/Commands/ImportErpAssetsCommand.php` (L157) — `'Active'` → `'enrolled'`.
5. **Temporary shim in `AssetController`** (store + update):
   - validation: `maintenance_status` → `in:enrolled,withdrawn,Active,Inactive`
   - normalizer maps legacy→new **before** passing to the action/model (so `booted` fires).
     Create a dedicated temporary class `App\Support\LegacyAssetStatusNormalizer` with a static
     `normalize(string): string` method. (Flag A: named generally on purpose — Plan 2 will add a
     `normalizeSubStatus(...)` method to the SAME class so both shims live in one file for a
     single-file removal in Plan 3.)
   - Class/docblock must say: "TEMPORARY — remove in **Plan 3**
     (`1782944404945-plan3-remove-status-shims.md`)", NOT "the follow-up frontend-coordinated plan"
     (Plan 2 keeps the shim active). (Flag B.)
   - Mark every shim piece **TEMPORARY** with a removal TODO tied to **Plan 3**.
   - ⚠️ If Plan 1 was already implemented with the name `LegacyMaintenanceStatusNormalizer` and a
     docblock reading "REMOVE with follow-up frontend plan", **rename the class to
     `LegacyAssetStatusNormalizer` and fix the docblock to reference Plan 3** as part of Plan 2's
     backend work (see Plan 2 task 2).
6. New migration `database/migrations/2026_07_02_xxxxxx_rename_maintenance_status_values.php`:
   - `UPDATE assets SET maintenance_status='enrolled' WHERE maintenance_status='Active'`
   - `UPDATE assets SET maintenance_status='withdrawn' WHERE maintenance_status='Inactive'`
   - `DB::statement("ALTER TABLE assets ALTER COLUMN maintenance_status SET DEFAULT 'enrolled'")`
   - keep `maintenance_status_index` (no action)
   - `down()`: reverse both UPDATEs + `SET DEFAULT 'Active'`
   - run: `docker exec atms-api php artisan migrate --no-interaction`

## Tests

- `tests/Feature/MaintenanceStatus/MaintenanceStatusGuardTest.php` — update constants/strings to
  `enrolled`/`withdrawn`; **ADD (shim)** PATCH legacy `'Active'`→200 persisted `enrolled`,
  legacy `'Inactive'`→200 persisted `withdrawn`; ADD a skipped/commented `legacy→422` assertion
  for post-shim removal.
- `tests/Feature/Assets/AssetBookingTest.php` — update to `withdrawn`; **ADD** legacy `'Inactive'`
  PATCH clears booking (shim behavior).
- `tests/Feature/ReadModels/AssetResourceTest.php` — assert default serialized
  `maintenance_status === 'enrolled'`.

## Validation

1. Targeted: `docker exec atms-api php artisan test --compact tests/Feature/MaintenanceStatus tests/Feature/Assets/AssetBookingTest.php tests/Feature/ReadModels/AssetResourceTest.php`
2. Regression: `docker exec atms-api php artisan test --compact tests/Feature/Assets tests/Feature/Pm tests/Feature/WorkOrders`
3. Full suite: `docker exec atms-api php artisan test --compact`
4. Pint: `docker exec atms-api php vendor/bin/pint --dirty --format agent`
5. Boost: `database-schema` (default changed); `database-query`
   `SELECT maintenance_status, COUNT(*) FROM assets GROUP BY 1` (expect only `enrolled`);
   `route:list --path=assets` (unchanged).

## Rollout & risk

- Backend deploys independently; shim prevents SPA write-path 422.
- Output change side-effects until Plan 2 lands: SPA shows literal
  `enrolled`/`withdrawn` and the maintenance-status badge loses its color class —
  **non-breaking, cosmetic, self-heals** once the frontend updates.
- Deploy ordering: enum code + migration ship together; migration runs before serving new code.
  ERP import literal updated in the same deploy.
- Fresh `migrate:fresh`: old seeder-migration inserts `'Active'`, new migration converts to
  `'enrolled'` (timestamp order) — verified safe.
- Historical audit logs keep old values in `before/after_state`; do NOT migrate `audit_logs`.
- **Shim-removal → Plan 3** (`1782944404945-plan3-remove-status-shims.md`): remove legacy values
  from validation + the normalizer; enable the `legacy→422` test assertion. (NOT Plan 2.)
- PHP runs in Docker via `docker exec atms-api php ...` (no local `php` on PATH).

## Deferred to other plans (NOT this plan)

- **Plan 2** (frontend-coordinated, `1782944404944-frontend-coordinated-status-normalization.md`):
  frontend value updates (`types/index.ts`, `useAssetDetail.ts`, `AssetDetailView.vue`,
  `displayHelpers.ts`, `assetColumns.ts`) + `MaintenanceSubStatus` normalization (backend enum +
  migration + frontend). Keeps this maintenance_status shim active.
- **Plan 3** (shim removal, `1782944404945-plan3-remove-status-shims.md`): removal of the
  `MaintenanceStatus` (and sub-status) shim, 14 days after Plan 2 deploys.

## Out of scope (separate tickets)

- `CreateAsset::execute()` drops lifecycle fields on create (latent bug).
- `ApplyWorkOrderAssetStatusTransition` (informational; does not affect this rename).
- `erp_status` raw varchar (intentionally no enum).
- The `maintenance_status` server-side filter (currently a no-op in `AssetIndexQuery`).
