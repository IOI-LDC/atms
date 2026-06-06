# Mock ERP Service

## Purpose

The project will ship a separate lightweight mock ERP service so ATMS can be
developed and demonstrated when the client ERP connection is unavailable.

The mock service is a substitute data source for demos and testing. It does not
change the rule that the real client ERP is the source of truth in an integrated
deployment.

## Deployment

- Run as a separate Docker Compose service.
- Use a minimal Laravel 13 application running on PHP 8.4.
- Use a dedicated SQLite database inside the mock ERP container.
- Enable only through an explicit Docker Compose profile.
- Support local OrbStack development and VPS demo deployments.
- Connect to ATMS through the internal Docker network only.
- Do not expose the mock ERP API directly to the public internet.

## Capabilities

- Store mock asset records in its own table.
- Store mock part records in its own table.
- Provide a small read-only HTTP API for listing and retrieving mock assets and parts.
- Provide deterministic seed data for repeatable demos and automated tests.
- Provide no management UI.

The HTTP API must expose only read operations. It must not provide create,
update, patch, or delete endpoints. Mock source records may be changed only
through deterministic seed data or container initialization outside the API.

The SQLite database is disposable demo infrastructure. It must be rebuilt from
deterministic migrations and seed data when the mock ERP container is
initialized or explicitly reset. It does not require a persistence volume.

## Asset API Contract

The initial mock ERP asset representation contains:

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

The ATMS ERP adapter maps these source fields to the corresponding local asset
fields. Additional fields must not be added without an explicit documentation
decision.

## Part API Contract

The initial mock ERP part representation contains:

- `id`
- `code`
- `name`
- `description`
- `unit_of_measure`
- `category`
- `status`
- `updated_at`

The ATMS ERP adapter maps these source fields to the corresponding local part
fields. Additional fields must not be added without an explicit documentation
decision.

## ATMS Integration

ATMS must call the mock ERP through HTTP using the same ERP adapter boundary
that will later support the real client ERP.

ATMS sync jobs must not read the mock ERP database directly.

ATMS must authenticate each mock ERP request using a static service API key in
an HTTP header. The header name and key value must be supplied through
environment variables. The key must not be committed to source control or
included in application logs.

Requests with a missing or invalid service API key must be rejected.

## Pagination and Filtering

Asset and part list endpoints must:

- Use cursor pagination.
- Accept a configurable page size with a server-enforced maximum.
- Support an optional `updated_since` timestamp filter for incremental sync.
- Return a stable next-cursor value when more records are available.

Records must be ordered deterministically so pagination does not skip or repeat
records during a normal sync run.

## Unresolved Decisions

No mock ERP design decisions remain unresolved in this document.
