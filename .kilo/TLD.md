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
| _(none active)_ | | |

---

## 🟡 Recently Completed — Frontend / Other Teams Need to Know

<!-- Backend finished something? Put it here so frontend knows the API changed.
     Frontend finished a screen? Put it here so others know the UX is done.
     Items stay here until the other team acknowledges. Then move to Done. -->

| Date | What changed | Who needs to know | Status |
|---|---|---|---|
| 2026-07-05 | **Dropped `maintenance_requests.type` column — `is_preventive` is the single stored discriminator.** `type` is now **derived** in `MaintenanceRequestResource` / `MaintenanceHistoryResource` (`is_preventive ? 'preventive' : 'corrective'`). **API contract unchanged**: both `type` and `is_preventive` are still emitted; `?type=preventive\|corrective` list filter still works (translated server-side to `is_preventive`). No frontend action required. Frontend team had pre-confirmed they can drop `is_preventive` from the TS interface and key off `type === 'preventive'` — safe to do so now. Migration `2026_07_05_000000_drop_type_from_maintenance_requests_table` applied to live Postgres. Full suite 483 passed. | Frontend (informational — no action needed), Backend | ⚠️ Unacknowledged |
| 2026-06-28 | VPS Frontend Issue Tracker created (`docs/atms/04-frontend/VPS_FRONTEND_ISSUES.md`) — 8 issues logged (5 MR, 3 WO, 1 Asset). 4 frontend fixes verified on VPS, 3 need backend follow-up. | Frontend, Backend | ⚠️ Unacknowledged |
| 2026-07-04 | **Email transport = Microsoft Graph `sendMail`** (pivoted from Power Automate — SMTP ruled out by tenant policy `535 5.7.139`). Azure app provisioned + Mail.Send consented + probe passed (HTTP 202). Shared Blade template `emails/atms-notification.blade.php` + 3 scenarios (MR Created, WO Assigned, WO Completed) test-sent. Routing decided. **Not built yet:** `GraphMailTransport` (queue-serialized + 429 retry) + Mailables + wiring into WO/MR actions + tests (TDD). Pre-release: final frontend URL, Application Access Policy, real user emails (demo has fakes), serialize+retry. See `docs/03-backend/NOTIFICATIONS.md`. | Frontend (notification UX if any), Backend (transport build) | ⚠️ Unacknowledged |
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
| D-002 | Update `CLAUDE.md` to match new docs structure | Explicitly out of scope for docs restructure | After `frontend/` → `atms/` rename |
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
| 4 | ~~Power Automate webhook URL~~ → **Graph email remaining: Application Access Policy** (restrict app to `notification@ldc.com.ly`) + **official LDC frontend subdomain** for email links | LDC IT (Exchange admin) / LDC (subdomain) |

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
