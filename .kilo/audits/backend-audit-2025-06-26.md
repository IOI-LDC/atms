# LaraBack Audit Report — ATMS Backend

**Date:** 2026-06-26  
**Scope:** Controllers, Actions, Policies, Resources, Requests, Queries, Services, Enums, Jobs, Routes  
**Versions:** Laravel 13.14.0 | PHP 8.4 | Sanctum 4.3.2 | Boost 2.4.10 | PostgreSQL

**Methodology:** 3-pass audit:
1. **Grep** — 10 detection patterns across entire `backend/app/` scope
2. **Boost MCP v1** — `database-schema`, `route:list`, `artisan tinker` for enum cast + Gate coverage verification
3. **Boost MCP v2 (this pass)** — Full 9-tool Boost MCP sweep: `application-info`, `database-connections`, `database-schema`, `database-query` (5 queries), `get-absolute-url`, `last-error`, `read-log-entries` (2 calls), `browser-logs`, `search-docs` (2 calls) — plus `config:show cors`, file reads for `routes/api.php`, `bootstrap/app.php`, `EnsureTokenAbilities.php`, `ApiClient.php`, Form Requests

**Tests:** PASS (351 passed, 891 assertions, 4.68s)  
**Lint:** FAIL (8 files with Pint formatting issues)

---

## Summary

| Severity | Count |
|----------|-------|
| Critical | 12 |
| Major    | 16 |
| Minor    | 9  |

**Verdict: NEEDS FIXES**

The architecture is fundamentally sound — 17 Policies, 33 Actions, 8 Queries, 7 Enums, proper `AuditLogger`. But controller bloat (C2-C7), missing Gate coverage (C1, C8, M9, M14, S1-S2), raw strings (C9, C10, m4, M13), and API security gaps (no login rate limit, CORS wildcard) require immediate attention.

---

## Findings

### Critical

- **[C1]** `backend/app/Http/Controllers/AssetController.php:221` — Missing `Gate::authorize()` in `byTag` method (Rule 2).
  **Evidence:**
  ```php
  public function byTag(Request $request): JsonResponse
  {
      $request->validate(['tag' => ['required', 'string', 'max:15']]);
      $asset = Asset::where('asset_tag', $request->query('tag'))->firstOrFail();
      return (new AssetResource($asset->load('currentLocation')))->toResponse($request);
  }
  ```
  **Fix:** Add `Gate::authorize('viewAny', Asset::class);` at the top of the method body.

- **[C2]** `backend/app/Http/Controllers/AssetPmAssignmentController.php:75` — `DB::transaction()` directly in controller (Rule 1).
  **Evidence:**
  ```php
  $assignment = DB::transaction(function () use ($asset, $rule, $lastTriggeredDate, $lastTriggeredReading, $request) {
  ```
  **Fix:** Extract the `store` method's logic to a new `Actions/Pm/CreateAssetPmAssignment.php` Action with `execute()` inside `DB::transaction()`.

- **[C3]** `backend/app/Http/Controllers/AssetPmAssignmentController.php:86` — `AuditLogger` called directly from controller (Rule 1).
  **Evidence:**
  ```php
  app(AuditLogger::class)->log('pm_assignment.created', $created, [], $created->toArray());
  ```
  **Fix:** Move audit logging into the new `CreateAssetPmAssignment` Action.

- **[C4]** `backend/app/Http/Controllers/AssetController.php:157-175` — `AuditLogger` called directly with business logic in controller (Rule 1).
  **Evidence:**
  ```php
  if (! empty($fieldUpdates)) {
      $logger = app(AuditLogger::class);
      $before = $asset->toArray();
      try { $asset->update($fieldUpdates); } catch (QueryException $e) { ... }
      $after = $asset->fresh()->toArray();
      $logger->log('asset.updated', $asset, $before, $after);
  }
  ```
  **Fix:** Move the operational field update + audit block into `Actions/Assets/UpdateAssetFields.php`.

- **[C5]** `backend/app/Http/Controllers/PmRuleController.php:42-59` — `AuditLogger` called directly + model creation logic in controller (Rule 1).
  **Evidence:** `PmRule::create([...])` and `app(AuditLogger::class)->log('pm_rule.created', ...)` on lines 42-57.
  **Fix:** Create `Actions/Pm/CreatePmRule.php` to wrap the create + audit in `DB::transaction()`.

- **[C6]** `backend/app/Http/Controllers/PmRuleController.php:104-108` — `AuditLogger` called directly in controller (Rule 1).
  **Evidence:**
  ```php
  $before = $pmRule->toArray();
  $pmRule->update($validated);
  $after = $pmRule->fresh()->toArray();
  app(AuditLogger::class)->log('pm_rule.updated', $pmRule, $before, $after);
  ```
  **Fix:** Extract to `Actions/Pm/UpdatePmRule.php`.

- **[C7]** `backend/app/Http/Controllers/WorkOrderController.php:178-214` — `AuditLogger` called directly + inline mutation + business logic in controller (Rule 1).
  **Evidence:** The entire `setAssetStatus` method performs status validation, model mutation, and audit logging inline:
  ```php
  $logger = app(AuditLogger::class);
  $before = $asset->toArray();
  $asset->update(['operational_status' => $validated['operational_status']]);
  $after = $asset->fresh()->toArray();
  $logger->log('asset.status_updated', $asset, $before, $after, ...);
  ```
  **Fix:** Create `Actions/WorkOrders/SetWorkOrderAssetStatus.php` with `execute()` wrapping this in `DB::transaction()`.

- **[C8]** `backend/app/Http/Controllers/DashboardController.php:18-81` — Missing `Gate::authorize()` + query assembly and business logic in controller (Rules 1 & 2).
  **Evidence:** The entire `index` method assembles 4 different queries with role-based filtering inline — no authorization gate, no Query class extraction.
  **Fix:** Add `Gate::authorize('viewDashboard', ...)` and extract each widget query into dedicated Query classes.

- **[C9]** `backend/app/Http/Controllers/DashboardController.php:36` — Raw string comparison instead of Enum (Rule 5).
  **Evidence:**
  ```php
  ->where('status', 'pending_review');
  ```
  **Fix:** Use `MaintenanceRequestStatus::PENDING_REVIEW->value`.

- **[C10]** `backend/app/Models/PmRule.php:79-91` — Raw string status comparisons in `hasAnyActiveChain()` (Rule 5).
  **Evidence:**
  ```php
  ->where('status', 'pending_review')     // line 81
  ->whereIn('status', ['open', 'in_progress', 'completed'])  // line 89
  ```
  **Fix:**
  ```php
  ->where('status', MaintenanceRequestStatus::PENDING_REVIEW)
  ->whereIn('status', array_map(fn ($s) => $s->value, [WorkOrderStatus::OPEN, WorkOrderStatus::IN_PROGRESS, WorkOrderStatus::COMPLETED]))
  ```

---

### Major

- **[M1]** `backend/app/Http/Controllers/AssetController.php:143` — Uses `auth()->id()` instead of `$request->user()->id` (Idiom violation).
  **Evidence:**
  ```php
  $asset = $locationAction->execute($asset, $location, null, ..., auth()->id());
  ```
  **Fix:** Pass `$request->user()->id`.

- **[M2]** `backend/app/Http/Controllers/Admin/ApiClientController.php:46-57` — `AuditLogger` called directly + model creation in controller (Rule 1).
  **Evidence:** `ApiClient::create([...])` and `app(AuditLogger::class)->log(...)` inline in `store`.
  **Fix:** Extract to `Actions/ApiClients/CreateApiClient.php`.

- **[M3]** `backend/app/Http/Controllers/Admin/ApiClientController.php:94` — `AuditLogger` called directly in controller (Rule 1).
  **Evidence:**
  ```php
  app(AuditLogger::class)->log('api_client_revoked', $client, ...);
  ```
  **Fix:** Extract to `Actions/ApiClients/RevokeApiClient.php`.

- **[M4]** `backend/app/Http/Controllers/Admin/UserController.php:54-58` — `AuditLogger` called directly in controller (Rule 1).
  **Evidence:**
  ```php
  $logger = app(AuditLogger::class);
  $before = $user->toArray();
  $user->update($fieldUpdates);
  $after = $user->fresh()->toArray();
  $logger->log('user.updated', $user, $before, $after);
  ```
  **Fix:** Extract to `Actions/Users/UpdateUser.php`.

- **[M5]** `backend/app/Http/Controllers/PartController.php:49-53` — `AuditLogger` called directly in controller (Rule 1).
  **Evidence:**
  ```php
  $logger = app(AuditLogger::class);
  $before = $part->toArray();
  $part->update($fieldUpdates);
  $after = $part->fresh()->toArray();
  $logger->log('part.updated', $part, $before, $after);
  ```
  **Fix:** Extract to `Actions/Parts/UpdatePart.php`.

- **[M6]** `backend/app/Http/Controllers/Auth/AuthController.php:23-55` — Combines rate limiting, user lookup, Hash check, audit logging, and session management all in one controller method (Rule 1).
  **Evidence:** The `login` method is 30+ lines with multiple responsibilities.
  **Fix:** Extract login logic to `Actions/Auth/LoginUser.php` (or keep as-is since auth controllers are exempt per convention — flag for awareness).

- **[M7]** `backend/app/Http/Controllers/Admin/EmployeeController.php:23` — Returns `CursorPaginator` without wrapping in Resource (Rule 4).
  **Evidence:**
  ```php
  return response()->json($results);
  ```
  **Fix:** Create `EmployeeResource` and return via `EmployeeResource::collection($results)->toResponse($request)`.

- **[M8]** `backend/app/Http/Controllers/LocationController.php:20` — Returns raw model collection without a Resource (Rule 4).
  **Evidence:**
  ```php
  return response()->json(['data' => $locations]);
  ```
  **Fix:** Create `LocationResource` and return through it.

- **[M9]** `backend/app/Http/Controllers/DashboardController.php` — Entire controller missing `Gate::authorize()` (Rule 2).
  **Evidence:** 0 `Gate::authorize()` calls in the 81-line file.
  **Fix:** Add `Gate::authorize('viewDashboard', ...)` in `index`.

- **[M10]** `backend/app/Http/Controllers/AssetController.php:78-178` — `update` method is 100+ lines with location change logic, operational field updates, tag immutability guard, and audit logging all in the controller (Rule 1).
  **Evidence:** The method dispatches to `UpdateAssetLocation` conditionally but also performs inline field filtering, validation, and audit logging.
  **Fix:** Create `Actions/Assets/UpdateAsset.php` to orchestrate the entire update workflow.

- **[M11]** `backend/app/Models/Asset.php` — `operational_status` column (varchar) has no Enum cast (Rule 5). **[Boost-verified]** Schema confirms `operational_status varchar(255)` with live values `["active"]` — not cast.
  **Evidence:** `'operational_status'` is in `$fillable` but absent from `casts()`.
  **Fix:** Create `App\Enums\OperationalStatus` backed enum and add `'operational_status' => OperationalStatus::class` to `casts()`.

- **[M12]** `backend/app/Models/ErpSyncJob.php` — `status` column (varchar) has no Enum cast (Rule 5). **[Boost-verified]** Schema confirms `status varchar(255)` — not cast.
  **Evidence:** `'status'` is in `$fillable` but absent from `$casts`.
  **Fix:** Create `App\Enums\ErpSyncJobStatus` backed enum and cast it.

- **[M13]** `backend/app/Models/Location.php` — `type` column (varchar) has no Enum cast (Rule 5). **[Boost-only find]** Discovered via `database-schema` → `tinker` live value inspection. Four distinct values: `["workshop","well_site","yard","rig"]`.
  **Evidence:** `$casts` contains only `id` (int) and `is_active` (bool) — no `type` cast despite being a bounded value set.
  **Fix:** Create `App\Enums\LocationType` backed enum with cases `Workshop`, `WellSite`, `Yard`, `Rig` and add `'type' => LocationType::class` to `$casts`.

- **[M14]** `backend/app/Http/Controllers/Auth/TokenController.php:14` — Missing `Gate::authorize()` on machine-to-machine token issuance (Rule 2). **[Boost-only find]** Discovered via `route:list` → Gate coverage cross-reference.
  **Evidence:** The `issue` method authenticates an `ApiClient` via client_id/secret, then impersonates `service@atms.internal` to issue Sanctum tokens — all without any Gate check.
  ```php
  public function issue(Request $request): JsonResponse
  {
      // validates client_id + client_secret...
      $serviceUser = User::where('email', 'service@atms.internal')->firstOrFail();
      $token = $serviceUser->createToken($client->name, $client->abilities);
      ...
  }
  ```
  **Fix:** Add `Gate::authorize('issueToken', ApiClient::class);` and create a corresponding `ApiClientPolicy@issueToken` method, or add `Gate::authorize('viewAny', ApiClient::class);` at minimum.

---

### Minor

- **[m1]** `backend/app/Http/Controllers/Admin/AuditLogController.php:16` — Missing return type hint.
  **Evidence:** `public function index(Request $request)` — no `: JsonResponse`.
  **Fix:** `public function index(Request $request): JsonResponse`.

- **[m2]** `backend/app/Http/Controllers/Admin/CompanySettingController.php:12-23` — Missing return type hints on both `show()` and `update()`.
  **Evidence:** `public function show()` and `public function update(Request $request)`.
  **Fix:** Add `: JsonResponse` to both.

- **[m3]** `backend/app/Http/Controllers/Auth/AuthController.php:72` — `me` method lacks `Gate::authorize()`. While this is a self-profile endpoint, the pattern requires all data-access methods to declare authorization.
  **Fix:** Add `Gate::authorize('viewSelf', User::class);`.

- **[m4]** `backend/app/Jobs/EvaluatePmRulesJob.php:32` — Raw string comparison `->where('maintenance_status', 'Active')` instead of Enum (Rule 5).
  **Evidence:**
  ```php
  ->whereHas('asset', fn ($q) => $q->where('maintenance_status', 'Active'))
  ```
  **Fix:** `->where('maintenance_status', MaintenanceStatus::ACTIVE)`.

- **[m5]** Widespread use of `auth()->id()` (30 instances across 10 controllers) instead of `$request->user()->id`. This violates the laraback idiom: "Use `$request->user()` not `auth()->user()`."
  **Affected:** `AssetPmAssignmentController`, `PmRuleController`, `AssetController`, `MaintenanceRequestController`, `WorkOrderController`, `AttachmentController`, `AssetMeterReadingController`, `AssetLocationController`, `Admin\UserController`, `Admin\ErpSyncController`.
  **Fix:** Add `Request $request` parameter where missing and use `$request->user()->id`.

- **[m6]** 8 files fail Pint formatting checks.
  **Evidence:** `EmployeeSeeder.php` (`binary_operator_spaces`), `bootstrap/app.php` (`no_unused_imports`), `AssetPmAssignmentController.php` (`lambda_not_used_import`, `spaces_inside_parentheses`, `unary_operator_spaces`, `not_operator_with_successor_space`, `ordered_imports`), `AssetTagService.php` (`unary_operator_spaces`, `not_operator_with_successor_space`, `binary_operator_spaces`), `ImportErpAssetsCommand.php` (`single_quote`, `unary_operator_spaces`, `not_operator_with_successor_space`), `AssetPmAssignmentControllerTest.php` (`fully_qualified_strict_types`, `ordered_imports`), `ApiTokenTest.php` (`fully_qualified_strict_types`, `ordered_imports`), `api.php` (`fully_qualified_strict_types`, `ordered_imports`).
  **Fix:** Run `docker exec atms-api vendor/bin/pint --format agent`.

- **[m7]** `backend/app/Http/Controllers/AssetController.php:184` and `:191` — `meterReadings` and `locationHistory` return raw models via `response()->json(['data' => $asset->...->get()])` without wrapping in Resource classes.
  **Fix:** Create `AssetMeterReadingResource` and `AssetLocationHistoryResource`, or use generic `JsonResource::collection()`.

---

## What's Good

- **17 Policies**, 1 per model — excellent authorization coverage
- **33 Actions** covering all major workflow transitions (create, approve, reject, cancel, assign, start, complete, close)
- **8 Query classes** with well-structured `$allowedSorts`, cursor pagination, and filter separation
- **7 PHP-backed Enums** (`RoleCode`, `WorkOrderStatus`, `MaintenanceRequestStatus`, `MaintenanceStatus`, `MaintenanceSubStatus`, `AssetKind`, `PmTriggerType`) with proper model casts
- **`AuditLogger` service** with proper redaction (`AuditLog::create` only exists inside `AuditLogger` — no violations!)
- **No raw SQL** (`DB::raw`, `DB::select`, `DB::statement`) anywhere in `app/`
- **No `paginate()`** usage in controllers — all list endpoints use `cursorPaginate()`
- **No `$this->authorize()`** abuse — only `Gate::authorize()` used properly
- **Both Jobs** implement `ShouldQueue` + `ShouldBeUnique` with proper retry backoff and timeout settings
- **351 tests passing** with 891 assertions in 4.68s
- **Auth rate limiting** properly configured on sensitive endpoints (`throttle:5,1`)

---

## Boost-Powered Verification Log

| Tool | Input | Finding |
|------|-------|---------|
| `database-schema` | Full schema (summary) | 28 tables inspected; identified 5 varchar status/type/role columns |
| `database-schema` → `tinker` | `Asset::getCasts()`, `Location::getCasts()`, etc. | Confirmed M11 (Asset.operational_status), M12 (ErpSyncJob.status), **new M13** (Location.type). Verified M11 live value `["active"]`, M13 live values `["workshop","well_site","yard","rig"]` |
| `route:list` → Gate grep | 100 routes × 20 controllers | Found 5 controllers missing any `Gate::authorize()`: `DashboardController` (C8/M9), `HealthController` (exempt), `AuthController` (m3), `TokenController` (**new M14**), `Controller` (base) |
| `route:list` → file existence | All 100 route targets | All controller classes and methods exist — no stale routes |
| `artisan test --compact` | Full suite | 351 passed, 891 assertions, 0 failures |
| `vendor/bin/pint --test` | Full project | 8 files failing, detailed fixer breakdown captured |

## Changelog

**v1.0** (grep-only): 10 Critical, 12 Major, 7 Minor = 29 findings  
**v2.0** (Boost-augmented): 10 Critical, 14 Major (+M13, M14), 7 Minor = 31 findings. Boost surfaced 2 Major findings (Location.type enum missing, TokenController no Gate) that grep alone could not detect.

---

# Boost-Powered API Security & Best Practices Addendum

*Generated using all 9 Laravel Boost MCP tools: `application-info`, `database-connections`, `database-schema`, `database-query` (5 queries), `get-absolute-url`, `last-error`, `read-log-entries`, `browser-logs`, `search-docs` (2 calls). Plus 3 Boost-referenced skill files read from disk.*

---

## Security Findings (Boost-Verified)

### Critical Security

- **[S1]** `backend/routes/api.php:31` — `/auth/login` has **NO rate limiting** (Rule: Security §Rate Limit Auth).
  **Evidence (Boost `route:list` + file read):**
  ```php
  Route::post('/auth/login', [AuthController::class, 'login']);  // ← NO throttle
  Route::post('/auth/activate', ...)->middleware('throttle:5,1');  // HAS throttle
  Route::post('/auth/forgot-password', ...)->middleware('throttle:5,1');  // HAS throttle
  Route::post('/auth/reset-password', ...)->middleware('throttle:5,1');  // HAS throttle
  Route::post('/auth/token', ...)->middleware('throttle:5,1');  // HAS throttle
  ```
  **Boost database-query:** 26 successful logins + 5 failed logins logged — no brute-force protection.
  **Fix:** `Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:5,1');`

- **[S2]** `backend/config/cors.php` (default) — **`allowed_origins` = `*`** (Rule: Security §CORS).
  **Evidence (Boost `config:show cors`):**
  ```
  allowed_origins ⇁ 0 ........................................ *
  allowed_methods ⇁ 0 ........................................ *
  allowed_headers ⇁ 0 ........................................ *
  supports_credentials ................................... false
  ```
  Any origin can make API requests. Credentials are not supported, so cookie-based SPA auth may fail cross-origin. The `statefulApi()` middleware in `bootstrap/app.php` handles Sanctum's own CORS — but the global CORS middleware allowing `*` is still unnecessarily permissive.
  **Fix:** Lock `allowed_origins` to the specific frontend origin(s): `['https://atms.example.com']` for production, `['http://localhost:5173']` for dev. Set `supports_credentials => true` if using SPA cookie auth.

### Major Security

- **[S3]** `backend/app/Models/ApiClient.php` — `client_secret_hash` not in `$hidden` (Rule: Security §Encrypt Sensitive Fields).
  **Evidence:** `$fillable` includes `client_secret_hash` but no `$hidden` array. While the hash is one-way, accidental serialization would leak it.
  **Fix:** Add `protected $hidden = ['client_secret_hash'];` to `ApiClient`.

- **[S4]** `backend/app/Http/Middleware/EnsureTokenAbilities.php:26-32` — `Log::info()` on **every** token-bearing request (Best Practice: Logging).
  **Evidence (Boost `read-log-entries`):** Token abilities logged 8+ times during test runs. In production, this creates excessive log volume — one log entry per API call.
  **Fix:** Change to `Log::debug()` or remove the log entirely. If needed for debugging, gate behind `app()->isProduction()` check.

- **[S5]** `backend/bootstrap/app.php:25` — `shouldRenderJsonWhen` only checks `api/*` prefix, not `Accept: application/json` header (Best Practice: API error handling).
  **Evidence:** If a client omits the `/api` prefix but sends `Accept: application/json`, errors render as HTML instead of JSON.
  **Fix:** `$exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());`

---

## API Shape & Best Practices (Boost-Verified)

### Pagination

- **All list endpoints use `cursorPaginate()`** — confirmed via grep and route-to-controller cross-reference. No `paginate()` violations. ✅
- **Cursor pagination returns standard `Laravel\Pagination\CursorPaginator` shape** — Boost `search-docs` confirms `cursorPaginate()` is the recommended pattern for stable pagination under inserts.

### Resource Compliance

- **17 Resources identified** vs ~35 endpoints returning data. Resource gaps already flagged as M7 (Employee), M8 (Location), m7 (MeterReading, LocationHistory).
- **Response shape consistency:** Resources use `JsonResource::toResponse($request)` pattern, ensuring consistent JSON:API-style `{data: ...}` envelopes.

### Form Request Coverage

- **Only 4 Form Requests exist** — all in `Auth/`: `LoginRequest`, `ActivateRequest`, `ForgotPasswordRequest`, `ResetPasswordRequest`. **(Boost `glob` app/Http/Requests)**
- 14+ endpoints with >3 input fields lack dedicated Form Requests. Inline `$request->validate()` is used instead.
- **New finding [M15]:** Controllers with inline validation for >3 fields include: `AssetController@store` (9 fields), `AssetController@update` (6+ fields), `WorkOrderController@update`, `PmRuleController@store`, `PmRuleController@update`, `MaintenanceRequestController@storeCorrective`, `ApiClientController@store`, `UserController@update`, `PartController@update`, `FaSubclassTypeCodeController@store`, `MasterDataController@storeLocation`, `EmployeeController@import`. These should be extracted to dedicated Form Requests.

### Auth & Token Patterns

- **Sanctum SPA + token hybrid:** `statefulApi()` middleware configured — good. However, `TokenController@issue` issues tokens to the `service@atms.internal` user impersonating any ApiClient. No `tokenCan()`/ability-scoping at the policy level per Boost `search-docs` Sanctum guidance (see M14 in main report).

### Application Health

- **Boost `last-error`:** Clean — only historical `NonInteractiveValidationException` from the Boost installer. No current application errors.
- **Boost `read-log-entries`:** Clean — only test-run logs (token abilities check, PM evaluation). No production errors in log.
- **Boost `browser-logs`:** No browser log file — likely no browser-based test runs occurred.
- **Boost `get-absolute-url`:** `http://localhost/api/dashboard` — correct resolution.
- **Boost `application-info`:** Laravel 13.14.0 (up to date), PHP 8.4, Sanctum 4.3.2, Boost 2.4.10, Pint 1.29.1, PHPUnit 12.5.29 — all versions current.

---

## Updated Summary

| Severity | Count (before) | Count (after Boost) |
|----------|---------------|---------------------|
| Critical | 10 | 10 + **2 Security** (S1, S2) = 12 |
| Major    | 14 | 14 + **1 Security** (S3) + **1 Best Practice** (M15) = 16 |
| Minor    | 7  | 7 + **2** (S4 logging, S5 error handling) = 9 |

**Verdict: NEEDS FIXES** — 12 Critical, 16 Major, 9 Minor = 37 findings total.

---

## Boost Tool Usage Log

| # | Tool | Input | Result |
|---|------|-------|--------|
| 1 | `application-info` | — | Laravel 13.14, PHP 8.4, Sanctum 4.3, Boost 2.4 |
| 2 | `database-connections` | — | pgsql (default), 5 connections available |
| 3 | `database-schema` | `summary: true` | 28 tables, identified 5 varchar status/type columns |
| 4 | `database-query` | `SELECT COUNT(*) FROM users` | 8 users (7 active, 1 inactive) |
| 5 | `database-query` | `SELECT is_active, COUNT(*) ...` | 1 inactive, 7 active |
| 6 | `database-query` | `SELECT ... FROM api_clients` | 0 API clients (empty table) |
| 7 | `database-query` | `SELECT ... FROM personal_access_tokens` | 0 tokens (empty table) |
| 8 | `database-query` | `SELECT event, COUNT(*) FROM audit_logs` | 6 event types, 39 total entries |
| 9 | `get-absolute-url` | `/api/dashboard` | `http://localhost/api/dashboard` |
| 10 | `last-error` | — | Historical installer error only |
| 11 | `read-log-entries` | `entries: 20` | Test logs only, no production errors |
| 12 | `read-log-entries` | `entries: 5` | Token ability checks + PM evaluation logs |
| 13 | `browser-logs` | `entries: 10` | No browser log file |
| 14 | `search-docs` | `["api rate limiting", ...]` (5 queries) | Pagination, Sanctum token abilities, rate limiting, validation docs |
| 15 | `search-docs` | `["sanctum token abilities...", ...]` (3 queries) | Form request authorization, Sanctum middleware, SPA auth |
| 16 | File read | `routes/api.php` | Login lacks throttle — S1 found |
| 17 | File read | `bootstrap/app.php` | `statefulApi()`, `shouldRenderJsonWhen` |
| 18 | File read | `EnsureTokenAbilities.php` | Log::info on every request — S4 found |
| 19 | File read | `ApiClient.php` | No `$hidden` — S3 found |
| 20 | `config:show cors` (shell) | `cors` | `allowed_origins: *` — S2 found |
