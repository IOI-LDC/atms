# Session State — 2026-06-25

> **For AI agents:** Read this at the start of every session. It tells you what
> was done, what is decided, what is blocked, and what to tackle next.

## Last Session Accomplished

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

- **Test suite: 304 tests passing** (278 baseline + 26 new across 4 new test files + 1 addition)

- **Documentation updated:**
  - `.kilo/plans/1782388457617-phase1-backend-cleanup-and-features.md` — Post-Review Fixes appendix
  - `CLAUDE.md` — updated for Phase 1 complete, 6 roles, stale warnings removed
  - `docs/03-backend/ARCHITECTURE.md` — removed stale sync job/erp_asset_id notes
  - `backend/.env.example` — fixed `MOCK_ERP_*` → `LDC_ERP_*` variables

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
