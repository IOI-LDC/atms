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

Asset `operational_status` vocabulary (**four values** since release 4b):
`ready_for_field` ("Ready for Field"), `under_maintenance` ("Under
Maintenance"), `failure` ("Failure"), and `at_the_field` ("At the Field").

- **`at_the_field` is derived, never sent.** It is written when an asset moves
  to a location classified DEPLOYED (rig or well site) and cleared when it comes
  back. Every manual status control rejects it with 422.
- **Manual moves are gated.** `PATCH /assets/{asset}` and
  `POST /assets/{asset}/location` accept a move for a `ready_for_field` asset
  (any destination) and for an `at_the_field` asset returning to a yard or
  building. A `failure` or `under_maintenance` asset returns **409** — the work
  order decides where it goes. Workflow-driven moves (work-order start, MR
  approval) bypass this gate.
- **Returning from the field** sets `ready_for_field` and stamps
  `condition_status = need_inspection`. Work-order-driven moves do not.
- **Close no longer takes `asset_status`** — it always returns the asset to
  `ready_for_field`. Cancel keeps the caller's choice, now `failure` |
  `ready_for_field`. A **deactivated** asset (`is_active = false`) is never
  touched by any lifecycle transition; that replaces the old "`scraped` is never
  touched" rule.

Assets also carry `condition_status` — the hand-set cause vocabulary (`normal`,
`need_assembly`, `missing_parts`, `need_inspection`), served by
`GET /list-options/asset_conditions` and administered through
`/admin/master-data/asset_conditions`. Validation accepts **active rows only**.
Payloads include `condition_status`, `condition_label`, and
`operational_status_label`.

Removed in 4b: `maintenance_sub_status` and `erp_status` are no longer served or
accepted (an old client sending `maintenance_sub_status` has it ignored, not
rejected). The `down`, `scraped`, `under_inspection` and `lih` values no longer
exist.

## Maintenance and work orders

| Method | Endpoint | Notes |
|---|---|---|
| GET | `/maintenance-requests` | Cursor list with policy-scoped visibility. |
| POST | `/maintenance-requests/corrective` | Create a corrective MR. |
| GET / PATCH | `/maintenance-requests/{maintenanceRequest}` | Detail and pending-request update. |
| POST | `/maintenance-requests/{maintenanceRequest}/approve` | Manager/Admin approval; atomically creates WO. Optional `move_to_location_id` sends the asset somewhere as part of the approval (corrective and preventive alike) — absent keeps its current location. The move, the location-history row and the audit entry share the approval's transaction, so a rejected location rolls the whole approval back (**409** for an inactive location, **422** for an unknown one). |
| POST | `/maintenance-requests/{maintenanceRequest}/reject` | Rejection; PM requests require appropriate suppression context. |
| POST | `/maintenance-requests/{maintenanceRequest}/cancel` | Cancellation; PM requests create suppression context. `decision_type` is `cancelled` for an ordinary cancellation, or `performed_under_repair` when a work-order close retires the request because the service was actually carried out — compliance reporting must not read the second as a skipped service. |
| GET / POST | `/maintenance-requests/{maintenanceRequest}/attachments` | MR attachment list/upload. |
| GET | `/work-orders` | Cursor list with supported filters. |
| GET / PATCH | `/work-orders/{workOrder}` | Detail and permitted execution update. Detail also returns `meter_snapshots` — the asset's meter position per reading type at close, the reference point for "usage since this job". Empty until closed. |
| POST | `/work-orders/{workOrder}/assign`, `/start`, `/complete`, `/close`, `/cancel` | State transitions; close/cancel remain Manager/Admin actions. |
| POST | `/work-orders/{workOrder}/close` | **Requires at least one attachment on the work order — 409 otherwise** (RQ2). Any attachment satisfies it; there is no attachment category, so this is a presence check. Cancel is deliberately not gated. Body: optional `is_failure` (corrective-origin only) and `serviced_pm_assignment_id` — **`asset_status` was removed in 4b**; close always returns the asset to `ready_for_field` and resets its `condition_status` to the vocabulary default. A PM level staged with `PUT /pm-mark` is applied here; an explicit `serviced_pm_assignment_id` **overrides** it and the difference is audited. A staged mark whose schedule was deactivated in the meantime is skipped, audited and reported rather than blocking the close. The response carries a `warnings` array (non-blocking notices — e.g. the asset was flagged Need Inspection **and** no PM level was recorded). **Closing confirms the readings taken on this work order** (oldest-first; a reading still failing a monotonicity guard is skipped and audited as `meter_reading.confirm_skipped`, never fatal) and snapshots the asset's meter position per reading type. `serviced_pm_assignment_id` declares that a preventive service was performed alongside the job: it resets that assignment's date and reading baselines, cascades to lower levels, and cancels any pending PM request for it — **409** if the assignment is inactive or belongs to another asset. |
| POST / DELETE | `/work-orders/{workOrder}/parts` | Add a part line; remove it using `/parts/{partLine}`. |
| PUT / DELETE | `/work-orders/{workOrder}/pm-mark` | **RQ1** — record (or clear) the highest PM level performed during this work order. Body `{ asset_pm_assignment_id }`. `PUT` is **idempotent**: one mark per work order, so re-marking replaces rather than accumulates. Authorization is `updateExecution` — the assigned technician while open/in-progress, Manager/Admin until close. **409** if the schedule belongs to another asset or is inactive. The mark is **staged**: nothing changes until the work order closes, and cancelling discards it. `DELETE` succeeds whether or not a mark exists. Exposed on the work-order payload as `pm_mark`. |
| POST | `/work-orders/{workOrder}/asset-status` | Set the asset's status by hand. Limited to `ready_for_field` \| `under_maintenance` \| `failure` — never `at_the_field`, which only a location change may write. Cancel's own `asset_status` is limited further, to `failure` \| `ready_for_field` (see the vocabulary note under Dashboard and assets). |
| GET | `/work-orders/{workOrder}/form` | Read attached WO form. |
| PATCH | `/work-orders/{workOrder}/form/fields` | Atomic bulk save of captured form values — the write path behind the checklist's Save button. Body `{ fields: [{ id, pre_value?, post_value?, notes? }] }`; partial in both directions (send only changed fields, and an absent slot key keeps its stored value). Any validation failure or duplicate id rejects the whole batch with `422`; an id not belonging to this form (typically dropped by a template sync since the form was opened) rejects it with `409`. Returns the updated form, so no re-fetch is needed. One audit entry per save. |
| PATCH | `/work-orders/{workOrder}/form/fields/{field}` | Update a single captured form value. Retained alongside the bulk route. |
| POST | `/work-orders/{workOrder}/form/sync`, `/form/defer-sync` | Accept newest template snapshot or defer it. |
| GET / POST | `/work-orders/{workOrder}/attachments` | WO attachment list/upload. Uploads are allowed through **completed** — the technician finishes the job, then supplies the paperwork the close requires — and are **403** once the work order is closed or cancelled. Assigned technician, Manager or Admin. No mime allowlist: PDFs, spreadsheets and photographs are all accepted. |

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
| GET | `/parts/export-csv` | **RQ3** — the whole catalogue as CSV (active and inactive), for offline reconciliation. Columns in order: `part_id`, `erp_part_id`, `erp_part_code`, `name`, `unit_of_measure`, `erp_status`, `is_active`, `available_quantity`. Quantities are written at stored precision (`numeric(14,3)`). **Administrator only** — `PartPolicy::importQuantities`, deliberately narrower than `manage()`. Registered above `/parts/{part}` or it binds as a part id. |
| POST | `/parts/import-quantities` | **RQ3** — apply corrected quantities. Multipart `file`. Required headers `part_id`, `erp_part_code`, `available_quantity`; extras ignored; a UTF-8 BOM is tolerated. Matching is on `part_id` with `erp_part_code` cross-checked (blank skips the check; a filled mismatch rejects). Quantities must match `/^\d{1,11}(\.\d{1,3})?$/`. **All-or-nothing** — one bad row returns **422** with `{ errors: ["line N: …"] (first 40), error_count }` and writes nothing. Success returns `{ rows, updated, unchanged }` and audits one `parts.quantity_upload.completed` event carrying the file's SHA-256. Caps: 5 MB / 25,000 rows. ⚠️ Interim — `SyncParts` overwrites these quantities wholesale once the live ERP feed lands (🟠 D-020). |
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
