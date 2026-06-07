# Implementation Instructions for Codex

## Locked Backend Versions

- Laravel 13
- PHP 8.4
- Laravel Sanctum SPA cookie/session authentication
- Bearer API tokens are not the primary authentication method for the main web application
- Each user has one of six seeded fixed roles; enforce authorization with Laravel policies and do not add multiple roles, custom roles, a permission matrix UI, or a granular permission package.

## Locked Infrastructure Decisions

- Use one Docker Compose service model for local OrbStack development and VPS production.
- Use environment-specific Docker Compose overrides or configuration.
- Use PostgreSQL-backed Laravel database queues for MVP.
- Store attachments on Laravel local storage backed by a persistent Docker volume.
- Keep Redis and MinIO out of the default MVP deployment.
- Ship a separate mock ERP container under an explicit Docker Compose profile.
- Implement the mock ERP as a minimal Laravel 13 application running on PHP 8.4.
- Use a dedicated disposable SQLite database for the mock ERP, rebuilt from deterministic migrations and seed data.
- Keep the mock ERP API on the internal Docker network only.
- Give the mock ERP a read-only asset/part API and deterministic seed data, with no mutation endpoints or management UI.
- Authenticate ATMS-to-mock-ERP HTTP requests with a static service API key supplied through environment variables.

## Always Read First

Before generating code, read:

- `README.md`
- `01-product/PRD.md`
- `01-product/IN_SCOPE.md`
- `01-product/OUT_OF_SCOPE.md`
- `03-backend/ARCHITECTURE.md`
- `03-backend/DATABASE_SCHEMA_DRAFT.md`

## Do Not Add Out-of-Scope Features

Do not implement:

- Financial asset management
- Procurement
- Full inventory/warehouse management
- Parts costing
- Labor tracking
- Technician wallets
- Gate passes/logistics
- Handover
- Advanced checklist engine
- Native mobile app
- QR/barcode scanning
- ERP write-back
- Offline sync

## Preserve Workflow Integrity

The backend must enforce:

- Maintenance Requests can be created by users or system.
- Maintenance Manager approves/rejects Maintenance Requests.
- Maintenance Requests may be cancelled only while pending review.
- Approval and Work Order creation are atomic; converted requests cannot be cancelled.
- Maintenance Requests transition directly from pending review to converted on approval; do not add an approved status.
- Work Orders inherit and store the Maintenance Request priority at conversion.
- Generate unique human-readable `MR-######` and `WO-######` numbers using database-atomic sequences, separate from internal primary keys.
- Store timestamps in UTC, return ISO 8601 values, and use `Africa/Tripoli` as the initial configurable company display timezone.
- Only Administrator or Maintenance Manager may assign/reassign Work Orders, and only to active Technicians.
- A Work Order must have an active Technician assignee before moving to in progress.
- Administrator and Maintenance Manager may edit execution details on non-terminal Work Orders for operational recovery.
- Audit every Work Order execution-detail mutation with redacted before/after values.
- After conversion, use the Work Order cancellation workflow.
- Requesters may cancel only their own pending user-created corrective requests.
- Maintenance Manager and Administrator may cancel any pending request, including system-generated preventive requests.
- Work Orders are created only from approved Maintenance Requests.
- Maintenance history is a derived read model; do not add a duplicate maintenance-history table.
- Closing a Work Order updates its authoritative source fields and applicable PM baselines; the history view reflects those changes.
- Technicians may mark eligible assigned Work Orders as completed.
- Only Maintenance Managers and Administrators may close completed Work Orders.
- Completed Work Orders are locked against Technician execution edits.
- Closed Work Orders are permanently immutable; do not add a reopen workflow.
- Maintenance Manager and Administrator may cancel open, in-progress, or completed Work Orders with a required reason.
- Cancelled Work Orders are terminal and read-only; Technicians cannot cancel.
- Logistics, Maintenance Manager, and Administrator may update asset physical location.
- Logistics has no maintenance approval, Work Order execution, PM Rule, or administration permissions.
- Requesters may submit unverified meter readings from an asset or with a Corrective Maintenance Request.
- Administrator, Maintenance Manager, and Technician may confirm meter readings.
- Use nullable confirmation user/timestamp fields instead of a meter-reading status workflow.
- Only confirmed readings update current meter values and participate in PM calculations.
- Confirmed readings are append-only and monotonically non-decreasing per asset and reading type.
- Do not add decreasing-reading overrides, editing, or deletion in MVP.
- Allow only one active maintenance chain per PM Rule and prevent duplicates under concurrent evaluations.
- Rejected or cancelled preventive requests create occurrence-level PM suppression records.
- The scheduler must not regenerate the same suppressed PM occurrence.
- For date-or-reading rules, require suppression boundaries for each dimension that actually triggered; require both when both triggered simultaneously.
- PM Rules are deactivated/reactivated and never physically deleted through the application.
- Block PM Rule deactivation while an active maintenance chain exists and preserve all historical references after deactivation.
- Limit attachments to 20 MB and allow only PDF, common images, Word, and Excel documents.
- Reject executables and archives; detect MIME type server-side.
- Soft-delete attachments, retain metadata and deletion audit fields, and provide no restore UI in MVP.
- Retain soft-deleted attachment metadata and physical files indefinitely; do not add a purge job or retention policy in MVP.
- VPS deployment requires nightly PostgreSQL and attachment-volume backups with seven daily and four weekly copies, plus documented restore verification.
- Maintain an append-only technical audit log for security-sensitive and workflow actions.
- Audit logs are Administrator-readable only and must redact secrets and sensitive authentication data.
- Do not add audit campaigns, audit mutation/deletion APIs, or an advanced governance interface.
- Retain technical audit logs indefinitely in MVP; do not add a purge job or retention policy.
- User accounts are activated/deactivated and never physically deleted.
- Deactivation blocks authentication and invalidates active sessions while preserving historical references.
- Import SharePoint employees into a local directory without granting access.
- Only Administrator may select an imported employee, assign one fixed role, provision an ATMS account, and send activation/reset links.
- Users set their own passwords through hashed, expiring, one-time activation/reset tokens.
- Do not implement self-registration or Administrator-assigned plaintext passwords.
- Store the unique immutable company `emp_id` on both imported employees and linked ATMS users.
- Activation links expire after 24 hours; password-reset links expire after 60 minutes.
- Only Administrator may manage location definitions and master-data values.
- Administrator and Maintenance Manager may trigger manual ERP sync runs.
- Only Administrator may manage ERP connection and synchronization schedule settings.
- Default ERP asset and parts sync frequency is weekly in `Africa/Tripoli`.
- Default PM evaluation frequency is daily in `Africa/Tripoli`.
- Administrator and Maintenance Manager may trigger manual PM evaluation.
- Prevent overlap between scheduled and manual ERP sync or PM evaluation jobs.
- Requesters may search active assets and view basic fields required for Corrective Maintenance Request creation.
- Do not expose asset maintenance history, location history, attachments, or ERP raw/reference details to Requesters.
- Raw ERP payloads are Administrator-only and must be excluded from normal API resources.
- Logistics has no Parts Reference access in MVP.
- Do not add technician hours, labor rates/costs, timesheets, utilization, or productivity reporting.
- PM Rules must target one individual ERP-linked asset only; do not add category, asset-type, unit/package, group, or template targeting.
- Work Order parts are operational usage records only; do not add stock balances, warehouse transactions, procurement, valuation, or costing.
- Keep Logistics limited to asset location updates/history; do not add gate passes, shipments, transport documents, delivery notes, handovers, custody approvals, or chain-of-custody workflows.

## Testing Priority

Add tests for:

- MR creation
- MR approval creating WO
- MR rejection
- WO closure
- PM rule evaluation
- ERP sync upsert
- Attachment permissions
