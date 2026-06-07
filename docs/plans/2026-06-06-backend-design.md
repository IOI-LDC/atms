# ATMS Backend Design

**Date:** 2026-06-06  
**Status:** Approved  
**Scope:** Backend, infrastructure, mock ERP, authentication, integrations, and testing

## Architecture

ATMS uses a conventional modular Laravel monolith. The implementation is
domain-organized and action-driven for workflow operations, without strict
module/package overhead and without a thin CRUD-only architecture.

### Applications

- `backend/`: Laravel 13 on PHP 8.4.
- `mock-erp/`: minimal Laravel 13/PHP 8.4 read-only API using disposable,
  deterministically seeded SQLite.

### Infrastructure

- PostgreSQL stores ATMS operational data and Laravel database queues.
- Nginx fronts the Laravel PHP-FPM application.
- Queue worker and scheduler containers use the same backend image.
- Local OrbStack and VPS production use one Docker Compose service model with
  environment-specific overrides.
- The mock ERP is enabled through an explicit Compose profile and is available
  only on the internal Docker network.
- Attachments use Laravel local storage on a persistent Docker volume.
- Redis and MinIO are optional future upgrades, excluded from the default MVP.

### SharePoint Portal Boundary

SharePoint acts only as the company's internal application portal. A normal
link opens the separately hosted ATMS web application. ATMS is not deployed to
SharePoint SitePages, embedded in SharePoint, or implemented through SPFx.

The SharePoint portal may be open to all internal users, but that does not grant
ATMS access. ATMS retains its own Laravel Sanctum login, account activation,
session handling, and fixed-role authorization. SharePoint or Microsoft Entra
SSO is excluded from MVP.

### Backend Organization

The backend is organized around:

- Authentication and access control
- Employee directory and SharePoint import
- Assets
- Parts
- Maintenance Requests
- Work Orders
- PM Rules
- Locations
- Attachments
- ERP Sync
- Administration and master data
- Technical audit
- Dashboard and reporting

Controllers remain thin. Form Requests validate input, Resources shape output,
Policies enforce access, query services build read models, Jobs handle
background work, and transactional Action classes implement workflows.

## Authentication And Users

The main web application uses Laravel Sanctum SPA cookie/session
authentication with CSRF protection. Bearer tokens are not the primary web
authentication method.

SharePoint is an employee-directory source, not an authentication dependency.
Administrators import employees into a local `employees` table, select which
employees become ATMS users, assign one fixed role, and send activation links.

- `emp_id` is unique and immutable on both employee and linked user records.
- Employee import does not grant ATMS access.
- Self-registration is disabled.
- Activation tokens are hashed, one-time, and expire after 24 hours.
- Password-reset tokens are hashed, one-time, and expire after 60 minutes.
- Production activation and password-reset emails are delivered through
  Microsoft Power Automate.
- Users set their own passwords.
- Users are activated/deactivated and never physically deleted.

Laravel owns token generation, hashing, expiry, single-use enforcement,
delivery retries, and audit records. A queued transport abstraction invokes an
authenticated Power Automate flow in production and a fake transport in local
development and tests. Tenant, application, flow, and mailbox details remain
environment-specific deployment configuration. Secrets, plaintext tokens, and
complete activation/reset URLs are not logged.

## Authorization

Each user has exactly one seeded, immutable role:

- Administrator
- Maintenance Manager
- Technician
- Logistics
- Requester
- Viewer

Laravel Policies enforce role behavior and record ownership. The frontend
reflects these rules but is not the security boundary.

Important role boundaries include:

- Administrators manage users, imported employees, fixed-role assignment,
  master data, locations, ERP settings, company settings, and audit logs.
- Maintenance Managers approve requests, assign Work Orders, manage PM Rules,
  close or cancel Work Orders, and run manual ERP or PM jobs.
- Technicians update and complete assigned Work Orders and confirm meter
  readings.
- Logistics records asset physical location changes only.
- Requesters create corrective requests, view their own requests, and submit
  unverified supporting readings.
- Viewers receive permitted read-only operational data.

## Data And Workflows

Authoritative source records are retained. Asset maintenance history is a
derived read model assembled from Maintenance Requests, Work Orders, parts,
readings, location histories, and attachments. No duplicate maintenance-history
table is created.

### Maintenance Requests

Statuses:

- `pending_review`
- `rejected`
- `converted`
- `cancelled`

Approval atomically creates exactly one Work Order and transitions directly
from `pending_review` to `converted`. There is no stored `approved` status.

Pending corrective requests may be cancelled by their Requester owner.
Administrators and Maintenance Managers may cancel any pending request.
Converted requests cannot be cancelled; their Work Order must be cancelled.

### Work Orders

Statuses:

- `open`
- `in_progress`
- `completed`
- `closed`
- `cancelled`

Normal transition:

`open → in_progress → completed → closed`

A Work Order must be assigned to an active Technician before entering
`in_progress`. The assigned Technician marks it `completed`. A Maintenance
Manager or Administrator closes it. Closed Work Orders are permanently
immutable and cannot be reopened.

Maintenance Managers and Administrators may cancel `open`, `in_progress`, or
`completed` Work Orders with a required reason. Cancelled Work Orders are
terminal and read-only.

Work Orders receive database-atomic `WO-######` numbers and store a
conversion-time snapshot of the Maintenance Request priority. Requests use
database-atomic `MR-######` numbers.

### Meter Readings

Requesters may submit readings as supporting information. These remain
unverified until confirmed by an Administrator, Maintenance Manager, or
Technician.

Confirmation is represented by nullable confirmation actor and timestamp
fields, not a reading-status workflow. Only confirmed readings update current
meter values or affect PM calculations.

Confirmed readings are append-only and monotonically non-decreasing per asset
and reading type. MVP has no edit, delete, or decreasing-reading override.

### PM Rules

PM Rules apply to specific assets. They support `date`, `reading`, and
`date_or_reading` triggers.

Each rule targets exactly one ERP-linked asset. Category, asset-type,
unit/package, group, and reusable-template targeting are excluded from MVP.

Only one active maintenance chain may exist per rule. An active chain is a
pending request or a converted Work Order in `open`, `in_progress`, or
`completed`.

Rejected or cancelled preventive requests create occurrence-level suppression
records. Suppression is required only for dimensions that triggered:

- Date trigger: date suppression boundary
- Reading trigger: reading suppression boundary
- Simultaneous triggers: both boundaries

PM Rules are deactivated/reactivated, never physically deleted. Deactivation is
blocked while an active chain exists. Historical references are preserved.

## Integrations

### ERP

ERP assets and parts are consumed through an adapter contract. The real client
ERP transport and mapping remain unresolved until credentials and API details
are available.

The mock ERP:

- Exposes read-only asset and part APIs.
- Uses a static environment-configured service API key.
- Uses cursor pagination and optional `updated_since`.
- Provides deterministic seeded data.
- Has no mutation API or management UI.

ATMS stores mapped ERP fields and Administrator-only raw payloads. Local
operational fields are never overwritten by sync.

### SharePoint

The SharePoint adapter imports employee directory records. Administrators
explicitly provision selected employees as users. SharePoint is not queried
during login.

Power Automate is used separately as the production transport for account
activation and password-reset email. It is not the source of employee data and
does not own ATMS authentication tokens or account state.

## API

The backend exposes a JSON REST API using:

- Form Requests
- Laravel Resources
- Explicit workflow endpoints
- Policy and query-scope authorization
- Cursor pagination for large lists
- ISO 8601 UTC timestamps

Response conventions:

- `422`: validation failure
- `403`: authorization failure
- `409`: invalid transition or concurrent-state conflict

All workflow mutations are transactional and audited.

## Attachments

Attachments support Assets, Parts, Maintenance Requests, and Work Orders.

- Maximum size: 20 MB per file
- Allowed: PDF, common images, Word, and Excel
- Rejected: executables, scripts, disk images, and archives
- MIME type is detected server-side
- Files are private and downloaded through authorized routes
- Deletion is soft deletion with actor and timestamp
- Metadata and physical files are retained indefinitely
- No restore UI or purge policy exists in MVP

## Explicit MVP Exclusions

- No technician hour logs, labor rates, labor costs, timesheets, utilization,
  or productivity reporting
- No grouped PM rules; PM Rules target one ERP-linked asset
- No stock balances, inventory valuation, procurement, warehouse transactions,
  or parts costing
- No gate passes, shipments, transport documents, delivery notes, handovers,
  custody approvals, or chain-of-custody workflows

## Jobs And Reliability

- ERP asset sync: weekly
- ERP parts sync: weekly
- PM evaluation: daily
- Scheduling timezone: `Africa/Tripoli`
- Manual ERP sync and PM evaluation: Administrator or Maintenance Manager
- Scheduled and manual jobs use overlap prevention
- Jobs are idempotent and retry only transient failures
- ERP row failures are isolated and recorded
- Concurrency-sensitive workflows use transactions and row locking

All persisted timestamps use UTC. User-facing display uses the configurable
company timezone, initially `Africa/Tripoli`.

## Audit

The application maintains an append-only technical audit log for
security-sensitive and workflow actions. It records actor, event, subject,
request metadata, and redacted before/after context.

Audit logs:

- Are Administrator-readable only
- Cannot be mutated or deleted through application APIs
- Are retained indefinitely
- Never contain passwords, session cookies, service API keys, attachment
  contents, or unredacted secrets

No advanced governance or audit-campaign module is included.

## Deployment And Backups

The default Compose topology includes:

- Nginx
- Laravel PHP-FPM application
- PostgreSQL
- Queue worker
- Scheduler

The mock ERP is profile-based. Redis and MinIO are optional future services.

VPS backups include:

- Nightly PostgreSQL backups
- Nightly attachment-volume backups
- Seven daily retained copies
- Four weekly retained copies
- Storage separate from active application volumes
- Documented and periodically verified restore procedures

## Testing

Testing includes:

- Feature tests for workflows and role boundaries
- Unit tests for PM calculations, suppression, and ERP mapping
- PostgreSQL integration tests
- Mock ERP contract tests
- Concurrency tests for exactly-one Work Order creation and PM duplicate
  prevention
- Sanctum, CSRF, inactive-user, field-restriction, attachment-authorization,
  and redaction security tests
- Docker smoke tests for app, PostgreSQL, worker, scheduler, storage, and mock
  ERP profile
- Repeatable backup restore verification
