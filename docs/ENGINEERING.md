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
- `app/Services/Notifications/GraphAccountEmailTransport.php` is the production
  account-email path. The fake transport is for development/tests only.

Use explicit action classes for state transitions, Form Requests for validation,
policies for authorization, Eloquent resources for API responses, and PHPUnit
feature tests for externally visible behavior.

## Authentication and security

- The SPA uses Sanctum cookie/session authentication. Browser clients must obtain
  the CSRF cookie before state-changing requests.
- Machine clients use `POST /api/auth/token`; authenticated API routes pass through
  `EnsureTokenAbilities`.
- Account activation and password reset use Microsoft Graph `sendMail` in
  production. SMTP AUTH and Power Automate are not supported paths.
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
