# Plan (FINAL): Plan 3 — remove the asset-status shims

> **Dependencies:** Plan 1 (backend `MaintenanceStatus` rename + shim) AND Plan 2 (sub-status
> normalization + frontend + sub-status shim) must both be deployed, AND a fixed observation window
> must elapse. Plan 1: `1782944404943-rename-asset-status-enums.md`. Plan 2:
> `1782944404944-frontend-coordinated-status-normalization.md`.

## Goal

Remove the temporary input-normalization shims (maintenance_status from Plan 1; sub-status from
Plan 2) so the API accepts only the canonical snake_case values. Tightens the contract and deletes
the temporary code. **No data migration** — purely code + tests.

## Trigger

Execute **14 days after Plan 2 deploys** (adjustable to your known session patterns).

Safety basis: `maintenance_status`/`maintenance_sub_status` are **lifecycle fields gated to
Admin/Manager only** (`AssetController` 403s other roles), so the only sessions that can send these
values are Admin/Manager — a small, known population. After ~14 days, no pre-Plan-2 open tab is
realistically still active. If a straggler 422s, it **self-heals on refresh** and the commit reverts
trivially. No telemetry required.

## Backend tasks (no migration)

1. `app/Http/Controllers/AssetController.php` (store + update) — tighten validation:
   - `maintenance_status` → `in:enrolled,withdrawn` (drop `Active,Inactive`)
   - `maintenance_sub_status` → `in:installed,ready,lih,dbr,disposed,scrapped,other` (drop PascalCase)
2. Delete the temporary normalizer (the `App\Support\Legacy*Normalizer` class/helper or static enum
   helper introduced in Plans 1 & 2) and **all its call sites** in `AssetController`.
   - The class is `App\Support\LegacyAssetStatusNormalizer` (renamed in Plan 2 per Flag A; holds
     both `normalize` and `normalizeSubStatus`). Delete the whole file + remove the `use` import
     and both call sites in `AssetController` (store + update).
 3. Remove every `TEMPORARY` marker / removal-TODO left by Plans 1 & 2.

## Tests

4. Un-skip the `legacy→422` assertions for BOTH fields (the stubs Plans 1 & 2 left commented/skipped):
   - PATCH legacy `maintenance_status` (`'Active'`/`'Inactive'`) → **422**
   - PATCH legacy `maintenance_sub_status` (e.g. `'Disposed'`) → **422**
   These now pass because the shims are gone.

## Validation

1. Targeted: `docker exec atms-api php artisan test --compact tests/Feature/MaintenanceStatus`
2. Full suite: `docker exec atms-api php artisan test --compact`
3. Pint: `docker exec atms-api php vendor/bin/pint --dirty --format agent`
4. Boost: `route:list --path=assets` (unchanged); `database-query` (no data change expected).

## Rollout & risk

- Standard code deploy; **fully revertible via git** (no migration to roll back).
- If 422s surface from straggler sessions, users refresh and resolve; revert the commit if widespread.
- Historical audit logs unaffected (shims never touched stored values — only input normalization).

## Out of scope

- Any data migration (none needed — data was normalized in Plans 1 & 2).
- Server-side `maintenance_status` filtering in `AssetIndexQuery` (still a separate ticket).
- `CreateAsset::execute()` field-drop bug; `erp_status`; `OperationalStatus`/`is_active`.
