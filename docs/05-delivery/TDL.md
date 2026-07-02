# Task Delivery List

> **Date:** 2026-06-24
> **Purpose:** Track items blocked on external dependencies or pending decisions.

## Blocked — ERP Team

### 1. Parts API page name

Parts sync cannot proceed without the BC custom API page name for parts/items.

| Field | Value |
|---|---|
| **Blocked since** | 2026-06-24 |
| **Depends on** | ERP team / LDC |
| **What we need** | The OData V4 API page name — the equivalent of `fixedAssestAPI` but for parts (e.g. `itemsAPI`, `partsAPI`) |
| **Impact if resolved** | `SyncErpPartsJob` can fetch parts; field mapping can be documented; parts catalogue populated in SM |

### 2. Parts field mapping

Cannot map the parts schema until the endpoint returns data.

| Field | Value |
|---|---|
| **Blocked since** | 2026-06-24 |
| **Depends on** | #1 (Parts API page name) |
| **What we need** | Sample response rows showing field names and types for each part |

### 3. `componentOfMainAsset` sample data

BC has `mainAssetComponent` and `componentOfMainAsset` fields that may support the Asset Assembly model natively. We need to see a real example of a component asset (where `componentOfMainAsset` is non-null).

| Field | Value |
|---|---|
| **Blocked since** | 2026-06-24 |
| **Depends on** | ERP team |
| **What we need** | An asset where `componentOfMainAsset` is set to a parent FA number, to confirm the ERP already models parent-child asset relationships |
| **Impact if resolved** | Asset Assembly model can map directly to BC's existing structure |

### 4. ✅ OData pagination behaviour

| Field | Value |
|---|---|
| **Resolved** | 2026-06-24 — No pagination. BC returns all records in one response. Full pull, no cursor logic needed. |

### 5. ✅ Incremental sync support

| Field | Value |
|---|---|
| **Resolved** | 2026-06-24 — Not needed. Pull all records and compare locally. No `$filter` required. |

### 6. ✅ `inactive` / `blocked` semantics

| Field | Value |
|---|---|
| **Resolved** | 2026-06-24 — ERP `inactive`/`blocked` mapped to `erp_status` (informational only). Does **not** control `is_active`. Sync never overwrites ATMS local fields. Field ownership boundary documented in `ERP_SYNC.md`. |

---

## Pending — Internal Decisions

### 7. ✅ Does BC have Store Order / Store Management live?

| Field | Value |
|---|---|
| **Resolved** | 2026-06-25 — VJ confirmed BC has **no** Store Order / Store Management module. Parts issuance flows through **Warehouse Management transactions**. By the documented decision rule, this means **build the SM subsystem as planned**. The "integrate on top of BC Warehouse" alternative was evaluated and declined (high coupling, scope creep, BC Warehouse is the client's execution layer — SM only needs a narrow consumption write-back). |
| **VJ reply** | Appended to [`ERP_STORE_ORDER_QUESTION.md`](../sm/01-product/ERP_STORE_ORDER_QUESTION.md) |
| **Follow-up** | [`ERP_WAREHOUSE_FOLLOWUP.md`](../sm/01-product/ERP_WAREHOUSE_FOLLOWUP.md) — warehouse write-back questions |

> **Decision:** Build SM as a focused maintenance-consumption module. BC
> Warehouse remains the inventory source of truth; SM posts a consumption
> write-back at Goods Receipt (see #8).

---

### 8. Parts read URL + QTY update on consumption 🔴

Two things from VJ, framed as outcomes (not BC internals — VJ owns the "how"):

1. **Read URL** for all M&S, Consumables, and Parts from BC (same Entra ID
   token as fixed assets).
2. **QTY update on consumption** — when a part is consumed in SM/ATMS, can the
   part's quantity in BC be decremented? If yes, what handoff does VJ need
   from us (API call, record/file, or ERP-side setup)? We will provide part
   code, qty consumed, date, and reference.

| Field | Value |
|---|---|
| **Blocked since** | 2026-06-25 |
| **Depends on** | VJ (ERP Consultant) |
| **What we need** | (1) Parts/consumables/M&S read URL; (2) confirmation that QTY can be updated in BC on consumption + the handoff format VJ requires from us. |
| **Phase** | Phase 2 (SM build). Phase 1 parts reference is read-only and unaffected. |
| **Impact if QTY update possible** | SM consumption flows straight into BC; ERP inventory stays accurate. |
| **Impact if QTY update not possible** | SM maintains its own balances; reconciliation with BC becomes manual/periodic. |
| **Message** | [`ERP_WAREHOUSE_FOLLOWUP.md`](../sm/01-product/ERP_WAREHOUSE_FOLLOWUP.md) |

---

### 8. ✅ Virtual Store — design questions resolved

| Field | Value |
|---|---|
| **Resolved** | 2026-06-24 — Q1: One Virtual Store per workshop (LDC has one). Q2: Manager approves per part/line item. Q3: System auto-flags at end of day. Q4: Covered by auto-flagging on SM dashboard. |
| **Spec** | [`docs/sm/01-product/VIRTUAL_STORE.md`](../sm/01-product/VIRTUAL_STORE.md) |

---

### 9. ✅ `asset_tag` field

| **Resolved** | 2026-06-24 — Format `L-BBB-CCC-XXXX`. Ownership (L/X), type code (3-char from faSubclassCode), size code (encoded inch measurement or 000), serial suffix (last 4 of serialNo). Manual generation, immutable after save, unique constraint. See [`ASSET_TAG.md`](../atms/01-product/ASSET_TAG.md). |

---

---

### 10. Is ERP the source of truth for assets? 🔴

**Status:** Pending LDC decision. Blocks the G-01 asset-creation strategy
(`PHASE_1_GAP_ANALYSIS.md` §4.1).

**The question for LDC:** Is ERP (Dynamics 365 BC) the source of truth for asset
**reference data**, or is ATMS?

**Path A — ERP is the source of truth for assets (preferred):**
- Remove the disabled "Add Asset" button entirely.
- Build an **ERP Asset Sync** mirroring the existing Parts Sync pattern:
  scheduled (daily) + manual on-demand trigger (`POST /api/admin/erp/sync-assets`).
- Same `ErpSource` contract, same token-exchange + OData fetch flow proven for parts.
- ERP-owned columns overwritten on every sync; ATMS operational fields never touched.
- The BC asset page (`fixedAssestAPI`, 429 assets, 24 fields) is **already confirmed**
  — unlike parts, the asset endpoint page name is known. Token auth is already working.
- Update `IN_SCOPE.md` §1, `PRD.md`, and `ERP_SYNC.md` — all currently assert Path B
  (_"Assets are managed fully within ATMS; there is no ERP asset source"_).

**Path B — ATMS is the source of truth for assets (current documented position):**
- Build the "Add Asset" UI sheet (Admin/Manager create assets manually).
- Matches current `IN_SCOPE.md`, `PRD.md`, and `ERP_SYNC.md`.

| Field | Value |
|---|---|
| **Raised** | 2026-06-27 |
| **Depends on** | LDC decision |
| **What we need** | Confirm Path A (ERP source of truth → build ERP asset sync, remove create UI) or Path B (manual → build Add Asset UI). |
| **Impact of Path A** | No manual asset creation in ATMS; assets arrive via daily/on-demand ERP sync. Cleaner data ownership. ~2 days to build the sync. |
| **Impact of Path B** | Assets created manually in ATMS by Admin/Manager; no ERP asset sync. ~1 day to build the create UI. |
| **Doc impact (Path A)** | `IN_SCOPE.md`, `PRD.md`, `ERP_SYNC.md` must be updated to reflect ERP as the source of truth for asset reference data. |

---

## Phase 1 Code Gaps — Discovered 2026-06-27 (Gap Analysis Re-verification)

> Full details in [`docs/PHASE_1_GAP_ANALYSIS.md`](../PHASE_1_GAP_ANALYSIS.md).

### Internal — Frontend Stubs & Defects

| # | Gap | Severity | File | Effort |
|---|-----|----------|------|--------|
| G-01 | "Add Asset" button disabled — **intentional, pending LDC decision** (Path A: ERP sync vs Path B: manual create). See decision #10 above. | ⏸ Decision | `AssetsView.vue:81` | Path A ~2.0d / Path B ~1.0d |
| G-02 | Parts Management UI is a stub (list + detail) | 🔴 Critical | `PartsView.vue`, `PartDetailView.vue` | 1.5d |
| G-03 | Location picker empty for Manager/Logistics | 🔴 Critical | `useLocations.ts:24-26` | 0.25d |
| G-05 | System Settings UI stub | 🟡 Medium | `SystemSettingsView.vue` | 0.5d |
| G-06 | Audit Logs viewer stub | 🟡 Medium | `AuditLogsView.vue` | 0.5d |
| G-08 | SharePoint import button disabled | 🟢 Low | `UsersView.vue:187-189` | 0.25d |
| G-09 | Effective Date field disabled in location update | 🟢 Low | `UpdateLocationSheet.vue:191` | 0.25d |
| G-10 | `sinceLastService` hardcoded null on WO detail | 🟢 Low | `useWorkOrderDetail.ts:110-111` | 0.25d |
| G-11 | Dashboard missing "Recently updated assets" widget | 🟢 Low | `DashboardController` / `DashboardView` | 0.5d |
| G-12 | Resend activation email not implemented | 🟢 Low | `ProvisionUserDialog.vue:74-75` | 0.25d |
| G-13 | Lists & Dropdowns — 6/8 groups decorative no-ops; priority hardcoded in 4 spots (MR create/edit, MR/WO filters) + backend `in:` rule; `master_data_items` table empty & unread by app runtime. Backend + frontend fix implemented (uncommitted). See `.kilo/plans/1783001396791-admin-lists-dropdowns-cleanup.md`. | 🔴 High | `useLists.ts`, `mrColumns.ts`, `woColumns.ts`, `WorkOrdersView.vue`, `MaintenanceRequestDetailView.vue` | — |

### Internal — Backend Defect

| # | Gap | Severity | File | Effort |
|---|-----|----------|------|--------|
| G-04 | `CreateAsset` action drops `asset_kind`, `maintenance_status`, `maintenance_sub_status`, `fa_subclass_code` (validated + permission-gated in controller but never persisted) | 🔴 High | `CreateAsset.php:16-27` | 0.25d |

### Internal — Dead Code

| # | Gap | Files |
|---|-----|-------|
| — | 5 orphaned stub views not wired into router (safe to delete) | `EmployeesView.vue`, `MasterDataView.vue`, `ErpSyncView.vue`, `CompanySettingsView.vue`, `admin/LocationsView.vue` |

### Doc Correction

| # | Item |
|---|------|
| — | `RBAC.md` "Known gap — Manager PM template access" — **resolved**. Manager PM workflow is complete via Asset Detail → PM Rules section. Template create/edit is Admin-only by design. |

---

## Resolved

| # | Item | Date |
|---|---|---|
| — | Token auth working (Entra ID → BC) | 2026-06-24 ✅ |
| — | BC Store Order question — VJ confirmed no Store Order module; SM to be built as planned | 2026-06-25 ✅ |
| — | Fixed assets endpoint confirmed (`fixedAssestAPI`, 429 assets, 24 fields) | 2026-06-24 ✅ |
| — | Asset Assembly model: Q1–Q5 all decided | 2026-06-24 ✅ |
| — | Mock ERP deprecated, config/infra cleaned | 2026-06-24 ✅ |
| — | Documentation restructure (3 subsystems, 5 roles) | 2026-06-24 ✅ |
| — | LDC Meeting Parts Write-Back document | 2026-06-24 ✅ |
