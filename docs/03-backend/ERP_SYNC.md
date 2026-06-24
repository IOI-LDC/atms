# ERP Sync Design


> ⚠️ **Parts sync blocked** — awaiting parts API page name from ERP team.
> See [`docs/05-delivery/TDL.md`](../05-delivery/TDL.md) for all pending ERP questions.

> **Status:** ERP parts sync is owned by the **SM (Store Management)** subsystem.
> ERP asset sync has been removed from scope — assets are managed fully within
> ATMS. This document describes the remaining parts-only sync integration with
> the LDC ERP.

## Principle

LDC ERP remains the source of truth for **parts reference data**. ATMS does
**not** sync assets from ERP; assets are created and managed within ATMS. SM
syncs parts into its local tables for workflow integrity, performance, search,
and operational use. ATMS reads parts from SM tables only to populate Work Order
part-request forms, which submit into SM's workflow.

## Sync Direction

Initial scope is read-only ERP integration:

**ERP → SM (parts only)**

No ATMS write-back to ERP is included in MVP. No asset sync endpoint exists.

## LDC ERP Connection

The LDC ERP is **Microsoft Dynamics 365 Business Central** exposed through the
standard Business Central REST API. Authentication uses **Microsoft Entra ID**
(Azure AD) OAuth2 client credentials.

### Authentication

| Parameter | Value |
|---|---|
| **Auth provider** | Microsoft Entra ID (Azure AD) |
| **Grant type** | `client_credentials` |
| **Token endpoint** | `https://login.microsoftonline.com/{tenant_id}/oauth2/v2.0/token` |
| **Content type** | `application/x-www-form-urlencoded` |
| **Scope** | `https://api.businesscentral.dynamics.com/.default` |

Request body (url-encoded):

```
grant_type=client_credentials
&scope=https://api.businesscentral.dynamics.com/.default
&client_id={LDC_ERP_CLIENT_ID}
&client_secret={LDC_ERP_CLIENT_SECRET}
```

Response:

```json
{
  "token_type": "Bearer",
  "expires_in": 3599,
  "access_token": "..."
}
```

### Business Central API

The LDC ERP exposes data through **custom OData V4 API pages** in Business Central.

Base URL pattern:
```
https://api.businesscentral.dynamics.com/v2.0/{tenant_id}/{environment}/ODataV4/Company('{company_name}')
```

| API Page | Purpose | Used by |
|---|---|---|
| `fixedAssestAPI` | Fixed asset master records | **Not used** — assets managed within ATMS (documented for reference only) |
| **(parts API)** | Parts/item master records | **SM** (parts sync) — **API page name TBD** |

**Fixed assets curl (reference only):**
```
curl --location 'https://api.businesscentral.dynamics.com/v2.0/{tenant}/Production/ODataV4/Company('{company}')/fixedAssestAPI'   --header 'Content-Type: application/json'   --header 'Authorization: Bearer {token}'
```

> The assets endpoint is **not used by ATMS** — assets are managed fully within
> ATMS. This curl command is documented for reference only. The equivalent
> parts API page name is needed from the ERP team before parts sync can be
> completed.

### Token lifecycle

1. Backend POSTs client credentials (url-encoded) to the Entra token endpoint.
2. Entra returns an access token + `expires_in` (typically 3599 seconds).
3. Backend caches the token (see [Token Management](#token-management)).
4. Backend uses the token as `Authorization: Bearer <token>` for Business Central API calls.
5. When the token expires (or a 401 is received), the backend re-acquires a fresh token.

### Endpoint pagination

The Business Central API returns all records in a single response — no pagination needed (confirmed by ERP team). No `$top`, `$skip`, or `@odata.nextLink` handling is required. Incremental sync is not used; the full dataset is pulled on every sync run and compared locally.
server-limited page size, and an optional `updated_since` timestamp filter for
incremental synchronization. The exact pagination shape is confirmed in
[Field Mapping](#field-mapping) once the endpoint contracts are finalised.

## ERP Adapter Boundary

The shared backend must access ERP data through an adapter contract. Sync jobs
and local upsert logic depend only on the `App\Contracts\Erp\ErpSource` interface
— never on a specific transport or vendor SDK.

```
config/erp.php  ──▶  LdcErpHttpSource implements ErpSource
                              │
                              ▼
                     SyncParts (Action)   ← SM-owned
                              │
                     SyncErpPartsJob      ← SM-owned
                     (weekly schedule)
                              │
                         Part model      ← SM tables
                         ErpSyncJob model
```

> The `SyncAssets` action and `SyncErpAssetsJob` are deprecated and scheduled for
> removal. No asset sync exists in the documented design.

### ErpSource contract

The contract is vendor-agnostic:

```php
interface ErpSource
{
    public function getParts(?string $updatedSince = null, ?string $cursor = null, int $limit = 100): array;
}
```

Returns `array{data: ExternalPartData[], next_cursor: string|null}`.

### Implementation selection

The concrete implementation is bound in `AppServiceProvider`:

```php
$this->app->singleton(ErpSource::class, LdcErpHttpSource::class);
```

If a second ERP source is ever needed, the binding can be made config-driven
following the same pattern already used by `AccountEmailTransport` (config key
selects the implementation).

## Token Management

Token acquisition is the key difference from the former mock adapter (which used
a static shared API-key header). LDC ERP requires a token-exchange step.

### Acquisition

`LdcErpHttpSource` POSTs the configured credentials (`client_id`, `client_secret`,
`scope`, `grant_type=client_credentials`) as `application/x-www-form-urlencoded`
to `https://login.microsoftonline.com/{tenant_id}/oauth2/v2.0/token` on first
use (or after cache expiry / forced refresh) and stores the resulting bearer
token in the Laravel cache.

### Caching

- The token is cached under a fixed cache key (e.g. `ldc-erp-token`).
- Cache TTL is set slightly below the token's `expires_in` (e.g. `expires_in - 60s`)
  to avoid using a token that is about to lapse mid-request.
- The cache store is `database` (the same store already used by the backend) so the
  token is shared across the `api`, `queue`, and `scheduler` containers within
  a single deployment.

### Refresh on 401

If LDC ERP returns `401 Unauthorized` for a parts call, the adapter:

1. Forgets the cached token.
2. Re-acquires a fresh token.
3. Retries the original request once.

This handles the edge case where the cached token was revoked or expired early.

### Secrets

Client credentials (`client_id`, `client_secret`) must never be committed to
source control or written to logs. They are supplied exclusively through
environment variables and the `config/erp.php` file.

## Field Mapping

> **TODO — pending endpoint contracts.** The response format is OData V4
> (`{"@odata.context":..., "value": [...]}`). The exact field names for the
> parts API page will be confirmed when the page name is provided.
> names for the LDC ERP token and parts endpoints will be confirmed when the
> endpoints are provided.

### Parts

The shared backend expects the following mapped fields per part record:

- `id` — stable ERP part identifier (used as the upsert key)
- `code`
- `name`
- `description`
- `unit_of_measure`
- `category`
- `status`
- `updated_at`

The backend stores the full source record as the raw ERP payload in addition to
mapping the required local fields.

### Mapping responsibility

Field mapping (ERP JSON → `ExternalPartData` DTO) lives entirely inside
`LdcErpHttpSource`. The DTOs, actions, jobs, and models never see raw ERP JSON —
only the normalised DTOs. This isolates vendor-specific shape changes to a
single class.

## Local Operational Fields

The following fields belong to the product family and are never overwritten by
ERP sync:

- Asset records (created and managed within ATMS)
- Current physical location (owned by AM)
- Location history (owned by AM)
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

- Parts sync: weekly, Monday 03:00 `Africa/Tripoli`
- Manual sync: available to Administrator and Maintenance Manager via
  `POST /api/admin/erp/sync-parts`

Scheduled and manual sync runs must use overlap prevention
(`withoutOverlapping()` + `onOneServer()`).

## Sync Job Behaviour

The parts sync job should:

1. Start sync log (`erp_sync_jobs` row, status `running`).
2. Acquire a valid token (from cache or token endpoint).
3. Fetch **all** parts data from ERP in a single call (no pagination).
4. Validate each record.
5. For each ERP record, upsert local record using ERP ID as identity:
   - **ERP-owned columns** are always updated: `erp_part_id`, `erp_part_code`,
     `erp_status`, `erp_raw_data`, `erp_last_synced_at`, and any mapped
     reference fields from the ERP payload.
   - **ATMS local fields** are **never overwritten**: `name`, `description`,
     `unit_of_measure`, `category`, `is_active`, and any other fields editable
     through the ATMS API. The sync reads these fields but does not write them.
6. Insert new records for ERP IDs not yet in the local table (ERP columns
   populated; local fields at defaults).
7. Mark local records that are no longer in the ERP pull (flag as
   `erp_status = 'removed_from_erp'` — never delete).
8. Store raw ERP payload for debugging.
9. Record success, skipped, and failed counts.
10. Store row-level errors (`erp_sync_errors` rows).
11. Complete sync log (status `success`, `partial`, or `failed`).

### Field ownership boundary

| Column group | Owned by | Sync behaviour |
|---|---|---|
| `erp_part_id`, `erp_part_code`, `erp_status`, `erp_raw_data`, `erp_last_synced_at` | ERP (via sync) | **Overwritten** on every sync |
| `name`, `description`, `unit_of_measure`, `category`, `is_active` | ATMS | **Never touched** by sync. Updated only via ATMS API (`PATCH /parts/{part}`). |
| Mapped reference fields (e.g. vendor, class code) | ERP (via sync) | **Overwritten** on every sync |

> **Critical:** If ATMS marks a part as `is_active = false` before the ERP
> marks it as `inactive`, the sync must NOT reactivate it. The local
> `is_active` flag is ATMS territory. The ERP `inactive` field is mapped
> to `erp_status` only — it is informational and does not control the
> local active flag.

Raw ERP payloads are diagnostic data and are visible only to Administrators.
Normal part responses expose mapped ERP reference fields instead.

## Identity Matching

Preferred identity key for Parts: ERP part ID (`erp_part_id`).

Since the full dataset is pulled on every sync, identity matching is
straightforward:
- ERP record exists locally → update ERP-owned columns only.
- ERP record does not exist locally → insert new row.
- Local record no longer in ERP pull → flag, do not delete.

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
| `LDC_ERP_TENANT_ID` | Microsoft Entra tenant ID (directory ID) |
| `LDC_ERP_CLIENT_ID` | Entra app registration client ID |
| `LDC_ERP_CLIENT_SECRET` | Entra app registration client secret |
| `LDC_ERP_ENVIRONMENT` | Business Central environment (e.g. `Production`) |
| `LDC_ERP_COMPANY` | Business Central company name (e.g. `xxxxName-LIVE`) |
| `LDC_ERP_PARTS_API` | Custom API page name for parts (TBD — e.g. `itemsAPI`) |

> No separate base URL or endpoint env var needed — the URL is assembled from
> the components above using the standard BC OData V4 pattern.

### Config file

`config/erp.php`:

```php
return [
    'tenant_id'    => env('LDC_ERP_TENANT_ID'),
    'client_id'    => env('LDC_ERP_CLIENT_ID'),
    'client_secret'=> env('LDC_ERP_CLIENT_SECRET'),
    'environment'  => env('LDC_ERP_ENVIRONMENT', 'Production'),
    'company'      => env('LDC_ERP_COMPANY'),
    'parts_api'    => env('LDC_ERP_PARTS_API'),
    'scope'        => 'https://api.businesscentral.dynamics.com/.default',
    'token_url'    => 'https://login.microsoftonline.com/'.env('LDC_ERP_TENANT_ID').'/oauth2/v2.0/token',
    'base_url'     => 'https://api.businesscentral.dynamics.com/v2.0/'.
                      env('LDC_ERP_TENANT_ID').'/'.
                      env('LDC_ERP_ENVIRONMENT', 'Production').'/ODataV4/'.
                      "Company('".env('LDC_ERP_COMPANY')."')",
    'token_cache_key' => 'ldc-erp-token',
    'timeout' => 30,
];
```

## Migration from Mock ERP (COMPLETED)

The Mock ERP development service and its Docker container have been removed. Migration to the real LDC ERP is complete. Token auth is working; asset endpoint confirmed; parts endpoint pending.
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

The `ErpSource` contract, the `ExternalPartData` DTO, the `SyncParts` action,
the sync job, the `ErpSyncController`, the routes, the scheduler entries, the
`ErpSyncJob`/`ErpSyncError` models, and their migrations all remain unchanged —
only the concrete adapter and config change.

> **Note:** `SyncAssets` action, `SyncErpAssetsJob`, and asset-related ERP
> endpoints and fields are deprecated. Assets are managed within ATMS — no ERP
> asset sync.
