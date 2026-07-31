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
- `app/Jobs/EvaluatePmRulesJob.php` evaluates PM assignments; `SyncErpPartsJob.php`
  is the current ERP sync job.
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

- ATMS owns operational maintenance data and current direct location updates.
- ERP integration is parts-focused. Do not reintroduce asset ERP sync or mock ERP
  services without an explicit product decision.
- UTC is stored; the current display timezone defaults to `Africa/Tripoli`.
- Use Laravel/PHP conventions already present in sibling code: strict types where
  used, explicit parameter and return types, descriptive action names, and no
  controller-hidden workflow logic.
- PHP changes require focused PHPUnit coverage and `vendor/bin/pint --dirty --format agent`.

## Where to inspect a disputed detail

| Detail | Source of truth |
|---|---|
| Route exists or changed | `backend/routes/api.php` |
| Request validation | `backend/app/Http/Requests/` |
| Response shape | `backend/app/Http/Resources/` and feature tests |
| Access control | `backend/app/Policies/` |
| State transition | `backend/app/Actions/` and tests |
| SPA route/UI behavior | `frontend/src/router/index.ts` and the target view/composable |
