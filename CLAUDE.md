# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

ATMS is LDC's asset maintenance tracking system: a Laravel 13 JSON API (`backend/`) plus a Vue 3 SPA (`frontend/`) on PostgreSQL, run as a Docker Compose stack. The core workflow is **Maintenance Request → manager approval → Work Order → complete → close**, alongside assets, parts, locations, preventive maintenance (PM) rules, meter readings, attachments, ~18 reports, and admin/master data.

## Commands

**There is no PHP or Composer on the host.** PHP 8.4 exists only inside the `atms-api` container, and tests require the PostgreSQL container. Every backend command runs through `docker exec atms-api`.

```bash
docker compose up -d                      # start stack (override auto-applies dev volume mounts)
docker compose logs -f api
```

### Backend

```bash
docker exec atms-api php artisan test --compact                                    # full suite
docker exec atms-api php artisan test --compact tests/Feature/WorkOrders            # one directory
docker exec atms-api php artisan test --compact tests/Feature/Health/HealthTest.php # one file
docker exec atms-api php artisan test --compact --filter=test_name                  # one test
docker exec atms-api vendor/bin/pint app/... tests/...                              # required after PHP edits — pass explicit paths
docker exec atms-api php artisan migrate
docker exec atms-api php artisan migrate:fresh --seed
docker exec atms-api php artisan schedule:run                                       # fire due jobs once
docker compose restart queue scheduler                                             # after ANY job/Action change
```

**`pint --dirty` does nothing here.** `.git` lives at the repo root, outside the `backend/` mount, so Pint finds no git repository and reports "0 files" without failing. Pass the paths you touched. Passing whole directories reformats unrelated files — check `git status` afterwards.

Project commands: `atms:make-admin`, `atms:import-parts`, `atms:import-assets`, `atms:import-erp-assets` (all support `--dry-run` where relevant; check `--help`).

### Frontend

Runs on the host and proxies `/api` + `/sanctum` to `http://localhost:80` (the nginx container).

```bash
cd frontend
npm run dev
npm run type-check    # vue-tsc — the only automated gate; there is no JS test runner
npm run build         # type-check + build
npm run format        # oxfmt
```

### Stack scripts

`./deploy.sh` (VPS deploy: builds SPA, brings up compose, migrates, seeds only on empty DB, SHA-256-verifies and applies the approved parts workbook, caches config/routes/views). `scripts/` holds backup/restore, `smoke-compose.sh`, `test-integration.sh`, `security-smoke.sh`, `reconcile-env.sh`.

## Environment gotcha

Application env vars must be set in the **root `.env`**, not `backend/.env`. Compose reads the root file and injects its (often empty) defaults into the containers as real environment variables; Laravel's `Env` reads `$_SERVER` before the dotenv file, so a value present only in `backend/.env` is silently ignored. This bites hardest on `FRONTEND_URL`, `ACCOUNT_EMAIL_*`, `GRAPH_*`, and `LDC_ERP_*`.

## Backend architecture

Request flow: **route → controller (`Gate::authorize` + `$request->validate`) → Action → Resource**.

- `app/Http/Controllers/` — thin. Authorize, validate, delegate, serialize. Catch `\DomainException` from actions and return **409** with its message; validation failures surface as 422.
- `app/Actions/<Domain>/` — every state transition. The house pattern is: wrap in `DB::transaction`, re-read the row with `lockForUpdate()`, guard the current status (throw `DomainException` if illegal), mutate, `AuditLogger::log($event, $subject, $before, $after)`, dispatch notifications, return `$model->fresh()`. `CloseWorkOrder` is the fullest example. Simple field updates with no preconditions may stay inline in the controller with an explicit `AuditLogger::log()` call.
- `app/Policies/` — the authorization source of truth. Do not put role checks in controllers; extend the policy instead.
- `app/Queries/` — every index and report. A query class owns role scoping, an `$allowedSorts` allowlist (`?sort=field:dir`), filters, and returns `cursorPaginate($perPage)`. **All list endpoints are cursor-paginated**; there is no offset pagination.
- `app/Http/Resources/` — response shapes. Assume tests assert them.
- `app/Enums/` — statuses and roles are backed enums (`WorkOrderStatus`, `MaintenanceRequestStatus`, `RoleCode`, `OperationalStatus`, `MaintenanceStatus`, `PmTriggerType`, …). Never compare against inline strings.

### Domain state

- **Maintenance Request:** `pending_review` → `converted` | `rejected` | `cancelled` (all terminal).
- **Work Order:** `open` → `in_progress` → `completed` → `closed`; any non-closed → `cancelled`. Closed is terminal and immutable.
- **Roles:** `administrator`, `maintenance_manager`, `technician`, `logistics`, `requester`, `service`. Technicians are row-scoped to their own assignments inside the query classes.
- Closing a WO also reverts the asset's operational status, stamps the PM assignment's `last_triggered_*`, and cascades a reset to lower PM levels (L1–L4).

### Maintenance Category is the routing key

`assets.maintenance_category_id` is **NOT NULL** and is the only asset classification ATMS owns. It is what selects a WO form template and what a PM rule may cover. `fa_subclass_code` is written by the ERP sync, so ATMS may *describe* an asset with it (reports, asset tags) but must never *route* behaviour by it. Do not reintroduce subclass-based routing.

- The column defaults to a seeded `UNCLASSIFIED` category (`MaintenanceCategory::UNCLASSIFIED_CODE`), captured by id in the migration that added the constraint. That is what lets the ERP sync keep creating assets it knows no category for. It cannot be deactivated, and the API refuses to clear an asset's category — "not classified" is a category, not a null.
- **WO forms:** `form_template_maintenance_category` pivot, with `is_active` mirrored from the template so a partial unique index can enforce *at most one active template per category*. `FormTemplateCategoryPivot` owns that mirror and the conflict guard; never write the pivot directly.
- **PM rules:** `pm_rule_maintenance_category` pivot records intent and is **expanded** into ordinary per-asset assignments by `ReconcilePmCategoryAssignments`, never resolved at read time — each assignment owns its own baseline, and a newly covered asset needs one interval of grace. `asset_pm_assignments.origin` separates `category` rows from `manual` ones.
- **Precedence:** reconciliation may create and withdraw its own rows only. A null `assigned_by`/`deactivated_by` marks its own work; a filled one marks a person's. It must never reactivate what a person deactivated, or a per-asset opt-out reverts on the next sync.

### Notifications and email

All mail goes through Microsoft Graph `sendMail` in production. Notifications live in `app/Notifications/`, use the `AccountEmailNotification` concern (shared channel, retries, mailbox overlap lock), implement `ShouldQueueAfterCommit` so a rolled-back transition cannot emit mail, and return a payload from `toAccountEmail()` — they never render HTML or pick recipient addresses themselves. That is the transport's job (`app/Services/Notifications/`). Dispatch from the Action that owns the transition, never from a controller or model event. Use `App\Support\FrontendUrl` for links a person opens in a browser; `url()` only for API URLs.

### Auth

SPA uses Sanctum cookie/session auth (`statefulApi()`, proxies trusted at `*`). Machine clients use `POST /api/auth/token` and pass through `EnsureTokenAbilities`.

### Jobs

`routes/console.php`: `SyncErpPartsJob` weekly Mondays 03:00, `EvaluatePmRulesJob` daily 06:00, both `Africa/Tripoli`, `withoutOverlapping()` keyed by `App\Support\Jobs\OverlapKeys`.

`EvaluatePmRulesJob` only chunks assignment ids and fans out `EvaluatePmAssignmentsJob`; the work happens there, through `PmEvaluationRunner` with a `PmEvaluationBatch` of pre-loaded readings and suppressions. Two properties must survive any change: readings/suppressions are loaded **per chunk, not per assignment**, and due-ness is checked **before** the transaction so the `lockForUpdate` is paid only for assignments that look due. `ReconcilePmCategoryAssignmentsJob` is dispatched `afterCommit` from the Actions that change PM coverage.

⚠️ **The `queue` and `scheduler` containers hold PHP classes in memory from boot.** After changing job or Action code against a running stack, `docker compose restart queue scheduler` — otherwise jobs fail against the old code with errors that make no sense against the source on disk, and the failure is invisible from the UI. Check `queue:failed` when queued work silently does not happen.

### Test suite

~100 test files, mostly feature tests, running on **PostgreSQL** against a separate `atms_testing` database via the dedicated `testing` connection — SQLite cannot be used (baseline migration uses PostgreSQL-only syntax, and matching production avoids masking driver bugs). Read the comments in `backend/phpunit.xml` before touching it: any env var pinned there needs **both** a forced `<env>` and a `<server>` twin, because the container injects real environment variables and Laravel reads `$_SERVER` first. This is what keeps the suite off the live Graph mailbox and on the sync queue driver.

## Frontend architecture

- `src/lib/api.ts` — the single HTTP client. Handles CSRF single-flight, a one-shot replay on 419, and a global redirect to `/login` on 401 (opt out with `skipAuthRedirect`). Never call `fetch` directly in a component. `VITE_API_ORIGIN` drives same-origin vs split-subdomain deployment; do not hardcode an origin.
- **Data loading is composable-first.** One `use<Feature>.ts` per screen owns fetching and state; views stay presentational. Reports follow this literally: one backend Query + Resource + one `use<Name>Report.ts` + one view under `src/views/reports/`.
- **Two list modes.** Default is client mode: `fetchList()` in `src/lib/dataTableSource.ts` walks every cursor page into memory, then `AppDataTable` (TanStack) sorts/filters/searches/paginates in the browser. Unbounded data must not use it — `useAuditLogs.ts` is the server-side, load-more counterexample to copy for anything that grows without limit.
- `AppDataTable.vue` caches sort/filter/search/page-size per route+label across navigation; column definitions live in `src/lib/*Columns.ts`.
- `src/stores/` — only `auth.store` (single-flight `/auth/me` probe, role computeds) and `ui.store`. Everything else is a composable.
- `src/router/index.ts` — lazy routes; guards enforce `meta.public`, `meta.requiresAdmin`, `meta.requiresAdminOrManager`.
- `src/types/index.ts` — single barrel for all API types, including `CursorPage<T>`.
- UI is shadcn-vue (`src/components/ui/`, reka-nova style, Lucide icons) on Tailwind v4. Design tokens are CSS custom properties in `src/style.css`. **The app is light-theme only — the `dark` variant block was removed deliberately; do not re-add it.** Prefer existing tokens over raw color values.
- `vite-plugin-vue-devtools` is installed but intentionally disabled in `vite.config.ts` (its fixed overlay added a blank page to every print); re-enable only if you re-check print output.
- Feature flags are build-time Vite env vars in `src/lib/features.ts` — flipping one requires a rebuild.

## Progress tracking (keep current)

`.kilo/STATE.md` and `.kilo/TLD.md` are the project's progress files and must be updated as work happens — not at the end of a project, and not only when asked.

- **`.kilo/STATE.md`** — session log, newest section first. Add a `## Session — YYYY-MM-DD` entry for what was done, what was decided, what broke and why, and anything a future session would otherwise rediscover the hard way. Record decisions with their reasoning so they aren't reopened.
- **`.kilo/TLD.md`** — the live tracker: 🔴 In Progress, 🟡 Recently Completed (things the other side of the stack needs to know, e.g. an API contract change), 🟠 Deferred (**every deferred item needs a trigger** — "later" is not one), 🟢 Done, 🔵 External Blockers.

Read both at the start of a session; update them during or immediately after the work.

## Conventions worth knowing

- Times are stored in UTC; display timezone is `Africa/Tripoli`.
- Attachments live on the `attachments` disk, backed by a Docker volume, not in the repo.
- When a detail is disputed, the code is authoritative: routes in `backend/routes/api.php`, access rules in `app/Policies/`, transitions in `app/Actions/` and their tests, response shapes in `app/Http/Resources/` and feature tests.
