# Phase 1 Gap Analysis Report

**Prepared for:** LDC ATMS Delivery Team
**Based on:** _LDC_ATMS_Two_Phase_Proposal.md_ (June 2026)
**Analysis date:** 2026-06-27 (revised — full code re-verification)
**Scope:** Phase 1 — ATMS Core Operational Maintenance (18 working days)

> **Revision 2 (2026-07-02):** Status updates reflecting build + scope decisions.
> - **G-02 (Parts Management UI) — CLOSED.** Full implementation shipped in commit
>   `56bd463` (`PartsView` + `PartDetailView`, composables, seed data, mock removal).
> - **G-01 (Add Asset) + G-04 (`CreateAsset` dropped fields) — DEFERRED TO PHASE 3 /
>   CANCELLED** (pending final call). Decision rationale: data-integrity concerns —
>   with ERP as the likely source of truth for asset reference data (Phase 3 SM
>   work), manual asset creation risks duplicates/drift; the create button is
>   disabled in production so G-04's dropped lifecycle fields have no live impact.
> - **Phase reorganisation:** SM decoupled into **Phase 3**. Phase 2 = AM movement +
>   Asset Assembly + Component PM cross-check + ERP parts write-back + Asset tag QR.
>   See `.kilo/TLD.md` for the full Phase 2/3 tables.

> **Revision note:** This report was rewritten after a line-by-line re-verification of
> both the frontend (`frontend/src/`) and backend (`backend/app/`) codebases. The first
> draft overstated completion status for several areas. The corrections below reflect
> what the code actually contains, including a number of full-page **stub views** and
> one backend action that silently drops validated fields.

---

## Table of Contents

1. [Executive Summary](#1-executive-summary)
2. [Methodology](#2-methodology)
3. [Phase 1 Scope Checklist (Revised)](#3-phase-1-scope-checklist-revised)
4. [Critical Gaps — Frontend Stub Views](#4-critical-gaps--frontend-stub-views)
5. [Critical Gaps — Functional Defects](#5-critical-gaps--functional-defects)
6. [Scope Boundary Issues](#6-scope-boundary-issues)
7. [Integration & External Dependency Gaps](#7-integration--external-dependency-gaps)
8. [Access Control Verification](#8-access-control-verification)
9. [Minor Gaps & Deferred UI](#9-minor-gaps--deferred-ui)
10. [Test Coverage Gaps](#10-test-coverage-gaps)
11. [Infrastructure & DevOps Gaps](#11-infrastructure--devops-gaps)
12. [Prioritised Action Plan](#12-prioritised-action-plan)
13. [Risk Register](#13-risk-register)

---

## 1. Executive Summary

A thorough re-verification of the codebase revealed that the first version of this
report was **over-optimistic**. While the backend is largely complete and
production-quality (zero `TODO`/`FIXME` markers, consistent action-query-controller
architecture), the **frontend contains four full-page stub views** that were
previously marked as "Complete", and several functional defects that affect Phase 1
core workflows.

### Severity-ranked gap summary

| # | Gap | Severity | Type |
|---|-----|----------|------|
| **G-01** | Asset Creation UI is disabled — **DEFERRED TO PHASE 3 / CANCELLED** (data-integrity decision, 2026-07-02) | ⏸ Deferred | Decision |
| **G-02** | ~~Parts Management is a stub~~ — **CLOSED (2026-07-02, `56bd463`)** | ✅ Done | Frontend |
| **G-03** | **Location picker empty for non-Admins** — Manager/Logistics get no locations (blocks their core job) | **Critical** | Frontend |
| **G-04** | ~~`CreateAsset` action drops lifecycle fields~~ — **DEFERRED TO PHASE 3 / CANCELLED** (create button disabled in prod; moot until manual create decision, 2026-07-02) | ⏸ Deferred | Backend |
| **G-05** | **System Settings is a stub** — `SystemSettingsView` shows "coming soon" | **Medium** | Frontend |
| **G-06** | **Audit Logs viewer is a stub** — `AuditLogsView` shows "coming soon" | **Medium** | Frontend |
| **G-07** | **Parts sync blocked** — ERP team has not provided the BC parts API page name | **High** | External |
| **G-08** | SharePoint employee import button disabled (no handler) | Low | Frontend |
| **G-09** | Effective Date field disabled in location update sheet | Low | Frontend |
| **G-10** | `sinceLastService` hardcoded to `null` on WO detail | Low | Frontend |
| **G-11** | Dashboard missing "Recently updated assets" widget | Low | Frontend |
| **G-12** | Resend activation email not implemented | Low | Frontend |

**Estimated effort to close all Phase 1 gaps: ~2–4 working days** (excluding the
external ERP dependency). G-02 is now closed. G-01 and G-04 are deferred to Phase 3 /
cancelled (data-integrity decision), removing them from the Phase 1 critical path.
The one remaining Critical code gap is **G-03** (location picker for non-Admins, ~0.25d).

---

## 2. Methodology

This revision was produced by an exhaustive scan of:

- **Frontend:** every `.vue` view (31), every domain component (15), every composable
  (16), every lib file (10), both stores. Each file was read in full and checked for
  stubs, placeholders, `TODO`/`FIXME`, disabled elements, and empty bodies.
- **Backend:** every controller (25), all admin controllers (10), both jobs, all key
  services (8), sampled actions across every `Actions/` subdirectory (42 total), the
  full `routes/api.php`, all 40 migrations, and all 17 policies. Checked for `TODO`,
  `dump`/`dd`, empty methods, and dropped fields.

Key finding: the backend has **zero** `TODO`/`FIXME`/`HACK` markers and is internally
consistent. The gaps cluster in the **frontend** (stub views) and in a small number of
**backend actions that silently drop validated input**.

---

## 3. Phase 1 Scope Checklist (Revised)

Each proposal scope item is reassessed against the actual code.

### 3.1 Asset Registry — ⚠️ PARTIALLY COMPLETE (was: Complete)

| Aspect | Backend | Frontend | Status |
|--------|---------|----------|--------|
| Asset list (search/filter/sort) | `AssetController::index` ✓ | `AssetsView` ✓ | ✅ |
| Asset detail view | `AssetController::show` ✓ | `AssetDetailView` ✓ | ✅ |
| **Asset CREATE** | `AssetController::store` + `CreateAsset` action ✓ | "Add Asset" button `disabled` — **deferred to Phase 3 / cancelled (2026-07-02, data-integrity decision)** (`AssetsView.vue:81`) | ⏸ **G-01 (deferred)** |
| Asset UPDATE | `AssetController::update` + `UpdateAssetFields` ✓ | Edit sheet on detail ✓ | ✅ |
| Soft-deactivate | `is_active` toggle via update ✓ | Edit sheet ✓ | ✅ |
| Asset tags | `AssetTagService`, unique constraint ✓ | Suggestion + display ✓ | ✅ |
| Maintenance status | Enums + casts ✓ | Displayed ✓ | ✅ |
| Operational status | Enum ✓ | Displayed + editable ✓ | ✅ |
| Usage readings | `AssetMeterReadingController` ✓ | Meter readings tab ✓ | ✅ |
| Attachments | Polymorphic ✓ | Upload/list/download ✓ | ✅ |
| Maintenance history | `GET maintenance-history` ✓ | History tab ✓ | ✅ |
| **`CreateAsset` field persistence** | **Drops `asset_kind`, `maintenance_status`, `maintenance_sub_status`, `fa_subclass_code`** (`CreateAsset.php:16-27`) | N/A | ⏸ **G-04 (deferred to Ph3/cancel)** |

**Verdict:** The asset registry is viewable and editable. Manual asset creation
(G-01) and its `CreateAsset` field-persistence defect (G-04) have been **deferred to
Phase 3 / cancelled** (2026-07-02) on data-integrity grounds — the create button is
disabled in production so neither has live impact, and ERP-as-source-of-truth (Phase 3
SM work) is the likely end state. Lifecycle fields remain settable via the **update**
flow (`UpdateAssetFields`), which honours them correctly.

---

### 3.2 Asset Tag — ✅ COMPLETE

All tag requirements are met: `L-BBB-CCC-XXXX` format generation (`AssetTagService`),
immutability guard, uniqueness constraint, `FaSubclassTypeCode` mapping table CRUD,
tag suggestion endpoint, and tag-based lookup. No gaps.

---

### 3.3 Preventive Maintenance — ✅ COMPLETE

PM templates (M:N model), asset assignments, scheduled + manual evaluation, auto-MR
generation, suppression on reject/cancel, cumulative L1–L4 reset, multi-level batch
creation — all implemented backend and frontend.

The Maintenance Manager's PM workflow is **complete and not a gap**. The Manager
manages all assignments from the **Asset Detail → PM Rules section**
(`AssetPmSection.vue`), which provides template assignment (picker listing all active
templates), evaluation, deactivation, reactivation, and deep-links to individual
template detail pages (`/admin/pm-rules/:ruleId`, route guard
`requiresAdminOrManager`). Template **creation and editing** is Admin-only by design
(`PmRulePolicy`), which is the correct scope boundary. (See §8 for details and the
RBAC.md correction note.)

---

### 3.4 Corrective Maintenance — ✅ COMPLETE

Create MR with optional meter reading, edit pending MR, cancel own MR, role-restricted
lists, attachments on MRs — all implemented.

---

### 3.5 Maintenance Approval — ✅ COMPLETE

Approve (atomic WO creation), reject (with PM suppression), cancel, role gating,
audit trail — all implemented.

---

### 3.6 Work Orders — ✅ COMPLETE (minor cosmetic gap)

Full lifecycle (assign → start → execute → complete → close → cancel), parts
management, attachments, meter readings, asset-status-via-WO, PM baseline update on
close — all implemented.

Minor: the `sinceLastService` computed on the WO detail is hardcoded to `null`
(`useWorkOrderDetail.ts:110-111`) — the "X / Y since last service" indicator does not
display. See **G-10**.

---

### 3.7 Parts Reference — ✅ COMPLETE (was: Stub) — CLOSED 2026-07-02

| Aspect | Backend | Frontend | Status |
|--------|---------|----------|--------|
| Parts list (search/filter/sort) | `PartController::index` ✓ | `PartsView.vue` — full `AppDataTable` + live category filter ✓ | ✅ |
| Part detail | `PartController::show` ✓ | `PartDetailView.vue` — overview + ERP reference rail (Admin/Mgr) ✓ | ✅ |
| Part update | `PartController::update` ✓ | Detail view (local-field editing wired) | ✅ |
| ERP-sourced fields | Read-only ✓ | Displayed in ERP Reference rail | ✅ |
| Attachments on parts | Endpoints exist ✓ | Upload + per-attachment delete on Part detail ✓ | ✅ |

**Verdict:** ✅ **G-02 CLOSED (2026-07-02, commit `56bd463`).** The two stub views
were replaced with full implementations plus `useParts`/`usePartDetail`/`usePartSearch`
composables, `partColumns`, and `PartCombobox`. The mock parts catalogue was removed;
the WO parts-used picker now reads live `GET /parts`. Backend `PartSeeder` (55 O&G
parts) provides seed data until the ERP parts sync (G-07) lands.

---

### 3.8 Parts Used on Work Orders — ✅ COMPLETE

Add/remove parts on non-terminal WOs, quantity tracking, notes, role gating — all
implemented in both backend and frontend (on the WO detail page). The parts picker
now reads live `GET /parts` (mock removed, G-02). Until G-07 (ERP parts sync) is
resolved, the catalogue runs on the `PartSeeder` seed set (55 O&G parts) — functional
for UAT, awaiting real ERP data.

---

### 3.9 Simple Location Update — 🔴 PARTIALLY COMPLETE (was: Complete)

| Aspect | Backend | Frontend | Status |
|--------|---------|----------|--------|
| Direct location update endpoint | `POST /api/assets/{asset}/location` ✓ | `UpdateLocationSheet` ✓ | ✅ |
| Active-locations read endpoint | `GET /api/locations` ✓ | — | ✅ |
| Location history auto-creation | `AssetLocationHistory` ✓ | Timeline display ✓ | ✅ |
| **Location picker for Manager/Logistics** | — | **`useLocations.ts:24-26` only loads for Admins; Manager/Logistics get an empty list** | 🔴 **G-03** |
| Location CRUD (Admin) | `GET/POST/PATCH /api/admin/locations` ✓ | `ManageLocationsView` ✓ | ✅ |
| Effective Date field | Backend ignores (hardcodes `now()`) | **Field is `disabled`** (`UpdateLocationSheet.vue:191`) | 🟡 G-09 |

**Verdict:** The location update workflow has a **critical defect**: the very roles
that need to update locations (Manager and Logistics) get an **empty location picker**,
making the feature unusable for them. The composable has a `TODO` comment acknowledging
this. The `GET /api/locations` read-only endpoint exists in the backend but the
frontend composable is not calling it.

---

### 3.10 Location History — ✅ COMPLETE

---

### 3.11 Role-Based Access — ✅ COMPLETE

All five human roles implemented with 17 policies, role-scoped queries, field-level
visibility, and frontend route guards. The Maintenance Manager's PM workflow is
complete through Asset Detail (see §3.3 and §8). The legacy Viewer role is merged into
Requester. M2M API token auth with `EnsureTokenAbilities` is in place.

---

### 3.12 Dashboard and Reporting — ⚠️ PARTIALLY COMPLETE

| Aspect | Status |
|--------|--------|
| Dashboard API (role-adaptive) | ✅ |
| Pending MRs / Open WOs / Overdue PMs / Recently Closed WOs widgets | ✅ |
| **Recently updated assets widget** | 🔴 **G-11** (missing per screen inventory) |
| Separate report screens | ⚠️ (dashboard likely satisfies "simple reports") |

---

### 3.13 Attachments — ✅ COMPLETE

Polymorphic attachments for all four parent types, validation, soft-delete,
authorized downloads — all implemented. Part attachments now have a frontend UI on
`PartDetailView` (G-02 closed).

---

## 4. Critical Gaps — Frontend Stub Views

Four full-page views are placeholders ("coming soon"). The backend for all four is
fully implemented; only the frontend is missing.

### 4.1 G-01: Asset Creation UI — ⏸ DEFERRED TO PHASE 3 / CANCELLED (2026-07-02)

**Status:** ⏸ **Deferred to Phase 3 or cancelled** (data-integrity decision, 2026-07-02).
**Files:** `frontend/src/views/assets/AssetsView.vue:80-83`
**Backend:** `POST /api/assets` + `CreateAsset` action are fully implemented and remain
available; only the **frontend button is disabled**.

```vue
<!-- Add Asset is scoped to Phase 2 create flow; placeholder until built -->
<Button v-if="auth.isAdminOrManager" disabled aria-label="Add asset — coming soon">
  Add Asset
</Button>
```

**Decision (2026-07-02):** Manual asset creation is deferred to Phase 3 / cancelled.
The reasoning is **data integrity**: with ERP as the likely source of truth for asset
reference data (re-evaluated during Phase 3 SM work), a manual create path risks
duplicates and data drift. The create button remains disabled in production, so there
is no live impact either way. The related `CreateAsset` field-persistence defect
(G-04) is deferred/cancelled under the same decision.

#### Background — the original Path A vs Path B question

The original decision hinged on: **Is ERP the source of truth for assets, or is ATMS?**

**Path A — LDC confirms ERP as source of truth:** Remove the button; build an ERP
Asset Sync mirroring the Parts Sync pattern (`LdcErpHttpSource`, scheduled + on-demand
trigger, ERP-owned columns overwritten on sync). The ERP `fixedAssestAPI` page is
already confirmed (429 assets, 24 fields — see `ERP_SYNC.md`).

**Path B — LDC wants manual asset management in ATMS (current product-doc position):**
Build the "Add Asset" sheet that calls `POST /api/assets` + `POST /api/assets/suggest-tag`.

> **Note:** The current product docs (`IN_SCOPE.md` §1, `PRD.md`, `ERP_SYNC.md`) assert
> Path B. The 2026-07-02 deferral leans toward Path A's eventual adoption, but a final
> confirm/cancel call is still pending. If Path A is confirmed, the three docs above
> must be updated to reflect ERP as the source of truth. Tracked in
> `docs/05-delivery/TDL.md`.

---

### 4.2 G-02: Parts Management UI — ✅ CLOSED (2026-07-02)

**Status:** ✅ **Resolved.** Committed in `56bd463`.
**Files:**
- `frontend/src/views/parts/PartsView.vue` — full `AppDataTable` implementation (was 19-line stub).
- `frontend/src/views/parts/PartDetailView.vue` — overview card + ERP reference rail + attachments (was 14-line stub).

**Also shipped:** `useParts`/`usePartDetail`/`usePartSearch` composables, `partColumns`,
`PartCombobox`, `displayHelpers` additions, `style.css` additions. `__mockParts.ts`
removed; WO parts-used picker reads live `GET /parts`. Backend `PartSeeder` (55 O&G
parts across 11 categories) + seeder tests; `erp_part_id`/`erp_raw_data` left NULL so
`SyncErpPartsJob` overwrites cleanly when the ERP endpoint lands.

See `.kilo/plans/1783038000000-parts-management-frontend.md`.

---

### 4.3 G-03: Location Picker Empty for Non-Admins

**Severity:** Critical
**File:** `frontend/src/composables/useLocations.ts:24-29`

```ts
// TODO: Switch to GET /api/locations once a non-admin endpoint is provided.
// Until then, Admins see the full list; Manager/Logistics see empty.
if (auth.isAdmin) {
  const res = await api.get<{ data: Location[] }>('/admin/locations')
  locations.value = res.data ?? []
}
```

The composable only loads locations when `auth.isAdmin`. Manager and Logistics users
get an **empty list**, which breaks:
- The location picker in `UpdateLocationSheet` (the core tool for Logistics/Manager).
- The location filter bar on the Assets list page (for Managers).

The backend **already has** `GET /api/locations` (returns active locations, accessible
to Admin/Manager/Logistics per `LocationPolicy`). The frontend just isn't calling it.

**Required:** Update `useLocations.ts` to call `GET /api/locations` for non-Admin
roles (or unconditionally, since that endpoint returns active-only and is
policy-gated).

---

## 5. Critical Gaps — Functional Defects

### 5.1 G-04: `CreateAsset` Action Drops Lifecycle Fields — ⏸ DEFERRED TO PHASE 3 / CANCELLED (2026-07-02)

**Status:** ⏸ **Deferred to Phase 3 / cancelled** under the same decision as G-01.
**File:** `backend/app/Actions/Assets/CreateAsset.php:16-27`

The controller (`AssetController::store`) validates and permission-gates
`maintenance_status`, `maintenance_sub_status`, `asset_kind`, and `fa_subclass_code`,
then passes `$validated` to `CreateAsset::execute()`. But the action only persists:

```php
$asset = Asset::create([
    'erp_asset_code', 'name', 'description', 'category', 'serial_number',
    'model', 'manufacturer', 'operational_status', 'current_location_id', 'is_active',
]);
```

The fields `asset_kind`, `maintenance_status`, `maintenance_sub_status`, and
`fa_subclass_code` are **silently dropped**.

**Decision (2026-07-02):** Deferred/cancelled with G-01. Because manual asset creation
is deferred to Phase 3 / cancelled (create button disabled in production), this defect
has **no live impact**. The `update()` flow (via `UpdateAssetFields`) honours these
fields correctly, so lifecycle fields remain settable post-create. If/when manual
create is revived in Phase 3, this fix should be applied first.

---

## 6. Scope Boundary Issues

### 6.1 Asset Assembly — NOT Implemented (Backend Absent)

> **Correction:** The first draft of this report stated assembly endpoints were
> "Implemented" in the backend. **This was incorrect.** They do not exist.

**Reality:** There are **no** assembly routes, no controller methods, no actions, no
policies, and no `asset_assembly_history` table. The only assembly infrastructure is:
- A `parent_asset_id` nullable FK column (migration `2026_06_25_124531`).
- `parentAsset()` / `childAssets()` model relations on `Asset`.
- Read-only `parent_asset_id` / `child_assets_count` on `AssetResource`.

This is **correct for Phase 1** — Asset Assembly is explicitly out of scope. The
frontend honestly shows a "Coming in Phase 2" placeholder
(`AssetsView.vue:168-184`). No action is required for Phase 1; the endpoints should
simply be built in Phase 2.

The asset create/edit sheets do expose `asset_kind` / `parent_asset_id` /
`maintenance_sub_status` fields, but with assembly unimplemented, these are inert.
Harmless in Phase 1.

---

### 6.2 "Part Request" Tab (SM Subsystem Link)

Not implemented (no SM subsystem). Not a Phase 1 gap. `PartsView` (now fully built,
G-02 closed) does not render a `part-request` tab — that belongs to the future SM
subsystem (Phase 3).

---

## 7. Integration & External Dependency Gaps

### 7.1 G-07: Parts Sync Blocked on ERP API Page Name

**Severity:** High (external)
**Tracked in:** `docs/05-delivery/TDL.md` §1–2

All parts sync infrastructure is built and complete (no `TODO` markers in the backend):
`SyncErpPartsJob`, `SyncParts` action, `LdcErpHttpSource` adapter (OAuth token
exchange, caching, 401 retry), `ErpSyncJob`/`ErpSyncError` tracking, manual trigger,
ERP Sync History UI (`ErpSyncView.vue` — note this is an orphaned stub; see §9.3).

**What is missing:** The Business Central custom API page name for parts
(`LDC_ERP_PARTS_API`). Without it, `LdcErpHttpSource::getParts()` gracefully skips.

**Impact:** Parts catalogue lacks real ERP data. The G-02 UI is built and runs on the
`PartSeeder` seed set (55 parts), so the workflow is functional end-to-end for UAT —
but real ERP parts won't appear until the sync endpoint lands.

---

## 8. Access Control Verification

### 8.1 Maintenance Manager PM Workflow — Confirmed Complete (Not a Gap)

The `docs/03-backend/RBAC.md` "Known gap" note about Manager access to PM templates is
**outdated**. On code review, the Manager's PM workflow is fully functional through the
**Asset Detail → PM Rules section**:

| Manager Action | Where | Evidence |
|---|---|---|
| View assigned templates | `AssetPmSection.vue` | `:can-manage="auth.isAdminOrManager"` |
| Assign template | "Assign Rule" dialog | `loadActiveTemplates()` populates picker |
| Evaluate assignment | Per-row button | `POST evaluate` endpoint |
| Deactivate / Reactivate | Toggle button | deactivate/reactivate endpoints |
| View template details | Template name link | `RouterLink` → `/admin/pm-rules/:ruleId` (`requiresAdminOrManager`) |

Template **creation/editing/deactivation** is Admin-only by design (`PmRulePolicy`),
which is the correct scope boundary — the Manager assigns existing templates, they do
not author them.

**Action:** The "Known gap" section in `RBAC.md` has been corrected in this revision
(see doc update).

---

## 9. Minor Gaps & Deferred UI

### 9.1 G-05: System Settings UI Stub

**File:** `frontend/src/views/admin/SystemSettingsView.vue` — "System settings coming soon."
**Backend:** `CompanySettingController` (show/update) — fully implemented.

The Settings sidebar item → System tab shows a placeholder. ERP sync controls, company
timezone, and Power Automate config are not exposed in the UI. **Medium** priority.

---

### 9.2 G-06: Audit Logs UI Stub

**File:** `frontend/src/views/admin/AuditLogsView.vue` — "Audit log viewer coming soon."
**Backend:** `AuditLogController::index` (filtered, cursor-paginated) — fully implemented.

The Settings sidebar → Audit Logs tab shows a placeholder. **Medium** priority.

---

### 9.3 Orphaned Stub Views (Dead Code)

These five views exist but are **not wired into the router** (zero imports found). They
are abandoned scaffold files superseded by functional views. Safe to delete:

- `frontend/src/views/admin/EmployeesView.vue`
- `frontend/src/views/admin/MasterDataView.vue`
- `frontend/src/views/admin/ErpSyncView.vue`
- `frontend/src/views/admin/CompanySettingsView.vue`
- `frontend/src/views/admin/LocationsView.vue`

---

### 9.4 G-08: SharePoint Employee Import Button Disabled

**File:** `frontend/src/views/admin/UsersView.vue:187-189`

The "Import from SharePoint" button is permanently `disabled` with no `@click`. The
backend (`EmployeeController::import`) supports CSV-based import. The button should
either be wired to the import flow or removed/relabeled if CSV is the only supported
source. **Low** priority.

---

### 9.5 G-09: Effective Date Field Disabled in Location Update

**File:** `frontend/src/components/locations/UpdateLocationSheet.vue:191`

The "Effective Date" `<Input>` is `disabled` and never sent in the `doSave()` payload.
The backend hardcodes `effective_at = now()`. Either implement custom effective dates
or remove the field to avoid confusion. **Low** priority.

---

### 9.6 G-10: `sinceLastService` Hardcoded to Null

**File:** `frontend/src/composables/useWorkOrderDetail.ts:110-111`

```ts
// reading-triggered PM rule. Left as a placeholder for the initial build.
const sinceLastService = computed<...>(() => null)
```

The "X / Y since last service" indicator on WO detail never displays. **Low** priority.

---

### 9.7 G-11: Dashboard Missing "Recently Updated Assets" Widget

**Source:** `SCREEN_INVENTORY.md` §1 lists "Recently updated assets" as a required dashboard element.
Neither the `DashboardController` nor `DashboardView` includes it. **Low** priority.

---

### 9.8 G-12: Resend Activation Email Not Implemented

**File:** `frontend/src/components/admin/ProvisionUserDialog.vue:74-75`

When a user's activation link expires, there is no resend option. **Low** priority.

---

## 10. Test Coverage Gaps

The backend has 40 feature test files. Coverage is strong for PM, WO lifecycle, auth,
attachments, dashboard, and ERP sync. Identified gaps:

| # | Missing Test | Priority |
|---|-------------|----------|
| T-01 | Asset CREATE via API (and verify G-04 field persistence) — **deferred with G-04 to Phase 3** (create disabled in prod) | ⏸ Deferred |
| T-02 | Part CRUD workflow (create/update, role gating) | Medium |
| T-03 | Parts used on WO lifecycle (add/remove edge cases) | Medium |
| T-04 | Manager can reach `/admin/pm-rules/:ruleId` and view (not edit) | Medium |
| T-05 | Location CRUD via admin endpoints | Low |
| T-06 | Master data CRUD across all group keys | Low |
| T-07 | Dashboard role scoping (Manager sees all; Logistics limited) | Low |

---

## 11. Infrastructure & DevOps Gaps

| ID | Gap | Severity |
|----|-----|----------|
| I-01 | Production Docker Compose needs verification (services, volumes, env) | Medium |
| I-02 | Backup/restore procedures need end-to-end verification | Medium |
| I-03 | Power Automate email transport needs production configuration (LDC IT) | Medium |
| I-04 | SSL/domain configuration pending (LDC IT) | Medium |
| I-05 | SharePoint transport throws `RuntimeException` if selected (AppServiceProvider:40) | Low |

---

## 12. Prioritised Action Plan

### Immediate — Blocking Phase 1 Delivery

| Priority | ID | Action | Effort |
|----------|----|--------|--------|
| 🔴 P0 | G-03 | Fix `useLocations.ts` to call `GET /api/locations` for non-Admin roles | 0.25d |
| ✅ Done | G-02 | ~~Build `PartsView` + `PartDetailView`~~ — CLOSED 2026-07-02 (`56bd463`) | — |
| ⏸ Deferred | G-04 | ~~Fix `CreateAsset::execute()` dropped lifecycle fields~~ — deferred to Phase 3 / cancelled (no live impact; create disabled) | — |

### Deferred / Cancelled — G-01 Manual Asset Creation (2026-07-02)

| Priority | ID | Action | Effort |
|----------|----|--------|--------|
| ⏸ Deferred | G-01 | **Manual asset creation deferred to Phase 3 / cancelled** (data-integrity concerns — ERP likely source of truth in Phase 3). Create button stays disabled. Final confirm/cancel call pending. | 0d (decision) |
| — | — | If revived (Phase 3): fix G-04 first, then build "Add Asset" sheet OR ERP asset sync per Path A/B (see §4.1) | ~1–2d |

### Short-Term — Before Production Deployment

| Priority | ID | Action | Effort |
|----------|----|--------|--------|
| 🟡 P1 | G-05 | Build `SystemSettingsView` (timezone + ERP sync controls) | 0.5d |
| 🟡 P1 | G-06 | Build `AuditLogsView` (filtered table) | 0.5d |
| 🟡 P1 | G-07 | Implement + test parts sync once ERP team provides page name | 1.5d |
| 🟡 P1 | I-01–I-04 | Production config (Docker, backup, Power Automate, SSL) | 2.5d |
| 🟢 P2 | — | Delete 5 orphaned stub views (dead code cleanup) | 0.1d |

### Lower Priority — Can Lag

| Priority | ID | Action | Effort |
|----------|----|--------|--------|
| 🟢 P2 | G-08 | Wire SharePoint import button or relabel | 0.25d |
| 🟢 P2 | G-09 | Implement or remove Effective Date field | 0.25d |
| 🟢 P2 | G-10 | Implement `sinceLastService` or remove the placeholder | 0.25d |
| 🟢 P2 | G-11 | Add "Recently updated assets" dashboard widget | 0.5d |
| 🟢 P2 | G-12 | Add resend-activation-email capability | 0.25d |
| 🟢 P2 | T-02–T-07 | Remaining test coverage | 2.0d |
| 🟢 P2 | — | Update `RBAC.md` "known gap" note (Manager PM access) | 0.1d |

---

## 13. Risk Register

| # | Risk | Likelihood | Impact | Mitigation |
|---|------|-----------|--------|------------|
| R-01 | ERP team delays parts API page name beyond Phase 1 | Medium | High — parts catalogue empty; G-02 UI is built but seeded, awaiting real ERP data | Seed parts manually for UAT (PartSeeder shipped); communicate blocker |
| R-02 | Asset-creation strategy undecided at go-live (G-01) | Low | Low — **deferred to Phase 3 / cancelled (2026-07-02)**; create button disabled, no live impact | Confirm Path A/B (or full cancel) when Phase 3 SM work begins (see §4.1) |
| R-03 | Logistics/Manager cannot update locations (G-03 empty picker) | **Certain** (current state) | High — core Logistics job blocked | Fix `useLocations.ts` (G-03) |
| R-04 | Newly created assets silently lose lifecycle fields (G-04) | None (current state) | None — **deferred to Phase 3 / cancelled**; create button disabled so the code path is unreachable in prod | Apply fix if/when manual create is revived in Phase 3 |
| R-05 | Scope creep: client requests Phase 2/3 features during UAT | Medium | Medium | Maintain scope boundary document; change control |
| R-06 | Production Power Automate config delayed by LDC IT | Medium | Medium | SMTP fallback |

---

## Appendix A: What Is Actually Complete and Working

To balance the gap list, the following Phase 1 capabilities are **fully implemented
and functional** in both backend and frontend:

- Authentication (login, logout, activate, forgot/reset password, rate limiting)
- Dashboard (4 of 5 widgets)
- Corrective Maintenance Requests (create, edit, cancel, list, detail)
- Maintenance Approval workflow (approve → WO, reject with suppression, cancel)
- Work Order full lifecycle (assign, start, execute, complete, close, cancel)
- Parts used on Work Orders (add/remove/quantity/notes)
- Parts Reference catalogue (list, detail, ERP reference rail, attachments — G-02 closed)
- Preventive Maintenance (templates, assignments, evaluation, suppression, L1–L4)
- Asset Tag generation and lookup
- Asset detail view (metadata, readings, history, attachments, PM section)
- Asset editing (operational fields, status, location, lifecycle fields via update)
- Location history
- Attachments (assets, MRs, WOs)
- Role-based access (5 roles + service, 17 policies)
- Admin: Users, Roles, Employees, Master Data, Locations, FA Subclass Codes, API Clients
- Audit logging (backend, 100% of mutating actions)
- M2M API token authentication

---

**Report prepared by:** Inova ATMS Delivery Team
**Revision 2:** 2026-07-02 — G-02 closed; G-01 + G-04 deferred to Phase 3 / cancelled;
SM reorganised into Phase 3.
**Next review:** After the one remaining Critical gap (G-03 location picker) is closed
and the G-01 confirm/cancel decision is finalised ahead of Phase 3.
