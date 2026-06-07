# API Plan

All endpoints are draft and should be refined during implementation.

## Auth

```text
POST   /api/auth/login
POST   /api/auth/logout
GET    /api/auth/me
POST   /api/auth/activate
POST   /api/auth/forgot-password
POST   /api/auth/reset-password
```

## Dashboard

```text
GET    /api/dashboard/summary
GET    /api/dashboard/pending-requests
GET    /api/dashboard/open-work-orders
GET    /api/dashboard/overdue-pm
```

## Assets

```text
GET    /api/assets
GET    /api/assets/{asset}
GET    /api/assets/{asset}/meter-readings
POST   /api/assets/{asset}/meter-readings
POST   /api/assets/{asset}/meter-readings/{reading}/confirm
GET    /api/assets/{asset}/location-history
POST   /api/assets/{asset}/location
GET    /api/assets/{asset}/maintenance-history
GET    /api/assets/{asset}/attachments
POST   /api/assets/{asset}/attachments
GET    /api/attachments/{attachment}/download
DELETE /api/attachments/{attachment}
```

Requester asset responses must be restricted to active assets and basic fields
needed for Corrective Maintenance Request creation. Requesters must not receive
maintenance history, location history, attachments, or ERP raw/reference
details.

The maintenance-history endpoint assembles a read model from authoritative
source records. It must not depend on a duplicate maintenance-history table.

Raw ERP JSON payloads must be excluded from normal asset and part API resources.
Only Administrator-authorized diagnostic responses may expose raw ERP payloads.
Other permitted roles receive mapped ERP reference fields only.

Only Logistics, Maintenance Manager, or Administrator may call the asset
location update endpoint.

Meter reading rules:

- Requesters may create unverified readings.
- A Corrective Maintenance Request may include a supporting unverified reading.
- Administrator, Maintenance Manager, or Technician may confirm readings.
- Only confirmed readings update current meter values or participate in PM evaluation.
- Confirmation rejects a value lower than the latest confirmed reading for the same asset and reading type.
- Confirmed readings cannot be edited or deleted in MVP.
- Corrections use a new valid reading and an Administrator technical audit note.

## Parts

```text
GET    /api/parts
GET    /api/parts/{part}
GET    /api/parts/{part}/attachments
POST   /api/parts/{part}/attachments
```

## Maintenance Requests

```text
GET    /api/maintenance-requests
POST   /api/maintenance-requests/corrective
GET    /api/maintenance-requests/{request}
POST   /api/maintenance-requests/{request}/approve
POST   /api/maintenance-requests/{request}/reject
POST   /api/maintenance-requests/{request}/cancel
POST   /api/maintenance-requests/{request}/attachments
```

Maintenance Request cancellation rules:

- Cancellation is allowed only while the request is `pending_review`.
- Approval and Work Order creation are atomic.
- Successful approval transitions directly from `pending_review` to `converted`; there is no stored `approved` status.
- The created Work Order inherits and stores the Maintenance Request priority at the time of conversion.
- A converted request cannot be cancelled.
- After conversion, the Work Order cancellation endpoint must be used.
- Cancellation stores the actor, timestamp, and reason transactionally.
- A Requester may cancel only their own user-created corrective request.
- Maintenance Manager or Administrator may cancel any pending request.
- A system-generated preventive request may be cancelled only by Maintenance Manager or Administrator.

## Work Orders

```text
GET    /api/work-orders
GET    /api/work-orders/{workOrder}
PATCH  /api/work-orders/{workOrder}
POST   /api/work-orders/{workOrder}/assign
POST   /api/work-orders/{workOrder}/start
POST   /api/work-orders/{workOrder}/parts
DELETE /api/work-orders/{workOrder}/parts/{partLine}
POST   /api/work-orders/{workOrder}/attachments
POST   /api/work-orders/{workOrder}/complete
POST   /api/work-orders/{workOrder}/close
POST   /api/work-orders/{workOrder}/cancel
```

Completion and closure rules:

- Only Maintenance Manager or Administrator may assign or reassign a Work Order.
- The assignee must be an active Technician.
- A Work Order cannot transition to `in_progress` without an active Technician assignee.
- Administrator and Maintenance Manager may edit execution details on non-closed, non-cancelled Work Orders.
- Technician edits are limited to assigned Work Orders before completion.
- Every execution-detail mutation must create an audit entry with redacted before/after values.
- A Technician may complete only an eligible Work Order assigned to that Technician.
- Completion validates and stores all required completion fields transactionally.
- A completed Work Order is locked against further Technician execution edits.
- Only a Maintenance Manager or Administrator may close a completed Work Order.
- The close operation runs transactionally.
- Closing finalizes the Work Order and updates PM baselines where applicable; the history read model reflects those source changes.
- Closed Work Orders are permanently immutable and cannot be reopened.

Cancellation rules:

- Only Maintenance Manager or Administrator may cancel a Work Order.
- Cancellation is allowed only from `open`, `in_progress`, or `completed`.
- A cancellation reason is required.
- Cancellation stores the actor and timestamp and runs transactionally.
- Cancelled Work Orders are terminal and read-only.

## PM Rules

```text
GET    /api/pm-rules
POST   /api/pm-rules
GET    /api/pm-rules/{rule}
PATCH  /api/pm-rules/{rule}
POST   /api/pm-rules/{rule}/deactivate
POST   /api/pm-rules/{rule}/reactivate
POST   /api/pm-rules/evaluate
```

PM Rules are never physically deleted through the application. Deactivation
preserves historical references. Inactive rules are not evaluated by the
scheduler and may be reactivated by an authorized user.

Deactivation rules:

- Block deactivation while the PM Rule has an active maintenance chain.
- The guard must be checked transactionally.
- Resolve or cancel the pending request or non-terminal Work Order before deactivation.
- Preserve all historical Maintenance Request, Work Order, and suppression references after deactivation.

PM evaluation rules:

- A PM Rule may have only one active maintenance chain at a time.
- Active means a `pending_review` request or a converted Work Order in `open`, `in_progress`, or `completed`.
- Rejected or cancelled preventive requests create a PM occurrence suppression.
- The scheduler must not regenerate the same suppressed occurrence.
- A future due occurrence may be generated only after it exceeds the applicable suppression date or reading boundary.
- Closed or cancelled Work Orders end the active chain; future generation still depends on due criteria and suppression checks.
- Concurrent scheduled or manual evaluations must not create duplicates.
- Automatic PM evaluation runs once daily by default in `Africa/Tripoli`.
- Administrator and Maintenance Manager may trigger manual PM evaluation.

Preventive request rejection or cancellation input includes:

- `reason`
- `suppressed_until_date`, nullable
- `suppressed_until_reading`, nullable

The backend derives and stores the PM rule, asset, trigger type, trigger
date/reading, decision actor, and decision timestamp.

Suppression validation:

- Date-triggered occurrence: require `suppressed_until_date`.
- Reading-triggered occurrence: require `suppressed_until_reading`.
- Simultaneous date and reading occurrence: require both.

Generated preventive requests store explicit `triggered_by_date` and
`triggered_by_reading` flags so the decision form and backend validation do not
infer the triggering dimensions from nullable values.

## Administration

```text
GET    /api/admin/employees
POST   /api/admin/employees/import
POST   /api/admin/employees/{employee}/provision-user

GET    /api/admin/users
PATCH  /api/admin/users/{user}
POST   /api/admin/users/{user}/deactivate
POST   /api/admin/users/{user}/reactivate
POST   /api/admin/users/{user}/resend-activation
POST   /api/admin/users/{user}/send-password-reset

GET    /api/admin/roles

GET    /api/admin/locations
POST   /api/admin/locations
PATCH  /api/admin/locations/{location}

GET    /api/admin/master-data/{groupKey}
POST   /api/admin/master-data/{groupKey}
PATCH  /api/admin/master-data/items/{item}

GET    /api/admin/erp-sync/jobs
POST   /api/admin/erp-sync/assets/run
POST   /api/admin/erp-sync/parts/run

GET    /api/admin/audit-logs

GET    /api/admin/company-settings
PATCH  /api/admin/company-settings
```

Location-definition and master-data mutation endpoints are Administrator-only.
Maintenance Managers and Logistics users may use active locations in operational
location updates but may not manage the definitions.

Administrator and Maintenance Manager may trigger manual ERP sync endpoints.
Only Administrator may manage ERP connection, credentials, adapter selection,
and synchronization schedule settings.

Automatic asset and parts synchronization runs once weekly by default in
`Africa/Tripoli`. Scheduled and manual sync runs must use overlap prevention.

Technical audit logs are append-only and Administrator-readable. The MVP
provides basic filtering only and no audit campaign, mutation, or deletion API.

Users are never physically deleted. Deactivation immediately prevents
authentication and invalidates active sessions while preserving historical
record ownership and audit references.

Company settings are Administrator-only. MVP company settings include the
display timezone, initially `Africa/Tripoli`.

SharePoint employee import and user provisioning are Administrator-only.
Imported employees receive no access until explicitly provisioned with one
fixed role. Activation and password-reset links use hashed, expiring, one-time
tokens. No endpoint accepts an Administrator-supplied user password.

Activation links expire after 24 hours. Password-reset links expire after 60
minutes.

Provisioning copies the unique company `emp_id` from the imported employee into
the local user and links the user to the employee record. `emp_id` is immutable
after provisioning.

## API Rules

- Persist timestamps in UTC and return ISO 8601 timestamps.
- The frontend displays timestamps in the configured company timezone, initially `Africa/Tripoli`.
- Maintenance Request numbers use unique sequential `MR-######` identifiers.
- Work Order numbers use unique sequential `WO-######` identifiers.
- Business numbers are generated database-atomically and are separate from internal primary keys.
- Work Orders are not created directly by normal users.
- Approving a Maintenance Request creates a Work Order.
- PM-generated Maintenance Requests are created by scheduled backend jobs.
- ERP-synced fields should be protected from manual edits unless explicitly allowed.
- Attachment uploads are limited to 20 MB per file.
- Attachment validation uses a centralized allowlist for PDF, common images, Word, and Excel files.
- Executables and archives are rejected.
- The backend detects and stores MIME type instead of trusting client-provided metadata.
- Attachment deletion is a soft delete that stores deletion actor and timestamp.
- Soft-deleted attachments are excluded from normal lists and cannot be downloaded.
- No attachment restore endpoint or UI is included in MVP.
