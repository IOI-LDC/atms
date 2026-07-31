# Admin → Lists & Dropdowns Cleanup

Make the Admin "Lists & Dropdowns" tab hold only genuinely-configurable, **live** dropdown values. Today 6 of 8 groups are decorative no-ops: backed by Enums / hardcoded, the `master_data_items` table is empty (0 rows), and nothing reads it at runtime — every status/priority dropdown is hardcoded. **Priority specifically is hardcoded in 4 separate spots** (MR create, MR edit, MR filter, WO filter) plus a backend `in:` rule, while the Admin "Maintenance Priorities" group shows nothing and edits nothing.

---

## Decisions (resolved)

1. **Remove** 5 groups from `LIST_GROUPS` — Enum-backed state machines or no backing concept:
   - `work_order_statuses` (`WorkOrderStatus` — drives WO state machine: `WorkOrderPolicy:51,60`, `OpenWorkOrdersQuery:20`)
   - `asset_statuses` (`OperationalStatus` — drives MR/PM workflows)
   - `asset_maintenance_sub_statuses` (`MaintenanceSubStatus` — drives assembly/disposition; conditional in `AssetDetailView:72`)
   - `asset_categories` (`Asset.category` column is dead; real classification = `fa_subclass_code`)
   - `maintenance_categories` (no backing field/concept; MR `type` is derived from `is_preventive`)
2. **Keep (live):** `usage_reading_types`, `fa_subclass_type_codes`.
3. **Make real:** `maintenance_priorities` (`MaintenanceRequest.priority` is a free string, no Enum, not cast — safe + correct to make configurable).
4. **Fix drift:** Asset edit form + Assets list filter read the **live** FA-subclass list, not the hardcoded 18-item `FA_SUBCLASS_OPTIONS` (`assetColumns.ts`, DB has 20).
5. **New public read path** for consumers: all `/admin/*` reads are Admin-gated (`MasterDataItemPolicy::manage` = Administrator only), but priorities (MR create = everyone) and the FA-subclass filter (Assets list = all roles) must be readable by non-Admins.
6. **Backend dynamic validation:** `MaintenanceRequestController.php:44,66` hardcode `in:low,medium,high,critical`. Replace with validation against the active configured `maintenance_priorities` values — otherwise an Admin adding a new priority is rejected on MR create/edit. Also review `CreateCorrectiveMaintenanceRequest.php:22` default (`'medium'`) so a deactivated default doesn't brick MR creation (default should resolve to the first active priority).
7. **Docs:** `SCREEN_INVENTORY.md §7b` and `ROUTES.md §Admin` are **already updated** (they reference this plan file) — no action. Several other docs still describe the old hardcoded state and must be corrected (see Documentation tasks).

> Enum statuses are **system constants**, not configurable vocab. Free-text CRUD there would break the WO/asset state machines — explicitly rejected.

---

## Affected boundaries

- **Backend:** new `ListOptionController` + route; seed migration for priorities; dynamic priority validation in `MaintenanceRequestController`.
- **Frontend:** `useLists.ts` trim; new `useListOptions` composable; wire 4 priority consumers + 2 FA-subclass consumers; drop hardcoded `FA_SUBCLASS_OPTIONS`; review `Priority` TS type.
- **Docs:** TDL + PHASE_1_GAP_ANALYSIS + STATUS_MODEL + NAVIGATION + IN_SCOPE + IMPLEMENTATION_PLAN + BACKEND_API_REFERENCE + BACKEND_API_HANDOFF.

---

## Data flow

- **Write:** Admin → existing `POST/PATCH /admin/master-data/maintenance_priorities` (generic master-data CRUD).
- **Read (consumers, all roles):** `GET /api/list-options/{group}` → active-only rows.
- **Read (admin tab):** existing `GET /admin/master-data/{groupKey}` (Admin only).

---

## Tasks

### Backend

1. **`ListOptionController`** (new, `app/Http/Controllers/ListOptionController.php`):
   - `index(string $group): JsonResponse` — authenticated, **not** admin-gated. Dispatch by group, return **active-only** rows in each model's natural shape:
     - `maintenance_priorities` → `MasterDataItem::where('group_key','maintenance_priorities')->where('is_active', true)->orderBy('sort_order')` → `{id, value, label, sort_order}`
     - `usage_reading_types` → `UsageReadingType::where('is_active', true)` → `{id, name, unit}`
     - `fa_subclass_type_codes` → `FaSubclassTypeCode::orderBy('fa_subclass_code')` → `{fa_subclass_code, type_code, description, has_no_physical_size}` (no `is_active` column — return all)
   - Unknown group → 404.
2. **Route:** `Route::get('/list-options/{group}', [ListOptionController::class, 'index']);` inside the `auth:sanctum` group, **outside** the `admin` prefix (`routes/api.php`).
3. **Seed migration** (`database/migrations/YYYY_MM_DD_HHMMSS_seed_maintenance_priorities.php`, follow the existing SQL `seed_baseline_real_data` pattern): insert 4 rows into `master_data_items` with `group_key='maintenance_priorities'`:
   - `(value='low', label='Low', sort_order=0)`, `(value='medium', label='Medium', sort_order=1)`, `(value='high', label='High', sort_order=2)`, `(value='critical', label='Critical', sort_order=3)`, all `is_active=true`. **Values MUST equal existing MR string values** so current records stay consistent.
4. **Dynamic priority validation:** in `MaintenanceRequestController.php` (store L66, update L44) replace `'priority' => ['…', 'in:low,medium,high,critical']` with a rule that accepts any value present in active `maintenance_priorities` master-data (e.g. `Rule::in($activePriorityValues)` resolved from the DB). In `CreateCorrectiveMaintenanceRequest.php:22`, change the default from `'medium'` to resolving the first active priority (so deactivating `medium` can't brick creation).

### Frontend

5. **`composables/useLists.ts`:** trim `LIST_GROUPS` to `maintenance_priorities` (Master Data), `usage_reading_types` (Reading Types), `fa_subclass_type_codes` (ERP Reference). `LIST_SECTIONS` unchanged.
6. **`composables/useListOptions.ts`** (new): fetch + cache `GET /api/list-options/{group}`; expose `loadPriorities()`, `loadReadingTypes()`, `loadFaSubclasses()` returning typed arrays. On fetch failure, fall back to a `DEFAULT_PRIORITIES` constant so MR create never breaks.
7. **Priority consumers → dynamic:**
   - `views/work-orders/WorkOrdersView.vue` (MR create `createPriority` select, ~L182): `v-for` over fetched priorities; default to first active.
   - `views/work-orders/MaintenanceRequestDetailView.vue` (MR edit `draft.priority` select, ~L134): same.
   - `lib/mrColumns.ts` (L44) + `lib/woColumns.ts` (L35): remove static `priority` arrays; each view builds a **computed** `filterOptions` merging fetched priorities, passed to `AppDataTable :filter-options`.
8. **FA-subclass drift fix:**
   - `views/assets/AssetDetailView.vue` (edit form, ~L540): replace `FA_SUBCLASS_OPTIONS` with the fetched live list.
   - `lib/assetColumns.ts`: make `assetFilterOptions.fa_subclass_code` resolvable from the fetched list (view computes merged `assetFilterOptions`); remove the stale hardcoded 18-item `FA_SUBCLASS_OPTIONS` as source of truth.
9. **`types/index.ts`:** review `Priority` — if it's a fixed literal union, relax to `string` (values are now admin-defined) or keep union in sync with the seeded set.

### Documentation (implementing agent — planner does not edit non-plan files)

10. **`docs/05-delivery/TDL.md`** — add a row under "Phase 1 Code Gaps — Internal — Frontend Stubs & Defects": "Lists & Dropdowns — 6/8 groups decorative no-ops; priority hardcoded in 4 spots (MR create/edit, MR/WO filters) + backend `in:` rule; `master_data_items` empty & unread by app." Severity 🔴 High. Add a "Doc Correction" row noting the STATUS_MODEL contradiction is resolved.
11. **`docs/PHASE_1_GAP_ANALYSIS.md`** (§4) — corresponding gap entry (TDL points here).
12. **`docs/03-backend/STATUS_MODEL.md`** (L90) — fix the contradiction: "Asset Operational Statuses should be configurable as master data" → these are Enum-backed state machines (`OperationalStatus`), **not** configurable master data.
13. **`docs/atms/02-design/NAVIGATION.md`** (L162-165) — correct the lists description to match §7b (configurable vocab = priorities + reading types + FA subclass; no statuses, no locations).
14. **`docs/atms/01-product/IN_SCOPE.md`** (L185-186) — same correction.
15. **`docs/05-delivery/IMPLEMENTATION_PLAN.md`** (L30) — remove "Asset Maintenance sub-statuses" from the master-data list.
16. **`docs/atms/04-technical/BACKEND_API_REFERENCE.md`** — priority validation rules → note values are now the active `maintenance_priorities` set (L761, L787, L876, L912); refine the master-data blurb (L1883); add a section for the new `GET /api/list-options/{group}` endpoint.
17. **`docs/atms/04-technical/BACKEND_API_HANDOFF.md`** — note `Priority` is a dynamic string (not a fixed union); add a `ListOption` TS shape + the `/api/list-options/{group}` consumer call.
18. *(Already done — verify only)* `docs/atms/02-design/SCREEN_INVENTORY.md §7b` and `docs/atms/04-frontend/ROUTES.md §Admin` already reflect this plan.

---

## Failure modes & edge cases

- **Priorities fetch failure:** `useListOptions` falls back to `DEFAULT_PRIORITIES` (low/medium/high/critical) — MR create/edit + filters still work.
- **Admin deactivates the default priority:** backend default resolves to first active priority (task 4); existing MRs keep their value (renders via `priorityLabel` raw fallback); create/filter drop it. Matches the existing toggle dialog copy ("remain on existing records").
- **Admin adds a new priority:** backend dynamic validation (task 4) now accepts it; consumers pick it up on next fetch.
- **Non-admin access:** consumer endpoint is outside `admin` prefix, auth-only — Requester/Technician can read.
- **Seed parity:** seeded `value`s equal existing string values → no orphaned MR priority values.

---

## Validation

- **Backend tests:** new feature test for `ListOptionController` — active-only response; accessible by Requester & Technician; `401` unauthenticated; unknown group → `404`. Dynamic validation test: configured value accepted, unknown value rejected (422), deactivated value rejected. Regression: Admin CRUD for `maintenance_priorities` still works (existing generic endpoints).
- **Run Pint:** `vendor/bin/pint --dirty --format agent` after PHP edits.
- **Frontend:** `npm run build` (vue-tsc typecheck) passes; manual: MR create shows dynamic priorities; after Admin edits a priority, MR/WO filters + MR create reflect the change; Assets list FA-subclass filter shows the full live set for a **non-Admin** role.
- **Run affected backend tests:** `php artisan test --compact --filter=ListOption` and the MR validation tests.

---

## Out of scope

- Location Type & PM Level made dynamic (deferred).
- Making Enum statuses configurable (architecturally rejected — would break state machines).
- **Optional low-risk follow-up:** migrate the existing reading-type consumer reads (`useWorkOrderDetail.ts:556`, `usePmRules.ts:64`) from the admin-gated `/admin/usage-reading-types` to the new public `GET /api/list-options/usage_reading_types` — fixes a latent non-Admin (Technician record-reading) access gap. Not required for this plan.
