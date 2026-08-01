# Task List — Development Tracker

> **Purpose:** Single source of truth for what is being built, what was completed
> that the other team needs to know about, and what was deferred so it doesn't
> get lost.
>
> **Update this during or immediately after every build session.** It is short
> by design — if a task takes more than 2 minutes to write down, it is too big
> and should be split.

---

## 🔴 In Progress

<!-- What someone is actively coding right now. Max 2 items per team. -->

| Team | Task | Started |
|---|---|---|
| Full-stack | **Maintenance Category as the ATMS routing key — agreed 4-phase plan.** **P0** D-013 PM eval scaling fix (standalone) → **P1** `assets.maintenance_category_id` NOT NULL (backfill 2 of 400, migration, validation + import) → **P2** D-011 WO Forms → categories (incl. deleting the 4 `/admin/fa-subclass-type-codes` CRUD routes) → **P3** D-012 PM Rules → categories. Nothing started; schema work is blocked on committing the 16 uncommitted R-1/dashboard files first. | 2026-07-31 |
| Full-stack | **Reports — CSV export part-done (D-010).** Mechanism built (`CsvReportStreamer` + `ReportCsvColumns`, `?format=csv` on existing endpoints, UTF-8 BOM, Tripoli dates, true streaming) and wired to **3 of 21** reports: R-1, R-2, R-22. **Remaining 18:** 10 cursor reports each need a `stream` closure beside their paginator + a column map; 8 aggregate reports need only a column map. **No frontend Export button yet on any report.** Then R2/R3 once LDC defines them (🔵 #5). | 2026-08-01 |

---

## 🟡 Recently Completed — Frontend / Other Teams Need to Know

<!-- Backend finished something? Put it here so frontend knows the API changed.
     Frontend finished a screen? Put it here so others know the UX is done.
     Items stay here until the other team acknowledges. Then move to Done. -->

| Date | What changed | Who needs to know | Status |
|---|---|---|---|
| 2026-08-01 | **⚠️ BREAKING report contracts — FA Subclass removed from all reports.** Filter param `fa_subclass_code` (string) → **`maintenance_category_id`** (int) on 7 reports. `group_by=asset_class` now **422s** on MTBF/MTTR/Bad-Actor — the dimension is gone. Response fields renamed: `AssetStatusReportItem` **dropped** `fa_subclass_code`; `PartsConsumptionItem.asset_class` → **`asset_maintenance_category`**; `FormResultRow.asset.asset_class` → **`asset.maintenance_category`**. Rationale: FA Subclass is ERP-written, so it cannot drive an ATMS report. **Asset detail API is unaffected** — `AssetResource` still exposes `asset_class`/`fa_subclass_code`. | Frontend, Backend | ⚠️ Unacknowledged |
| 2026-08-01 | **R-2 generalised → Asset Distribution.** `GET /api/reports/asset-distribution?group_by=location\|maintenance_category\|size` (default `location`). ⚠️ **Row shape changed:** `location_id`/`location_name` → **`group_key`/`group_label`**, `summary.total_locations` → **`total_groups`**, plus new `group_by` in the payload. Old API path `/reports/assets-by-location` and SPA route `/reports/assets-by-location` both still resolve as aliases. Frontend files renamed to `useAssetDistributionReport.ts` / `AssetDistributionReport.vue`; **DashboardView consumes this report** and was migrated. | Frontend, Backend | ⚠️ Unacknowledged |
| 2026-08-01 | **R-22 Most-Used Assets BUILT.** New `GET /api/reports/asset-usage` + page at `/reports/asset-usage`. Ranks assets by accumulated usage against **one** reading type (Operating Hours / Kilometer Driven / Depth — units never mix; `reading_type.unit` travels with the payload). `group_by=asset\|maintenance_category\|size`, top-N `limit`, summary spans all assets not just shown rows. **Usage is a difference, not a sum** — meters are cumulative, so baseline is the last confirmed reading *before* the window (falling back to the window floor for newly-metered assets). Only confirmed readings count. | Frontend, Backend | ⚠️ Unacknowledged |
| 2026-08-01 | **CSV export live on 3 reports.** `?format=csv` on the **existing** report endpoint (no separate `/export` route), so filters/auth/sort are provably identical to the on-screen table. UTF-8 BOM for Excel + Arabic; timestamps in `Africa/Tripoli`; streamed, so an export is the whole result set not one page. Wired: `asset-status`, `asset-distribution`, `asset-usage`. **18 reports still JSON-only, and no Export button exists in the UI yet.** | Frontend, Backend | ⚠️ Unacknowledged |
| 2026-07-31 | **R-1 Assets Status Report BUILT + reports index renamed.** New `GET /api/reports/asset-status` (cursor-paginated, `{data, summary{total,by_status,booked}, meta}`) and page at `/reports/asset-status` — LDC's "Report 1", the only listing in an otherwise analysis-only catalogue. Filters: location, operational_status, asset_kind, booked, from/to + `date_field` (`updated_at` default, `created_at` optional). ⚠️ **Two pinned interpretations, stated in the UI and awaiting LDC confirmation:** the date range filters created/updated timestamps and returns *current* status (no status-history table exists — see D-009), and **"Assigned To" is the technician on the asset's open work order** (assets have no custodian column). **Routes renamed:** `/reports` → `ReportsView.vue` (final), `/reports-real` → `/reports-verification` + `ReportsVerificationView.vue` (admin-only, delete after sign-off). **Catalogue entries R-10B and R-11 removed** (withdrawn/disposed = ERP-owned); R-12 correctly remains deferred (Phase 2 assembly, not ERP). 946 tests pass, Pint + `vue-tsc` clean. **No export yet — D-010 is next.** | Frontend, Backend | ⚠️ Unacknowledged |
| 2026-07-31 | **Dashboard BUILT — `/dashboard` is final, and `GET /api/dashboard/kpis` gained two keys.** New `kpis.utilisation` (percentage, eligible, deployed_eligible, by_bucket{deployed,idle,maintenance}, unlocated, unclassified, booked, total) and `kpis.readiness` (total + pm_coverage/location_recorded/baseline_reading, each {covered, percentage}). `kpis.asset_health` additively gained `by_booking` — **additive only, nothing removed, no consumer breaks.** ⛔ **Withdrawal is ERP-owned and out of ATMS scope (user decision):** `maintenance_status = withdrawn` and all `maintenance_sub_status` values (disposed, scrapped, lost in hole, DBR, …) must never be surfaced, counted, or reported by ATMS. A `by_maintenance_status` key was built and then removed for this reason — do not re-add it. The `enrolled` filter remains an internal population guard only. New `App\Enums\AssetDeployment` is the **single source of truth for deployed-vs-idle** (rig+well_site = deployed; yard+building = idle; workshop = maintenance) — change it there and nowhere else if LDC redefines it. Components renamed: `/dashboard` → `DashboardView.vue` (final), `/dashboard-real` → `/dashboard-verification` + `DashboardVerificationView.vue` (admin-only, delete after sign-off). New `components/ui/segmented-bar` primitive. 933 tests pass, Pint clean, `vue-tsc` clean for all dashboard files. ⚠️ Closing column is **Recent asset moves**, not a full activity feed — that needs a new audit-log endpoint (not built). | Frontend, Backend | ⚠️ Unacknowledged |
| 2026-07-31 | **Frontend routes changed.** `/locations2` and `views/locations/LocationsView.vue` **deleted** (legacy tabbed Locations view; `/locations` → `LogisticsLocationView` is unaffected, `ManageLocationsView` still used). `/dashboard-real` and `/reports-real` now carry `meta: { requiresAdmin: true }` — they previously had **no guard**, so they shipped in the production bundle and any authenticated user could reach them by URL. Both are internal-verification only and must never reach the client product. ⚠️ **`views/locations/AssetLocationUpdateView.vue` is now orphaned** — decision needed (see 🟠 D-007). `vue-tsc` clean. | Frontend | ⚠️ Unacknowledged |
| 2026-07-31 | **`CLAUDE.md` rewritten; `backend/CLAUDE.md` + `frontend/CLAUDE.md` removed — one root file now.** Rebuilt from live code only. Also: **git history was reset by the user** — single `Initial commit`, nothing prior is recoverable, no diff baseline. Verified baseline on that commit: backend **911 passed (2666 assertions)**, frontend `vue-tsc --build` clean. | Backend, Frontend | ⚠️ Unacknowledged |
| 2026-07-11 | **Docs reconciliation (Phase 1).** Closed out G-03 (location picker) and G-11 (relocated-assets widget) across `PHASE_1_GAP_ANALYSIS.md`, `TDL.md`, `CLAUDE.md` — both shipped in `de85abe` but were never marked closed. No code change. | Backend, Frontend (informational) | ⚠️ Unacknowledged |
| 2026-07-05 | **Dropped `maintenance_requests.type` column — `is_preventive` is the single stored discriminator.** `type` is now **derived** in `MaintenanceRequestResource` / `MaintenanceHistoryResource` (`is_preventive ? 'preventive' : 'corrective'`). **API contract unchanged**: both `type` and `is_preventive` are still emitted; `?type=preventive\|corrective` list filter still works (translated server-side to `is_preventive`). No frontend action required. Frontend team had pre-confirmed they can drop `is_preventive` from the TS interface and key off `type === 'preventive'` — safe to do so now. Migration `2026_07_05_000000_drop_type_from_maintenance_requests_table` applied to live Postgres. Full suite 483 passed. | Frontend (informational — no action needed), Backend | ⚠️ Unacknowledged |
| 2026-06-28 | VPS Frontend Issue Tracker created (`docs/atms/04-frontend/VPS_FRONTEND_ISSUES.md`) — 8 issues logged (5 MR, 3 WO, 1 Asset). 4 frontend fixes verified on VPS, 3 need backend follow-up. | Frontend, Backend | ⚠️ Unacknowledged |
| 2026-07-25 | **Operational MR/WO email notifications built — supersedes the 2026-07-11 "activation + reset only" scope.** 8 workflow notifications dispatched from the owning Actions (MR submitted/approved/rejected; WO assigned/started/completed/closed/cancelled). `AccountEmailTransport::send()` signature **changed** to a single `array $message` (`{ to[], cc?[], subject, templateData }`) — breaking for any other transport implementation. New `AccountEmailNotification` trait carries the shared mailbox lock + retry policy. 16 notification + 97 MR/WO tests green, Pint clean. **Still `ACCOUNT_EMAIL_TRANSPORT=fake` everywhere — nothing is delivered.** Hardening pass (R-007) done the same session: BCC default removed; new `FRONTEND_URL` + `App\Support\FrontendUrl` for all 10 email link sites (incl. activation/reset, which had the same wrong-host bug); all notifications moved to `ShouldQueueAfterCommit`. **Cc routing decision revised — the 2026-07-04 plan is withdrawn and the as-built routing accepted.** PM evaluation stays silent by decision. Full suite 679 passed. Docs updated: `PRODUCT.md` §Notifications, `ENGINEERING.md`, `OPERATIONS.md` §Email delivery, `ROADMAP.md`, `IMPLEMENTATION_HISTORY.md`, `README.md`, `CLAUDE.md`. **Also fixed (R-008): the test suite had been running as `APP_ENV=local` on the `database` queue driver** — `phpunit.xml` now needs a forced `<env>` *and* a `<server>` twin per value, because Laravel's `Env` reads `$_SERVER` before `getenv()`. Queued jobs now actually execute in tests; suite unchanged at 679 passed. | Backend (contract change + test env), Frontend (informational) | ⚠️ Unacknowledged |
| 2026-07-11 | **Email transport decision confirmed: Microsoft Graph `sendMail` only; Power Automate retired.** Azure app provisioned + `Mail.Send` consented + probe passed (HTTP 202). Graph transport **built and committed `618a8fe`**. Scope limit to activation + password reset is **superseded by the 2026-07-25 row**. Pre-release still open: final frontend URL, Application Access Policy, real user emails, production credential, queue-worker verification. Design notes archived at `docs/_archive/2026-07-13/legacy/03-backend/NOTIFICATIONS.md`; current behaviour lives in `docs/PRODUCT.md` / `ENGINEERING.md` / `OPERATIONS.md`. | Backend | ✅ Superseded 2026-07-25 |
| 2026-07-11 | **Asset API location filter correction documented.** `GET /api/assets?location_id={id}` now maps to the persisted `current_location_id` column in `AssetIndexQuery`; regression tests cover selected-location filtering and requester active-asset scoping. Verification is pending delivery-team test run. | Backend | ⚠️ Unacknowledged |
| 2026-07-11 | **G-09 Effective Date UI mismatch closed.** Removed the disabled/non-submitted datetime control from `UpdateLocationSheet`; immediate Phase 1 moves continue to use backend `effective_at = now()`. Updated navigation, screen inventory, component, form-requirement, and gap-tracking docs. Frontend type-check and production build pass. | Frontend, Backend (informational) | ⚠️ Unacknowledged |
| 2026-07-04 | **Self-service password change — `POST /api/auth/change-password`** (committed `a03b078`). Authenticated, no current-password required, invalidates all sessions/tokens, audits `user.password_changed`. 7 tests; full suite 483 green. | Frontend, Backend | ✅ Acknowledged (FE UI shipped in `77d4e13`) |
| 2026-06-28 | WO assignable roles expanded — Admin/Manager can assign WO to Technician OR Maintenance Manager (small teams, overloaded tech). | Frontend (assign picker), Backend (policy) | ⚠️ Unacknowledged |
| 2026-06-24 | ERP connection confirmed (Entra ID → BC OData V4). Token + assets working. Env vars in `backend/.env`. | Frontend (API base URL pattern changed), SM team | ⚠️ Unacknowledged |
| 2026-06-24 | Asset Assembly model decided. 5 API endpoints defined: `install`, `remove`, `swap`, `assembly-history`, `children`. | Frontend (new routes), Backend (new actions) | ⚠️ Unacknowledged |
| 2026-06-24 | Asset tag format `L-BBB-CCC-XXXX` decided. New `asset_tag` column added to spec. | Frontend (create/edit forms, QR), Backend (migration, validation) | ⚠️ Unacknowledged |
| 2026-06-24 | Mock ERP deprecated. Real ERP auth + URL pattern documented in `ERP_SYNC.md`. | Backend (4 PHP files to clean up) | ⚠️ Unacknowledged |
| 2026-07-02 | Admin Lists & Dropdowns cleaned — removed 5 decorative/Enum-backed groups (`asset_categories`, `maintenance_categories`, `asset_statuses`, `asset_maintenance_sub_statuses`, `work_order_statuses`). Tab now 3 live groups: Maintenance Priorities (new dynamic CRUD), Usage Reading Types, FA Subclass Type Codes. Fixed priority hardcoding (was in 4 spots + backend `in:` rule). New public `GET /api/list-options/{group}` endpoint for non-Admin consumers. FA-subclass drift fixed (hardcoded 18 → DB 20). See `.kilo/plans/1783001396791-admin-lists-dropdowns-cleanup.md`. | Frontend (admin tab changed), Backend (new controller + seed migration) | ⚠️ Unacknowledged |
| 2026-07-02 | **Parts Management UI (G-02) — DONE.** `PartsView.vue` (table + category filter) + `PartDetailView.vue` (details, ERP reference rail for Admin/Manager, attachments upload/delete) replace the "coming soon" stubs. New `useParts`/`usePartDetail`/`usePartSearch` composables + `partColumns` + `PartCombobox`. `__mockParts.ts` removed; WO parts-used picker now reads live `GET /parts`. Backend `PartSeeder` (55 O&G parts across 11 categories) + seeder tests. Committed `56bd463`. See `.kilo/plans/1783038000000-parts-management-frontend.md`. | Frontend (parts views live), Backend (seeder) | ⚠️ Unacknowledged |
| 2026-07-03 | **Dashboard KPIs endpoint — `GET /api/dashboard/kpis`.** Rolling 90-day window; serves Row 2 (MTBF / MTTR / Failure Rate) + Row 3 (PM Compliance / Avg MR Duration / Avg WO Duration) + "Recently Relocated Assets" widget (latest 5). Full payload to **every authenticated role** (reuses `viewDashboard` gate). Row 1 counts stay on the existing role-adaptive `GET /api/dashboard`. New `DashboardKpiController`, `ReliabilityKpiQuery`/`ProcessPerformanceKpiQuery`/`RecentlyRelocatedAssetsQuery`, `DashboardKpiResource`; `AssetLocationHistoryResource` gained an `asset` fragment. Frontend handover: `docs/atms/04-technical/DASHBOARD_KPI_HANDOFF.md`. 11 tests, full suite 476 green. | **Frontend** (build the 9-card dashboard + relocated widget), Backend | ⚠️ Unacknowledged |

---

## 🟠 Deferred — Do Not Forget

<!-- Agreed changes that were postponed. Must include a TRIGGER — when to
     bring it back. Without a trigger, it will be forgotten. -->

| ID | What | Reason deferred | Trigger to revisit |
|---|---|---|---|
| D-001 | Rename `frontend/` → `atms/` + update Docker/nginx configs | Docs restructure done; code rename deferred per plan | When backend team starts SM subsystem |
| D-002 ✅ | Update `CLAUDE.md` to match new docs structure | — | **Done 2026-07-31** — rewritten from live code as a single root file |
| D-007 | Delete or keep `views/locations/AssetLocationUpdateView.vue` | Orphaned when `/locations2` + `LocationsView.vue` were deleted 2026-07-31; it is a full view, so not removed on assumption | Next frontend session — confirm with the user, then delete or re-consume it |
| D-008 ✅ | **Proper Booking model — BUILT (2026-07-31).** Dedicated `bookings` table with date range, job reference, booked-by user, status lifecycle (`active`/`cancelled`/`released`), overlap detection **with force-override warning**, auto-release on deactivation/withdrawal, full history. `is_booked` on assets is now derived. Endpoints: `GET/POST /assets/{id}/bookings`, `PUT /assets/{id}/bookings/{booking}` (edit), `POST /assets/{id}/bookings/{booking}/cancel`. Frontend: Bookings card in right rail (Reference + Status rows), click → detail Dialog (all fields), Edit button → pre-filled form Dialog, overlap → 409 with conflicts → "Book Anyway" force button. `AssetIdentityBadges` gained a slot for inline "Booked" badge in asset list. 933 tests green, Pint clean, vue-tsc clean. | Operations book up to 3 months ahead but `is_booked` was a bare boolean — no dates, no job link, no record of who committed the asset, no overlap detection, no history. | **Done 2026-07-31.** Overlap is a warning (user can force), not a hard block. |
| D-009 | Asset **status history** table | Needed only if LDC wants status as-of a past date; today `operational_status`/`maintenance_status` are overwritten in place and history exists only in `audit_logs` blobs | LDC confirms Report 1 date-range semantics (point-in-time vs created/updated-in-range) |
| D-010 | Report **export** (CSV / PDF / xlsx) | No export capability exists anywhere in the codebase | LDC confirms acceptable format. CSV streams from existing cursor queries; PDF can reuse the `PartRequestPrintView` print-route pattern; xlsx needs a new dependency |
| D-011 | **WO Forms: route by Maintenance Category, not FA Subclass (many-to-many).** `form_templates.fa_subclass_code` → `form_template_maintenance_category` pivot; a form serves **one or more** categories (searchable multi-select in admin). Uniqueness moves to the pivot: **at most one active template per category**, enforced by mirrored `is_active` + partial unique index `ON (maintenance_category_id) WHERE is_active`, with a 422 that names the colliding category and owning template. Re-point `SnapshotFormTemplateIntoWorkOrder` + `SyncWorkOrderFormToLatest`. Existing templates migrate **deactivated** for manual reassignment (no subclass→category mapping exists). **Also deletes the 4 `/admin/fa-subclass-type-codes` CRUD routes + controller** — its only consumer is the dropdown this work removes. | **Not that the column is dropped — that it isn't ATMS's.** `fa_subclass_code` is written by the ERP sync (`ImportErpAssetsCommand`) so ATMS cannot govern it; `maintenance_categories` is ATMS-owned (Admin-editable). A field ATMS doesn't control must not route behaviour ATMS is accountable for. Subclass **stays** as a read-only report dimension in 7 reports — describing is fine, controlling is not. | Next implementation session. **Cost grows with delay** — every template built meanwhile is another hand-remap. Requires `maintenance_category_id` NOT NULL first. |
| D-012 | **PM Rules: assign to Maintenance Categories (multi-select).** Rules currently target individual assets only. Add `pm_rule_maintenance_category` pivot + **`origin` (`manual`\|`category`) and source category on `asset_pm_assignments`**. **Materialize, don't resolve:** the pivot records intent and expands into one assignment row per member asset. Expansion is a **queued, batched job with one audit entry per operation** (not N). Reconciliation hooks: asset create, asset category change, rule-category change, bulk import (once at end, never per row). | Per-asset PM state is inherent — each row owns `last_triggered_date`/`last_triggered_reading`, so a category link can only *create and maintain* rows, never replace them. Materialize wins on the baseline: `CreateAssetPmAssignment` stamps `last_triggered_date = now()` to grant one full interval of grace, and dynamic resolution has no moment to do that — every asset would land immediately overdue or never due. It also leaves the eval job, the L1–L4 cascade in `CloseWorkOrder`, and all 4 PM reports untouched. | Next implementation session, **after D-013**. Requires `maintenance_category_id` NOT NULL first. |
| D-013 | **`EvaluatePmRulesJob` does not scale — fix before D-012.** `chunkById` instead of `->get()`; actually pass the `$readings`/`$suppressions` collections `PmDueCalculator` already accepts; extend `isTriggeredByDate`/`isTriggeredByReading` to take them (they hardcode `null`, defeating half the batch path); fan out per-chunk child jobs instead of one 300s run. | **The batch path was built and is unused.** The job loads every active assignment with `->get()`, then `EvaluatePmRule` calls `isDue($locked)` with one arg — so each assignment costs ~6–12 queries in its own transaction, even when nothing is due. `maintenance_level` is an **L1–L4** scheme, so ~4 rules/asset is the *designed* shape: 400 assets ≈ 1,600 assignments ≈ 10–19k queries against `timeout = 300`. It won't finish — and D-012 is exactly what turns a handful of assignments into thousands in one click. | **Do first, standalone.** It's a latent bug today regardless of D-012, and far easier to verify against small data than tangled into a new feature. |
| D-004 ✅ | Virtual Store resolved — one workshop, per-part approval, auto-flag, overnight hold with next-day enforcement | 2026-06-24 | Done — spec in `docs/sm/01-product/VIRTUAL_STORE.md` |

> **Note:** Asset tag QR code generation (was D-003), SM architecture/parts
> catalogue (was D-005), and ERP parts write-back (was D-006) have been
> assigned concrete phases — see the Phase 2 / Phase 3 tables below.

---

## 🟢 Done

<!-- Move items here once all teams have acknowledged. Keep last 10. -->

| Date | What | Completed by |
|---|---|---|
| 2026-06-28 | VPS frontend bug tracker + notification integration spec + docs README updated | AI-assisted |
| 2026-06-24 | Documentation restructure (3 subsystems, 5 roles, 19 files updated) | AI-assisted |
| 2026-06-24 | ERP connection tested: token acquired, 429 assets fetched from BC | AI-assisted |
| 2026-06-24 | Mock ERP env vars removed from compose.yaml, .env, backend/.env | AI-assisted |
| 2026-06-24 | `RISKS_AND_ASSUMPTIONS.md` updated with real ERP details | AI-assisted |
| 2026-06-24 | LDC meeting prep document (parts write-back) + PDF | AI-assisted |
| 2026-06-25 | Sidebar navigation redesign (flat + tabs, 7 items, 5 docs rewritten) + Secure Remote API Access spec (new doc, 3 docs updated) | AI-assisted |

---

## 🔵 External Blockers

<!-- Things we cannot advance until someone outside the team provides
     something. Full details in docs/05-delivery/TDL.md. -->

| # | What | Waiting on |
|---|---|---|
| 1 | Parts API page name (BC custom API page) | ERP team / LDC |
| 2 | Parts field mapping (response schema) | ERP team / LDC |
| 3 | `componentOfMainAsset` sample with non-null parent | ERP team / LDC |
| 5 | **Dashboard/Reports clarifications sent to LDC (2026-07-31), awaiting reply:** (a) which axis "asset status" means — booking, operational, or enrolment — since ATMS models three independently; (b) do bookings have start/end dates and a job reference, and should overlaps be blocked; (c) does Report 1's date range mean point-in-time status (needs new history table) or current status created/updated in range; (d) Report 1 lists "Assigned To" but assets have no custodian field — does it mean the technician on the open WO; (e) intended users + full requirements for Reports 2 and 3; (f) is CSV acceptable in place of xlsx | LDC |
| 4 | ~~Power Automate webhook URL~~ → **Graph email remaining: Application Access Policy** (restrict app to `notification@ldc.com.ly`) + **official LDC frontend subdomain** for email links. Both now gate MR/WO workflow email as well as account email — see R-007. | LDC IT (Exchange admin) / LDC (subdomain) |

---

## Process

1. **Start of build session:** Read this file. Pick up anything in 🔴 In Progress
   or pull from 🟠 Deferred if its trigger fired.
2. **During build:** If a requirement surfaces that needs the other team, add it
   to 🟡 immediately — do not assume you will remember.
3. **When postponing:** Add to 🟠 Deferred with a clear trigger. "Later" is not
   a trigger. "After asset_tag migration is merged" is a trigger.
4. **End of session:** Move completed items to 🟢 Done. Move 🟡 items that were
   acknowledged. Review 🟠 for any triggers that fired.

## Phase 2 (deferred)

| ID | What |
|---|---|
| P2-001 | Asset Assembly: parent_asset_id column, install/remove/swap Actions, assembly_history table |
| P2-002 | Component PM cross-check: 🟢🟡🔴 indicators + "Create MR for Component" |
| P2-005 | AM movement workflow: Requester → Logistics approve → confirm arrival |
| P2-006 | ERP parts write-back from SM GR to BC ERP (ERP team must confirm consumption/decrement API contract) |
| P2-008 | Asset tag QR code generation on asset detail page (format `L-BBB-CCC-XXXX` already decided; visual rendering is the remaining work) |

## Phase 3 — SM Subsystem (deferred)

> SM decoupled into its own phase (2026-07-02). The Store Management subsystem
> is the largest, most uncertain scope item — pending VJ's answer on whether BC
> Store Order is live (determines local `parts` table vs direct BC query).

| ID | What |
|---|---|
| P3-001 | SM architecture + parts catalogue design — full local build vs. BC Store Order integration (pending VJ reply about BC Store Order). `work_order_parts` already exists in Phase 1 regardless. |
| P3-002 | SM Order workflow: Order → Approval → Dispatch → Goods Receipt |
| P3-003 | SM inventory management: stock movement, balances, Virtual Store |
| P3-004 | Manual Asset Creation + lifecycle-field persistence (G-01 Add Asset button + G-04 `CreateAsset` dropped fields) — **deferred to Phase 3 or cancelled** pending data-integrity decision. See gap analysis §4.1/§5.1. |
