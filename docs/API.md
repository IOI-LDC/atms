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
| GET / POST | `/assets` | Cursor list and authorized asset creation. Public filters remain stable. `?search=` matches a case-insensitive fragment of name, ERP asset code, serial number, or asset tag — every identifier the asset pickers display is matchable, because those pickers search server-side. |
| GET / PATCH | `/assets/{asset}` | Detail and authorized operational update. |
| GET | `/assets/by-tag` | Find asset by printed tag. |
| POST | `/assets/{asset}/suggest-tag` | Generate a proposed asset tag. |
| GET | `/assets/{asset}/meter-readings` | Reading history. |
| POST | `/assets/{asset}/meter-readings` | Record a reading. Accepts optional `entered_delta` — what the operator typed when the WO form takes an amount-since-last-reading rather than a total. Informational: `reading_value` stays the authoritative absolute, and nothing in PM evaluation or reporting reads the delta. |
| PATCH / DELETE | `/assets/{asset}/meter-readings/{reading}` | Update/delete only while policy and reading state permit. PATCH also accepts `entered_delta`, so a reading entered as a delta can be corrected as that same delta with the caller resolving the absolute. Sending no delta while changing `reading_value` **clears** any stored one, rather than leaving a figure that no longer matches. |
| POST | `/assets/{asset}/meter-readings/{reading}/confirm` | Confirm a reading; lower or earlier-dated values than the latest confirmed are rejected with 409. **No UI calls this route** — confirmation happens as a side effect of closing a work order (see `/work-orders/{workOrder}/close`). Kept for machine clients and as the mechanism that close reuses. |
| GET | `/assets/{asset}/location-history` | Direct-update history. |
| POST | `/assets/{asset}/location` | Direct Phase 1 location change: `location_id` required; `reason` and `notes` optional. |
| POST | `/assets/{asset}/book`, `/assets/{asset}/unbook` | Booking lifecycle. |
| GET | `/assets/{asset}/maintenance-history` | Derived maintenance read model. |
| GET / POST | `/assets/{asset}/attachments` | Asset attachment list/upload. |

Asset `operational_status` vocabulary (six values): `ready_for_field` ("Ready
for Field"), `under_maintenance` ("Under Maintenance"), `down` ("Down"),
`under_inspection` ("Under Inspection"), `scraped` ("Scraped"), and `lih` ("Lost
in Hole"). WO close/cancel accept only `down` or `ready_for_field` for the
asset's next status (pre-seeded to `ready_for_field`); a `scraped` asset is
never touched by those transitions.

## Maintenance and work orders

| Method | Endpoint | Notes |
|---|---|---|
| GET | `/maintenance-requests` | Cursor list with policy-scoped visibility. |
| POST | `/maintenance-requests/corrective` | Create a corrective MR. |
| GET / PATCH | `/maintenance-requests/{maintenanceRequest}` | Detail and pending-request update. |
| POST | `/maintenance-requests/{maintenanceRequest}/approve` | Manager/Admin approval; atomically creates WO. |
| POST | `/maintenance-requests/{maintenanceRequest}/reject` | Rejection; PM requests require appropriate suppression context. |
| POST | `/maintenance-requests/{maintenanceRequest}/cancel` | Cancellation; PM requests create suppression context. `decision_type` is `cancelled` for an ordinary cancellation, or `performed_under_repair` when a work-order close retires the request because the service was actually carried out — compliance reporting must not read the second as a skipped service. |
| GET / POST | `/maintenance-requests/{maintenanceRequest}/attachments` | MR attachment list/upload. |
| GET | `/work-orders` | Cursor list with supported filters. |
| GET / PATCH | `/work-orders/{workOrder}` | Detail and permitted execution update. Detail also returns `meter_snapshots` — the asset's meter position per reading type at close, the reference point for "usage since this job". Empty until closed. |
| POST | `/work-orders/{workOrder}/assign`, `/start`, `/complete`, `/close`, `/cancel` | State transitions; close/cancel remain Manager/Admin actions. |
| POST | `/work-orders/{workOrder}/close` | Body: optional `is_failure` (corrective-origin only), `asset_status`, and `serviced_pm_assignment_id`. **Closing confirms the readings taken on this work order** (oldest-first; a reading still failing a monotonicity guard is skipped and audited as `meter_reading.confirm_skipped`, never fatal) and snapshots the asset's meter position per reading type. `serviced_pm_assignment_id` declares that a preventive service was performed alongside the job: it resets that assignment's date and reading baselines, cascades to lower levels, and cancels any pending PM request for it — **409** if the assignment is inactive or belongs to another asset. |
| POST / DELETE | `/work-orders/{workOrder}/parts` | Add a part line; remove it using `/parts/{partLine}`. |
| POST | `/work-orders/{workOrder}/asset-status` | Set permitted post-work asset status. On close/cancel the choice is limited to `down` \| `ready_for_field` (see the vocabulary note under Dashboard and assets). |
| GET | `/work-orders/{workOrder}/form` | Read attached WO form. |
| PATCH | `/work-orders/{workOrder}/form/fields` | Atomic bulk save of captured form values — the write path behind the checklist's Save button. Body `{ fields: [{ id, pre_value?, post_value?, notes? }] }`; partial in both directions (send only changed fields, and an absent slot key keeps its stored value). Any validation failure or duplicate id rejects the whole batch with `422`; an id not belonging to this form (typically dropped by a template sync since the form was opened) rejects it with `409`. Returns the updated form, so no re-fetch is needed. One audit entry per save. |
| PATCH | `/work-orders/{workOrder}/form/fields/{field}` | Update a single captured form value. Retained alongside the bulk route. |
| POST | `/work-orders/{workOrder}/form/sync`, `/form/defer-sync` | Accept newest template snapshot or defer it. |
| GET / POST | `/work-orders/{workOrder}/attachments` | WO attachment list/upload. |

## PM, parts, locations, and attachments

| Method | Endpoint | Notes |
|---|---|---|
| GET / POST | `/pm-rules` | Rule-template list/create. The API still accepts all three `trigger_type` values, but the UI offers **date only** — reading-based triggers are parked until the Job Management feed supplies usage (D-014). Existing `reading` / `date_or_reading` rules keep working on their date dimension. |
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

Administrators manage company settings, users, ERP parts sync, audit
logs, locations, master data, maintenance categories, API clients, usage-reading
types, and WO-form templates beneath `/admin/…`. The full endpoints are:

- `GET/PATCH /admin/company-settings`
- user create/list/detail/update, reset-password, deactivate, and reactivate under
  `/admin/users`
- `POST /admin/users` creates a user directly (name, email, role); the activation
  email is sent to the given address. The SharePoint employee directory is not
  used — see [PRODUCT.md](PRODUCT.md).
- `GET /admin/erp/sync-jobs` and `POST /admin/erp/sync-parts`
- `GET /admin/audit-logs`
- location and master-data CRUD under `/admin/locations` and `/admin/master-data`
- maintenance-category list/create/update under `/admin/maintenance-categories`.
  The `UNCLASSIFIED` category cannot be deactivated: it is the column default for
  `assets.maintenance_category_id`, so new ERP assets keep arriving in it.
- API-client list/create/read/revoke under `/admin/api-clients`
- usage-reading-type CRUD under `/admin/usage-reading-types`
- WO-form template, field, reorder, deactivate, and reactivate endpoints under
  `/admin/wo-forms/templates`. Templates are routed by **Maintenance Category**:
  create and update take `maintenance_category_ids[]`, the list filters on
  `?maintenance_category_id=`, and the payload returns `maintenance_categories`.
  At most one *active* template may serve a category — create returns **422** and
  reactivate/update return **409** naming the colliding category and the template
  already holding it.

There is **no** FA subclass type-code CRUD. Those four `/admin/fa-subclass-type-codes`
routes were removed with D-011: the vocabulary is ERP-owned, so ATMS records unseen
codes during asset import rather than curating them. The read-only
`GET /list-options/fa_subclass_type_codes` remains, because report filters use it.

Some read access is intentionally shared with a Maintenance Manager; the policy
remains authoritative.

## Reports

All report endpoints are `GET /reports/{name}` and are read-only:

`asset-status`, `asset-usage`, `asset-distribution` (alias:
`assets-by-location`), `upcoming-pm`, `pm-compliance`, `overdue-pm`,
`asset-status-distribution`, `wo-backlog`, `mtbf`, `mttr`, `bad-actors`,
`pm-coverage`, `booking`, `technician-workload`, `throughput`,
`parts-consumption`, `asset-movement`, `form-results`, `meter-progression`, and
`pm-suppression`.

They are backed by `backend/app/Queries/Reports/` and corresponding feature tests.
Use those sources for filters, pagination, summary fields, and calculation
definitions. Representative contracts: `upcoming-pm` defaults to a 30-day window;
`overdue-pm` and `wo-backlog` are cursor-paginated; MTBF/MTTR default to the prior
90 days; and all `per_page` inputs are capped at 500.

**Reports group and filter only on fields ATMS owns.** MTBF, MTTR, and
`bad-actors` take an explicit `group_by` dimension — `asset`,
`maintenance_category`, `size`, plus `location` (MTBF/bad-actors) or `technician`
(MTTR). `asset_class` was **removed** as a dimension and now returns 422, and the
legacy `category` value is likewise rejected. Asset-side filtering is by
`maintenance_category_id` (int), not `fa_subclass_code`. `parts-consumption`
returns a nested Part Identity with `asset_maintenance_category` and `asset_size`
per group.

Because every asset now carries a Maintenance Category (defaulting to
`UNCLASSIFIED`), the "Uncategorised" null bucket is unreachable on the
maintenance-category dimension for assets — unclassified assets appear under the
real `UNCLASSIFIED` group and are counted in `summary.total_groups`. Parts keep a
nullable category, so their null bucket still occurs.

Reports also accept `?format=csv` on the same endpoint (not a separate route), so
an export uses identical authorization, filters, and sorting to the table on
screen. Deferred report ideas are not API contracts.

## API-change checklist

1. Update the route, Form Request, policy, action/query, resource, and focused
   PHPUnit feature tests together.
2. Preserve cursor behavior, deterministic ordering, and existing role visibility.
3. Update this file only for a durable public-contract change; do not create a
   separate handoff document.
