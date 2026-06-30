# Session State — 2026-06-28

> **For AI agents:** Read this at the start of every session. It tells you what
> was done, what is decided, what is blocked, and what to tackle next.

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

- **Power Automate Notification Integration — DOCUMENTED (2026-06-28):**
  - Created `docs/03-backend/NOTIFICATIONS.md` — full spec for email delivery via
    company-standard Microsoft Power Automate.
  - Architecture: ATMS event → queued job → HTTP POST (JSON) → Power Automate
    HTTP trigger → email. No DB polling, push-based.
  - 5 notification triggers documented with full payload contracts:
    - Phase 1: MR Created, WO Assigned/Reassigned
    - Phase 2: SM Order Submitted, SM Order Approved, SM Order Rejected
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
- **Parts Management UI** — `PartsView.vue` + `PartDetailView.vue` are "coming soon"
  stubs. Backend done (`GET /parts`, `PATCH /parts/{part}`, attachments).
  ⏸️ **PARKED (2026-06-30)** — client hasn't finalised Parts scope and the ERP Parts
  schema isn't available yet. Do **not** start until both land. (Data side already
  ERP-blocked — see P2.)
- **System Settings** — `SystemSettingsView.vue` stub; backend done.
- **Audit Logs** — `AuditLogsView.vue` stub; backend done.
- **Manager → Admin-area access** — decided but not implemented: `AppSidebar.vue`
  Admin items still `visibleTo: isAdmin` (lines 86, 93); router still has
  `requiresAdmin` guards (lines 118, 127). Grant Managers access (see Open Follow-ups).

### Notification Testing (2026-06-29)
- Test Power Automate webhook integration: POST sample payloads from ATMS queue
  worker → verify email arrives via Power Automate flow.

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
testing.** Asset Booking frontend ✅ done. Parts Management UI is ⏸️ PARKED (client
scope + ERP schema). P2 parts data stays ERP-blocked. #6 / #7 anytime.

---

## Phase 1 pending review
Phase 1 core is **COMPLETE**. VPS bug fixes and WO assignment enhancements are
**done** (2026-06-28). Remaining: stub-view frontend builds (Parts UI, System
Settings, Audit Logs), Manager admin-area access, Asset Booking frontend, and
notification integration testing.

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
| Notifications / Email | All transactional emails delivered via Microsoft Power Automate (company standard). ATMS dispatches queued job → HTTP POST → Power Automate HTTP trigger → email. Not Laravel's native mail driver. (2026-06-28) |
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
