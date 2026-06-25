# Plan: Documentation Restructure and Scope Update

**Date:** 2026-06-24  
**Scope:** `docs/` only — no code changes  
**Outcome:** A restructured `docs/` folder reflecting the three-subsystem architecture with outdated content removed and new specifications added.

---

## Architecture Decisions (pre-agreed)

### Three subsystems sharing one backend + DB

| Subsystem | Owns | Code folder (future) | Docs folder |
|---|---|---|---|
| **ATMS** | Assets, MRs, WOs, PM rules, dashboard, RBAC | `atms/` (renamed from `frontend/`) | `docs/atms/` |
| **SM** (Store Management) | Parts table, inventory, stock movement, ERP parts sync, Order→Approval→Dispatch→GR | `sm/` (new) | `docs/sm/` |
| **AM** (Asset Movement) | Asset movement form, location history | `am/` (new) | `docs/am/` |

**Shared:** `backend/` Laravel API, `03-backend/` docs, `00-project-rules/`, `operations/`

### RBAC (5 roles)
Administrator, Maintenance Manager, Technician, Logistics, Requester  
(Viewer merged into Requester — all users are Requesters)

### Scope changes
- **Assets** managed fully within ATMS — no ERP asset sync (codebase still has `SyncErpAssetsJob`/`erp_asset_id` columns — backend team must remove these)
- **Asset Maintenance Status:** Active / Inactive with purely informational sub-statuses: LIH (Lost in Hole), DBR (Damaged Beyond Repair), Disposed, Scrapped, Other
- **Parts** owned by SM system — ERP syncs parts into SM tables; ATMS reads parts only to populate a WO part-request form; that form submits to SM's workflow
- **Location tracking** moved from ATMS to AM — ATMS reads current location from AM tables
- **AM workflow:** Requester submits → Logistics approves → Logistics confirms arrival → location updates in AM tables

---

## Tasks

### Phase 1 — Remove outdated content

1. **Delete entire directories:**
   - `docs/06-prompts/` (Codex prompts — project now uses Kilo/Claude)
   - `docs/07-meetings/` (one-time client discovery docs)
   - `docs/08-email-attachments/` (client Excel/Word files, not documentation)
   - `docs/plans/` (14 completed implementation plan files from June 6–23)

2. **Delete individual stale files:**
   - `docs/03-backend/API_PLAN.md` (superseded by `BACKEND_API_REFERENCE.md`)
   - `docs/03-backend/DATABASE_SCHEMA_DRAFT.md` (superseded by actual migrations)
   - `docs/04-frontend/Issues.md` (issue tracking belongs in git, not docs)
   - `docs/05-delivery/TDL.md` (stale task delivery list)

### Phase 2 — Create new folder structure

3. **Create subsystem doc directories:**
   ```
   docs/atms/01-product/
   docs/atms/02-design/
   docs/atms/04-frontend/
   docs/atms/04-technical/
   docs/sm/01-product/
   docs/sm/02-design/
   docs/sm/04-frontend/
   docs/am/01-product/
   docs/am/02-design/
   docs/am/04-frontend/
   ```

### Phase 3 — Move ATMS docs into subsystem folder

4. **Move ATMS product docs:** `docs/01-product/*` → `docs/atms/01-product/`
5. **Move ATMS design docs:** `docs/02-design/*` → `docs/atms/02-design/`
6. **Move ATMS frontend docs:** `docs/04-frontend/*` → `docs/atms/04-frontend/`
7. **Move ATMS technical docs:** `docs/04-technical/*` → `docs/atms/04-technical/`
8. **Remove empty old directories:** `docs/01-product/`, `docs/02-design/`, `docs/04-frontend/`, `docs/04-technical/`

### Phase 4 — Create SM and AM placeholder docs

9. **Create SM placeholder docs** (minimal — SM is not built yet):
   - `docs/sm/01-product/PRD.md` — brief description of Store Management scope
   - `docs/sm/02-design/ARCHITECTURE.md` — note that SM shares backend/DB with ATMS
   - `docs/sm/04-frontend/ARCHITECTURE.md` — note planned Vue 3 + shadcn-vue stack

10. **Create AM placeholder docs** (minimal — AM is not built yet):
    - `docs/am/01-product/PRD.md` — brief description of Asset Movement scope and workflow
    - `docs/am/02-design/ARCHITECTURE.md` — note that AM shares backend/DB with ATMS
    - `docs/am/04-frontend/ARCHITECTURE.md` — note planned Vue 3 + shadcn-vue stack

### Phase 5 — Update shared root docs

11. **Rewrite `docs/README.md`:**
    - Three-subsystem overview (ATMS, SM, AM)
    - Shared backend note
    - Updated folder structure diagram
    - Remove all "Codex" and "ERP-linked assets" references
    - Update RBAC to 5 roles

12. **Update `docs/00-project-rules/authoritative-sources.md`:**
    - Change "ERP-linked assets" → "ATMS-managed assets"
    - Change "Work Order parts are operational usage records selected from ERP-linked reference data" → reflect that parts are requested through SM

13. **Update `docs/03-backend/ARCHITECTURE.md`:**
    - Add note about three subsystems sharing the backend
    - Mention AM location tables are the source of truth for asset location
    - Mention SM parts tables are the source of truth for parts

### Phase 6 — Update ATMS product docs for new scope

14. **Update `docs/atms/01-product/PRD.md`:**
    - Remove any remaining "ERP-linked assets" phrasing
    - Add Asset Maintenance Status section (Active/Inactive + sub-statuses)
    - Update parts section — parts are consumed via SM, not managed in ATMS
    - Remove location tracking section (moved to AM)

15. **Update `docs/atms/01-product/IN_SCOPE.md`:**
    - Add Asset Maintenance Status (section 6b or new section)
    - Update parts section — reference SM as external provider
    - Update location tracking — note it's now in AM, ATMS reads only
    - Fix "ERP-linked assets" references

16. **Update `docs/atms/01-product/OUT_OF_SCOPE.md`:**
    - Remove item 3 "Full Inventory / Warehouse Management" if SM covers it
    - Add note: parts inventory and stock movement handled by SM system
    - Add note: asset physical movement handled by AM system

17. **Update `docs/atms/01-product/ROLES_AND_PERMISSIONS.md`:**
    - Remove Viewer role, merge into Requester
    - Update permission matrix for 5 roles
    - Add Logistics role note for AM workflow

18. **Update `docs/atms/01-product/WORKFLOWS.md`:**
    - Update PM rule references (remove "ERP-linked assets")
    - Note that parts come through SM
    - Note that location comes through AM

19. **Add `docs/atms/01-product/ASSET_STATUS.md`:**
    - Asset Maintenance Status specification
    - Active state definition
    - Inactive state + sub-statuses: LIH, DBR, Disposed, Scrapped, Other
    - Explicit: sub-statuses are purely informational, no workflow triggers
    - Asset status is independent of ERP disposal/financial treatment

### Phase 7 — Update remaining ATMS docs

20. **Update `docs/atms/02-design/NAVIGATION.md`:**
    - Keep existing navigation, no changes needed unless role visibility changed

21. **Update `docs/atms/03-backend/STATUS_MODEL.md`:**
    - Add Asset Maintenance Status model (Active/Inactive + sub-statuses)
    - Keep existing MR and WO status models

22. **Update `docs/atms/04-technical/BACKEND_API_REFERENCE.md` and `docs/atms/04-technical/BACKEND_API_HANDOFF.md`:**
    - Update all paths referencing `frontend/src/` → `atms/src/`

23. **Update `docs/05-delivery/IMPLEMENTATION_PLAN.md`:**
    - Remove references to ERP asset sync
    - Add AM subsystem placeholder phase
    - Add SM subsystem placeholder phase
    - Update RBAC role counts

### Phase 8 — Verification

24. **Verify no remaining references to removed content:**
    - `grep -r "06-prompts\|07-meetings\|08-email-attachments" docs/` → expect no matches
    - `grep -r "API_PLAN\|DATABASE_SCHEMA_DRAFT" docs/` → expect no matches
    - `grep -r "plans/" docs/` → expect no matches in active docs (sm/am may reference future plans)

25. **Verify stale references cleaned:**
    - `grep -ri "codex" docs/` → expect no matches
    - `grep -ri "ERP-linked asset" docs/` → expect no matches in ATMS product docs
    - `grep -ri "viewer" docs/atms/01-product/ROLES_AND_PERMISSIONS.md` → expect no matches as standalone role

26. **Verify new structure:**
    - Confirm `docs/atms/`, `docs/sm/`, `docs/am/` directories exist with correct subdirectories
    - Confirm `docs/03-backend/` and `docs/00-project-rules/` remain at root
    - Confirm removed directories are gone

---

## Out of Scope (explicitly not in this plan)

- Renaming `frontend/` → `atms/` and updating all code references
- Creating `sm/` or `am/` Vue 3 scaffold directories
- Updating `CLAUDE.md` to reflect new folder structure
- Updating Docker or nginx configuration
- Removing `SyncErpAssetsJob` or `erp_asset_id` columns from backend
- Updating `docs/plans/` references inside old plan files (they're being deleted)

## Risks

- **CLAUDE.md becomes stale:** After this restructure, `CLAUDE.md` still references the old `frontend/` paths and 6 roles. A follow-up task is needed to sync it.
- **Backend code remains inconsistent:** The codebase still has `SyncErpAssetsJob` and `erp_asset_id` columns — docs will say "no ERP asset sync" but code still has it. Backend team must resolve this.
- **SM/AM placeholder docs are thin:** These subsystems have no real specifications yet — placeholders prevent the docs from looking broken but won't guide implementation.

## Validation

After implementation, the implementing agent should:
1. Confirm `ls docs/` shows only: `README.md`, `00-project-rules/`, `03-backend/`, `05-delivery/`, `operations/`, `atms/`, `sm/`, `am/`
2. Confirm `ls docs/atms/` shows: `01-product/`, `02-design/`, `04-frontend/`, `04-technical/`
3. Confirm all stale reference grep commands return zero matches
4. Read the updated `docs/README.md` to verify correctness
