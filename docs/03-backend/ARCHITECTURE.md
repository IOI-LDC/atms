# Backend Architecture

## Three Subsystems Sharing One Backend

There is a single Laravel backend, one queue, one scheduler, and one PostgreSQL
database shared by three product subsystems:

- **ATMS** (Asset Maintenance Tracking) — Assets, Maintenance Requests, Work Orders, PM rules, dashboard, RBAC.
- **SM** (Store Management) — Parts catalogue, inventory, stock movement, ERP parts sync, Order → Approval → Dispatch → GR.
- **AM** (Asset Movement) — Asset movement form, location history, movement workflow.

Subsystems live in separate frontend applications but share this backend and
database. Source-of-truth boundaries:

- **Assets** are owned by ATMS and managed fully within ATMS. There is **no ERP asset sync**.
- **Parts** are owned by SM. ERP syncs parts into SM tables. ATMS reads parts only to populate Work Order part-request forms, which submit into SM's workflow. The SM parts tables are the source of truth for parts.
- **Asset location** is owned by AM. ATMS reads the current location from AM tables for display only. The AM location tables are the source of truth for asset location and location history.

> ✅ Phase 1 backend cleanup complete — `SyncErpAssetsJob`, the `erp_asset_id` column, and `MockErpHttpSource` have been removed. Parts-only sync via `LdcErpHttpSource` is the live binding. `SyncErpPartsJob` is the only remaining ERP sync job.

## Locked Backend Stack

- Backend framework: Laravel 13 API backend
- PHP runtime: PHP 8.4
- Database: PostgreSQL
- Deployment: one Docker Compose service model for local OrbStack development and VPS production, with environment-specific overrides
- Background jobs: Laravel Queues using the PostgreSQL database driver for MVP
- Scheduled jobs: Laravel Scheduler
- File storage: Laravel local storage on a persistent Docker volume
- Authentication: Laravel Sanctum SPA cookie/session authentication (web users)
- Machine-to-machine API authentication: Laravel Sanctum API bearer tokens (see [SECURE_REMOTE_API_ACCESS.md](./SECURE_REMOTE_API_ACCESS.md))
- Primary web (browser) authentication must not use bearer API tokens
- Authorization: Laravel policies using one fixed role per user
- Granular permission packages and multiple roles per user are excluded from MVP
- Timestamp storage: UTC
- Initial company display timezone: `Africa/Tripoli`
- Redis and MinIO are optional future upgrades and are not part of the default MVP deployment

## SharePoint Portal Boundary

The company SharePoint portal provides only a normal hyperlink to the
separately hosted product web applications. They are not hosted in SharePoint
SitePages, embedded in SharePoint, or implemented as SPFx web parts.

Access to the internal SharePoint portal does not create an application session or
grant any permissions. The backend continues to authenticate through Laravel Sanctum
SPA cookie/session authentication and authorize users through its five fixed
roles (Administrator, Maintenance Manager, Technician, Logistics, Requester).
SharePoint or Microsoft Entra SSO is not part of MVP.

## Architectural Style

The backend should be a conventional modular Laravel application. It should contain clear domain boundaries but avoid unnecessary over-engineering.

All persisted timestamps use UTC. API responses should use ISO 8601 timestamps.
User-facing display uses the configured company timezone, initially
`Africa/Tripoli`. Only Administrator may change the company timezone.

Recommended domains:

- Auth & Access Control
- Employee Directory / SharePoint Import
- Assets
- Asset Assembly (parent/child relationships, install/removal, swap)
- Parts
- Maintenance Requests
- Work Orders
- PM Rules
- Locations
- Attachments
- ERP Sync
- Master Data & Dropdowns
- Dashboard / Reporting

## Core Backend Responsibilities

The backend owns all business rules:

- ERP parts sync and local upsert logic (owned by SM)
- Asset assembly management (install, remove, swap; cycle prevention; component hours derivation)
- PM rule evaluation
- Preventive Maintenance Request generation
- Transaction-safe prevention of duplicate active maintenance chains per PM Rule
- PM occurrence suppression after preventive request rejection or cancellation
- Corrective Maintenance Request creation
- MR approval/rejection
- Work Order creation from approved MR
- Work Order status transition rules
- Work Order closure rules
- Asset location history
- Asset reading history
- Asset maintenance history read-model assembly from authoritative source records (reads current location from AM tables)
- Attachment ownership and permissions
- Role-based access control
- Append-only technical audit logging
- SharePoint employee import and explicit user provisioning

The MVP architecture must not introduce:

- Labor-hour, labor-rate, labor-cost, timesheet, or productivity domains
- Category, asset-type, unit/package, group, or template PM targeting
- Inventory balances, warehouse transactions, procurement, valuation, or parts costing
- Logistics documents, approvals, handovers, or custody workflows

## Maintenance History

Asset maintenance history must be assembled from authoritative source records:

- Maintenance Requests
- Work Orders
- Work Order parts
- Confirmed and unverified meter readings, labeled appropriately
- Asset location history (source of truth in AM tables)
- Attachments

Do not create a duplicate `maintenance_histories` table. The history endpoint
may use query services, API resources, or database views where useful, but the
underlying domain records remain the source of truth.

## Technical Audit Log

Record security-sensitive and workflow actions in an append-only technical
audit log. Minimum events include:

- Login success and failure
- Logout
- User activation/deactivation and fixed-role assignment
- Maintenance Request approval, rejection, and cancellation
- Work Order assignment, completion, closure, and cancellation
- Work Order execution-detail mutations, including redacted before/after values
- Asset location changes
- Meter reading submission and confirmation
- PM Rule create/update/deactivate/reactivate and suppression decisions
- Manual ERP parts sync runs and ERP configuration changes
- Attachment upload and soft deletion
- Component installation, removal, and swap
- Asset assembly history record creation

Audit logging must not store passwords, session cookies, service API keys,
attachment contents, or unredacted secrets. Audit entries cannot be edited or
deleted through application APIs.

Technical audit logs are retained indefinitely in MVP. Do not implement an
audit-log purge job or configurable retention policy.

## Employee Provisioning

SharePoint is an employee-directory source, not the ATMS authentication system.
The backend imports employee reference records into a local table through a
SharePoint adapter. Imported employees have no application access.

An Administrator selects an imported employee, assigns one fixed role, creates
the linked ATMS user, and sends a one-time activation link. The employee sets
their own password. The same secure one-time-link mechanism supports password
resets. There is no self-registration.

## Production Account Email Transport

Microsoft Power Automate is the production transport for activation and
password-reset emails. Laravel remains responsible for creating and hashing
one-time tokens, enforcing expiry and single use, building the frontend link,
queuing delivery, retrying failed delivery, and recording the audited outcome.

The queued Laravel notification adapter sends only the minimum message payload
required by an authenticated Power Automate flow. The flow sends the email
through the client's approved Microsoft 365 mailbox or connection. Secrets,
plaintext tokens, and complete reset URLs must not be written to application or
flow logs.

The Power Automate endpoint, tenant identifiers, application credentials,
mailbox, and environment-specific flow details are deployment configuration.
Local development and automated tests use a fake transport and do not require
access to the client's Microsoft tenant.

## Recommended Laravel Structure

A pragmatic Laravel structure:

```text
app/
  Actions/
    Maintenance/
    ERP/
    Assets/
  Console/
  Enums/
  Http/
    Controllers/
    Requests/
    Resources/
  Jobs/
  Models/
  Policies/
  Services/
    ERP/
    Maintenance/
    Files/
  Support/
  ViewModels/

database/
  migrations/
  seeders/
  factories/

routes/
  api.php
```

## Use Actions for Business Operations

Important workflow transitions should be implemented as explicit action classes, not hidden inside controllers.

Examples:

- `CreateCorrectiveMaintenanceRequest`
- `GeneratePreventiveMaintenanceRequest`
- `ApproveMaintenanceRequestAndCreateWorkOrder`
- `RejectMaintenanceRequest`
- `CloseWorkOrder`
- `RecordAssetMeterReading`
- `UpdateAssetLocation` (writes to AM location tables)
- `SyncErpParts` (SM-owned; parts only)
- `InstallComponent`
- `RemoveComponent`
- `SwapComponent`
## Background Jobs

ERP parts sync (SM-owned) and PM rule evaluation should run as jobs.

Jobs:

- `SyncErpPartsJob` (SM-owned; no asset sync job exists in the documented design)
- `EvaluatePmRulesJob`
- `GeneratePmRequestsJob`
- `CleanupTemporaryUploadsJob`

`CleanupTemporaryUploadsJob` may remove only abandoned temporary upload files.
It must never purge soft-deleted attachment records or their physical files in
MVP.

## Scheduler

Laravel Scheduler should trigger:

- ERP parts sync (SM-owned; no asset sync)
- PM rule evaluation
- housekeeping jobs

Default schedules in the `Africa/Tripoli` company timezone:

- ERP parts sync: weekly (no asset sync)
- PM rule evaluation: daily

Administrator may configure scheduled run times. Administrator and Maintenance
Manager may trigger manual ERP parts sync and PM evaluation. Scheduled and
manual jobs must use overlap prevention.

## Deployment Pattern

Local OrbStack development and VPS production must use the same Docker Compose
service model. Environment-specific configuration or override files may change
ports, resource settings, credentials, domains, and operational settings without
changing the core service topology.

Default Docker Compose services:

- `app` — Laravel PHP-FPM application
- `web` — Nginx reverse proxy
- `postgres` — PostgreSQL database
- `queue` — Laravel queue worker using the PostgreSQL database queue
- `scheduler` — Laravel scheduler runner

Optional future services:

- `redis` — optional future queue/cache upgrade; excluded from the default MVP deployment
- `minio` — optional future object-storage upgrade; excluded from the default MVP deployment

## Backup Baseline

The VPS deployment must provide:

- Nightly PostgreSQL backups.
- Seven daily PostgreSQL backup copies.
- Four weekly PostgreSQL backup copies.
- Nightly backup of the persistent attachment-storage volume.
- Seven daily attachment backup copies.
- Four weekly attachment backup copies.
- Backup storage separate from the active application volumes.
- Documented restore commands and periodic restore verification.

Secrets and environment configuration must be backed up through an approved
secure operational process, not included in application backup archives.

## Secure Remote API Access

Machine-to-machine API access from external systems (e.g., a remote system
consuming parts QTY data from SM tables) requires three production safeguards:
HTTPS (TLS via reverse proxy), API bearer tokens scoped to read-only, and
strong secrets with locked-down ports.

See [SECURE_REMOTE_API_ACCESS.md](./SECURE_REMOTE_API_ACCESS.md) for the full
specification and deployment checklist.
