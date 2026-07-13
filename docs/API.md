# ATMS API Reference

Base path: `/api`. JSON is used except attachment uploads, which use
`multipart/form-data`. Authentication is required unless marked public.

## Authentication

| Endpoint | Purpose |
|---|---|
| `POST /auth/login` | Start SPA session; throttled. |
| `POST /auth/activate` | Consume one-time account-activation token; throttled. |
| `POST /auth/forgot-password` / `POST /auth/reset-password` | Password-reset lifecycle; throttled. |
| `POST /auth/token` | Issue a machine token; throttled. |
| `POST /auth/logout`, `GET /auth/me`, `POST /auth/change-password` | Authenticated session lifecycle. |
| `GET /health/live`, `GET /health/ready` | Public liveness/readiness probes. |

All remaining endpoints require Sanctum authentication and token abilities. Exact
request fields, validation, role visibility, and response resources live beside the
route's controller in `backend/`; tests are the contract for edge cases.

## Response and integration conventions

- `200`/`201` responses return JSON. `204` represents a successful no-content
  operation. Validation failures use `422`; missing authentication uses `401`; a
  policy failure uses `403`; an invalid state transition can use `409`.
- List read models use the project cursor-pagination shape when pagination is
  required: `data`, `links`, and `meta`. Preserve all active query parameters when
  following a cursor. Do not replace a public query name with an internal column
  name.
- Timestamps are ISO-8601 UTC. The SPA is responsible for display-timezone
  formatting.
- Attachment creation uses `multipart/form-data`; other writes use JSON. A file is
  limited to 20 MB and accepts PDF, common image, Word, and Excel formats.

## Dashboard and assets

| Method | Endpoint | Notes |
|---|---|---|
| GET | `/dashboard` | Operational counts and role-adaptive dashboard read model. |
| GET | `/dashboard/kpis` | Reliability/process metrics and recent relocations. Null metric values mean no basis to calculate, not zero. |
| GET / POST | `/assets` | Cursor list and authorized asset creation. Public filters remain stable. |
| GET / PATCH | `/assets/{asset}` | Detail and authorized operational update. |
| GET | `/assets/by-tag` | Find asset by printed tag. |
| POST | `/assets/{asset}/suggest-tag` | Generate a proposed asset tag. |
| GET | `/assets/{asset}/meter-readings` | Reading history. |
| POST | `/assets/{asset}/meter-readings` | Record a reading. |
| PATCH / DELETE | `/assets/{asset}/meter-readings/{reading}` | Update/delete only while policy and reading state permit. |
| POST | `/assets/{asset}/meter-readings/{reading}/confirm` | Confirm a reading; lower confirmed values are rejected. |
| GET | `/assets/{asset}/location-history` | Direct-update history. |
| POST | `/assets/{asset}/location` | Direct Phase 1 location change: `location_id` required; `reason` and `notes` optional. |
| POST | `/assets/{asset}/book`, `/assets/{asset}/unbook` | Booking lifecycle. |
| GET | `/assets/{asset}/maintenance-history` | Derived maintenance read model. |
| GET / POST | `/assets/{asset}/attachments` | Asset attachment list/upload. |

## Maintenance and work orders

| Method | Endpoint | Notes |
|---|---|---|
| GET | `/maintenance-requests` | Cursor list with policy-scoped visibility. |
| POST | `/maintenance-requests/corrective` | Create a corrective MR. |
| GET / PATCH | `/maintenance-requests/{maintenanceRequest}` | Detail and pending-request update. |
| POST | `/maintenance-requests/{maintenanceRequest}/approve` | Manager/Admin approval; atomically creates WO. |
| POST | `/maintenance-requests/{maintenanceRequest}/reject` | Rejection; PM requests require appropriate suppression context. |
| POST | `/maintenance-requests/{maintenanceRequest}/cancel` | Cancellation; PM requests create suppression context. |
| GET / POST | `/maintenance-requests/{maintenanceRequest}/attachments` | MR attachment list/upload. |
| GET | `/work-orders` | Cursor list with supported filters. |
| GET / PATCH | `/work-orders/{workOrder}` | Detail and permitted execution update. |
| POST | `/work-orders/{workOrder}/assign`, `/start`, `/complete`, `/close`, `/cancel` | State transitions; close/cancel remain Manager/Admin actions. |
| POST / DELETE | `/work-orders/{workOrder}/parts` | Add a part line; remove it using `/parts/{partLine}`. |
| POST | `/work-orders/{workOrder}/asset-status` | Set permitted post-work asset status. |
| GET | `/work-orders/{workOrder}/form` | Read attached WO form. |
| PATCH | `/work-orders/{workOrder}/form/fields/{field}` | Update captured form value. |
| POST | `/work-orders/{workOrder}/form/sync`, `/form/defer-sync` | Accept newest template snapshot or defer it. |
| GET / POST | `/work-orders/{workOrder}/attachments` | WO attachment list/upload. |

## PM, parts, locations, and attachments

| Method | Endpoint | Notes |
|---|---|---|
| GET / POST | `/pm-rules` | Rule-template list/create. |
| GET / PATCH | `/pm-rules/{pmRule}` | Rule detail/update. |
| POST | `/pm-rules/{pmRule}/deactivate`, `/reactivate` | Template lifecycle. |
| GET | `/pm-rules/{pmRule}/assignments` | Assignment read model. |
| POST | `/pm-rules/evaluate-all` | Explicit global PM evaluation. |
| GET / POST | `/assets/{asset}/pm-assignments` | Assignment list/create. |
| GET | `/assets/{asset}/pm-assignments/{assignment}` | Assignment detail. |
| POST | `/assets/{asset}/pm-assignments/{assignment}/deactivate`, `/reactivate`, `/evaluate` | Assignment lifecycle/manual evaluation. |
| GET | `/parts`, `/parts/{part}` | Parts read model. |
| PATCH | `/parts/{part}` | Authorized parts update. |
| GET / POST | `/parts/{part}/attachments` | Part attachment list/upload. |
| GET | `/locations` | Active locations available to authenticated users. |
| GET | `/list-options/{group}` | Read-only dropdown vocabulary. |
| GET | `/attachments/{attachment}/download` | Download an authorized attachment. |
| DELETE | `/attachments/{attachment}` | Policy-controlled soft deletion. |

## Admin endpoints

Administrators manage company settings, users, employees, ERP parts sync, audit
logs, locations, master data, FA subclass type codes, API clients, usage-reading
types, and WO-form templates beneath `/admin/…`. The full endpoints are:

- `GET/PATCH /admin/company-settings`
- user list/detail/update, reset-password, deactivate, and reactivate under
  `/admin/users`
- employee list/import/provisioning under `/admin/employees`
- `GET /admin/erp/sync-jobs` and `POST /admin/erp/sync-parts`
- `GET /admin/audit-logs`
- location and master-data CRUD under `/admin/locations` and `/admin/master-data`
- FA subclass type-code CRUD under `/admin/fa-subclass-type-codes`
- API-client list/create/read/revoke under `/admin/api-clients`
- usage-reading-type CRUD under `/admin/usage-reading-types`
- WO-form template, field, reorder, deactivate, and reactivate endpoints under
  `/admin/wo-forms/templates`

Some read access is intentionally shared with a Maintenance Manager; the policy
remains authoritative.

## Reports

All report endpoints are `GET /reports/{name}` and are read-only:

`upcoming-pm`, `assets-by-location`, `pm-compliance`, `overdue-pm`,
`asset-status-distribution`, `wo-backlog`, `mtbf`, `mttr`, `bad-actors`,
`pm-coverage`, `booking`, `technician-workload`, `throughput`,
`parts-consumption`, `asset-movement`, `form-results`, `meter-progression`, and
`pm-suppression`.

They are backed by `backend/app/Queries/Reports/` and corresponding feature tests.
Use those sources for filters, pagination, summary fields, and calculation
definitions. Representative contracts: `upcoming-pm` defaults to a 30-day window;
`overdue-pm` and `wo-backlog` are cursor-paginated; MTBF/MTTR default to the prior
90 days; and all `per_page` inputs are capped at 500. Deferred report ideas are not
API contracts.

## API-change checklist

1. Update the route, Form Request, policy, action/query, resource, and focused
   PHPUnit feature tests together.
2. Preserve cursor behavior, deterministic ordering, and existing role visibility.
3. Update this file only for a durable public-contract change; do not create a
   separate handoff document.
