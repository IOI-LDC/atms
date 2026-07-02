# Management of Change — Documentation Restructure & Scope Update

**Date:** 2026-06-24
**MOC ID:** MOC-2026-06-24-DOCS
**Status:** Implemented (docs only; backend code to follow)
**Author:** AI-assisted refactoring

## 1. Summary of Change

The `docs/` directory was restructured to reflect a **three-subsystem architecture** (ATMS, SM, AM) sharing one Laravel backend and PostgreSQL database. Several scope changes were made: assets are now fully ATMS-managed (no ERP sync), parts are owned by SM, location is owned by AM, the Viewer role is merged into Requester, and Asset Maintenance Status was added. Stale content was purged.

## 2. What Changed

### 2.1 Documentation Structure

**Before:**
```
docs/
├── 01-product/      (ATMS product docs)
├── 02-design/       (ATMS design docs)
├── 03-backend/      (shared backend)
├── 04-frontend/     (ATMS frontend docs)
├── 04-technical/    (ATMS API docs)
├── 05-delivery/
├── 06-prompts/      (deleted — Codex prompts)
├── 07-meetings/     (deleted — client discovery)
├── 08-email-attachments/  (deleted — client files)
├── plans/           (deleted — completed plans)
├── operations/
└── README.md
```

**After:**
```
docs/
├── 00-project-rules/      (shared)
├── 03-backend/            (shared backend docs)
├── 05-delivery/           (shared delivery docs)
├── operations/            (shared ops docs)
├── atms/                  ← moved from 01-product, 02-design, 04-frontend, 04-technical
│   ├── 01-product/
│   ├── 02-design/
│   ├── 04-frontend/
│   └── 04-technical/
├── sm/                    ← new (placeholder)
│   ├── 01-product/
│   ├── 02-design/
│   └── 04-frontend/
├── am/                    ← new (placeholder)
│   ├── 01-product/
│   ├── 02-design/
│   └── 04-frontend/
├── MOC_SCOPE_RESTRUCTURE.md  ← this file
└── README.md              ← rewritten
```

### 2.2 Three Subsystems

| Subsystem | Owns | Future code folder | Docs folder |
|---|---|---|---|
| **ATMS** | Assets, MRs, WOs, PM rules, dashboard, RBAC | `atms/` (rename from `frontend/`) | `docs/atms/` |
| **SM** | Parts catalogue, inventory, stock movement, ERP parts sync, Order → Approval → Dispatch → GR | `sm/` (new) | `docs/sm/` |
| **AM** | Asset location, location history, movement workflow | `am/` (new) | `docs/am/` |

### 2.3 RBAC: 6 Roles → 5 Roles

- **Removed:** Viewer (merged into Requester)
- **Result:** Administrator, Maintenance Manager, Technician, Logistics, Requester
- **Impact:** All users are Requesters at minimum. Requester now has view access to maintenance history, location history, attachments, and mapped ERP fields — capabilities the old Viewer had but the old Requester did not.

### 2.4 Scope Changes

| Change | Before | After |
|---|---|---|
| **Asset sync** | Assets synced from ERP (`SyncErpAssetsJob`, `erp_asset_id`) | Assets managed fully in ATMS. No ERP asset sync. |
| **Parts ownership** | Parts referenced from ERP within ATMS | Parts owned by SM. ERP syncs parts into SM tables. ATMS reads SM parts to populate WO part-request forms. |
| **Location ownership** | ATMS tracked location and location history | AM owns location and movement workflow. ATMS reads current location from AM tables for display only. |
| **Asset Maintenance Status** | Simple active/inactive | `enrolled` / `withdrawn` with informational sub-statuses: `lih`, `dbr`, `disposed`, `scrapped`, `other` (renamed 2026-07-02 from `Active`/`Inactive`) |
| **PM Rule assets** | "ERP-linked assets" | "ATMS-managed assets" |
| **Asset Assembly** | Not in scope | Asset Package/Component model — assets composed of other assets with independent maintenance lifecycles |

### 2.5 Deleted Content

- `docs/06-prompts/` — Codex prompts (project uses Kilo/Claude)
- `docs/07-meetings/` — one-time client discovery docs
- `docs/08-email-attachments/` — client Excel/Word files
- `docs/plans/` — 14 completed implementation plan files
- `docs/03-backend/API_PLAN.md` — superseded
- `docs/03-backend/DATABASE_SCHEMA_DRAFT.md` — superseded
- `docs/04-frontend/Issues.md` — issue tracking in git
- `docs/05-delivery/TDL.md` — stale task list

## 3. Known Inconsistencies (Action Required)

### 3.1 Backend Code Still Has Old Patterns

The PHP codebase was NOT modified as part of this MOC. The following code-level inconsistencies exist and must be resolved by the backend team:

| What | Where | Action |
|---|---|---|
| `SyncErpAssetsJob` | `app/Jobs/` | Remove or deprecate |
| `SyncAssets` Action | `app/Actions/ERP/` | Remove or deprecate |
| `erp_asset_id` column | Assets migration/model | Remove |
| `SyncErpAssetsJob` in scheduler | `app/Console/Kernel.php` | Remove schedule entry |
| Asset sync route | `routes/api.php` | Remove |
| `getAssets()` on ErpSource contract | `app/Contracts/Erp/ErpSource.php` | Remove |
| Asset endpoint config | `config/erp.php` | Remove `assets_endpoint` |
| `Viewer` role seeder | `database/seeders/RoleSeeder.php` | Remove or mark inactive |
| Six-role references in code | Various | Update to five roles |

### 3.2 CLAUDE.md Is Stale

`CLAUDE.md` still references:
- Old `frontend/` paths (should be `atms/`)
- 6 roles (should be 5 human + 1 service)
- ERP asset sync
- Old doc folder structure

A follow-up task is needed to sync `CLAUDE.md` with the new docs.

### 3.3 Future Code Folders Don't Exist

- `sm/` and `am/` Vue 3 scaffolds are not created
- `frontend/` is not yet renamed to `atms/`
- Docker/nginx configs still reference `frontend/`

## 4. Guidance for Future AI-Assisted Development

### 4.1 When Building ATMS Features

- **Read docs from:** `docs/atms/01-product/` (PRD, scope, workflows, roles, ASSET_STATUS), `docs/atms/02-design/`, `docs/atms/04-frontend/`, `docs/atms/04-technical/`
- **Shared backend docs:** `docs/03-backend/` (ARCHITECTURE, RBAC, STATUS_MODEL, CODING_STANDARDS)
- **Source of truth:** `docs/00-project-rules/authoritative-sources.md`
- **Parts are read-only from SM.** When recording parts on a WO, read the part from SM tables. The part-request form submits to SM's workflow.
- **Location is read-only from AM.** Display the current location from AM tables. Do not write location data from ATMS.
- **Assets have NO `erp_asset_id`.** ✅ Phase 1 removed the column, job, and all references. Assets are ATMS-managed only.
- **5 human roles (+1 service).** Viewer merged into Requester. SERVICE is a non-user-assignable role for M2M API tokens.

### 4.2 When Building SM Features

- Read `docs/sm/` placeholder docs for scope context
- SM shares the same `backend/` and database — create new Laravel routes/controllers as needed
- Parts table is SM's — sync from ERP, manage locally
- ATMS reads parts from SM tables only

### 4.3 When Building AM Features

- Read `docs/am/` placeholder docs for scope context
- AM shares the same `backend/` and database
- Location tables are AM's — source of truth for asset location
- ATMS reads location from AM tables only

### 4.4 Backend Cleanup Tasks (Separate MOC)

These tasks were addressed in Phase 1 (2026-06-25). Status:
1. ✅ Remove `SyncErpAssetsJob` and `SyncAssets` Action
2. ✅ Remove `erp_asset_id` column from assets migration/model
3. ✅ Remove asset sync route and scheduler entry
4. ✅ Simplify `ErpSource` contract to parts-only
5. ✅ Remove Viewer role seeder or merge into Requester
6. ⏳ Rename `frontend/` → `atms/` and update Docker/nginx config (deferred)
7. ✅ Update `CLAUDE.md` to match new docs structure

### 4.5 When In Doubt

The authoritative sources for this MOC are:
- **`docs/README.md`** — top-level overview and folder structure
- **`docs/00-project-rules/authoritative-sources.md`** — binding rules
- **`docs/atms/01-product/PRD.md`** — product scope
- **`docs/03-backend/ARCHITECTURE.md`** — backend design with three-subsystem note
- **This MOC document** — the "why" and "what changed"

## 5. Files Modified (Complete List)

### Shared Root
- `docs/README.md` — rewritten with three-subsystem overview
- `docs/00-project-rules/authoritative-sources.md` — updated ERP/parts/location boundaries
- `docs/03-backend/ARCHITECTURE.md` — added three-subsystem section, removed asset sync refs
- `docs/03-backend/RBAC.md` — 6→5 roles, merged Viewer into Requester
- `docs/03-backend/ERP_SYNC.md` — parts-only, SM-owned
- `docs/03-backend/MOCK_ERP.md` — SM-relevant, asset mock data removed
- `docs/03-backend/STATUS_MODEL.md` — added Asset Maintenance Status section

### ATMS Product
- `docs/atms/01-product/PRD.md` — rewritten for new scope
- `docs/atms/01-product/IN_SCOPE.md` — rewritten for new scope, added section 6b (Asset Maintenance Status)
- `docs/atms/01-product/OUT_OF_SCOPE.md` — sections 3, 7 updated for SM/AM ownership
- `docs/atms/01-product/ROLES_AND_PERMISSIONS.md` — 6→5 roles, added AM context for Logistics
- `docs/atms/01-product/WORKFLOWS.md` — updated PM/location/parts references
- `docs/atms/01-product/CLIENT_SCOPE_NOTE.md` — ERP-linked→ATMS-managed
- `docs/atms/01-product/ASSET_STATUS.md` — NEW: Asset Maintenance Status spec
- `docs/atms/01-product/ASSET_ASSEMBLY.md` — NEW: Asset Assembly (Package/Component) specification (data model, workflows, PM rules, all Q1–Q5 resolved)

### ATMS Design
- `docs/atms/02-design/NAVIGATION.md` — ERP-linked→SM parts
- `docs/atms/02-design/SCREEN_INVENTORY.md` — ERP-linked→ATMS/SM

### ATMS Technical
- `docs/atms/04-technical/BACKEND_API_HANDOFF.md` — ERP-linked→ATMS-managed, frontend/src→atms/src, 6→5 roles
- `docs/atms/04-technical/BACKEND_API_REFERENCE.md` — ERP-linked→ATMS-managed

### ATMS Frontend
- `docs/atms/04-frontend/ROUTES.md` — 6→5 roles

### Delivery
- `docs/05-delivery/IMPLEMENTATION_PLAN.md` — added SM/AM phases, removed ERP asset sync

### New Placeholder Docs
- `docs/sm/01-product/PRD.md`, `docs/sm/02-design/ARCHITECTURE.md`, `docs/sm/04-frontend/ARCHITECTURE.md`
- `docs/am/01-product/PRD.md`, `docs/am/02-design/ARCHITECTURE.md`, `docs/am/04-frontend/ARCHITECTURE.md`

## 6. Verification

All verification greps passed:
- ✅ No references to deleted directories (`06-prompts`, `07-meetings`, `08-email-attachments`, `plans/`)
- ✅ No references to deleted files (`API_PLAN`, `DATABASE_SCHEMA_DRAFT`)
- ✅ No "Codex" references remain
- ✅ No "ERP-linked asset" references remain in ATMS product/docs
- ✅ No standalone Viewer role references
- ✅ `docs/atms/`, `docs/sm/`, `docs/am/` directories exist with correct structure
- ✅ `docs/03-backend/` and `docs/00-project-rules/` remain at root
- ✅ Old directories (`01-product/`, `02-design/`, `04-frontend/`, `04-technical/`) removed from root
