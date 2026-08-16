# ATMS Engineering Summary

## Topology

| Layer | Location | Responsibility |
|---|---|---|
| Backend | `backend/` | Laravel 13 JSON API, policies, actions, jobs, resources, and tests. |
| Frontend | `frontend/` | Vue 3 + TypeScript SPA using Vite, Tailwind, and shadcn-vue. |
| Database | PostgreSQL | Application data, queue driver, and audit history. |
| Runtime | Docker Compose | Local development and VPS deployment with persistent volumes. |

The product family is intended to share one backend and database. ATMS is the
implemented subsystem; SM and AM are future, bounded work.

## Backend structure

- `app/Http/Controllers` exposes HTTP endpoints; controllers delegate business
  transitions to `app/Actions`.
- `app/Policies` is the authorization source of truth. Use policy checks rather
  than role checks embedded in controllers.
- `app/Queries` owns read-model filtering and reporting queries.
- `app/Http/Resources` defines response serialization.
- `app/Jobs/EvaluatePmRulesJob.php` selects the PM assignments worth evaluating and
  fans them out to `EvaluatePmAssignmentsJob.php`, one child per chunk; it performs
  no evaluation itself. `app/Services/Pm/PmEvaluationBatch.php` loads the readings
  and suppressions for a chunk in a fixed number of queries, and
  `PmEvaluationRunner.php` checks due-ness **before** opening a transaction, so the
  row lock is paid only for assignments that look due. Keep both properties: a few
  hundred assets is already thousands of assignments, and the previous
  one-transaction-per-assignment loop could not finish inside the job timeout.
- `app/Jobs/ReconcilePmCategoryAssignmentsJob.php` expands a PM rule's Maintenance
  Category coverage into per-asset assignments (`app/Actions/Pm/
  ReconcilePmCategoryAssignments.php`). It is overlap-locked **per scope** —
  `pm-category-reconcile:rule-7` — so two edits to one rule cannot interleave while
  unrelated rules still reconcile in parallel. `SyncErpPartsJob.php` is the current
  ERP sync job.
- **`asset_pm_assignments.assigned_by` and `deactivated_by` are nullable, and that
  is load-bearing.** A null actor means reconciliation did it; a filled one means a
  person did. Reconciliation may restore an assignment it withdrew itself but must
  never reactivate one a person deactivated, or a per-asset opt-out would silently
  revert on the next sync.
- `app/Notifications` holds both email families: account notifications at the root,
  workflow notifications under `MaintenanceRequests/` and `WorkOrders/`. Every one is
  queued, returns `account_email` from `via()`, and builds its payload in
  `toAccountEmail()`.
- `app/Notifications/Concerns/AccountEmailNotification.php` supplies the shared
  channel, retry policy, and the mailbox-wide overlap lock. Notifications must use
  this trait rather than setting their own queue behaviour, and must implement
  `ShouldQueueAfterCommit` rather than plain `ShouldQueue` so a rolled-back transition
  cannot emit mail. A trait cannot enforce the interface, so a test asserts it for
  every notification in the application.
- `app/Support/FrontendUrl.php` builds links to SPA routes from `atms.frontend_url`.
  Use it for anything a person opens in a browser; `url()` remains correct for API
  URLs such as attachment downloads.
- `app/Notifications/Channels/AccountEmailChannel.php` passes the payload to the
  bound `AccountEmailTransport`. The payload shape is
  `{ to: string[], cc?: string[], subject: string, templateData: array }`; a
  notification never renders HTML or names a recipient address itself.
- `app/Services/Notifications/GraphAccountEmailTransport.php` is the production
  transport: it renders `resources/views/emails/atms-notification.blade.php` from
  `templateData`, applies the configured BCC, and posts to Graph `sendMail`. The fake
  transport records sends in memory for development/tests only.

Use explicit action classes for state transitions, Form Requests for validation,
policies for authorization, Eloquent resources for API responses, and PHPUnit
feature tests for externally visible behavior.

## Authentication and security

- The SPA uses Sanctum cookie/session authentication. Browser clients must obtain
  the CSRF cookie before state-changing requests.
- Machine clients use `POST /api/auth/token`; authenticated API routes pass through
  `EnsureTokenAbilities`.
- All outbound email — account activation, password reset, and MR/WO workflow
  notifications — uses Microsoft Graph `sendMail` in production. SMTP AUTH and Power
  Automate are not supported paths and must not be reintroduced.
- Workflow notifications are dispatched from the action that owns the transition, not
  from controllers or model events, so an unauthorized or rejected transition cannot
  emit mail.
- Store secrets only in environment configuration. Do not log access tokens,
  passwords, or complete reset URLs.
- HTTPS terminates at the reverse proxy in production. Keep database and internal
  services off the public network.

## Data ownership and conventions

- **ATMS routes behaviour only on fields it owns.** `assets.maintenance_category_id`
  is the routing key: it selects a WO form template and is what a PM rule may
  cover. `fa_subclass_code` is written by the ERP sync, so it may describe an asset
  (reports, asset tags) but must never control one. The column is NOT NULL and
  defaults to a seeded `UNCLASSIFIED` category, so an asset the ERP created with no
  category is a visible, countable state rather than a null that no screen shows.
- ATMS owns operational maintenance data and current direct location updates.
- **`work_order_meter_snapshots` is an immutable historical record.** It is written
  only by `SnapshotWorkOrderMeterReadings`, once, as a work order closes: one row
  per reading type holding the asset's meter position at that moment. It is
  deliberately **not** recomputed when a source reading is later edited or deleted —
  it records what the meter was understood to read at close, which is what "usage
  since that job" has to measure against. Stored per type rather than as a column
  pair on `work_orders` because three reading types are live (Operating Hours,
  Kilometer Driven, Depth) and assets carry readings for several of them.
- **`asset_meter_readings.entered_delta` is informational, never authoritative.**
  `reading_value` remains the single source of truth for what the meter says;
  nothing in PM evaluation, the monotonicity guards, or reporting reads the delta.
- ERP integration is parts-focused. Do not reintroduce asset ERP sync or mock ERP
  services without an explicit product decision.
- UTC is stored; the current display timezone defaults to `Africa/Tripoli`.
- Use Laravel/PHP conventions already present in sibling code: strict types where
  used, explicit parameter and return types, descriptive action names, and no
  controller-hidden workflow logic.
- PHP changes require focused PHPUnit coverage and a Pint run.

  ⚠️ **`pint --dirty` is a silent no-op in the container.** `.git` lives at the
  repository root, outside the `backend/` mount, so Pint finds no git repository,
  reports "0 files", and exits successfully. Pass the paths you touched
  (`docker exec atms-api vendor/bin/pint app/… tests/…`). Passing whole directories
  reformats unrelated files — check `git status` afterwards and revert the noise.

## Where to inspect a disputed detail

| Detail | Source of truth |
|---|---|
| Route exists or changed | `backend/routes/api.php` |
| Request validation | `backend/app/Http/Requests/` |
| Response shape | `backend/app/Http/Resources/` and feature tests |
| Access control | `backend/app/Policies/` |
| State transition | `backend/app/Actions/` and tests |
| SPA route/UI behavior | `frontend/src/router/index.ts` and the target view/composable |
| Why a queued job "did nothing" | `queue:failed`, then restart `queue`/`scheduler` — see [OPERATIONS.md](OPERATIONS.md) |
| Session decisions and their reasoning | `.kilo/STATE.md` (newest first) and `.kilo/TLD.md` |
