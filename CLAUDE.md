# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

ATMS (Asset Maintenance Tracking System) is an operational maintenance application for a Libyan client. It is one of three subsystems (ATMS, SM, AM) sharing a single Laravel backend and PostgreSQL database. It integrates with LDC ERP for parts reference data (via the SM subsystem), and manages the full maintenance workflow: **Corrective/Preventive Maintenance Request → Manager Approval → Work Order → Closure → Maintenance History**.

The authoritative product specification is in `docs/`.

## Repository Layout

```
backend/          Laravel 13 API backend (PHP 8.4, PostgreSQL) — shared by all subsystems
frontend/         ATMS subsystem frontend (Vue 3 + TypeScript + Tailwind, Vite) — rename to atms/ pending
docker/           Dockerfiles and nginx configs
docs/             Full product, design, backend, and frontend documentation
  README.md       Documentation index and subsystem overview
  00-project-rules/  Authoritative sources, scope changes
  03-backend/     Shared backend architecture, RBAC, ERP sync, coding standards
  05-delivery/    Implementation plan, milestones, risks
  operations/     Backup, restore, deployment guides
  atms/           ATMS subsystem docs
    01-product/   PRD, scope, roles, workflows, asset assembly, asset status, asset tag
    02-design/    UI design system, navigation, screen inventory, UX principles
    04-frontend/  Frontend architecture, routes, components, UI states, forms
    04-technical/ Backend API reference, API handoff (frontend integration)
  sm/             Store Management (SM) subsystem docs (placeholder)
  am/             Asset Movement (AM) subsystem docs (placeholder)
scripts/          Backup, restore, smoke-test, and integration-test shell scripts
compose.yaml               Production Docker Compose
compose.override.yaml      Dev override (mounts source, sets APP_ENV=local)
compose.production.yaml    Production-specific overrides
```

## Commands

### Frontend (Vue 3 + Vite)

```bash
cd frontend

# Development server (hot reload)
npm run dev

# Type-check + production build
npm run build

# Preview production build locally
npm run preview
```

The frontend dev server runs on `http://localhost:5173` by default. It proxies `/api` requests to the backend at `http://localhost:80` (the nginx container).

All commands run inside the `backend/` directory unless noted for backend work.

### Running the stack (OrbStack / Docker Compose)

```bash
# Development — source is volume-mounted; override is auto-applied
docker compose up -d

# Execute Artisan commands inside the running api container
docker compose exec api php artisan <command>
```

### Backend (Laravel)

```bash
# Run all tests (in-memory SQLite, no containers required)
cd backend && php artisan test

# Run a single test file
php artisan test tests/Feature/MaintenanceRequests/CorrectiveMrTest.php

# Run a specific test method
php artisan test --filter test_corrective_mr_can_be_created

# Run only the Feature suite
php artisan test --testsuite Feature

# Refresh the database and run all seeders
php artisan migrate:fresh --seed

# Queue worker (dev — already managed by the queue container)
php artisan queue:work --tries=3

# Run the scheduler manually (fires all due jobs once)
php artisan schedule:run
```

### Integration / smoke tests

```bash
# Full integration test against a running stack
./scripts/test-integration.sh

# Docker Compose smoke test
./scripts/smoke-compose.sh

# Security smoke test
./scripts/security-smoke.sh
```

### Git conventions

- When the user says **"commit ALL"** (in all-caps), stage everything including untracked
  files: `git add . && git commit -m "..."` — no exclusions, no questions.
- When the user says "commit" (lowercase), stage only tracked changes:
  `git add -u && git commit -m "..."`

## Backend Architecture

### Layer conventions

| Layer | Location | Purpose |
|---|---|---|
| Controllers | `app/Http/Controllers/` | Route dispatch only — validate via Form Request, call Actions |
| Form Requests | `app/Http/Requests/` | Input validation |
| API Resources | `app/Http/Resources/` | Response shaping |
| **Actions** | `app/Actions/` | All important workflow operations (see below) |
| Services | `app/Services/` | Infrastructure helpers (ERP adapter, email transport) |
| Jobs | `app/Jobs/` | Queue-dispatched work (ERP parts sync, PM evaluation) |
| Policies | `app/Policies/` | All authorization — one policy per model |
| Enums | `app/Enums/` | Statuses and types — `MaintenanceRequestStatus`, `WorkOrderStatus`, `PmTriggerType`, `RoleCode` |
| Queries | `app/Queries/` | Complex read queries / view-model assembly |

### Action classes (important workflow transitions)

Actions live under `app/Actions/` grouped by domain. Use an Action for every important state transition — not the controller directly. Examples already implemented:

- `Assets/CreateAsset` — creates asset in a transaction, appends initial `AssetLocationHistory` if location provided, logs to audit trail
- `MaintenanceRequests/ApproveMaintenanceRequestAndCreateWorkOrder` — atomically converts MR → WO
- `MaintenanceRequests/CreateCorrectiveMaintenanceRequest`
- `MaintenanceRequests/UpdateMaintenanceRequest` — pessimistic lock, enforces `pending_review` guard, logs before/after diff
- `MaintenanceRequests/CancelMaintenanceRequest` / `RejectMaintenanceRequest`
- `Users/AdminResetUserPassword` — sets new password, invalidates all sessions and tokens
- `WorkOrders/AssignWorkOrder`, `StartWorkOrder`, `CompleteWorkOrder`, `CloseWorkOrder`, `CancelWorkOrder`
- `WorkOrders/UpdateWorkOrderExecution`, `RecordWorkOrderPart`, `DeleteWorkOrderPart`

**Decision rule:** Use an Action when a transition has complex preconditions, multi-step side effects, or needs locking. Use inline controller logic (`$model->update()` + `AuditLogger::log()`) for simple updates with no preconditions — see `PartController::update` and `UserController::update` as examples.

### Status models

**Maintenance Request:** `pending_review` → `converted` (terminal) | `rejected` (terminal) | `cancelled` (terminal)

**Work Order:** `open` → `in_progress` → `completed` → `closed` (terminal) | any non-closed → `cancelled` (terminal). Closed WOs are permanently immutable.

**Asset Maintenance Status:** Active (Standalone / Installed / Ready) and Inactive (LIH, DBR, Disposed, Scrapped, Other). Sub-statuses are purely informational with no workflow triggers. Asset status is independent of ERP disposal/financial treatment.

Statuses are defined as PHP-backed Enums in `app/Enums/`.

### RBAC

Five human roles: `administrator`, `maintenance_manager`, `technician`, `logistics`, `requester` — plus one non-human `service` role for M2M API token authentication. The legacy Viewer role has been merged into Requester — all users are Requesters at minimum. Roles are immutable system data; each user has exactly one role. Authorization is enforced exclusively through Laravel Policies (`app/Policies/`). See `docs/03-backend/RBAC.md` for the full permission matrix.

### Three Subsystems Sharing One Backend

The single Laravel backend and PostgreSQL database are shared by three product subsystems:

- **ATMS** — Assets, Maintenance Requests, Work Orders, PM rules, dashboard, RBAC.
- **SM** (Store Management) — Parts catalogue, inventory, stock movement, ERP parts sync, Order → Approval → Dispatch → GR.
- **AM** (Asset Movement) — Asset movement form, location history, movement workflow.

Source-of-truth boundaries:
- **Assets** are owned by ATMS and managed fully within ATMS. There is **no ERP asset sync**.
- **Parts** are owned by SM. ERP syncs parts into SM tables. ATMS reads parts only to populate Work Order part-request forms. SM parts tables are the source of truth for parts.
- **Asset location** is owned by AM. ATMS reads the current location from AM tables for display only. AM location tables are the source of truth for location history.

> ✅ Phase 1 backend cleanup complete — `SyncErpAssetsJob`, `SyncAssets`, `ExternalAssetData`, `MockErpHttpSource`, and `erp_asset_id` column have been removed. ERP now syncs parts only via `LdcErpHttpSource`.

### Asset management

Assets are managed fully within ATMS via `POST /assets` and `PATCH /assets/{asset}`. The `CreateAsset` Action handles creation; the `AssetController::update` method delegates location changes to the existing `UpdateAssetLocation` Action (generating a separate `asset.location_updated` audit entry) and updates remaining operational fields separately — so a single PATCH that changes both name and location produces two audit entries. Asset create/update is restricted to Administrator and Maintenance Manager.

### Parts and ERP sync

Parts are owned by the **SM (Store Management)** subsystem. ERP syncs parts into SM tables via `SyncErpPartsJob`; ATMS reads parts from SM tables only to populate Work Order part-request forms, which submit into SM"s workflow. ERP-owned columns (`erp_part_id`, `erp_part_code`, `erp_status`, `erp_raw_data`, `erp_last_synced_at`) are never writable through the API. Local operational fields (`name`, `description`, `unit_of_measure`, `category`, `is_active`) can be updated via `PATCH /parts/{part}` (Administrator and Maintenance Manager only). The sync adapter boundary is defined in `app/Contracts/`; the concrete adapter is `LdcErpHttpSource` (token-based auth via `app/Contracts/Erp/ErpSource`). Sync schedule default: weekly. Manual sync available to Administrator. Overlap prevention is required.

### PM (Preventive Maintenance)

`EvaluatePmRulesJob` runs daily (via scheduler) and generates PM Maintenance Requests when rules are due. PM trigger types: `date`, `reading`, `date_or_reading`. PM rules apply to individual ATMS-managed assets only (not categories or asset-types).

### Maintenance history

Asset maintenance history is assembled from authoritative source records (MRs, WOs, WO parts, meter readings, location history, attachments). ATMS reads current location from AM tables and parts data from SM tables. There is no `maintenance_histories` table — use `app/Queries/` for read-model assembly.

### Email transport

Two implementations in `app/Services/Notifications/`: `FakeAccountEmailTransport` (dev/test, default) and `PowerAutomateAccountEmailTransport` (production). Selected via `ACCOUNT_EMAIL_TRANSPORT` env var. Laravel owns token lifecycle; Power Automate only receives the minimum payload required to send the email.

### Audit log

Append-only `audit_logs` table. All security-sensitive and workflow events must be logged. Audit entries can never be edited or deleted through the API. Visible only to Administrators.

### Testing

Tests use in-memory SQLite (`DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`) — no running containers required. Queue is synchronous (`QUEUE_CONNECTION=sync`) in tests. Minimum required test coverage per `docs/03-backend/CODING_STANDARDS.md`:

- Corrective MR creation
- PM evaluation generating preventive MR
- MR approval → WO creation
- MR rejection → no WO
- WO closure updating history
- Location update creating history record
- ERP parts sync upsert

## Frontend Architecture

Stack: **Vue 3 + TypeScript + Tailwind CSS + shadcn-vue**. Source lives in `frontend/src/` (the ATMS subsystem frontend; will be renamed to `atms/src/`).

### Key constraints (non-negotiable)
1. **Frontend team does not touch the backend.** If a backend change is needed, flag it and let the backend team implement it. The backend is under active development alongside the frontend.
2. **No Tailwind in feature files.** Feature Vue files use only semantic CSS classes. Tailwind is allowed only inside `components/ui/`.
3. **All interactive controls use shadcn-vue.** No raw `<button>`, `<input>`, `<select>`, `<textarea>`, `<dialog>` in feature code.
4. **Every persistent action requires confirmation.** Before any API mutation: validate form → open confirm dialog → submit → toast result.
5. **CSRF before first POST.** Call `GET /sanctum/csrf-cookie` before the first mutating request.
6. **Cursor pagination.** All list endpoints return `{ data, links, meta }` with `meta.next_cursor` — not offset pagination.

### Semantic CSS classes

The full list of semantic classes that feature files use (defined in the shared CSS layer):

`app-layout`, `page-header`, `page-actions`, `kpi-grid`, `data-card`, `dense-table`, `filter-bar`, `form-grid`, `form-actions`, `loading-state`, `empty-state`, `error-state`

### Design tokens (CSS custom properties)

```css
:root {
  --primary: 221.4 32% 17.5%;    /* deep navy */
  --secondary: 349.4 59% 31.2%;  /* deep rose */
  --tertiary: 262.1 21% 32.5%;   /* deep purple */
  --background: 0 0% 100%;
  --foreground: 222.2 84% 4.9%;
  --muted: 210 40% 96%;
  --border: 214.3 31.8% 91.4%;
  --destructive: 0 84.2% 60.2%;
}
```

Light theme only for MVP. Full spec: `docs/atms/02-design/UI_DESIGN_SYSTEM.md`.

### Navigation layout

shadcn-vue `Sidebar` component (`AppSidebar.vue` + `AppLayout.vue`). Collapsible icon-mode sidebar on desktop; slide-in sheet on mobile. Nav structure: Dashboard / Maintenance Requests / Work Orders / Asset Management / Parts Management / Admin / Settings. Uses flat sidebar with tabbed content areas; no nested dropdown menus. Full nav tree with role visibility is defined in `AppSidebar.vue`.

### State management

Pinia only for: `auth.store.ts` (user session + role), `ui.store.ts` (preferences). All other state is local API-query state per view.

### API client conventions

- Base URL: `/api/`
- Auth: Sanctum SPA cookie — call `GET /sanctum/csrf-cookie` before first POST
- Pagination: cursor-based — `meta.next_cursor` / `meta.prev_cursor`
- Sort: `field:direction` query param (e.g., `created_at:desc`)
- Errors: 422 = validation (`errors` object), 409 = domain conflict, 401 = unauthenticated, 403 = unauthorized

### Role codes

`administrator`, `maintenance_manager`, `technician`, `logistics`, `requester`, `service`

Five human roles (Administrator, Maintenance Manager, Technician, Logistics, Requester) + one non-human `service` role for M2M API token authentication. The legacy Viewer role has been merged into Requester — all users are Requesters at minimum. Role determines which actions and fields are visible. Backend authorization is authoritative — hide unavailable UI but do not rely solely on frontend guards.

### Forms and overlays pattern

- Side sheets: create / edit forms
- Dialogs: confirmations, rejection, cancellation, deactivation (short consequential)
- Full pages: MR Review, WO Detail (complex workflow execution)

## Docker services

| Service | Description |
|---|---|
| `api` | Laravel PHP-FPM |
| `nginx` | Nginx reverse proxy (port `WEB_PORT`, default 80) |
| `postgres` | PostgreSQL 17 |
| `queue` | `php artisan queue:work --tries=3` |
| `scheduler` | `php artisan schedule:work` |

`compose.override.yaml` mounts the `backend/` source tree for hot-reload in local dev. Production uses built images from `compose.production.yaml`.

## Key environment variables

| Variable | Purpose |
|---|---|
| `ACCOUNT_EMAIL_TRANSPORT` | `fake` (default/dev) or `power_automate` (production) |
| `LDC_ERP_BASE_URL` | Base URL of the LDC ERP API |
| `LDC_ERP_CLIENT_ID` / `LDC_ERP_CLIENT_SECRET` | OAuth2 client credentials for ERP token acquisition |
| `ATMS_COMPANY_TIMEZONE` | Display timezone (default `Africa/Tripoli`) |
| `ATMS_ATTACHMENT_DISK` | Storage disk name for attachments |
| `QUEUE_CONNECTION` | `database` (prod/dev) or `sync` (tests) |

## Out of scope (MVP)

Do not add: labor hours/rates/costs/timesheets, category-level or template-level PM rules, Redis, MinIO, SharePoint SSO, or Microsoft Entra SSO. Parts inventory, stock movement, and warehouse operations are owned by the SM (Store Management) subsystem. Asset physical movement tracking and location history are owned by the AM (Asset Movement) subsystem. The Logistics role handles AM movement approval/confirmation but does not introduce gate passes, shipment documents, or custody workflows within ATMS.

## New endpoints (added 2026-06-23)

| Method | Path | Roles | Notes |
|---|---|---|---|
| `GET` | `/locations` | Admin, Manager, Logistics | List active locations only (`is_active = true`), sorted by name. Distinct from Admin-only `/admin/locations` (all statuses). Backs the Locations sidebar location picker. |
| `POST` | `/assets` | Admin, Manager | Create asset; location at creation starts location history |
| `PATCH` | `/assets/{asset}` | Admin, Manager | Update asset; location change delegates to `UpdateAssetLocation` Action |
| `PATCH` | `/parts/{part}` | Admin, Manager | Update local fields only; ERP columns are excluded from validation |
| `PATCH` | `/maintenance-requests/{mr}` | Admin, Manager, Tech/Requester (own corrective only) | Edit MR while `pending_review`; immutable once converted/rejected/cancelled |
| `PATCH` | `/admin/users/{user}` | Admin | Update user details; self-update rejected with 422 |
| `POST` | `/admin/users/{user}/reset-password` | Admin | Force-reset password, invalidates all sessions/tokens; self-reset rejected |

Full request/response shapes: `docs/atms/04-technical/BACKEND_API_REFERENCE.md`.

## Documentation index

| Path | Contents |
|---|---|
| `docs/00-project-rules/authoritative-sources.md` | Source-of-truth hierarchy and known screenshot conflicts |
| `docs/00-project-rules/SCOPE_CHANGE.md` | Scope change diff: original proposal vs. current three-subsystem scope |
| `docs/03-backend/ARCHITECTURE.md` | Full backend architecture including three-subsystem design |
| `docs/03-backend/RBAC.md` | Permission matrix for all five roles |
| `docs/03-backend/STATUS_MODEL.md` | MR, WO, and Asset Maintenance Status transitions |
| `docs/03-backend/CODING_STANDARDS.md` | Layer conventions and test priorities |
| `docs/03-backend/JOBS_AND_SCHEDULER.md` | Scheduler and queue job specs |
| `docs/03-backend/ERP_SYNC.md` | ERP adapter design and parts-only sync behaviour (SM-owned) |
| `docs/atms/01-product/PRD.md` | ATMS product requirements and subsystem boundaries |
| `docs/atms/01-product/IN_SCOPE.md` | Full in-scope item list for ATMS |
| `docs/atms/01-product/OUT_OF_SCOPE.md` | Full exclusions list (SM and AM ownership noted) |
| `docs/atms/01-product/ROLES_AND_PERMISSIONS.md` | Detailed five-role permission specification |
| `docs/atms/01-product/WORKFLOWS.md` | Maintenance and asset management workflows |
| `docs/atms/01-product/ASSET_ASSEMBLY.md` | Assembly model: parent/child, install/remove/swap |
| `docs/atms/01-product/ASSET_STATUS.md` | Asset Maintenance Status (Active/Inactive + sub-statuses) |
| `docs/atms/01-product/ASSET_TAG.md` | Asset tag format spec (`L-BBB-CCC-XXXX`) |
| `docs/atms/02-design/UI_DESIGN_SYSTEM.md` | Design tokens, semantic classes, component rules |
| `docs/atms/02-design/NAVIGATION.md` | Navigation structure and role visibility |
| `docs/atms/02-design/SCREEN_INVENTORY.md` | Screen inventory for ATMS frontend |
| `docs/atms/04-frontend/FRONTEND_ARCHITECTURE.md` | Vue architecture and routing |
| `docs/atms/04-frontend/COMPONENTS.md` | Shared component catalogue |
| `docs/atms/04-frontend/UI_STATES.md` | Loading, empty, error state patterns |
| `docs/atms/04-frontend/FORM_REQUIREMENTS.md` | Form validation and submission patterns |
| `docs/atms/04-technical/BACKEND_API_HANDOFF.md` | Frontend integration guide: auth lifecycle, conventions, TS types, workflow sequences, patterns |
| `docs/atms/04-technical/BACKEND_API_REFERENCE.md` | Exhaustive per-endpoint reference (request/response/role visibility) |
| `docs/sm/01-product/PRD.md` | Store Management (SM) subsystem scope (placeholder) |
| `docs/am/01-product/PRD.md` | Asset Movement (AM) subsystem scope (placeholder) |
| `docs/05-delivery/IMPLEMENTATION_PLAN.md` | Phased implementation plan (ATMS, SM, AM) |
| `docs/05-delivery/MILESTONES.md` | Milestone definitions |
| `docs/05-delivery/RISKS_AND_ASSUMPTIONS.md` | Risks and assumptions |
| `docs/operations/BACKUP_AND_RESTORE.md` | Backup and restore procedures |
| `docs/operations/DEPLOYMENT.md` | Deployment guide |
