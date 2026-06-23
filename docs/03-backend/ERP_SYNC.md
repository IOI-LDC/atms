# ERP Sync Design

> **Status:** Replacing the former mock-erp development adapter with the real
> LDC ERP integration. The mock-erp service and its Docker container have been
> removed from the repository. The backend ERP sync pipeline (contract, actions,
> jobs, routes, models, tests) remains and is being retargeted at LDC ERP.

## Principle

LDC ERP remains the source of truth for fixed assets and parts. ATMS maintains a
local operational copy for workflow integrity, performance, search, attachments,
history, and maintenance operations.

## Sync Direction

Initial scope is read-only ERP integration:

**ERP → ATMS**

No ATMS write-back to ERP is included in MVP.

## LDC ERP Connection

LDC ERP exposes three HTTP endpoints that ATMS integrates with:

| Endpoint | Purpose | Auth |
|---|---|---|
| **Token endpoint** | Acquire a bearer access token | Client credentials (sent in request body) |
| **Assets endpoint** | List fixed-asset reference records | `Authorization: Bearer <token>` |
| **Parts endpoint** | List parts reference records | `Authorization: Bearer <token>` |

The token is **shared** — a single token acquired from the token endpoint
authorises calls to both the assets and parts endpoints until it expires.

### Token lifecycle

1. ATMS POSTs client credentials to the token endpoint.
2. LDC ERP returns an access token plus an expiry (e.g. `expires_in` seconds).
3. ATMS caches the token (see [Token Management](#token-management)).
4. ATMS uses the token as `Authorization: Bearer <token>` for assets/parts calls.
5. When the token expires (or a 401 is received), ATMS re-acquires a fresh token.

### Endpoint pagination

The assets and parts endpoints must support cursor pagination with a
configurable, server-limited page size, and an optional `updated_since`
timestamp filter for incremental synchronization. The exact pagination shape
(cursor field name, page-size parameter name) is confirmed in
[Field Mapping](#field-mapping) once the endpoint contracts are finalised.

## ERP Adapter Boundary

ATMS must access ERP data through an adapter contract. Sync jobs and local
upsert logic depend only on the `App\Contracts\Erp\ErpSource` interface — never
on a specific transport or vendor SDK.

```
config/erp.php  ──▶  LdcErpHttpSource implements ErpSource
                              │
                ┌─────────────┴─────────────┐
                ▼                           ▼
        SyncAssets (Action)         SyncParts (Action)
                │                           │
        SyncErpAssetsJob            SyncErpPartsJob
        (weekly schedule)           (weekly schedule)
                │                           │
            Asset model                Part model
            ErpSyncJob model           ErpSyncJob model
```

### ErpSource contract

The contract is vendor-agnostic and stays stable regardless of backend:

```php
interface ErpSource
{
    public function getAssets(?string $updatedSince = null, ?string $cursor = null, int $limit = 100): array;
    public function getParts(?string $updatedSince = null, ?string $cursor = null, int $limit = 100): array;
}
```

Both methods return `array{data: ExternalAssetData[]|ExternalPartData[], next_cursor: string|null}`.

### Implementation selection

The concrete implementation is bound in `AppServiceProvider`:

```php
$this->app->singleton(ErpSource::class, LdcErpHttpSource::class);
```

If a second ERP source is ever needed (e.g. a different division or a fallback),
the binding can be made config-driven following the same pattern already used by
`AccountEmailTransport` (config key selects the implementation).

## Token Management

Token acquisition is the key difference from the former mock adapter (which used
a static shared API-key header). LDC ERP requires a token-exchange step.

### Acquisition

`LdcErpHttpSource` POSTs the configured credentials to the token endpoint on
first use (or after cache expiry / forced refresh) and stores the resulting
bearer token in the Laravel cache.

### Caching

- The token is cached under a fixed cache key (e.g. `ldc-erp-token`).
- Cache TTL is set slightly below the token's `expires_in` (e.g. `expires_in - 60s`)
  to avoid using a token that is about to lapse mid-request.
- The cache store is `database` (the same store already used by ATMS) so the
  token is shared across the `api`, `queue`, and `scheduler` containers within
  a single deployment.

### Refresh on 401

If LDC ERP returns `401 Unauthorized` for an assets/parts call, the adapter:

1. Forgets the cached token.
2. Re-acquires a fresh token.
3. Retries the original request once.

This handles the edge case where the cached token was revoked or expired early.

### Secrets

Client credentials (`client_id`, `client_secret`) must never be committed to
source control or written to logs. They are supplied exclusively through
environment variables and the `config/erp.php` file.

## Field Mapping

> **TODO — pending endpoint contracts.** The exact request/response field
> names for the LDC ERP token, assets, and parts endpoints will be confirmed
> when the endpoints are provided. The sections below describe the target shape
> that the DTOs and sync actions already expect.

### Fixed Assets

ATMS expects the following mapped fields per asset record:

- `id` — stable ERP asset identifier (used as the upsert key)
- `code` — human-readable asset code
- `name`
- `description`
- `category`
- `serial_number`
- `model`
- `manufacturer`
- `status` — maps to ATMS `erp_status`; `active` → `is_active = true`
- `updated_at` — last-modified timestamp from ERP

ATMS stores the full source record as the raw ERP payload (`erp_raw_data`) in
addition to mapping the required local fields.

### Parts

ATMS expects the following mapped fields per part record:

- `id` — stable ERP part identifier (used as the upsert key)
- `code`
- `name`
- `description`
- `unit_of_measure`
- `category`
- `status`
- `updated_at`

ATMS stores the full source record as the raw ERP payload in addition to mapping
the required local fields.

### Mapping responsibility

Field mapping (ERP JSON → `ExternalAssetData` / `ExternalPartData` DTO) lives
entirely inside `LdcErpHttpSource`. The DTOs, actions, jobs, and models never see
raw ERP JSON — only the normalised DTOs. This isolates vendor-specific shape
changes to a single class.

## Local Operational Fields

The following fields belong to ATMS and are never overwritten by ERP sync:

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

- Assets: weekly, Monday 02:00 `Africa/Tripoli`
- Parts: weekly, Monday 03:00 `Africa/Tripoli`
- Manual sync: available to Administrator and Maintenance Manager via
  `POST /api/admin/erp/sync-assets` and `POST /api/admin/erp/sync-parts`

Scheduled and manual sync runs must use overlap prevention
(`withoutOverlapping()` + `onOneServer()`).

## Sync Job Behaviour

Each sync job should:

1. Start sync log (`erp_sync_jobs` row, status `running`).
2. Acquire a valid token (from cache or token endpoint).
3. Fetch source data from ERP (cursor-paginated).
4. Validate each record.
5. Upsert local record using ERP ID as identity.
6. Store raw ERP payload for debugging.
7. Mark records inactive according to ERP status rules.
8. Record success, skipped, and failed counts.
9. Store row-level errors (`erp_sync_errors` rows).
10. Complete sync log (status `success`, `partial`, or `failed`).

Raw ERP payloads are diagnostic data and are visible only to Administrators.
Normal asset and part responses expose mapped ERP reference fields instead.

## Identity Matching

Preferred identity keys:

- Fixed Assets: ERP asset ID (`erp_asset_id`)
- Parts: ERP part ID (`erp_part_id`)

Serial numbers are not used as the main identity key because they may be missing
or inconsistent.

## Error Handling

Sync errors should not stop the entire job unless the source connection (token
acquisition or initial fetch) fails. Row-level errors are recorded and visible
in ERP Sync History.

Connection-level failures (token endpoint unreachable, repeated 5xx) fail the
job after the configured retry/backoff policy and are logged to the audit trail.

## Configuration

### Environment variables

| Variable | Purpose |
|---|---|
| `LDC_ERP_BASE_URL` | Base URL of the LDC ERP API |
| `LDC_ERP_TOKEN_ENDPOINT` | Path/URL of the token endpoint (relative to base or absolute) |
| `LDC_ERP_ASSETS_ENDPOINT` | Path/URL of the assets endpoint |
| `LDC_ERP_PARTS_ENDPOINT` | Path/URL of the parts endpoint |
| `LDC_ERP_CLIENT_ID` | Client credential ID for token acquisition |
| `LDC_ERP_CLIENT_SECRET` | Client credential secret for token acquisition |

> The exact token-request body shape (field names, grant type) will be confirmed
> when the token endpoint contract is provided. Defaults are captured in
> `config/erp.php`.

### Config file

`config/erp.php` (replaces the former `config/mock-erp.php`):

```php
return [
    'base_url' => env('LDC_ERP_BASE_URL'),
    'token_endpoint' => env('LDC_ERP_TOKEN_ENDPOINT'),
    'assets_endpoint' => env('LDC_ERP_ASSETS_ENDPOINT'),
    'parts_endpoint' => env('LDC_ERP_PARTS_ENDPOINT'),
    'client_id' => env('LDC_ERP_CLIENT_ID'),
    'client_secret' => env('LDC_ERP_CLIENT_SECRET'),
    'token_cache_key' => 'ldc-erp-token',
    'timeout' => 30,
];
```

## Migration from mock-erp

The mock-erp development service and its Docker container have been removed.
The following renames are required to retarget the existing pipeline at LDC ERP
(implementation tracked separately):

| Current | Target |
|---|---|
| `config/mock-erp.php` | `config/erp.php` |
| `MockErpHttpSource` | `LdcErpHttpSource` |
| `MOCK_ERP_URL` / `MOCK_ERP_API_KEY` env vars | `LDC_ERP_*` env vars (see above) |
| Static `X-Service-API-Key` header | Token-exchange + `Authorization: Bearer` |
| `AppServiceProvider` binding to `MockErpHttpSource` | Binding to `LdcErpHttpSource` |
| `tests/Contract/MockErpContractTest` | `tests/Contract/LdcErpContractTest` (token + pagination + mapping) |

The `ErpSource` contract, the `ExternalAssetData`/`ExternalPartData` DTOs, the
`SyncAssets`/`SyncParts` actions, the sync jobs, the `ErpSyncController`, the
routes, the scheduler entries, the `ErpSyncJob`/`ErpSyncError` models, and their
migrations all remain unchanged — only the concrete adapter and config change.
