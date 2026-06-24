# Implementation Plan

> **Live blockers tracked in:** [TDL.md](./TDL.md)

This plan covers implementation of the ATMS subsystem and future phases for SM
and AM. All three subsystems share one Laravel backend and PostgreSQL database.

## Phase 0 — Project Setup

- Repository setup
- Shared Docker Compose service model for OrbStack and VPS
- Environment-specific Docker Compose overrides/configuration
- Laravel backend skeleton
- Vue frontend skeleton (ATMS)
- PostgreSQL setup
- PostgreSQL-backed Laravel queue setup
- Persistent attachment storage volume
- Auth baseline (5 roles: Administrator, Maintenance Manager, Technician, Logistics, Requester)
- Environment configuration

## Phase 1 — Core Master Data and ERP Sync Foundation

- SharePoint employee adapter and manual import
- Local employee directory
- Administrator employee-to-user provisioning
- One-time activation and password-reset flow
- Power Automate production transport for activation and password-reset email
- Users and roles (5 roles)
- Locations
- Master data/dropdowns (including Asset Maintenance sub-statuses)
- ERP adapter contract
- ERP sync job structure (parts only — SM-owned; no asset sync)
- Parts sync (SM-owned)
- Sync history and errors

## Phase 2 — ATMS Asset Registry

- Asset list
- Asset detail
- Asset Maintenance Status (Active / Inactive + sub-statuses)
- Asset usage readings
- Asset location display (read from AM tables, once AM is implemented)
- Asset attachments
- Asset maintenance history read-model foundation

## Phase 2a — ATMS Asset Assembly

- `asset_kind` enum (`asset` / `package` / `component`) on assets table
- `parent_asset_id` FK (nullable, self-referencing to assets.id)
- `asset_assembly_history` table (migration, model, API)
- Asset Maintenance Status Active sub-statuses (`Installed`, `Ready`)
- Install Component, Remove Component, Swap Component backend Actions
- Component operating hours derivation from parent readings + install timestamps
- Cycle prevention validation on parent assignment
- Package child listing endpoint with PM status indicators
- "Create MR for Component" action from parent WO detail screen
- Frontend: AssemblyTree, InstallComponentSheet, RemoveComponentDialog,
  SwapComponentSheet, ComponentAssemblyHistory, AssetKindBadge
- Tests: component install, remove, swap, cycle prevention, hours derivation,
  sub-status consistency, assembly history audit trail

## Phase 3 — ATMS Maintenance Requests

- Corrective MR creation
- PM-generated MR structure
- MR list and filters
- MR review screen
- Approval and rejection workflow
- Approved MR creates WO

## Phase 4 — ATMS Work Orders

- Active WO list
- WO detail
- Parts used on WO (populated from SM parts catalogue)
- WO attachments
- WO closure
- Reflect closed Work Orders in the derived maintenance history
- Closed WO list

## Phase 5 — ATMS PM Rules and Scheduler

- PM rule management (applied to individual ATMS-managed assets)
- Usage/date-based trigger logic
- Scheduled PM evaluation
- Automatic preventive MR generation
- PM baseline update on closure

## Phase 6 — ATMS Dashboard and Reports

- Dashboard summary cards
- Pending MRs
- Open WOs
- Overdue PMs
- Recently closed WOs
- Asset maintenance history views

## Phase 7 — ATMS Hardening and UAT

- Permissions review (5 roles)
- Validation review
- Error handling
- Test coverage
- Client UAT fixes
- Deployment preparation

## Phase 8 — SM (Store Management) Subsystem

> Placeholder — SM is not yet built. This phase creates the SM frontend and its
> backend endpoints within the shared Laravel backend.

- SM frontend scaffold (Vue 3 + TypeScript + Tailwind + shadcn-vue)
- Parts catalogue (read/write on local operational fields)
- Inventory balances and stock movement
- Order workflow: Order → Approval → Dispatch → Goods Receipt (GR)
- ERP parts sync (SM-owned; retarget existing sync pipeline)
- SM RBAC integration with shared 5-role model

## Phase 9 — AM (Asset Movement) Subsystem

> Placeholder — AM is not yet built. This phase creates the AM frontend and its
> backend endpoints within the shared Laravel backend.

- AM frontend scaffold (Vue 3 + TypeScript + Tailwind + shadcn-vue)
- Asset movement form (Requester submits → Logistics approves → Logistics confirms arrival)
- Asset location history tables (source of truth for all subsystems)
- AM RBAC integration with shared 5-role model

## Phase 10 — Deployment

- VPS setup
- Docker deployment
- Backup plan
- Nightly PostgreSQL and attachment-volume backups
- Seven daily and four weekly retained copies
- Restore procedure and verification
- SSL/domain setup
- Initial ERP parts sync
- User setup
- Go-live support
