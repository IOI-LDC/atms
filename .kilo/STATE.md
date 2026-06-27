# Session State — 2026-06-27

> **For AI agents:** Read this at the start of every session. It tells you what
> was done, what is decided, what is blocked, and what to tackle next.

## Last Session Accomplished

- **Asset Booking feature — COMPLETE (2026-06-27):**
  - New `is_booked` boolean on `assets` table (default `false`). Availability marker for Operations to reserve an asset for a specific Job/Project. Auto-releases on location change or asset deactivation/inactivation. Does NOT gate MR/WO/PM.
  - `ToggleAssetBooking` action with audit log (`asset.booked` / `asset.unbooked`), idempotency guards, inactive-asset block. `AssetBookingController` (`POST /assets/{id}/book`, `POST /assets/{id}/unbook`).
  - `AssetPolicy::toggleBooking()` — Admin, Manager, Logistics.
  - Auto-clear on location change: `UpdateAssetLocation::execute()` sets `is_booked = false`.
  - Auto-clear on inactivation: `Asset::updating()` listener clears `is_booked` when `is_active → false` or `maintenance_status → Inactive`.
  - MOC doc: `docs/atms/01-product/ASSET_BOOKING.md` (implemented, with rationale). Docs synced: STATUS_MODEL, RBAC, ROLES_AND_PERMISSIONS, ASSET_STATUS, BACKEND_API_REFERENCE (new Asset Booking section + `is_booked` in AssetResource table).
  - **Test suite: 372 passed (951 assertions).** 14 new tests (`AssetBookingTest`).
  - Commits: `673ca87` (booking + docs).

- **P0 — Employee Directory from CSV (no DB import) — COMPLETE (2026-06-27):**
  - `CsvEmployeeDirectorySource` reads `employee.csv` (94 employees) in-memory — zero DB rows until provisioning.
  - `GET /api/admin/employees` now reads from CSV source, with search, sort, pagination, and `emp_ids` filter.
  - Config-based whitelist via `EMPLOYEE_VISIBLE_EMP_IDS=45,6,18,29,23,60,37,3,9` — only those 9 appear by default.
  - `POST /api/admin/employees/provision-user` changed: accepts `{emp_id, role_id}` in body (no route-model binding). Flow: find in CSV → upsert single Employee record → provision User → queue activation notification.
  - Provisioning emails: backend only (`UserActivationNotification` → `AccountEmailChannel` → `AccountEmailTransport`). Currently `fake` transport (in-memory capture). Real transport is `PowerAutomateAccountEmailTransport` (Entra ID OAuth → MS Graph).
  - Dirty users (demo accounts) deleted — only `system@atms.internal` and `admin@atms.local` remain.
  - **Test suite: 358 passed (921 assertions).** 7 new tests (EmployeeIndexTest, emp_ids filter tests, updated EmployeeProvisioningTest).
  - Commits: `56ff747` (P0 employee CSV + frontend provision fix).

- **PM Rules 1:1 → M:N refactor (backend) — COMPLETE (2026-06-26):**
  - `PmRule` is now a reusable schedule **template** (no `asset_id`); new `asset_pm_assignments` pivot (`AssetPmAssignment`) carries each asset's own `last_triggered_date`/`_reading`/`is_active`.
  - Two-layer RBAC: template lifecycle (create/edit/deactivate/reactivate) Admin-only (`PmRulePolicy`); assignment assign/evaluate/deactivate/reactivate Admin **+ Manager** (`AssetPmAssignmentPolicy`). `SERVICE` retained for view endpoints.
  - `PmDueCalculator`, `EvaluatePmRule`, `EvaluatePmRulesJob`, `OverduePmQuery`, `CloseWorkOrder` operate on assignments and double-gate on **both** assignment and template `is_active` (a retired template stops all PM work without cascade-deactivating assignments).
  - New routes: `/assets/{asset}/pm-assignments/*` (scoped binding), `/pm-rules/{rule}/assignments` (coverage), `/pm-rules/evaluate-all` (structured `{evaluated, generated}`); removed `/pm-rules/{rule}/evaluate` and `/pm-rules/evaluate`. Dashboard key `overdue_pm_rules` → `overdue_pm_assignments`.
  - Assignment deactivation clears still-effective suppression windows (date+reading → null) so reactivation isn't blocked by pre-deactivation windows (deviation from plan §7.8: null instead of `now()`, since the calculator uses `>= today`).
  - WO closure resets the originating assignment's baselines + lower-level sibling assignments on the same asset (cumulative reset).
  - **Test suite: 351 passed (891 assertions)** (was 327; 4 rewritten suites + 3 new test files).
  - Docs synced: `CLAUDE.md`, `RBAC`, `SCOPE_CHANGE`, `BACKEND_API_REFERENCE`/`HANDOFF`, `JOBS_AND_SCHEDULER`, `ROLES_AND_PERMISSIONS`, `WORKFLOWS`, `SCREEN_INVENTORY`, `NAVIGATION`, `ROUTES`. Plan: `.kilo/plans/1782413031648-pm-rules-mn-refactor.md`.
  - **Note:** frontend (views/composable/Asset Detail PM section) was implemented in the same session but is now out of the agent's scope — review/integration of frontend is the user's / frontend team's call.

- **Locations Sidebar Backend Dependency — COMPLETE (2026-06-25):**
  - Implemented `GET /api/locations` read-only endpoint: active locations only (`is_active = true`), sorted by name, authorized for Admin/Manager/Logistics.
  - `LocationPolicy::viewAny()`, `LocationController@index`, route `GET /api/locations` (outside admin prefix — distinct from `GET /api/admin/locations` which is Admin-only/all-status).
  - 5 feature tests in `tests/Feature/Locations/ListActiveLocationsTest.php` (role auth 200/403/401, active-only filter, name sort, response shape).
  - Docs synced: `LOCATION_SIDEBAR_CHANGE.md` (status → resolved, added Backend Implementation section), `BACKEND_API_REFERENCE.md` (removed ⚠️ warning).
  - **Test suite: 316 passed (808 assertions)** — no regressions.

- **Phase 1 Backend Cleanup & ATMS Core Features — COMPLETE:**
  - Purged `SyncErpAssetsJob`, `erp_asset_id` column, `MockErpHttpSource`, Viewer role
  - Asset registry with tags (`L-BBB-CCC-XXXX`) + `AssetTagService` generation algorithm
  - Maintenance status (`Active`/`Inactive`) gating MR creation, WO assignment, PM evaluation
  - API bearer tokens (M2M auth via `TokenController` + `EnsureTokenAbilities` middleware)
  - Real ERP adapter (`LdcErpHttpSource`) with Entra ID OAuth, dynamic token TTL
  - 5(+1) role RBAC: Admin, Manager, Tech, Logistics, Requester + SERVICE (non-user, M2M only)
  - `FaSubclassTypeCode` admin CRUD for asset tag type-code lookup
  - QR-code asset lookup via `GET /api/assets/by-tag?tag=...`
  - PM suppression dual-boundary validation (date + reading)

- **Code review (2 rounds) — 13 findings → all resolved:**
  - 3 critical: missing Collection import, zero test coverage, stale .env.example
  - 4 medium: silent lifecycle drop → 403, null tag clearing, hardcoded TTL, tag collision race
  - 6 additional fixes found during test writing (see plan doc Post-Review Fixes section)

- **Test suite: 316 tests passing** (was 304 baseline; 5 new in `ListActiveLocationsTest` for `GET /api/locations`, plus location workflow tests)

- **Documentation updated:**
  - `.kilo/plans/1782388457617-phase1-backend-cleanup-and-features.md` — Post-Review Fixes appendix
  - `CLAUDE.md` — updated for Phase 1 complete, 6 roles, stale warnings removed
  - `docs/03-backend/ARCHITECTURE.md` — removed stale sync job/erp_asset_id notes
  - `backend/.env.example` — fixed `MOCK_ERP_*` → `LDC_ERP_*` variables

- **SPA auth "save → kicked to login" investigation (2026-06-26):**
  - Symptom: intermittent **401 on mutations** (MR/WO/PM/User) → redirect to `/login`.
  - Root cause: **SPA-side concurrency races**, NOT backend. `initCsrf()` (api.ts) wasn't single-flight → N parallel `/sanctum/csrf-cookie` calls racing each other's `Set-Cookie`; the router guard skipped an in-flight `fetchCurrentUser()` and redirected prematurely. Backend auth is correct and stable for sequential requests (verified via curl: login → GET×5 = all 200).
  - **Frontend team fixed it** (single-flight `initCsrf` + `fetchCurrentUser`, router guard). Backend **unchanged**; `SANCTUM_STATEFUL_DOMAINS=localhost`.
  - **⚠️ Do NOT re-add `:5173` to `SANCTUM_STATEFUL_DOMAINS`.** Tried it (made `localhost:5173` stateful) → exposed `AuthenticateSession`/session instability → *every* navigation 401'd. Reverted. That config is the wrong lever.
  - If 401s recur post-deploy → the backend lever is the **DB session driver concurrent-write (last-write-wins)**: `session.block` / `session.block_seconds` (tradeoff: serialized latency). Investigate then.
  - **Config gotcha:** `SANCTUM_STATEFUL_DOMAINS`/`SESSION_DOMAIN`/`APP_URL` are injected by `compose.yaml` from the **root `.env`** (`atms/.env`) — they **override** `backend/.env`. Edit the root `.env`, then `docker compose up -d api`.
  - **Operational:** `admin@atms.local` password was reset to `Password123!` during offline curl testing.
- **Git state (2026-06-27):** On `main`. Latest commits: `673ca87` (asset booking + docs), `56ff747` (P0 employee CSV + provision fix). Both pushed to working tree, not yet pushed to remote. `.env` is gitignored.

## Next Steps — Prioritized Execution Order (2026-06-27)

Ordered by value and unblocking. **B** = backend (this agent), **F** = frontend
(team), ⏳ = blocked on an external dependency.

### ~~P0 — Employee → System User provisioning~~ ✅ COMPLETE (2026-06-27)
- Backend done (CSV directory + provision by emp_id). Frontend UsersView +
  useUsers updated for new endpoint. 9 real employees whitelisted.
- **Remaining (F):** Frontend needs UI polish — booking book/unbook buttons on
  Asset Detail + Asset List (backend ready, frontend not wired). Also: provision
  UX to actually invoke provision-user on the 9 whitelisted employees once the
  client confirms role assignments per person.

### P1 — Work Order Assign 🔓
- **Goal:** assign a Technician to an open WO.
- **Backend — EXISTS:** `POST /work-orders/{wo}/assign` → `AssignWorkOrder`.
- **Needed:** **(F)** WO detail "Assign" action (technician picker). **(B)** verify
  policy + audit on the existing endpoint.

### P1 — Assign at MR → WO conversion 🔓
- **Goal:** when a Manager approves an MR (converting it to a WO), optionally
  assign the Technician right then instead of after.
- **Backend — TO BUILD (B):** extend `ApproveMaintenanceRequestAndCreateWorkOrder`
  to accept an optional `assigned_to_user_id` (+ assign timestamp) and persist it
  on the created WO. Add validation + policy check + tests.
- **Needed:** **(F)** "Assign" option in the MR review/approve flow, just before
  conversion.

### Asset Booking — Frontend wiring (F) 🔓
- Backend complete (`POST /assets/{id}/book` + `/unbook`, `is_booked` in
  AssetResource, auto-release on move/inactivation).
- **Needed (F):** Book/Unbook toggle UI on Asset Detail + "Booked" badge in
  Asset List. See `docs/atms/01-product/ASSET_BOOKING.md`.

### P2 — Parts catalogue from ERP ⏳ BLOCKED
- **Goal:** populate the parts list (SM-owned) from BC, the same way Assets are
  pulled.
- **Backend — EXISTS (pipeline):** `SyncErpPartsJob`, `LdcErpHttpSource`. Cannot
  run without the ERP parts endpoint.
- **Blocked on ERP team (TDL #1, #2, #8):**
  1. Parts / M&S / consumables **read URL** (OData page name).
  2. **Field mapping** (sample response rows).
  3. QTY-on-consumption write-back feasibility + handoff format.
- **Action:** chase VJ/ERP; once #1 + #2 land, wire `SyncErpPartsJob`, document
  the mapping, and the WO parts picker gets real data.

### Existing backlog (low urgency, no dependencies — slot in opportunistically)
- #6 Rename `frontend/` → `atms/` + update Docker/nginx (infra).
- #7 Create `sm/` and `am/` Vue 3 scaffolds (Phase 8/9).

### Suggested execution order
**~~P0 (employees/users)~~ ✅ → P1 (WO assign verify) → P1 (MR→WO assign build)
→ Asset Booking frontend → P2 (parts, when ERP replies).** #6 / #7 anytime.

---

## Phase 1 pending review
Phase 1 core is **COMPLETE** (see note below). The remaining "Backend Team
(future)" items (#6 rename, #7 scaffolds) are infra/Phase 8–9, not feature gaps.
The feature work above (P0–P2) is the real next iteration; P0 and P1 are
unblocked and mostly backend-complete (need the client CSV for P0 and small
backend additions for the MR-assign item). P2 is fully blocked on the ERP team.

---

## Key Decisions (do not reopen unless new information)

| Topic | Decision |
|---|---|
| Subsystem architecture | ATMS / SM / AM — one backend, one DB |
| RBAC roles | 5 human + 1 system: Admin, Manager, Tech, Logistics, Requester + **SERVICE** (non-user-assignable, M2M tokens only) |
| Service user | `service@atms.internal`, seeded, never logs in via SPA. Immutable. |
| Asset source | ATMS-managed only — no ERP asset sync |
| Parts ownership | SM — ERP syncs into SM. ATMS reads only. |
| Location ownership | AM — ATMS reads from AM tables only. |
| ERP auth | Entra ID OAuth2 `client_credentials`, `x-www-form-urlencoded` |
| ERP sync strategy | Full pull every time. No pagination. No incremental sync. |
| ERP field boundary | Sync writes ERP columns only. Local fields never touched. |
| Asset tag format | `L-BBB-CCC-XXXX` (final 2026-06-25) — 4 segments with dashes. Size code truncated to 3 chars rightmost. RTR/STR detected by description keyword. Immutable after create (Admin override with reason allowed, clearing forbidden). |
| Asset tag ownership codes | `L` = LDC (we maintain), `X` = External (we don't) |
| Asset maintenance status | `Active`/`Inactive` — gates MR/WO/PM workflows. Sub-statuses informational only. |
| Asset operational status | Separate axis from maintenance_status — informational only, no workflow gating. |
| Asset booking (`is_booked`) | Availability marker for Operations to reserve an asset for a Job/Project. Boolean, no job reference stored. Auto-releases on location change or deactivation/inactivation. Does NOT gate MR/WO/PM. Toggled by Admin/Manager/Logistics. (2026-06-27) |
| Employee directory source | CSV-backed (`CsvEmployeeDirectorySource`, `EMPLOYEE_CSV_PATH`), not DB import. `EMPLOYEE_VISIBLE_EMP_IDS` whitelist controls who appears in the list. Provisioning upserts a single Employee row to DB. (2026-06-27) |
| Migration strategy for erp_asset_id | Edit original migration (SQLite `:memory:` runs `migrate:fresh`). Production one-time `ALTER TABLE DROP COLUMN`. |
| Mock ERP | Fully deleted. `LdcErpHttpSource` skips sync gracefully when `LDC_ERP_PARTS_API` is empty. |
| API token abilities | Read-only (`['read']`) blocked on POST/PUT/PATCH/DELETE → 403. Write (`['read','write']`) allowed all. SPA session never blocked. |
| Git commit convention | When the user says "commit ALL" (capitalized), use `git add .` — stage everything including untracked files, then commit. |

## Pending — Blocked on ERP Team 🔴

| # | Item | Tracker |
|---|---|---|
| 1 | Parts API page name (BC custom API page) | `docs/05-delivery/TDL.md` |
| 2 | Parts field mapping (response schema) | `docs/05-delivery/TDL.md` |
| 3 | `componentOfMainAsset` sample with non-null parent | `docs/05-delivery/TDL.md` |
| 4 | **Store Order / Store Management in BC** — does it exist and is it used at LDC? Can we query store orders by number through OData? | VJ (ERP Consultant) |

## Pending — Backend Team (future)

| # | Item |
|---|---|
| 4 | ~~Remove 4 Mock ERP PHP files~~ ✅ Done (Phase 1) |
| 5 | ~~Sync `CLAUDE.md` with new docs structure~~ ✅ Done (Phase 1) |
| 6 | Rename `frontend/` → `atms/` + update Docker/nginx |
| 7 | Create `sm/` and `am/` Vue 3 scaffolds |

## Known Inconsistencies

- **`CLAUDE.md`** references old `frontend/` paths — stale but out of scope for Phase 1 backend cleanup. Frontend rename is deferred.

> ✅ **Phase 1 complete (2026-06-25)** — 8 tasks implemented, 304 tests passing, 2 rounds code review resolved, all documentation updated. See `.kilo/plans/1782388457617-phase1-backend-cleanup-and-features.md` for full execution log and post-review fixes.

## When Starting a New Session

1. Read this file first.
2. Check `.kilo/TLD.md` for active tasks, deferred items, and cross-team awareness.
3. Check `docs/05-delivery/TDL.md` for external blocker details.
4. The authoritative source-of-truth is `docs/00-project-rules/authoritative-sources.md`.
5. Key docs map:
   - ATMS product: `docs/atms/01-product/`
   - Backend: `docs/03-backend/`
   - Frontend: `docs/atms/04-frontend/`
   - API: `docs/atms/04-technical/`
   - ERP: `docs/03-backend/ERP_SYNC.md`
   - Assembly: `docs/atms/01-product/ASSET_ASSEMBLY.md`
   - Tags: `docs/atms/01-product/ASSET_TAG.md`
   - Phase 1 plan: `.kilo/plans/1782388457617-phase1-backend-cleanup-and-features.md`
6. ERP test: source `backend/.env`, then the curl commands commented in that file.

## Implementation Phases (2026-06-24)

### Phase 1 — ATMS Core ✅ COMPLETE (2026-06-25)
- Asset registry + tags + maintenance status
- Corrective + Preventive MR → WO workflow
- Parts catalogue (read-only from SM tables, ERP-synced)
- Simple asset location update by Logistics (no workflow)
- 5(+1)-role RBAC with SERVICE for M2M API tokens
- Dashboard, reporting, attachments
- API bearer tokens with ability-based access control
- Real ERP adapter (LdcErpHttpSource)

### Phase 2 — SM + AM + Assembly (future)
- Asset Assembly (parent/child, install/remove/swap)
- Component PM cross-check
- SM: Order → Approval → Dispatch → GR, inventory, Virtual Store
- AM: Movement request workflow with approval chain
- ERP parts write-back

### Deferred entirely from Phase 1
- Asset Assembly (parent_asset_id, assembly_history, component hours)
- Component PM cross-check indicators
- SM Order workflow, inventory, stock movement, Virtual Store
- AM movement approval workflow
- ERP parts write-back
- MinIO object storage

## Parts Table Decision (on hold — 2026-06-24)

`work_order_parts` (WO consumption log) is always needed, regardless of VJ's answer.
The parts catalogue source depends on VJ:

| VJ says | Parts list source | Local tables needed |
|---|---|---|
| BC Store Order live | BC OData query by store order ID | `work_order_parts` only (references BC part IDs) |
| BC Store Order NOT live | Need our own catalogue | `parts` table (ERP-synced) + `work_order_parts` (FK to `parts.id`) |

**Decision:** Defer `parts` table until VJ replies. Build `work_order_parts` in Phase 1,
with a placeholder parts picker using demo data if VJ hasn't replied by then.

## Open Follow-ups

- **Manager access to PM template viewing (decided, pending implementation):**
  Under the M:N model, **assignment** management (assign/evaluate/deactivate/
  reactivate a template on an asset) is reachable by a Maintenance Manager from
  the **Asset Detail** screen — so the Manager's `AssetPmAssignmentPolicy`
  permissions are no longer dormant. The remaining gap is **template viewing**:
  PM Rules (template management) lives under the Admin sidebar item
  (`visibleTo: isAdmin`), and a Manager — who holds `view`/`viewAny` via
  `PmRulePolicy` and passes the `requiresAdminOrManager` guard on
  `/admin/pm-rules` — has no UI path to view templates. Template creation is
  `POST /api/pm-rules` (Admin-only). **Agreed direction: grant the Manager full
  Admin-area access** (all three tabs). To implement: `AppSidebar.vue`
  `visibleTo`, the `requiresAdmin` guards on `/admin/lists` & `/admin/users` in
  `router/index.ts`, and confirm the Admin endpoints' policies match the intended
  scope. **Frontend work — out of the backend agent's scope.** Canonical note:
  `docs/03-backend/RBAC.md` (Known gap). Pointers in SCREEN_INVENTORY.md §7c and
  NAVIGATION.md §7.

