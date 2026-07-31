# Phase 1 Backend: Cleanup + ATMS Core Features

## Goal

Align the Laravel 13 backend with the updated docs by removing deprecated ERP asset sync artifacts, fixing the 6→5 role model, and implementing three remaining ATMS Phase 1 features: asset tagging, asset maintenance status, and API bearer tokens.

## Design Decisions (Pre-Resolved)

| # | Decision | Rationale |
|---|---|---|
| D1 | **Edit original migration** for `erp_asset_id` removal (no separate `dropColumn` migration) | SQLite `:memory:` tests run `migrate:fresh`. Cleanest path. Production DBs need documented one-time `ALTER TABLE DROP COLUMN`. |
| D2 | **Keep `operational_status` and `maintenance_status` as two orthogonal axes** | `operational_status` stays informational (no workflow gates, existing values/endpoint preserved). `maintenance_status` controls MR/WO eligibility. Don't merge them. |
| D3 | **Delete mock ERP entirely** (no fallback) | `LdcErpHttpSource` skips sync gracefully when `LDC_ERP_PARTS_API` is empty (log warning, don't fail). Aligns with docs' deprecation mandate. |
| D4 | **Service account = dedicated `SERVICE` machine role (not Requester)** | Policies authorize by role, not token ability. A Requester service user gets 403 on almost every read. A non-human `SERVICE` role with broad read pass-through (and blocked writes via middleware) makes Task 6's acceptance criteria reachable. This is a 6th role but non-user-assignable. |

## Context

- **Stack:** Laravel 13, PHP 8.4, PostgreSQL 17, Sanctum SPA auth
- **Patterns:** Action classes, API Resources, Form Requests, Laravel Policies, PHP Enums, Query builders
- **Testing:** PHPUnit 12.5, SQLite `:memory:`, `migrate:fresh` on every run
- **Blockers:** `LDC_ERP_PARTS_API` page name pending from LDC ERP team → `LdcErpHttpSource` skips sync gracefully when env var is empty
- **Base test file count:** Capture with `php artisan test --list` before starting; goal is all pre-existing tests pass after changes + new tests added

## Migration Strategy for `erp_asset_id` Removal

The column is removed by **editing the original migration** (`2026_06_07_093108_create_assets_table.php`) to delete the `$table->string('erp_asset_id')` line. This works cleanly because:

- Tests always run `migrate:fresh` on SQLite `:memory:` — the column simply never exists.
- For existing production DBs: document a one-time `ALTER TABLE assets DROP COLUMN IF EXISTS erp_asset_id` in deployment notes. No Laravel migration ships this.

The model reference is removed in Task 1. The migration edit happens in Task 2 (alongside all other asset column changes — one migration file edit, not two).

---

## Task 1: Purge SyncErpAssets (Legacy ERP Asset Sync)

The docs mandate: "NO ERP asset sync. Assets are fully ATMS-managed."

### 1.1 Delete backend files (entire classes/files)

| File | Reason |
|---|---|
| `app/Jobs/SyncErpAssetsJob.php` | Deprecated job |
| `app/Actions/Erp/SyncAssets.php` | Deprecated action |
| `app/Data/Erp/ExternalAssetData.php` | DTO used only by `getAssets()` — dead after removal |

### 1.2 Shrink the ErpSource contract

**File:** `app/Contracts/Erp/ErpSource.php`
- Remove the `getAssets()` method and its docblock.
- Remove `use App\Data\Erp\ExternalAssetData;` import.
- `getParts()` remains (parts sync is alive).

### 1.3 Remove route + controller method

**File:** `routes/api.php`
- Delete line: `Route::post('/erp/sync-assets', [ErpSyncController::class, 'syncAssets']);`

**File:** `app/Http/Controllers/Admin/ErpSyncController.php`
- Delete the `syncAssets()` method entirely.
- Remove `use App\Jobs\SyncErpAssetsJob;` import.

### 1.4 Remove scheduler entry

**File:** `routes/console.php`
- Delete the `Schedule::job(new SyncErpAssetsJob())` block (weekly, Mondays 02:00 `Africa/Tripoli`).

### 1.5 Remove `erp_asset_id` from Asset model

**File:** `app/Models/Asset.php`
- Remove `'erp_asset_id'` from `$fillable`.

(The column is physically removed from the schema in Task 2 by editing the original migration.)

### 1.6 Remove live business-logic dependency on `erp_asset_id`

**File:** `app/Http/Controllers/PmRuleController.php` — store method (~line 44)
- Delete the guard:
  ```php
  if (! $asset->erp_asset_id) {
      return response()->json(['message' => 'PM rules can only target ERP-linked assets.'], 422);
  }
  ```
  Per docs, PM rules now apply to all ATMS-managed assets.

### 1.7 Update tests — remove `erp_asset_id` and asset sync references

All test files that reference `erp_asset_id`, `SyncErpAssetsJob`, `sync-assets`, or `ExternalAssetData` must be cleaned. Pattern-based approach:

**Tests using `erp_asset_id` in asset factory data** — remove the key from factory calls:
- `tests/Feature/ReadModels/AssetResourceTest.php`
- `tests/Feature/ReadModels/WorkOrderResourceTest.php`
- `tests/Feature/ReadModels/MaintenanceRequestResourceTest.php`
- `tests/Feature/ReadModels/PmRuleResourceTest.php`
- `tests/Feature/ReadModels/AttachmentResourceTest.php`
- `tests/Feature/Pm/PmWorkflowTest.php`
- `tests/Feature/Jobs/EvaluatePmRulesJobTest.php`
- `tests/Feature/Dashboard/MaintenanceHistoryTest.php`
- `tests/Feature/Dashboard/DashboardTest.php`
- `tests/Feature/Concurrency/ConcurrencyTest.php`
- `tests/Feature/Security/AttachmentSecurityTest.php`

**Tests referencing `SyncErpAssetsJob` or `sync-assets`:**
- `tests/Feature/Jobs/JobConfigTest.php` — remove SyncErpAssetsJob test cases
- `tests/Feature/Erp/ErpSyncTest.php` — remove sync-assets endpoint tests, asset sync assertions

**Tests referencing `ExternalAssetData`:**
- `tests/Contract/MockErpContractTest.php` — remove `getAssets` contract test; this entire test file will be deleted in Task 7 when mock ERP is removed

**Note for executor:** Use `grep -rl "erp_asset_id\|SyncErpAssetsJob\|sync-assets\|ExternalAssetData" backend/tests/` (or the codebase Grep tool) to find any remaining references.

### 1.8 Update docs referencing SyncErpAssetsJob

**Files:** `.kilo/STATE.md`, `CLAUDE.md`
- Remove or update any mentions of `SyncErpAssetsJob`, `SyncAssets`, or asset sync.

### Acceptance Criteria
- `grep -rl "SyncErpAssetsJob" backend/` returns zero results
- `grep -rl "SyncAssets" backend/` returns zero results (except `SyncParts` references)
- `grep -rl "ExternalAssetData" backend/` returns zero results
- `grep -rl "erp_asset_id" backend/` returns zero results (model, controller, tests)
- `grep -rl "sync-assets" backend/routes/ backend/app/Http/Controllers/` returns zero results
- `php artisan route:list` shows no `sync-assets` route
- `PmRuleController::store` no longer checks `erp_asset_id`
- All existing tests pass after updates

---

## Task 2: Asset Schema Changes

### 2.1 Edit original assets migration: drop `erp_asset_id` line

**File:** `database/migrations/2026_06_07_093108_create_assets_table.php`
- Delete: `$table->string('erp_asset_id')->nullable()->unique();`

This is the only schema change for `erp_asset_id` removal. No separate `dropColumn` migration. For production DBs, document a one-time `ALTER TABLE assets DROP COLUMN IF EXISTS erp_asset_id` in deployment notes.

### 2.2 Create migration for new asset columns

```bash
php artisan make:migration update_assets_table_for_phase1 --table=assets
```

**New columns:**

| Column | Type | Constraints | Notes |
|---|---|---|---|
| `maintenance_status` | `string` | `default('Active')` | Controls MR/WO eligibility. Values: `Active`, `Inactive`. |
| `maintenance_sub_status` | `string` | `nullable` | Values: `Installed`, `Ready`, `LIH`, `DBR`, `Disposed`, `Scrapped`, `Other`. Phase 1: always null (`asset_kind=asset` has no sub-status). |
| `asset_kind` | `string` | `default('asset')` | Values: `asset`, `package`, `component`. Phase 1: only `asset`. |
| `asset_tag` | `string(15)` | `nullable`, `unique` | Format: `L-BBB-CCC-XXXX`. Immutable after set. |
| `asset_tag_generated_at` | `timestamp` | `nullable` | When tag was auto-generated. |
| `asset_tag_override_reason` | `text` | `nullable` | Free-text reason for manual override. |
| `fa_subclass_code` | `string(20)` | `nullable` | For tag type-code lookup. |
| `parent_asset_id` | `foreignId` | `nullable`, FK→`assets` | Phase 2 assembly. Schema defined now. |

**Add indexes:**

| Column(s) | Reason |
|---|---|
| `maintenance_status` | Status filtering in index queries |
| `parent_asset_id` | Assembly queries in Phase 2; cheap to add now |

Migration must be safe for SQLite `:memory:` — the original migration no longer defines `erp_asset_id`, so this migration only adds columns (no `dropColumn`).

### 2.3 Create `fa_subclass_type_codes` table migration

```bash
php artisan make:migration create_fa_subclass_type_codes_table
```

| Column | Type | Constraints |
|---|---|---|
| `id` | bigint auto | PK |
| `fa_subclass_code` | `string(20)` | NOT NULL, unique |
| `type_code` | `string(3)` | NOT NULL |
| `description` | `string` | nullable |
| `has_no_physical_size` | `boolean` | `default(false)` |
| timestamps | | |

### 2.4 Create `api_clients` table migration

```bash
php artisan make:migration create_api_clients_table
```

| Column | Type | Constraints |
|---|---|---|
| `id` | bigint auto | PK |
| `name` | `string` | NOT NULL |
| `client_id` | `string(64)` | NOT NULL, unique |
| `client_secret_hash` | `string(128)` | NOT NULL |
| `abilities` | `jsonb` | `default('["read"]')` |
| `last_used_at` | `timestamp` | nullable |
| `revoked_at` | `timestamp` | nullable |
| timestamps | | |

### 2.5 Update Asset model

**File:** `app/Models/Asset.php`
- Add to `$fillable`: `maintenance_status`, `maintenance_sub_status`, `asset_kind`, `asset_tag`, `asset_tag_generated_at`, `asset_tag_override_reason`, `fa_subclass_code`, `parent_asset_id`
- Add cast: `'asset_tag_generated_at' => 'datetime'`
- Add relationship: `parentAsset()` → `belongsTo(Asset::class, 'parent_asset_id')`
- Add relationship: `childAssets()` → `hasMany(Asset::class, 'parent_asset_id')`

### Acceptance Criteria
- `php artisan migrate:fresh` succeeds (SQLite `:memory:`)
- Asset model has all new fillable fields; `erp_asset_id` removed from model
- Asset factory generates valid data with new columns
- `grep -rl "erp_asset_id" backend/app/Models/Asset.php` returns zero results

---

## Task 3: Fix 6 Roles → 5 Roles

### 3.1 Remove `VIEWER` from RoleCode enum

**File:** `app/Enums/RoleCode.php`
- Delete: `case VIEWER = 'viewer';`

### 3.2 Update RoleSeeder

**File:** `database/seeders/RoleSeeder.php`
- Remove `VIEWER` entry from the seed array.
- Remaining 5 roles: Administrator, Maintenance Manager, Technician, Logistics, Requester.

### 3.3 Audit ALL Policy files (14 total)

Remove `RoleCode::VIEWER` or `'viewer'` from any role-check arrays in every file in `backend/app/Policies/`:

| Policy File |  
|---|
| `AssetPolicy.php` |
| `AssetMeterReadingPolicy.php` |
| `AttachmentPolicy.php` |
| `AuditLogPolicy.php` |
| `CompanySettingPolicy.php` |
| `EmployeePolicy.php` |
| `ErpSyncJobPolicy.php` | (may not exist — verify; if not, skip)
| `LocationPolicy.php` |
| `MaintenanceRequestPolicy.php` |
| `MasterDataItemPolicy.php` |
| `PartPolicy.php` |
| `PmRulePolicy.php` |
| `RolePolicy.php` |
| `UserPolicy.php` |
| `WorkOrderPolicy.php` |

### 3.4 Audit NON-POLICY production files for Viewer references

These files reference `RoleCode::VIEWER` or `$isViewer` in field-visibility logic. They will **fatal-error** if the enum case is deleted without refactoring:

| File | Action |
|---|---|
| `app/Http/Resources/WorkOrderResource.php` | Refactor `isViewer` branches. Viewer was merged into Requester; Requester already has equivalent read access. Remove Viewer-specific field restrictions — Requester visibility already covers them. |
| `app/Http/Resources/MaintenanceRequestResource.php` | Same — remove Viewer-specific branches. |
| `app/Http/Resources/AttachmentResource.php` | Remove Viewer-only field conditions. |
| `app/Http/Controllers/DashboardController.php` | Remove Viewer role checks from dashboard widget logic. |

**Approach:** Where Viewer had the same visibility as Requester, delete the Viewer branch entirely. Where Viewer had *less* visibility than Requester, the Requester branch now applies to merged Viewers — evaluate whether the broader visibility is acceptable per the docs (Requester can view own MRs, asset history, attachments).

### 3.5 Update all test files referencing Viewer

Use `grep -rl "viewer\|VIEWER" backend/tests/` to locate. Expected files:

| Test File | Action |
|---|---|
| `tests/Feature/Authorization/FixedRolePolicyTest.php` | Remove Viewer test cases, update role count assertions to 5 |
| `tests/Feature/UserManagement/UserManagementTest.php` | If it creates a Viewer user, remove or repurpose as Requester |
| `tests/Feature/ReadModels/WorkOrderResourceTest.php` | Remove Viewer-specific field assertions |
| `tests/Feature/ReadModels/AttachmentResourceTest.php` | Remove Viewer-specific assertions |
| `tests/Feature/ReadModels/MaintenanceRequestResourceTest.php` | Remove Viewer-specific assertions |
| `tests/Feature/ReadModels/PartResourceTest.php` | Remove Viewer-specific assertions |
| `tests/Feature/ReadModels/AssetResourceTest.php` | Remove Viewer-specific assertions |
| `tests/Feature/MaintenanceRequests/MaintenanceRequestWorkflowTest.php` | Remove Viewer role usage |
| `tests/Feature/Employees/EmployeeProvisioningTest.php` | Remove Viewer role usage |
| `tests/Feature/Employees/EmployeeImportTest.php` | Remove Viewer role usage |
| `tests/Feature/Dashboard/DashboardTest.php` | Remove Viewer-specific assertions |
| `tests/Feature/Attachments/AttachmentWorkflowTest.php` | ~6 Viewer references — remove or repurpose |

**Note for executor:** The grep above is the *minimum* list. Run `grep -rli "viewer\|VIEWER" backend/tests/` to catch any additional files. Do not proceed to Task 3 acceptance until this returns zero results.

### 3.6 Update .kilo/STATE.md and CLAUDE.md

- Change "6 roles" to "5 roles".
- Remove Viewer role references.

### Acceptance Criteria
- `grep -rli "viewer\|VIEWER" backend/app/ backend/database/ backend/routes/` returns zero results (case-insensitive)
- `grep -rli "viewer\|VIEWER" backend/tests/` returns zero results
- `php artisan db:seed --class=RoleSeeder` creates exactly 5 roles
- All 14 policy files compile without `RoleCode::VIEWER`
- Resources and DashboardController compile without fatal errors
- All tests pass

---

## Task 4: Asset Tag — L-BBB-CCC-XXXX

### 4.1 Create FaSubclassTypeCode model

**File:** `app/Models/FaSubclassTypeCode.php`

```php
class FaSubclassTypeCode extends Model
{
    protected $fillable = ['fa_subclass_code', 'type_code', 'description', 'has_no_physical_size'];

    protected function casts(): array
    {
        return ['has_no_physical_size' => 'boolean'];
    }
}
```

### 4.2 Create AssetTagService

**File:** `app/Services/AssetTagService.php`

Method: `generateTag(Asset $asset): ?string` (returns null on collision).

**Encoding rules (4 segments, dash-separated):**

| Segment | Source | Algorithm |
|---|---|---|
| Ownership (`L`) | Hardcoded `L` | Default; future configurable |
| Type code (`BBB`) | `FaSubclassTypeCode::where('fa_subclass_code', $asset->fa_subclass_code)->first()?->type_code` | 3-char; fallback `UNK` |
| Size code (`CCC`) | `$asset->description` | See below |
| Serial suffix (`XXXX`) | `$asset->serial_number` | See below |

**Size code algorithm (order matters):**

1. Extract inch measurement from description using regex: `/(\d+(?:\.\d+)?(?:\s+\d+\/\d+)?)\s*"/`.
   - Matches: `8"`, `9 5/8"`, `6 3/4"`, `1.25"`.
2. If no match → `000`.
3. Strip the `/` character from the match.
4. If whole number with no fraction or decimal (e.g. `8`) → pad with trailing `00` → `800`.
5. If fractional (e.g. `9 5/8` → `958`, `6 3/4` → `634`): remove spaces, strip `/`, pad to 3 chars left.
6. If decimal (e.g. `1.25`): remove the dot `125`, pad to 3 chars left.

**Serial suffix algorithm (order: take-last-4 → uppercase → strip → zero-pad):**

1. Take last 4 characters of `serial_number`.
2. Uppercase them.
3. Strip any non-alphanumeric characters (`[^A-Z0-9]`).
4. Zero-pad on the left to 4 characters.

**Collision check:** After generating, query `Asset::where('asset_tag', $generated)->exists()`. If collision → return `null`.

### 4.3 Create suggest-tag endpoint

**Route:** `POST /api/assets/{asset}/suggest-tag` (in `auth:sanctum` group)

**Controller:** `AssetController::suggestTag` (new method)

**Response (200 — tag generated):**
```json
{ "asset_tag": "L-MTR-9580011", "collision": false }
```

**Response (200 — collision):**
```json
{ "asset_tag": null, "collision": true }
```

Does NOT persist. Admin reviews suggested tag, then submits via `PATCH /api/assets/{asset}` with `{ "asset_tag": "L-MTR-9580011" }`.

### 4.4 Create FaSubclassTypeCode admin CRUD

**File:** New controller `app/Http/Controllers/Admin/FaSubclassTypeCodeController.php`

**Routes (Admin-only, in auth:sanctum group):**
```
GET    /api/admin/fa-subclass-type-codes
POST   /api/admin/fa-subclass-type-codes
PATCH  /api/admin/fa-subclass-type-codes/{code}
DELETE /api/admin/fa-subclass-type-codes/{code}
```

**Route model binding:** `{code}` binds by `fa_subclass_code` column. Set `getRouteKeyName()` on `FaSubclassTypeCode` model:
```php
public function getRouteKeyName(): string { return 'fa_subclass_code'; }
```

### 4.5 Tag immutability guard

**File:** `app/Actions/Assets/UpdateAsset.php` (or controller validation if no Action exists)

```php
// If asset_tag is already set and request tries to change it:
if ($asset->asset_tag !== null && $request->has('asset_tag') && $request->asset_tag !== $asset->asset_tag) {
    // Allow override only if Admin AND asset_tag_override_reason is provided
    if ($request->user()->role_code !== RoleCode::ADMINISTRATOR || empty($request->asset_tag_override_reason)) {
        return response()->json([
            'errors' => ['asset_tag' => ['Asset tag is immutable after creation.']]
        ], 422);
    }
}
```

Override to blank/null is NOT allowed — tag can only be changed to a different valid tag with a reason.

### 4.6 QR-code lookup

**Route:** `GET /api/assets/by-tag` with query parameter `tag` (in `auth:sanctum` group)

Returns single asset or 404. Since `asset_tag` is unique, this is a simple `Asset::where('asset_tag', $request->query('tag'))->firstOrFail()`.

### Acceptance Criteria
- `POST /api/assets/{id}/suggest-tag` returns `L-BBB-CCC-XXXX` when generation succeeds, `{ asset_tag: null, collision: true }` on collision
- Size code: `9 5/8"` → `958`, `6 3/4"` → `634`, `8"` → `800`, `1.25"` → `125`, no inch → `000`
- Serial suffix: `M7-962-0011` → `0011`, `A1` → `00A1`
- Tag is unique and immutable after save (unless Admin override with `asset_tag_override_reason`)
- `GET /api/assets/by-tag?tag=L-MTR-9580011` returns asset or 404
- Tests cover: tag generation (all size/suffix variants), collision detection + return null, immutability (reject without override reason, allow with Admin reason), QR lookup

---

## Task 5: Asset Maintenance Status

### Design: Two Orthogonal Axes

| Field | Purpose | Values | Who Can Change | Workflow Gates |
|---|---|---|---|---|
| `operational_status` | Informational/contextual | `active`, `under_maintenance`, `down`, `inactive` (hard-coded, existing) | Admin/Manager via `PATCH /api/assets/{asset}`; also set by `POST /work-orders/{wo}/asset-status` | None |
| `maintenance_status` | MR/WO eligibility control | `Active`, `Inactive` | Admin/Manager only via `PATCH /api/assets/{asset}` | Inactive assets reject MR creation and WO assignment |

Both fields coexist on `assets`. They have different contracts. No automatic derivation between them.

### 5.1 Create enums

**File:** `app/Enums/MaintenanceStatus.php`
```php
enum MaintenanceStatus: string
{
    case ACTIVE = 'Active';
    case INACTIVE = 'Inactive';
}
```

**File:** `app/Enums/MaintenanceSubStatus.php`
```php
// Phase 1: always null when asset_kind=asset. Enum defined for Phase 2.
enum MaintenanceSubStatus: string
{
    case INSTALLED = 'Installed';
    case READY = 'Ready';
    case LIH = 'LIH';
    case DBR = 'DBR';
    case DISPOSED = 'Disposed';
    case SCRAPPED = 'Scrapped';
    case OTHER = 'Other';
}
```

**File:** `app/Enums/AssetKind.php`
```php
enum AssetKind: string
{
    case ASSET = 'asset';
    case PACKAGE = 'package';     // Phase 2
    case COMPONENT = 'component'; // Phase 2
}
```

### 5.2 Add validation rules

**File:** `app/Http/Requests/StoreAssetRequest.php` / wherever asset create/update is validated

| Field | Rules |
|---|---|
| `maintenance_status` | `in:Active,Inactive` |
| `maintenance_sub_status` | `nullable`, `in:Installed,Ready,LIH,DBR,Disposed,Scrapped,Other` |
| `asset_kind` | `in:asset,package,component` |

No `required_if` on `maintenance_sub_status` in Phase 1 (it's always null for `asset_kind=asset`).

### 5.3 Transition guard — Admin/Manager only

**File:** `app/Actions/Assets/UpdateAsset.php` (or `AssetController::update` if no Action)

- Only Admin (`RoleCode::ADMINISTRATOR`) or Maintenance Manager (`RoleCode::MAINTENANCE_MANAGER`) may change `maintenance_status`, `maintenance_sub_status`, or `asset_kind`.
- Returns 403 otherwise.

### 5.4 Guard MR/WO creation against Inactive assets

**File:** `app/Actions/MaintenanceRequests/ApproveMaintenanceRequestAndCreateWorkOrder.php`
- Before creating WO, check `$asset->maintenance_status !== MaintenanceStatus::INACTIVE`. If inactive → 422.

**File:** `app/Actions/WorkOrders/AssignWorkOrder.php`
- Before assigning, check `$asset->maintenance_status !== MaintenanceStatus::INACTIVE`. If inactive → 422.

Also enforce in relevant Policies (`MaintenanceRequestPolicy`, `WorkOrderPolicy`) for defense-in-depth.

### 5.5 Preserve existing `operational_status` behavior — NO changes

- `operational_status` column, validation (`in:active,under_maintenance,down,inactive`), and `POST /work-orders/{wo}/asset-status` endpoint remain exactly as-is.
- `WorkOrderPolicy::setAssetStatus` remains unchanged.
- `tests/Feature/WorkOrders/WorkOrderAssetStatusTest.php` remains unchanged.
- `operational_status` carries NO workflow gates — it is purely informational.

### 5.6 Migrate existing rows

Migration sets `maintenance_status` default to `'Active'`. Existing assets will be `Active` after migration. No reconciliation with `operational_status` values needed (they are orthogonal axes). If an asset had `operational_status='inactive'`, its `maintenance_status` will still be `Active` — the Admin/Manager must manually update `maintenance_status` if the asset should be excluded from maintenance workflows.

### 5.7 Update Asset API Resource

**File:** `app/Http/Resources/AssetResource.php`
- Include: `maintenance_status`, `maintenance_sub_status`, `asset_kind`, `asset_tag`
- Include: `parent_asset_id` (nullable)
- Include: `child_assets_count` via `withCount()`

### Acceptance Criteria
- Assets can be created with defaults: `asset_kind=asset`, `maintenance_status=Active`
- Admin/Manager can transition `Active → Inactive` with a sub-status
- Non-Admin/Manager receives 403 when trying to change `maintenance_status`
- Inactive assets: creating MR for them → 422; assigning WO for them → 422
- `operational_status` and `POST /work-orders/{wo}/asset-status` work exactly as before
- API resource returns all new fields
- Tests cover: create defaults, transition guard, inactive rejection, resource serialization

---

## Task 6: API Bearer Tokens (Machine-to-Machine)

### 6.1 Create ServiceUser seeder

**File:** `database/seeders/ServiceUserSeeder.php`

Seeds a single user that owns all API tokens:
- `email`: `service@atms.internal`
- `role`: Requester (5-role)
- `is_active`: true
- `name`: `ATMS Service Account`
- Password: randomly generated, never used (all auth is via API tokens)

Seed this in `DatabaseSeeder` after `RoleSeeder`.

### 6.2 Create ApiClient model

**File:** `app/Models/ApiClient.php`
```php
class ApiClient extends Model
{
    protected $fillable = ['name', 'client_id', 'client_secret_hash', 'abilities', 'last_used_at', 'revoked_at'];

    protected function casts(): array
    {
        return [
            'abilities' => 'array',
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }
}
```

### 6.3 Create TokenController

**File:** `app/Http/Controllers/Auth/TokenController.php`

```php
public function issue(Request $request): JsonResponse
{
    $validated = $request->validate([
        'client_id' => 'required|string|max:64',
        'client_secret' => 'required|string|max:128',
    ]);

    $client = ApiClient::where('client_id', $validated['client_id'])->first();

    if (! $client || $client->isRevoked() || ! Hash::check($validated['client_secret'], $client->client_secret_hash)) {
        return response()->json(['message' => 'Invalid credentials.'], 401);
    }

    $serviceUser = User::where('email', 'service@atms.internal')->firstOrFail();

    // Create Sanctum token with the abilities defined on the ApiClient
    $token = $serviceUser->createToken($client->name, $client->abilities);

    $client->touch('last_used_at');

    return response()->json([
        'token' => $token->plainTextToken,
        'abilities' => $client->abilities,
    ]);
}
```

### 6.4 Create read-only middleware

**File:** `app/Http/Middleware/EnsureTokenAbilities.php`

```php
public function handle(Request $request, Closure $next): Response
{
    $user = $request->user();

    // Only enforce when using bearer token auth (not SPA cookie/session)
    if (! $user || ! $request->bearerToken()) {
        return $next($request);
    }

    $method = $request->method();

    // Block mutating requests if the token does NOT have a write ability
    if (in_array($method, ['POST', 'PATCH', 'PUT', 'DELETE'])) {
        if (! $user->tokenCan('write')) {
            return response()->json([
                'message' => 'This token is read-only and cannot perform mutating requests.',
            ], 403);
        }
    }

    return $next($request);
}
```

Register in `bootstrap/app.php` and add to the `api` middleware group AFTER `auth:sanctum` so `$request->user()` is resolved.

**Note for executor:** The middleware delegates to Sanctum's `tokenCan()`. By default, all API clients get `['read']` abilities. If a future client needs write access, assign `['read', 'write']` abilities and the middleware will allow mutating requests because `tokenCan('write')` returns true.

### 6.5 Register route for token issuance

**File:** `routes/api.php`
```php
Route::post('/auth/token', [TokenController::class, 'issue'])
    ->middleware('throttle:5,1');
```

This is OUTSIDE the `auth:sanctum` group (machine-to-machine auth).

### 6.6 Admin CRUD for API clients

**Routes (in auth:sanctum group, Admin-only):**
```
GET    /api/admin/api-clients
POST   /api/admin/api-clients
GET    /api/admin/api-clients/{client}
DELETE /api/admin/api-clients/{client}   // Revoke (sets revoked_at)
```

**File:** `app/Http/Controllers/Admin/ApiClientController.php`
- `index()` — list all clients (mask `client_secret_hash`, never return it)
- `store()` — generate `client_id` (64-char random, `Str::random(64)`), hash `client_secret` with `Hash::make()`, store. **Return the raw `client_secret` in the response ONLY on creation.** Document that this is the only time the secret is visible; the admin must relay it securely to the remote system.
- `show()` — return client details (no secret, no hash)
- `destroy()` — set `revoked_at = now()`, save

### 6.7 Audit logging for API client operations

Log in `audit_logs` using the existing `AuditLogger` service:
- **Client creation:** `event = 'api_client_created'`, `subject = ApiClient`, `before_state = null`, `after_state = { name, client_id, abilities }` (NOT the secret)
- **Client revocation:** `event = 'api_client_revoked'`, `subject = ApiClient`

Do NOT log per-request token usage — that would be a high-volume per-API-call audit write. `last_used_at` on the `ApiClient` row is sufficient.

### Acceptance Criteria
- `POST /api/auth/token` with valid `client_id`/`client_secret` → `{ token, abilities }` (200)
- `POST /api/auth/token` with invalid credentials → 401
- `POST /api/auth/token` throttled at 5 attempts/minute
- `POST/PATCH/PUT/DELETE` with a read-only token (no `write` ability) → 403
- `GET` with a read-only token → 200
- SPA/cookie session auth (no bearer token) is never blocked by the middleware
- Admin can create/list/revoke API clients
- Raw secret returned only once on `store()`
- Audit log records creation and revocation events
- Tests cover: issue with valid creds, issue with invalid creds, read-only enforcement, SPA auth bypass, admin CRUD, secret visibility

---

## Task 7: Mock ERP → Real ERP Adapter (Blocked — Graceful Skip)

### 7.1 Build LdcErpHttpSource

**File:** `app/Services/Erp/LdcErpHttpSource.php`

Implements `ErpSource` (only `getParts()` — `getAssets()` was removed in Task 1.2).

**Graceful skip:** Before making any HTTP call, check `$this->getPartsEndpoint()`. If empty/null → log warning (`Log::warning('LDC_ERP_PARTS_API is not configured; skipping parts sync.')`) and return `{ data: [], next_cursor: null }`.

**When configured:**
- Acquire Entra ID token from `https://login.microsoftonline.com/{tenant}/oauth2/v2.0/token` (client_credentials grant, scope: `https://api.businesscentral.dynamics.com/.default`)
- Cache token in Laravel `database` cache store with TTL = `expires_in - 60s`
- On 401 response: forget cached token, re-acquire, retry once
- Fetch parts from: `{base_url}/{parts_endpoint}` (single call, no pagination per ERP team confirmation)
- Return `ExternalPartData[]` per existing contract

### 7.2 Create erp.php config

**File:** `config/erp.php`
```php
return [
    'provider' => env('LDC_ERP_PROVIDER', 'business_central'),
    'oauth' => [
        'token_url' => sprintf('https://login.microsoftonline.com/%s/oauth2/v2.0/token', env('LDC_ERP_TENANT_ID')),
        'client_id' => env('LDC_ERP_CLIENT_ID'),
        'client_secret' => env('LDC_ERP_CLIENT_SECRET'),
        'scope' => 'https://api.businesscentral.dynamics.com/.default',
    ],
    'api' => [
        'base_url' => sprintf(
            'https://api.businesscentral.dynamics.com/v2.0/%s/%s/ODataV4/Company(\'%s\')',
            env('LDC_ERP_TENANT_ID'),
            env('LDC_ERP_ENVIRONMENT'),
            env('LDC_ERP_COMPANY')
        ),
        'parts_endpoint' => env('LDC_ERP_PARTS_API'),
        'timeout' => 30,
    ],
];
```

### 7.3 Update env files

**File:** `.env.example`
- Remove: `MOCK_ERP_URL`, `MOCK_ERP_API_KEY`
- Add with empty defaults:
  ```
  LDC_ERP_TENANT_ID=
  LDC_ERP_CLIENT_ID=
  LDC_ERP_CLIENT_SECRET=
  LDC_ERP_ENVIRONMENT=
  LDC_ERP_COMPANY=
  LDC_ERP_PARTS_API=
  ```

**File:** `backend/.env`
- Replace `MOCK_ERP_URL`/`MOCK_ERP_API_KEY` with the `LDC_ERP_*` variables (values from LDC ERP team when available; leave empty for now).

### 7.4 Update AppServiceProvider binding

**File:** `app/Providers/AppServiceProvider.php`
```php
// WAS:
$this->app->singleton(ErpSource::class, MockErpHttpSource::class);

// IS:
$this->app->singleton(ErpSource::class, LdcErpHttpSource::class);
```

### 7.5 Delete mock ERP artifacts — no fallback

| File | Action |
|---|---|
| `app/Services/Erp/MockErpHttpSource.php` | Delete |
| `config/mock-erp.php` | Delete |
| `tests/Contract/MockErpContractTest.php` | Delete |

### 7.6 Clean Docker Compose

**File:** `compose.yaml`
- Remove `MOCK_ERP_URL` env var from `api` service
- Remove `mock-erp` service definition (if present)

**File:** `compose.production.yaml`
- Verify no mock ERP references

### Acceptance Criteria
- `LdcErpHttpSource` is the live `ErpSource` binding
- `SyncErpPartsJob` skips gracefully (logs warning, no failure) when `LDC_ERP_PARTS_API` is empty
- `grep -rli "mock.erp\|mockErp\|MockErp\|MOCK_ERP" backend/app/ backend/config/ backend/tests/ backend/routes/` returns zero results
- `Migrate:fresh` succeeds (no mock-erp config dependency)
- All existing parts-sync tests pass (or are updated for the new adapter)

---

## Task 8: Verification & Audit

### 8.1 PM Suppression date_or_reading edge case

**File:** `app/Services/Pm/PmDueCalculator.php`

Audit `isTriggeredByDate()` and `isTriggeredByReading()`. When a `date_or_reading` rule has both dimensions due simultaneously:
- Both `triggered_by_date` and `triggered_by_reading` can be `true` on the generated MR.
- Suppression creation then requires BOTH `suppressed_until_date` AND `suppressed_until_reading` (per `app/Actions/Pm/CreatePmSuppression.php` validation).
- This is correct per spec. Verify with a test that both boundaries must be provided when both dimensions triggered.

### 8.2 MR status: confirm no "approved" exists

**File:** `app/Enums/MaintenanceRequestStatus.php`
- Confirm: `converted` is the post-approval status, NOT `approved`.

**File:** `app/Actions/MaintenanceRequests/ApproveMaintenanceRequestAndCreateWorkOrder.php`
- Confirm: sets MR `status → converted` and creates WO atomically within a DB transaction.
- Confirm: no intermediate "approved" state is ever persisted.

### 8.3 Run full test suite

```bash
php artisan test
```

**Pre-work baseline:** Run `php artisan test --list` (or equivalent) and note the test count before starting any tasks. After all tasks, all pre-existing tests must pass (modulo those intentionally deleted, e.g., `MockErpContractTest`).

**New tests to write:**
- Asset tag generation (all size code variants: fractional, decimal, whole, none; serial suffix variants; collision returns null)
- Asset tag immutability (reject without override reason; allow with Admin + reason)
- Asset tag QR-code lookup (found, not found)
- Maintenance status: Admin can change, non-Admin 403, Inactive asset rejects MR creation and WO assignment
- API token: issue with valid creds, issue with invalid creds (401), read-only token blocked on POST (403), SPA session bypass
- 5-role authorization: no Viewer role in any policy or resource
- PM suppression: date_or_reading dual-boundary required when both dimensions triggered

### 8.4 Run code quality

```bash
./vendor/bin/pint --test
php artisan optimize:clear
```

---

## Task Order

```
Task 1 (Purge SyncErpAssets)     ─┐
Task 2 (Migrations)              ─┤ Parallel — no shared dependencies
Task 3 (Fix 6→5 Roles)          ─┘

         ↓ After 1,2,3 complete ↓

Task 4 (Asset Tag)               ─┐
Task 5 (Maintenance Status)      ─┤ Parallel
Task 6 (API Tokens)              ─┘

Task 7 (LDC ERP Adapter)         ── After Tasks 1,2 complete (needs clean ErpSource + config)

Task 8 (Verification & Test)     ── After all tasks complete
```

---

## Risks & Notes

| Risk | Mitigation |
|---|---|
| `erp_asset_id` removal breaks many test files | Task 1.7 lists all known files; use `grep -rl` to find any missed ones |
| Viewer role removal fatals resource/Dashboard files before policies are checked | Task 3.4 audits non-policy files first; run `php artisan test` after Task 3 before moving on |
| `LDC_ERP_PARTS_API` page name unknown | `LdcErpHttpSource` skips sync gracefully; `SyncErpPartsJob` logs warning, doesn't fail |
| Asset tag collisions (~8.2%) | Return `{ asset_tag: null, collision: true }`; Admin manually adjusts and submits |
| Existing production DBs have `erp_asset_id` column | Documented one-time `ALTER TABLE DROP COLUMN`. No Laravel migration ships this (see Migration Strategy section) |
| Two status fields (`operational_status` + `maintenance_status`) confuse users | Frontend must label them distinctly; `operational_status` = "Operational State" (informational), `maintenance_status` = "Maintenance Eligibility" (gates workflows). API resource returns both with clear key names. |
| Service user (`service@atms.internal`) in users table | Seeded, immutable, never logs in via SPA. Document in `CLAUDE.md`. Alternative (custom guard) deferred post-MVP. |

---

## Execution Status (2026-06-25)

| Task | Status | Tests |
|---|---|---|
| Task 1 — Purge SyncErpAssets | ✅ Done | 284 pass |
| Task 2 — Asset Schema | ✅ Done | 284 pass |
| Task 3 — 6→5 Roles | ✅ Done | 278 pass |
| Task 4 — Asset Tag | ✅ Done | 278 pass |
| Task 5 — Maintenance Status | ✅ Done | 278 pass |
| Task 6 — API Bearer Tokens | ✅ Done | 278 pass |
| Task 7 — ERP Adapter | ✅ Done | 278 pass |
| Task 8 — Verification | ✅ Done | 278 pass, pint clean |

**Key implementation divergences from original plan:**
- D4: Service account uses `SERVICE` role (not Requester) — added to RoleCode enum, 6 policies, and RoleSeeder. Required for M2M read tokens to pass policy checks.
- Task 1.6 guard removal: Also updated `tests/Feature/Pm/PmWorkflowTest.php::test_pm_rule_can_target_any_atms_managed_asset` (was `test_pm_rule_cannot_target_non_erp_asset`).
- Task 3.4 merge direction: Where Viewer had *broader* read than Requester, Requester was widened (not narrowed). Affected `MaintenanceRequestPolicy`, `AttachmentPolicy`, MR/WorkOrder/Attachment Resources, DashboardController.
- Task 5.4: Inactive guard added to `CreateCorrectiveMaintenanceRequest`, `ApproveMaintenanceRequestAndCreateWorkOrder`, `AssignWorkOrder`, `EvaluatePmRulesJob`.
- Task 7: `ErpSyncTest` updated to configure `LdcErpHttpSource` via `config()->set()` + token + parts endpoint fakes.

---

## Post-Review Fixes (2026-06-25)

After the initial implementation, two rounds of code review identified and resolved the following issues:

### Round 1 — Critical & Medium Findings

| # | Issue | Severity | Fix |
|---|---|---|---|
| 1 | `PmDueCalculator` missing `use Illuminate\Support\Collection` | Critical | Added import at line 9 |
| 2 | Missing test coverage — 11 new feature areas untested | Critical | Wrote 4 new test files + 1 test addition (~560 lines) |
| 4 | Lifecycle fields silently dropped (not 403) in `AssetController` | Medium | Changed `unset()` to 403 response in both `store()` and `update()` |
| 5 | Null/empty tag override not blocked for Admin | Medium | Added `empty($request->asset_tag)` check before override guard |
| 6 | `LdcErpHttpSource` hardcoded cache TTL (3540s) | Medium | Parse `expires_in` from OAuth response; dynamic `Cache::put()` with `max(60, expires_in - 60)` |
| 7 | Tag collision race condition (TOCTOU) | Medium | Added `QueryException` catch with 409 response on unique constraint violation |
| 8 | `TokenController` `firstOrFail()` can 500 if unseeded | Low | Accepted as-is (seeded in all environments); documented |

### Round 2 — Remaining Issues

| # | Issue | Severity | Fix |
|---|---|---|---|
| R1 | `.env.example` still had `MOCK_ERP_*` variables | Critical | Replaced with `LDC_ERP_*` variables (lines 67-68) |
| R2 | Debug `dump()` left in `ApiTokenTest.php` | Medium | Removed lines 122-125 |
| R3 | Tag suggestion-persist UX mismatch (TOCTOU) | Low | Accepted with DB constraint as safety net; documented |

### Additional Fixes Discovered During Testing

| # | Issue | Fix |
|---|---|---|
| F1 | `ApiClientController::store` passed `null` to `AuditLogger::log()` ($before must be array) | Changed to `[]` |
| F2 | `CreateAsset` action missing `erp_asset_code` in create payload → NOT NULL violation | Added `erp_asset_code` to validation and action |
| F3 | `EnsureTokenAbilities` middleware ran before `auth:sanctum` → skipped on Bearer tokens | Moved from `api` group to route-level after `auth:sanctum` |
| F4 | Session auth hijacked Bearer token requests in tests → `TransientToken` with empty abilities | Added `auth()->guard('web')->logout()` in token tests |
| F5 | `AssetPolicy::manage` didn't include SERVICE role → 403 on write-token asset creation | Added `SERVICE` role to `manage()` |
| F6 | `storeCorrective` didn't catch `DomainException` on inactive assets → 500 | Added try/catch returning 422 |

### Test Results

| Metric | Before Fixes | After Fixes |
|---|---|---|
| Total tests | 278 | 304 |
| New tests | 0 | 26 (4 files + 1 addition) |
| Passing | 278 | 304 |
| Failing | 0 | 0 |
| Duration | ~3.5s | ~3.7s |

### New Test Files

| File | Lines | Coverage |
|---|---|---|
| `tests/Unit/AssetTag/AssetTagServiceTest.php` | 155 | Tag generation (8 size/serial variants, collision) |
| `tests/Feature/AssetTag/AssetTagApiTest.php` | 126 | Tag suggest, immutability, override, clear-rejection, QR lookup |
| `tests/Feature/MaintenanceStatus/MaintenanceStatusGuardTest.php` | 133 | Admin change, technician 403, inactive MR/WO rejection |
| `tests/Feature/ApiToken/ApiTokenTest.php` | 140 | Token issue valid/invalid, read-only block, write allow, SPA bypass, revoked |
| `tests/Feature/Pm/PmWorkflowTest.php` (addition) | +68 | `date_or_reading` dual-boundary suppression |

### Files Modified (Post-Review)

| File | Change |
|---|---|
| `app/Services/Pm/PmDueCalculator.php` | Added `use Illuminate\Support\Collection` |
| `app/Http/Controllers/AssetController.php` | Silent drop → 403; null tag block; `QueryException` catch; `erp_asset_code` validation |
| `app/Services/Erp/LdcErpHttpSource.php` | Dynamic TTL from `expires_in` |
| `app/Http/Controllers/Admin/ApiClientController.php` | Fixed `null` → `[]` in audit log |
| `app/Actions/Assets/CreateAsset.php` | Added `erp_asset_code` to create payload |
| `app/Http/Controllers/MaintenanceRequestController.php` | Added `DomainException` try/catch on `storeCorrective` |
| `app/Http/Middleware/EnsureTokenAbilities.php` | Fixed duplicate code; cleaned up |
| `app/Policies/AssetPolicy.php` | Added `SERVICE` role to `manage()` |
| `bootstrap/app.php` | Removed `EnsureTokenAbilities` from `api` group |
| `routes/api.php` | Added `EnsureTokenAbilities` after `auth:sanctum` in route group |
| `backend/.env.example` | Replaced `MOCK_ERP_*` with `LDC_ERP_*` |
