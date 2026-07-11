# Session State — 2026-06-28

> **For AI agents:** Read this at the start of every session. It tells you what
> was done, what is decided, what is blocked, and what to tackle next.

## Decision update — 2026-07-11

- **Microsoft Graph `sendMail` is the only ATMS production email transport.**
  Power Automate is retired and must not be implemented, configured, or retained
  as a fallback. Development and automated tests use the fake transport.
- **Phase 1 email scope is limited to account activation and password reset.**
  Operational MR/WO emails are outside the current Phase 1 scope.
- **Required backend follow-up:** implement Graph behind
  `AccountEmailTransport`, wire `ACCOUNT_EMAIL_TRANSPORT=graph`, add the
  `GRAPH_*` configuration, tests, queue serialization and 429 retry handling,
  then remove the legacy Power Automate class, configuration, binding, and tests.


## Session — 2026-07-11

- **Asset API location filter correction — implementation applied, verification pending.**
  `GET /api/assets?location_id={id}` preserves the public parameter and now filters
  `assets.current_location_id` in `AssetIndexQuery` instead of the nonexistent
  `assets.location_id`. Regression tests cover selected-location filtering and
  requester active-asset scoping. The delivery team will run the focused test.
- **G-09 Effective Date UI mismatch — DONE.** Removed the disabled,
  non-submitted datetime control from `UpdateLocationSheet`. Phase 1 moves take
  effect immediately, and backend `effective_at = now()` remains authoritative.
  Updated the relevant location UI/specification docs. Frontend type-check and
  production build pass.

## Session — 2026-07-05

- **`is_failure` failure-classification flag for corrective MRs — DONE (backend + frontend).** Nullable boolean on corrective MRs marking a real failure vs. no-fault-found/duplicate/etc. Classified **twice** by qualified roles (not the requester): required at **MR approval** (`POST /maintenance-requests/{id}/approve` — 422 if missing for corrective in `pending_review`), optional override at **WO closure** (`POST /work-orders/{id}/close`). Preventive MRs never classified (`null`). MTBF + Failure Rate now count `is_failure = true` (not every corrective event); MTTR unchanged.
  - **Renamed `is_fault` → `is_failure`** wire-level (column, payloads, audit `close_work_order_update_mr_is_failure`) — "Failure" is the correct reliability term (MTBF = Mean Time Between **Failures**). Migration **recreated, not patched — no deprecation window**.
  - **⚠️ Contract note (bit both sides):** `WorkOrderResource` embeds `maintenance_request` as a **partial** `{ id, number, is_preventive, is_failure }` — carries **`is_preventive`, not `type`**. Corrective-origin detection keys off `is_preventive === false`.
  - **Backend files:** migration (backfills CONVERTED corrective MRs → `true`, pending-review stay `null`); `MaintenanceRequest` (`$fillable`+`$casts`); approve action (conditional-required; `use ($isFailure)` closure-capture bug caught in test); close action (corrective-origin override + audit); `MaintenanceRequestResource` (always) + `WorkOrderResource` (`whenLoaded('maintenanceRequest')`); `ReliabilityKpiQuery` (MTBF/failure_rate → `is_failure=true`). 34 WO-lifecycle tests green; Pint clean.
  - **Frontend files (7):** `types/index.ts` (`is_failure` + new `WorkOrderMaintenanceRequestRef`), `useMaintenanceRequestDetail.ts` (`approveIsFailure`, required-gate, payload), `useWorkOrderDetail.ts` (`closeIsFailure`, `isCorrectiveOrigin` via `is_preventive`, `originTypeLabel`, close-as-dialog, omit-key-unless-chosen), `MaintenanceRequestDetailView.vue` + `WorkOrderDetailView.vue` (badges incl. **WO command-bar badge next to status/priority per user request**, Approve/Close Select dialogs), `displayHelpers.ts` (`failureLabel`/`failureClass`), `style.css` (`.status-failure`/`.status-no-failure`/`.status-unclassified`). `vue-tsc --build` + `npm run build` green; oxfmt clean.
  - **Docs:** `user-manual.md` §6.2/§7.0/§7.5/§8.5 (Rawand). **Frontend uncommitted in the working tree — user said DO NOT COMMIT (2026-07-05).**
- **Dropped redundant `maintenance_requests.type` column — `is_preventive` is now the single stored source of truth.** Closes the guardrail gap flagged 2026-07-03 (bare-varchar `type` without an Enum cast). Rather than dress the redundant column up as a `MaintenanceRequestType` Enum, the column was removed entirely: `is_preventive` (boolean) already encodes the same fact and is what every authoritative consumer (policies, lifecycle actions, dashboard KPIs, PM chain-prevention) already trusted. `type` is now **derived** inline in `MaintenanceRequestResource` and `MaintenanceHistoryResource` (`$this->is_preventive ? 'preventive' : 'corrective'`) — API output shape unchanged, non-breaking. The `?type=preventive|corrective` list filter is translated server-side to `where(is_preventive, …)` so existing consumers keep working.
  - **Migration:** `2026_07_05_000000_drop_type_from_maintenance_requests_table` (drops `type`; `down()` re-adds it `->after('asset_id')`). Applied to live Postgres (4 corrective MRs preserved). SQLite `:memory:` tests apply it via `migrate:fresh`.
  - **Files touched:** migration; `MaintenanceRequest` model (removed `type` from `$fillable`); both Resources (derive `type`); `MaintenanceRequestIndexQuery` (filter translation); `CreateCorrectiveMaintenanceRequest` + `EvaluatePmRule` (removed redundant `type` writes — keep `is_preventive`); `MaintenanceRequestDemoSeeder` (drives off a single boolean pool); 19 test files (removed redundant `'type' => …` keys from MR create arrays / one `assertDatabaseHas`).
  - **Tests:** full suite **483 passed (1292 assertions)** — identical to baseline. Pint clean. No fresh log errors.
  - **Docs updated:** `user-manual.md` (data-model table — `is_preventive` promoted to main fields as the discriminator; `type` marked derived; PM-generation narrative reworded); `BACKEND_API_REFERENCE.md` (added data-model note + derived-field marker + `?type=` translation note); `BACKEND_API_HANDOFF.md` (TS `type` field annotated as derived).
  - **Frontend impact:** none required — the API still emits both `type` (derived) and `is_preventive`. Frontend team confirmed they will voluntarily drop `is_preventive` from their TS interface and key the one `v-if` off `record.type === 'preventive'`. Logged in `.kilo/TLD.md` 🟡.

## Session — 2026-07-04

- **Email transport pivoted to Microsoft Graph `sendMail` (replacing the Power Automate plan).** SMTP AUTH ruled out empirically — LDC M365 tenant `SmtpClientAuthenticationDisabled` → `535 5.7.139` (creds valid; policy block). XOAUTH2-over-SMTP is not a supported M365 app-only path. Power Automate is retired and will not be used. Chose **Graph `sendMail`** (OAuth2 client credentials), sending from `notification@ldc.com.ly`, unaffected by the SMTP AUTH policy.
  - **Azure provisioning DONE (2026-07-04):** separate Entra app from `LDC_ERP_*` (Client `6dd70b5f-…`, Tenant `a8a21afa-…`, Object `ffbb837a-…`); `Mail.Send` (Application) + tenant-wide admin consent granted; probe delivered test mail to both recipients (HTTP 202). Config in `backend/.env` as `GRAPH_TENANT_ID/CLIENT_ID/CLIENT_SECRET/MAILBOX`; `ACCOUNT_EMAIL_TRANSPORT` stays `fake` until the transport is built.
  - **Template:** shared Blade view `resources/views/emails/atms-notification.blade.php` (client-provided HTML adapted; amber `#d97706` accent, navy `#21274b` header, **no logo**, dynamic CTA). 3 scenarios rendered + test-sent (202 each): MR Created, WO Assigned, WO Completed.
  - **Routing decided:** MR Created → To: all active Managers, Cc: all Admins. WO Assigned/Reassigned → To: new assignee, Cc: action taker (notify on any change). WO Completed → To: all active Managers, Cc: completer. Greeting = To recipient only. From-name "ATMS Notifications", **no Reply-To**.
  - **Superseded 2026-07-11:** Graph is the production implementation behind `AccountEmailTransport` for the in-scope activation and password-reset emails. Operational MR/WO notifications are outside current Phase 1.
  - **Throttle finding (important):** Exchange Online throttles concurrent app access per mailbox (~3–4) → `429 ApplicationThrottled` (and gateway `504`s) when blasting parallel sends. Production dispatch MUST be **serialized via the queue** + **retry-on-429 honouring `Retry-After`**.
  - **Docs updated:** `NOTIFICATIONS.md` (full rewrite), `ARCHITECTURE.md`, `CLAUDE.md`, `README.md`, `IMPLEMENTATION_PLAN.md`, `DEPLOYMENT.md`, `PHASE_1_GAP_ANALYSIS.md` (I-03, R-06).
  - **NOT built yet (next, TDD):** Graph implementation behind `AccountEmailTransport` for activation/reset, queue serialization + 429 retry, configuration/binding, tests, and removal of the legacy Power Automate transport. Operational MR/WO Mailables and action wiring are future scope.
  - **Pre-release checklist (email):** frontend base URL NOT final (temp `atms.inova.krd` → official LDC subdomain); real user emails (demo has fakes); serialize+retry; prod secret/cert; Application Access Policy; queue worker.
- **Self-service password change — DONE (committed `a03b078`).** `POST /api/auth/change-password` (authenticated; no current-password required per product decision); `ChangeUserPassword` action (invalidates all sessions + tokens, audits `user.password_changed`); `ChangePasswordRequest`; `UserPolicy::changePassword`. 7 tests; full suite **483 passed (1292 assertions)**.

## Session — 2026-07-03

- **Dashboard KPIs endpoint — DONE (backend, uncommitted).** New `GET /api/dashboard/kpis` serves the 9-card dashboard's Row 2 (MTBF / MTTR / Failure Rate) + Row 3 (PM Compliance / Avg MR Duration / Avg WO Duration) plus a "Recently Relocated Assets" widget (latest 5 `asset_location_histories`). Visible to **every authenticated role** (reuses the existing `viewDashboard` gate, which is `fn (User $user): bool => true`); payload is **not** role-filtered — Row 1 counts stay on the existing role-adaptive `GET /api/dashboard` (decision (a): KPIs = aggregate numbers for all; record lists stay role-scoped on `/dashboard`).
  - **Decisions locked:** rolling **90-day** window; MTBF = **calendar** basis (`90 / corrective failures`); MTTR = `assigned_at → closed_at` on **corrective** WOs; PM Compliance = **date-triggered** PMs only, on-time = `wo.closed_at::date ≤ mr.trigger_date` (no grace); relocated = latest 5 within the window.
  - **Files:** `DashboardKpiController` (thin: Gate → 2 query classes → `DashboardKpiResource`, `$wrap=null` for a flat object matching `/dashboard`), `app/Queries/Dashboard/Kpis/ReliabilityKpiQuery` + `ProcessPerformanceKpiQuery`, `app/Queries/Dashboard/RecentlyRelocatedAssetsQuery`, `app/Http/Resources/DashboardKpiResource`. Route added under the auth group.
  - **"Failure" = `maintenance_requests.is_preventive = false`** (boolean) — deliberately avoided the raw `type` string. `maintenance_requests.type` is still a bare varchar without an Enum cast (pre-existing guardrail gap — flagged as a separate cleanup; create `MaintenanceRequestType` enum + cast).
  - **Resource enhancement:** `AssetLocationHistoryResource` now exposes an `asset` fragment (`whenLoaded`) so the relocated widget can show asset name/tag/code without a second fetch. Safe — the existing `/assets/{asset}/location-history` endpoint doesn't load `asset`, so its response is unchanged.
  - **Tests:** `tests/Feature/Dashboard/DashboardKpiTest` — 11 tests (auth/401, every-role access, structure, each KPI's math incl. corrective-only filtering + window exclusion, empty→null state, relocated top-5 + asset identity). Full suite **476 passed (1278 assertions)**. Pint clean. No fresh log errors.
  - **Gotcha for future tests:** `created_at`/`updated_at` are **not** in the models' `$fillable` (guarded) — passing them via `create()` is silently ignored. Use `forceCreate([...])` when a test needs an explicit `created_at`. Also `work_orders.maintenance_request_id` is NOT NULL.
- **Docs updated:** `BACKEND_API_REFERENCE.md` (§Dashboard — full `/dashboard/kpis` endpoint), `BACKEND_API_HANDOFF.md` (TS types `DashboardKpiResponse`/`RelocatedAssetItem` + quick-ref row), new focused `DASHBOARD_KPI_HANDOFF.md` (self-contained frontend handover: 9-card mapping, null handling, formatting), `.kilo/TLD.md` (🟡 Recently Completed), `CLAUDE.md` (New endpoints table).

## Session — 2026-07-02

- **Parts Management UI (G-02) — DONE (committed `56bd463`).** Replaced the two "coming soon" stubs with full implementations: `PartsView.vue` (searchable/filterable table via `AppDataTable`, category filter derived live from data) + `PartDetailView.vue` (overview card, ERP reference rail for Admin/Manager incl. raw ERP JSON, attachments upload + per-attachment delete). New `useParts`/`usePartDetail`/`usePartSearch` composables, `partColumns`, and `PartCombobox`. Removed `__mockParts.ts` + all `// MOCK(PARTS)` blocks; the WO parts-used picker now reads live `GET /parts`. Backend: `PartSeeder` (55 O&G drilling-maintenance parts across 11 categories) registered in `DatabaseSeeder` + feature tests. Placeholder `erp_part_id`/`erp_raw_data` are NULL so `SyncErpPartsJob` overwrites cleanly when the ERP parts endpoint lands. Closes critical gap **G-02** from `docs/PHASE_1_GAP_ANALYSIS.md`.
- **Phase reorganisation decided (2026-07-02):** SM decoupled into **Phase 3** (largest, most uncertain scope — pending VJ's BC Store Order answer). Phase 2 = AM movement + Asset Assembly + Component PM cross-check + ERP parts write-back + Asset tag QR generation. Manual Asset Creation (G-01 Add Asset + G-04 `CreateAsset` dropped lifecycle fields) **deferred to Phase 3 or cancelled** — data-integrity concerns: with ERP as the likely source of truth for asset reference data (Phase 3 SM work), manual create risks duplicates/drift; and the create button is disabled in production so G-04's dropped fields have no live impact. See updated `.kilo/TLD.md` Phase 2/3 tables.
- **Admin Lists & Dropdowns cleanup — DONE (backend + frontend, parallel implementation).** `.kilo/plans/1783001396791-admin-lists-dropdowns-cleanup.md`. Trimmed the Admin "Lists & Dropdowns" tab from 8 groups to 3 genuinely-configurable ones (`maintenance_priorities`, `usage_reading_types`, `fa_subclass_type_codes`) — the other 5 were Enum-backed state machines (`WorkOrderStatus`, `OperationalStatus`, `MaintenanceSubStatus`) or dead concepts (`asset_categories`, `maintenance_categories`), decorative no-ops since `master_data_items` was empty. New public read path `GET /api/list-options/{group}` (auth-only, not Admin-gated — see CLAUDE.md New endpoints) lets every role read active-only priorities/reading-types/FA-subclasses without the Admin-gated `/admin/master-data/*` CRUD. Backend: `ListOptionController` + route + `maintenance_priorities` seed migration (4 rows: low/medium/high/critical) — 7 tests passing (20 assertions), confirmed via `docker exec atms-api php artisan test`. Frontend: new `useListOptions.ts` composable (fallback `DEFAULT_PRIORITIES` on fetch failure); `mrColumns.ts`/`woColumns.ts` dropped static priority arrays, `WorkOrdersView.vue`/`MaintenanceRequestDetailView.vue`/`WorkOrdersListView.vue` now merge live priorities into filter/select options; `useMaintenanceRequestDetail.ts` draft `priority` widened `Priority`→`string` (now dynamic data). **Bug fixed in passing:** the hardcoded FA-subclass filter list (`assetColumns.ts`) had drifted to 18 codes vs. 20 in the DB — missing `ROTOR`/`STATOR`. Fixed by fetching the live list; kept a display-only `FA_SUBCLASS_LABELS` lookup (repurposed from the old hardcoded array) so friendly labels ("Mud Motor") are preserved, falling back to the raw code for anything uncurated. Also preserved the "Critical — immediate attention required" picker hint via a new `priorityPickerLabel()` helper. Docs updated: `ROUTES.md` §Admin, `SCREEN_INVENTORY.md` §7b. Both sides uncommitted in the working tree as of this session.
- **Asset status enum rename — DONE (backend + frontend).** `maintenance_status` `Active`/`Inactive`→`enrolled`/`withdrawn`; `maintenance_sub_status` PascalCase→lowercase (`installed`,`ready`,`lih`,`dbr`,`disposed`,`scrapped`,`other`). Reason: kill the `operational_status='active'` collision. Rolled out as 3 plans (`.kilo/plans/1782944404943/44/45`). Backend done: both enums, `LegacyAssetStatusNormalizer` (`normalize`+`normalizeSubStatus`, both `?string`; validation accepts both cases), 2 migrations. Frontend done: 6 files (`types/index.ts`, `useAssetDetail.ts` L83+L227, `AssetDetailView.vue`, `displayHelpers.ts`, `assetColumns.ts`, `content/user-manual.md`) — type-check + build green, sweep clean. Display labels: enrolled→"In maintenance program", withdrawn→"Withdrawn". **Ordering: backend-shim-first (NOT atomic)** — shim decouples FE/BE timing. **PENDING: Plan 3** (`1782944404945`) removes both shims ~14 days after Plan 2 deploy (≈mid-July 2026); un-skips `legacy→422` test stubs. Untouched: `operational_status`, `is_active`.
- **Docs clean-up (2026-07-02):** `TDL.md` (added G-13 gap entry), `STATUS_MODEL.md` (L90 — fixed "configurable as master data" → Enum-backed state machine contradiction), `NAVIGATION.md` (L162-165 — corrected lists description), and `IN_SCOPE.md` (L185-188 — same). `SCREEN_INVENTORY.md` §7b and `ROUTES.md` §Admin were already aligned from the Lists implementation. All docs now match the dynamic-config model.
- **WO Detail frontend review:** reading-type URL fixed (`/admin/usage-reading-types`), WorkOrderResource now ships `asset.operational_status`, upload dialog has `.dialog-md` (user prefers wrap/trim — pending). Mock parts catalogue (8 items) in `src/lib/__mockParts.ts` + `// MOCK(PARTS)` blocks — **remove** when Parts API ships.
- **WO Form layout**: Sheet (A) vs tighter-card (B) — recommended Sheet. Pending user decision.
- **Attachment delete**: `DELETE /api/attachments/{id}` (generic, not WO-scoped). `can_delete` shipped by AttachmentResource.
- **Meter reading edit/delete**: backend shipped + frontend wired. PATCH/DELETE under `/assets/{asset}/meter-readings/{reading}`, Admin/Manager/Tech, confirmed-locked (409). Frontend: `useWorkOrderDetail.ts` (`canManageReadings`, `openEditReading/doEditReading`, `openDeleteReading/doDeleteReading`) + `WorkOrderDetailView.vue` readings-table actions column + Edit/Delete dialogs. Editable fields: value, read_at, notes (type read-only). Actions hidden for confirmed readings.
- **Environment**: PHP not on PATH; pint/tests require `php` binary.
- **Asset operational status → AUTOMATIC (replaces Option A suggestion approach)**. Backend-driven via `ApplyWorkOrderAssetStatusTransition` action (audit `asset.status_updated` w/ `source=work_order_lifecycle`). Mapping: CM MR approved → `down` (skip if already `under_maintenance`); PM approved → no change; WO start → `under_maintenance` (forced, all WOs); WO close → `active` (only if currently down/UM — never un-retire `inactive`); WO cancel → caller chooses `down`|`active` (new `asset_status` param on `POST /work-orders/{id}/cancel`). Hooks: `ApproveMaintenanceRequestAndCreateWorkOrder`, `StartWorkOrder`, `CloseWorkOrder`, `CancelWorkOrder` (+ controller). Frontend cancel dialog now requires the Down/Active choice. Manual 'Update status…' setter remains as override. **Reverted** the earlier suggestion-banner code. Backend tests + pint NOT run (no PHP on PATH) — needs `vendor/bin/pint` + WorkOrderLifecycleTest updates.


## Last Session Accomplished

- **VPS Frontend Testing — Bug Tracker (2026-06-28): ALL 9 ISSUES RESOLVED.**
  - `docs/atms/04-frontend/VPS_FRONTEND_ISSUES.md` — live tracker for frontend bugs
    found during VPS deployment testing.
  - **MR (5):** MR-01 case-insensitive asset search ✅ (backend `LOWER(col) LIKE`);
    MR-02 list refresh after create ✅; MR-03 attachments open in new tab ✅ (blob +
    object URL — API forces `Content-Disposition: attachment`); MR-04 layout +
    "Approved by" ✅; MR-05 delete attachments ✅ (backend policy allows owner-delete
    while `pending_review`; `AttachmentResource` exposes an unconditional policy-driven
    `can_delete` flag (+ `attachable` eager-load); frontend gates per-attachment via
    `canDeleteAttachment(a)`).
  - **WO (3):** WO-01 layout ✅; WO-02 assign-at-approval ✅ (atomic — `/approve`
    accepts `assignee_id`); WO-03 assign/reassign ✅ (reassign while `in_progress`;
    picker lists active Technicians **and** Managers; backend `AssignWorkOrder` +
    `StartWorkOrder` accept both via `User::isWorkOrderAssignee()`). Also fixed a
    pre-existing bug: pickers called `/users` (404) → now `/admin/users`. Assign
    control is an icon button in the WO Details card header.
  - **Asset (1):** AS-01 location "#undefined" ✅ (frontend consumes
    `from_location`/`to_location` objects directly; backend eager-loads them).
  - **No leftovers** — all 9 VPS issues fully resolved (frontend + backend).

- **Power Automate Notification Integration — HISTORICAL, SUPERSEDED 2026-07-11:**
  - **Do not implement this design.** Power Automate is retired; Microsoft Graph
    `sendMail` is the only production email transport. The following bullets are
    retained only as session history.
  - Created `docs/03-backend/NOTIFICATIONS.md` — full spec for email delivery via
    company-standard Microsoft Power Automate.
  - Architecture: ATMS event → queued job → HTTP POST (JSON) → Power Automate
    HTTP trigger → email. No DB polling, push-based.
  - 5 notification triggers documented with full payload contracts:
    - Phase 1: MR Created, WO Assigned/Reassigned
    - Phase 3: SM Order Submitted, SM Order Approved, SM Order Rejected
  - Laravel implementation: `SendNotificationToPowerAutomate` queued job, event
    listeners, retry/failure handling.
  - Power Automate setup checklist.

- **Docs README Updated (2026-06-28):**
  - `docs/README.md` — updated folder structure to include new files, replaced old
    activation-only Power Automate line with full notification integration summary,
    added "Key Documents" table with new entries.

## Next Steps — Prioritized Execution Order (2026-06-28)

Ordered by value and unblocking. **B** = backend (this agent), **F** = frontend
(team), ⏳ = blocked on an external dependency.

### ✅ DONE — VPS Frontend Fixes + WO Assignment (2026-06-28)

- **VPS issues (MR-01..05, WO-01..03, AS-01):** all resolved (see "Last Session
  Accomplished"). Frontend changes need a **rebuild/redeploy** to appear on the VPS.
- **WO Assign + Assign-at-approval:** both shipped (atomic `/approve` w/ `assignee_id`;
  WO detail assign/reassign; Technician OR Manager assignable).
- **MR-05 `can_delete` flag:** ✅ shipped (unconditional policy-driven flag +
  `attachable` eager-load + tests). Frontend already consumes it — owner Delete
  buttons now surface automatically.

### Remaining Frontend Builds (F) — stub views with backend already implemented
- ~~**Parts Management UI**~~ — ✅ **DONE (2026-07-02, committed `56bd463`).** See session log above.
- **System Settings** — `SystemSettingsView.vue` stub; backend done.
- **Audit Logs** — `AuditLogsView.vue` stub; backend done.
- **Manager → Admin-area access** — decided but not implemented: `AppSidebar.vue`
  Admin items still `visibleTo: isAdmin` (lines 86, 93); router still has
  `requiresAdmin` guards (lines 118, 127). Grant Managers access (see Open Follow-ups).

### Notification Testing — ✅ Graph probe passed (2026-07-04)
- Graph `sendMail` probe delivered test mail to both recipients (HTTP 202).
- Azure app provisioned (separate Entra app from `LDC_ERP_*`), `Mail.Send` (Application) consented.
- Remaining before prod: Application Access Policy (restrict app to mailbox), official
  LDC frontend subdomain for links, prod secret/cert, queue-serialized dispatch. See
  `docs/03-backend/NOTIFICATIONS.md` pre-release checklist. (Supersedes the
  2026-06-29 Power Automate webhook test plan.)

### ✅ Asset Booking — Frontend wiring (F) DONE (2026-06-30)
- Backend complete (`POST /assets/{id}/book` + `/unbook`, `is_booked` in
  AssetResource, auto-release on move/inactivation).
- **Frontend shipped:** Book/Unbook button + amber "Booked" badge in the Asset
  Detail header (gated Admin/Manager/Logistics via `canToggleBooking`); confirm
  dialog before toggle; 409 handled via toast. Inline "Booked" badge in the Asset
  List Name cell. (`useAssetDetail.ts`, `AssetDetailView.vue`, `AssetsView.vue`,
  `types`, `style.css` `.status-booked`.) Rebuild/redeploy to see it.

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
**System Settings + Audit Logs views → Manager admin-area access → Notification
testing.** Asset Booking frontend ✅ done. Parts Management UI ✅ done (G-02 closed).
G-01 (Add Asset) + G-04 (`CreateAsset` dropped fields) deferred to Phase 3 / cancelled
(data-integrity decision). P2 parts data stays ERP-blocked. #6 / #7 anytime.

---

## Phase 1 pending review
Phase 1 core is **COMPLETE**. VPS bug fixes and WO assignment enhancements are
**done** (2026-06-28). **Parts Management UI (G-02) closed (2026-07-02).**
Remaining: stub-view frontend builds (System Settings, Audit Logs), Manager
admin-area access, and notification integration testing. G-01 (Add Asset) and G-04
(`CreateAsset` dropped fields) deferred to Phase 3 / cancelled (data-integrity
concerns). G-03 (location picker for non-Admins) still open.

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
| Asset maintenance status | `enrolled`/`withdrawn` (renamed from `Active`/`Inactive` to kill the `operational_status='active'` collision) — gates MR/WO/PM workflows. Sub-statuses `installed`/`ready`/`lih`/`dbr`/`disposed`/`scrapped`/`other` (lowercased), informational only. Display labels: enrolled→"In maintenance program", withdrawn→"Withdrawn". Input shims (`LegacyAssetStatusNormalizer`) accept both cases until Plan 3 removes them. (2026-07-02) |
| Asset operational status | Separate axis from maintenance_status — informational only, no workflow gating. |
| Asset booking (`is_booked`) | Availability marker for Operations to reserve an asset for a Job/Project. Boolean, no job reference stored. Auto-releases on location change or deactivation/inactivation. Does NOT gate MR/WO/PM. Toggled by Admin/Manager/Logistics. (2026-06-27) |
| Employee directory source | CSV-backed (`CsvEmployeeDirectorySource`, `EMPLOYEE_CSV_PATH`), not DB import. `EMPLOYEE_VISIBLE_EMP_IDS` whitelist controls who appears in the list. Provisioning upserts a single Employee row to DB. (2026-06-27) |
| Migration strategy for erp_asset_id | Edit original migration (SQLite `:memory:` runs `migrate:fresh`). Production one-time `ALTER TABLE DROP COLUMN`. |
| Mock ERP | Fully deleted. `LdcErpHttpSource` skips sync gracefully when `LDC_ERP_PARTS_API` is empty. |
| API token abilities | Read-only (`['read']`) blocked on POST/PUT/PATCH/DELETE → 403. Write (`['read','write']`) allowed all. SPA session never blocked. |
| Git commit convention | When the user says "commit ALL" (capitalized), use `git add .` — stage everything including untracked files, then commit. |
| Notifications / Email | Phase 1 activation and password-reset emails are delivered via **Microsoft Graph `sendMail`** (OAuth2 client credentials) from `notification@ldc.com.ly`. SMTP AUTH is ruled out (tenant `SmtpClientAuthenticationDisabled` → `535 5.7.139`); Power Automate is retired and will not be used. Queued, throttle-aware transport (serialize per mailbox + retry on 429). Operational MR/WO emails are outside current Phase 1. |
| WO assignable roles | Admin/Manager can assign WO to active Technician OR Maintenance Manager (small teams, overloaded tech). Assignment authority remains solely Admin/Manager. (2026-06-28) |

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
| 6 | Rename `frontend/` → `atms/` + update Docker/nginx |
| 7 | Create `sm/` and `am/` Vue 3 scaffolds |

## Known Inconsistencies

- **`CLAUDE.md`** references old `frontend/` paths — stale but out of scope for Phase 1 backend cleanup. Frontend rename is deferred.

> ✅ **Phase 1 complete (2026-06-25)** — 8 tasks implemented, 304 tests passing, 2 rounds code review resolved, all documentation updated. See `.kilo/plans/1782388457617-phase1-backend-cleanup-and-features.md` for full execution log and post-review fixes.

## When Starting a New Session

1. Read this file first.
2. Check `.kilo/TLD.md` for active tasks, deferred items, and cross-team awareness.
3. Check `docs/05-delivery/TDL.md` for external blocker details.
4. Check `docs/atms/04-frontend/VPS_FRONTEND_ISSUES.md` for open frontend bugs.
5. The authoritative source-of-truth is `docs/00-project-rules/authoritative-sources.md`.
6. Key docs map:
   - ATMS product: `docs/atms/01-product/`
   - Backend: `docs/03-backend/`
   - Frontend: `docs/atms/04-frontend/`
   - API: `docs/atms/04-technical/`
   - Notifications: `docs/03-backend/NOTIFICATIONS.md`
   - ERP: `docs/03-backend/ERP_SYNC.md`
   - Assembly: `docs/atms/01-product/ASSET_ASSEMBLY.md`
   - Tags: `docs/atms/01-product/ASSET_TAG.md`
   - Phase 1 plan: `.kilo/plans/1782388457617-phase1-backend-cleanup-and-features.md`
   - VPS issues: `docs/atms/04-frontend/VPS_FRONTEND_ISSUES.md`
7. ERP test: source `backend/.env`, then the curl commands commented in that file.

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

### Phase 2 — AM + Assembly + integrations (future)
- Asset Assembly (parent/child, install/remove/swap)
- Component PM cross-check indicators
- AM: Movement request workflow with approval chain
- ERP parts write-back (SM GR → BC ERP; ERP team must confirm consumption API contract)
- Asset tag QR code generation on asset detail page

### Phase 3 — SM Subsystem (future, decoupled 2026-07-02)
- SM architecture + parts catalogue design (full local build vs. BC Store Order integration — pending VJ reply)
- SM: Order → Approval → Dispatch → GR, inventory, Virtual Store
- Manual Asset Creation + lifecycle-field persistence (G-01 Add Asset button + G-04 `CreateAsset` dropped fields) — deferred-to-Phase-3-or-cancelled decision (data-integrity concerns)

### Deferred entirely from Phase 1
- Asset Assembly (parent_asset_id, assembly_history, component hours) — Phase 2
- Component PM cross-check indicators — Phase 2
- SM Order workflow, inventory, stock movement, Virtual Store — **Phase 3**
- AM movement approval workflow — Phase 2
- ERP parts write-back — Phase 2
- Asset tag QR code generation — Phase 2
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
