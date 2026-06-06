# ERP Sync Design

## Principle

ERP remains the source of truth for fixed assets and parts. ATMS maintains a local operational copy for workflow integrity, performance, search, attachments, history, and maintenance operations.

The client ERP connection method is not yet confirmed. An HTTP API is currently
the most likely integration method, but the final transport, authentication,
credentials, and field mapping must not be assumed.

## Sync Direction

Initial scope is read-only ERP integration:

**ERP → ATMS**

No ATMS write-back to ERP is included in MVP.

## ERP Adapter Boundary

ATMS must access ERP data through an adapter contract. Sync jobs and local upsert
logic must not depend directly on the mock ERP implementation or a specific
client ERP transport.

The adapter implementation must be selected through configuration. The initial
implementations are:

- Mock ERP HTTP API adapter for development and demos.
- Real client ERP adapter after the connection method and field mapping are confirmed.

The exact real ERP API contract remains unresolved.

## Mock ERP Service

A separate lightweight mock ERP container will be shipped for local development
and VPS demonstrations when the client ERP connection is unavailable.

The mock ERP service will:

- Use a minimal Laravel 13 application running on PHP 8.4.
- Use a dedicated, disposable SQLite database rebuilt from deterministic migrations and seed data.
- Maintain mock asset and part source records in its own database tables.
- Expose a small read-only HTTP API for listing and retrieving assets and parts.
- Support deterministic seed data for repeatable demos and automated tests.
- Expose only the basic ERP fields needed by the initial ATMS sync contract.
- Be enabled through an explicit Docker Compose profile.
- Be accessible only through the internal Docker network.
- Have no management UI.
- Provide no create, update, patch, or delete API endpoints.
- Require a static service API key in an HTTP header configured through environment variables.

Mock source records may be changed only through deterministic seed data or
container initialization outside the API.

The service API key must not be committed to source control or written to logs.

Mock ERP asset and part list endpoints use cursor pagination with a configurable,
server-limited page size. They support an optional `updated_since` timestamp
filter for incremental synchronization.

The final basic field contract must be confirmed separately before
implementation.

## Synced Data

### Fixed Assets

Initial mock ERP fields:

- `id`
- `code`
- `name`
- `description`
- `serial_number`
- `category`
- `manufacturer`
- `model`
- `status`
- `updated_at`

ATMS stores the full source record as the raw ERP payload in addition to mapping
the required local fields.

### Parts

Initial mock ERP fields:

- `id`
- `code`
- `name`
- `description`
- `unit_of_measure`
- `category`
- `status`
- `updated_at`

ATMS stores the full source record as the raw ERP payload in addition to mapping
the required local fields.

## Local Operational Fields

The following fields belong to ATMS and should not be overwritten by ERP sync:

- Current physical location
- Location history
- Usage readings
- Maintenance status
- PM rules
- Maintenance requests
- Work orders
- Parts used on work orders
- Attachments
- Maintenance history

## Sync Frequency

Frequency is configurable by Administrator. MVP defaults:

- Assets: once per week in `Africa/Tripoli`
- Parts: once per week in `Africa/Tripoli`
- Manual sync: available to Administrator and Maintenance Manager

Scheduled and manual sync runs must use overlap prevention.

## Sync Job Behaviour

Each sync job should:

1. Start sync log.
2. Fetch source data from ERP.
3. Validate each record.
4. Upsert local record using ERP ID/code as identity.
5. Store raw ERP payload for debugging.
6. Mark missing/inactive records according to ERP status rules.
7. Record success, skipped, and failed counts.
8. Store row-level errors.
9. Complete sync log.

Raw ERP payloads are diagnostic data and are visible only to Administrators.
Normal asset and part responses expose mapped ERP reference fields instead.

## Identity Matching

Preferred identity keys:

- Fixed Assets: ERP asset ID or ERP asset code
- Parts: ERP part ID or ERP part code

Serial numbers should not be used as the main identity key because they may be missing or inconsistent.

## Error Handling

Sync errors should not stop the entire job unless the source connection fails. Row-level errors should be recorded and visible in ERP Sync History.
