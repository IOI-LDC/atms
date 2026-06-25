# Session State — 2026-06-25

> **For AI agents:** Read this at the start of every session. It tells you what
> was done, what is decided, what is blocked, and what to tackle next.

## Last Session Accomplished

- **Sidebar navigation redesign:** Rewrote 5 docs — flat 7-item sidebar (Dashboard /
  Maintenance Requests / Work Orders / Asset Management / Parts Management / Admin /
  Settings) with tabbed content areas, zero nested dropdowns, role-based visibility.
  PM Rules moved under Admin; Locations merged into Lists & Dropdowns.

- **Secure Remote API Access spec:** Created `docs/03-backend/SECURE_REMOTE_API_ACCESS.md`
  (HTTPS/TLS enforcement, bearer tokens, strong secrets, locked-down ports), updated
  `ARCHITECTURE.md` (machine-to-machine auth), `DEPLOYMENT.md` (TLS + port lockdown).

- **Documentation restructure:** Moved all ATMS docs into `docs/atms/`. Created
  `docs/sm/` and `docs/am/` placeholder directories. Deleted stale directories
  (`06-prompts/`, `07-meetings/`, `08-email-attachments/`, `plans/`) and stale
  files (`API_PLAN.md`, `DATABASE_SCHEMA_DRAFT.md`, `Issues.md`, `TDL.md` —
  TDL.md was later recreated fresh). Full MOC in `docs/MOC_SCOPE_RESTRUCTURE.md`.

- **RBAC:** Reduced from 6 roles to 5 (Viewer merged into Requester).

- **ERP integration confirmed working:**
  - Token auth: Microsoft Entra ID OAuth2 client credentials
    (`x-www-form-urlencoded`) against Dynamics 365 Business Central.
  - Fixed assets: `fixedAssestAPI` (OData V4), 429 assets, 24 fields.
  - Contract test passed — token acquired, assets fetched.
  - Env vars in `backend/.env`, `compose.yaml`, and root `.env`.
  - `docs/03-backend/ERP_SYNC.md` updated with real auth + full-pull design.

- **Mock ERP deprecated:** Config/infra cleaned. 4 PHP files flagged for
  backend team (see Known Inconsistencies below).

- **Asset Assembly model:** All 5 questions resolved. Documents updated across
  19 files (CRITICAL: `BACKEND_API_HANDOFF.md`, `BACKEND_API_REFERENCE.md`,
  `WORKFLOWS.md`, `IMPLEMENTATION_PLAN.md`, `RBAC.md`).

- **Asset tag:** Format `L-BBB-CCC-XXXX`. Spec in
  `docs/atms/01-product/ASSET_TAG.md`. Team and CFO communications drafted.

- **Risks updated:** `docs/05-delivery/RISKS_AND_ASSUMPTIONS.md` now reflects
  real ERP connection and field ownership boundary.

- **LDC meeting prep:** `docs/sm/01-product/LDC_MEETING_PARTS_WRITEBACK.md`
  (PDF also generated) — parts write-back questions for ERP consultant.

## Key Decisions (do not reopen unless new information)

| Topic | Decision |
|---|---|
| Subsystem architecture | ATMS / SM / AM — one backend, one DB |
| RBAC roles | 5: Admin, Manager, Tech, Logistics, Requester (no Viewer) |
| Asset source | ATMS-managed only — no ERP asset sync |
| Parts ownership | SM — ERP syncs into SM. ATMS reads only. |
| Location ownership | AM — ATMS reads from AM tables only. |
| ERP auth | Entra ID OAuth2 `client_credentials`, `x-www-form-urlencoded` |
| ERP sync strategy | Full pull every time. No pagination. No incremental sync. |
| ERP field boundary | Sync writes ERP columns only. Local fields never touched. |
| Asset Assembly Q1 | One WO per asset. Component status updated separately. |
| Asset Assembly Q2 | Component hours derived from parent readings + install timestamp. |
| Asset Assembly Q3 | Dedicated `asset_assembly_history` table. |
| Asset Assembly Q4 | Active sub-statuses: Installed / Ready. |
| Asset Assembly Q5 | Parent + component PM independent. Cross-check at parent service. |
| Asset tag format | `L-BBB-CCC-XXXX` — manual, immutable, unique. |
| Asset tag ownership codes | `L` = LDC (we maintain), `X` = External (we don't). |
| Git commit convention | When the user says "commit ALL" (capitalized), use `git add .` — stage everything including untracked files, then commit. |

## Pending — Blocked on ERP Team 🔴

| # | Item | Who |
|---|---|---|
| 1 | Parts API page name (BC custom API page) | ERP team |
| 2 | Parts field mapping | ERP team |
| 3 | `componentOfMainAsset` sample with non-null parent | ERP team |
| 4 | **Store Order / Store Management in BC** — does it exist and is it used at LDC? Can we query store orders by number through OData? | VJ (ERP Consultant) |

| # | Item | Tracker |
|---|---|---|
| 1 | Parts API page name (BC custom API page) | `docs/05-delivery/TDL.md` |
| 2 | Parts field mapping (response schema) | `docs/05-delivery/TDL.md` |
| 3 | `componentOfMainAsset` sample with non-null parent | `docs/05-delivery/TDL.md` |

## Pending — Backend Team (future)

| # | Item |
|---|---|
| 4 | Remove 4 Mock ERP PHP files + switch `AppServiceProvider` binding to `LdcErpHttpSource` |
| 5 | Sync `CLAUDE.md` with new docs structure (explicitly deferred) |
| 6 | Rename `frontend/` → `atms/` + update Docker/nginx |
| 7 | Create `sm/` and `am/` Vue 3 scaffolds |

## Known Inconsistencies

- **Backend code still has** `SyncErpAssetsJob`, `erp_asset_id` columns,
  `MockErpHttpSource` binding — docs say no ERP asset sync. Backend team must
  resolve.
- **`CLAUDE.md`** references old `frontend/` paths, 6 roles, ERP asset sync —
  stale but explicitly out of scope for docs restructure.

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
6. ERP test: source `backend/.env`, then the curl commands commented in that file.

## Implementation Phases (2026-06-24)

### Phase 1 — ATMS Core (current focus)
- Asset registry + tags + maintenance status
- Corrective + Preventive MR → WO workflow
- Parts catalogue (read-only from SM tables, ERP-synced)
- Simple asset location update by Logistics (no workflow)
- 5-role RBAC, dashboard, reporting, attachments

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
