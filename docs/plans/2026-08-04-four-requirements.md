# ATMS Four-Requirement Implementation Plan (User Provisioning · Tajoura Base · Operational Status · Meter-Reading Delta)

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Implement four approved requirements: (1) direct admin user creation with activation email (dropping the SharePoint employee-directory flow), (2) relocate all assets to a new "Tajoura Base" (TJB) location, (3) rename and extend the operational-status vocabulary in DB values, (4) change the WO meter-reading form from absolute-value entry to delta entry with an auto-calculated Total.

**Architecture:** Laravel 13 API + Vue 3 SPA, PostgreSQL. The activation-email flow already exists (`UserActivationToken` + `ActivateUser` + `UserActivationNotification` + `FrontendUrl`) and is reused verbatim for direct creation. `assets.operational_status` is a plain `varchar(255)` with **no CHECK constraint**, so value renames are plain UPDATE statements; enum case renames ripple through queries, actions, imports, seeders, tests, and frontend label maps. The meter-reading change is frontend-only: the API contract (`reading_value` = absolute total) is unchanged, so backend tests must stay green untouched. `deploy.sh` already runs `php artisan migrate --force`, so all migrations are effective on the VPS with no deploy-script change.

**Tech Stack:** Laravel 13, PHP 8.4, PostgreSQL 16, Sanctum 4, Vue 3.5 + TypeScript + Vite + shadcn-vue, PHPUnit 12, Laravel Pint.

**Verification commands (used throughout):**
- Backend: `php artisan test --compact --filter=<Name>` / `php artisan test --compact tests/Feature/...`
- Full backend: `php artisan test --compact`
- Frontend: `npm run type-check` (in `frontend/`), then `npm run build` to see the change in the UI
- Format: `vendor/bin/pint --dirty --format agent` (run before finishing any task that touched PHP)

**Conventions:** follow the sibling-file patterns in `app/Actions`, `app/Http/Controllers/Admin`, `tests/Feature`; PHP types + return types everywhere; no comments unless necessary; TDD — write the failing test first, then the code.

---

## Phase A — Operational status: rename DB values + add new statuses

Decision (confirmed with user): **rename DB values too**, i.e. `active` → `ready_for_field`, `inactive` → `scraped`, plus new `under_inspection` and `lih`.

### Task A1: Rewrite the enum

**Files:**
- Modify: `backend/app/Enums/OperationalStatus.php` (full rewrite)

```php
<?php

namespace App\Enums;

enum OperationalStatus: string
{
    case READY_FOR_FIELD = 'ready_for_field';
    case UNDER_MAINTENANCE = 'under_maintenance';
    case DOWN = 'down';
    case SCRAPED = 'scraped';
    case UNDER_INSPECTION = 'under_inspection';
    case LIH = 'lih';
}
```

Commit: `feat(assets): rename operational status values + add Under Inspection / LIH`.

### Task A2: Data migration (value renames + new values)

**Files:**
- Create: `backend/database/migrations/2026_08_04_000001_rename_operational_status_values.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('assets')->where('operational_status', 'active')->update(['operational_status' => 'ready_for_field']);
        DB::table('assets')->where('operational_status', 'inactive')->update(['operational_status' => 'scraped']);

        // The column default must follow the rename or raw inserts would write a dead value.
        Schema::table('assets', function (Blueprint $table) {
            $table->string('operational_status')->default('ready_for_field')->change();
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->string('operational_status')->default('active')->change();
        });

        DB::table('assets')->where('operational_status', 'ready_for_field')->update(['operational_status' => 'active']);
        DB::table('assets')->where('operational_status', 'scraped')->update(['operational_status' => 'inactive']);
    }
};
```

**Step 1: Write the failing migration test** — Create `backend/tests/Feature/Migrations/RenameOperationalStatusValuesTest.php`, following the existing pattern in `backend/tests/Feature/Migrations/NormalizeMaintenanceCategoryCodesTest.php` (instantiate the real migration class, call `up()`, assert DB state):

```php
<?php

namespace Tests\Feature\Migrations;

use App\Models\Asset;
use App\Models\Location;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RenameOperationalStatusValuesTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_renames_legacy_values_and_is_idempotent(): void
    {
        $location = Location::factory()->create();
        $active = Asset::factory()->create(['operational_status' => 'active', 'current_location_id' => $location->id]);
        $inactive = Asset::factory()->create(['operational_status' => 'inactive', 'current_location_id' => $location->id]);

        $migration = new \2026_08_04_000001_RenameOperationalStatusValues;
        $migration->up();

        $this->assertSame('ready_for_field', $active->fresh()->operational_status);
        $this->assertSame('scraped', $inactive->fresh()->operational_status);

        // Idempotent: running again changes nothing.
        $migration->up();
        $this->assertSame('ready_for_field', $active->fresh()->operational_status);
    }
}
```

Note: the migration class name in the test must match the file's anonymous-class convention used by `NormalizeMaintenanceCategoryCodesTest` — check how that test references its migration (named class or `require` + instantiate) and mirror it exactly. If there is no `Location::factory()`, create the location via `Location::create([...])` like `LocationSeeder`.

**Step 2:** Run `php artisan test --compact tests/Feature/Migrations/RenameOperationalStatusValuesTest.php` — expect RED (migration does not exist yet).
**Step 3:** Create the migration file above, run again — expect GREEN.
**Step 4:** Commit with the enum.

### Task A3: Update backend consumers

**Files (modify):**

1. `backend/app/Queries/Reports/AssetDistributionReportQuery.php:136-139` — replace the four `selectRaw('sum(case when operational_status = ? …) as active_count', [OperationalStatus::ACTIVE->value])` lines with the six-status version:
   - `ready_for_field_count` → `OperationalStatus::READY_FOR_FIELD->value`
   - `under_maintenance_count` → `UNDER_MAINTENANCE`
   - `down_count` → `DOWN`
   - `scraped_count` → `SCRAPED`
   - `under_inspection_count` → `UNDER_INSPECTION`
   - `lih_count` → `LIH`
   Then update the `item()` method (same file, below line 140) so `by_operational_status` uses the new keys (read the current mapping first; it keys off the `*_count` column names).
2. `backend/app/Queries/Dashboard/Kpis/AssetHealthKpiQuery.php` — `by_status` keys `active` → `ready_for_field`, `inactive` → `scraped`; add `under_inspection` and `lih` (zero default); update the PHPDoc array shape; `$active` now reads `OperationalStatus::READY_FOR_FIELD->value`.
3. `backend/app/Actions/WorkOrders/CloseWorkOrder.php:50` — `OperationalStatus::ACTIVE` → `OperationalStatus::READY_FOR_FIELD` (also the `?OperationalStatus` docblock).
4. `backend/app/Queries/Reports/OperationalStatusDistributionReportQuery.php` — docblock "always all four values" → "always all six values" (logic already iterates `cases()`).
5. `backend/app/Console/Commands/ImportAssetsCommand.php:210` — the allowed list `['active', 'under_maintenance', 'down', 'inactive']` → `['ready_for_field', 'under_maintenance', 'down', 'scraped', 'under_inspection', 'lih']`. (The import workbook `database/data/assets.csv` must use the new values; the export dev aid `assets-export.csv` at repo root is an export, not an import source.)
6. `backend/database/seeders/MaintenanceRequestDemoSeeder.php:61` — `'operational_status' => 'active'` → `'ready_for_field'`.
7. `backend/app/Queries/Dashboard/AssetUtilisationQuery.php` — verify only; logic references `DOWN`/`UNDER_MAINTENANCE` (unchanged) — no edit expected.

**Step:** Run `php artisan test --compact --filter=AssetDistribution` and `--filter=DashboardKpi` — expect RED (failing assertions, see Task A4).
**Commit:** `feat(assets): update queries, close-work-order default, and import vocabulary for renamed statuses`.

### Task A4: Update backend tests

**Files (modify):**

1. `backend/tests/Feature/Reports/OperationalStatusDistributionReportTest.php` — `test_returns_all_four_statuses_with_zero_for_missing` → rename to `...all_six_statuses...` and change the expected array to `['ready_for_field' => 2, 'under_maintenance' => 0, 'down' => 1, 'scraped' => 0, 'under_inspection' => 0, 'lih' => 0]`; `test_inactive_operational_status_is_shown_not_hidden` → use `OperationalStatus::SCRAPED` and assert `['scraped']` count.
2. `backend/tests/Feature/Reports/AssetStatusReportTest.php:145` — filter `'operational_status' => 'active'` → `'ready_for_field'`.
3. `backend/tests/Feature/ReadModels/AssetResourceTest.php:43` — `'operational_status' => 'active'` → `'ready_for_field'`.
4. `backend/tests/Feature/WorkOrders/WorkOrderAssetStatusTest.php` — string literals `'active'` → `'ready_for_field'` and `'inactive'` → `'scraped'` (keep `'under_maintenance'`, `'down'`, and the invalid `'broken'` case).
5. Grep for any other `'active'`/`'inactive'` operational-status literals in `backend/tests` (`grep -rn "'operational_status' => 'active'\|'operational_status' => 'inactive'" backend/tests`) and fix. Enum-referencing tests (`DashboardKpiTest`, `AssetDistributionReportTest`, `AssetUtilisationTest`, `WorkOrderLifecycleTest`) adapt automatically via `OperationalStatus::*` — only run them to confirm.

**Step:** `php artisan test --compact tests/Feature/Reports tests/Feature/WorkOrders tests/Feature/ReadModels tests/Feature/Dashboard` — expect GREEN.
**Commit:** `test(assets): update operational-status expectations for renamed values`.

### Task A5: Frontend types + label maps

**Files (modify):**

1. `frontend/src/types/index.ts:19`
   ```ts
   export type AssetOperationalStatus =
     | 'ready_for_field'
     | 'under_maintenance'
     | 'down'
     | 'scraped'
     | 'under_inspection'
     | 'lih'
   ```
2. `frontend/src/lib/displayHelpers.ts:81-103`:
   ```ts
   const m: Record<string, string> = {
     ready_for_field: 'Ready for Field',
     under_maintenance: 'Under Maintenance',
     down: 'Down',
     scraped: 'Scraped',
     under_inspection: 'Under Inspection',
     lih: 'Lost in Hole',
   }
   ```
   and the class map: `ready_for_field: 'status-badge status-active'`, `scraped: 'status-badge status-inactive'`, `under_inspection: 'status-badge status-in-progress'`, `lih: 'status-badge status-inactive'`, keep `under_maintenance`/`down` as today.
3. `frontend/src/lib/reportOptions.ts:23-28` — `OPERATIONAL_STATUS_OPTIONS` becomes six entries: `ready_for_field → Ready for Field`, `under_maintenance → Under Maintenance`, `down → Down`, `scraped → Scraped`, `under_inspection → Under Inspection`, `lih → Lost in Hole`.
4. `frontend/src/lib/assetColumns.ts:110-115` — the `operational_status` filter array becomes the six values; update the docblock at line 17.

**Step:** `npm run type-check` (from `frontend/`) — GREEN.
**Commit:** `feat(frontend): operational-status vocabulary — new values and labels`.

### Task A6: Dashboard + distribution report frontend

**Files (modify):**

1. `frontend/src/composables/useDashboardKpis.ts:132-146` — `operationalStatusRows` becomes six rows keyed `ready_for_field` (`h.by_status.ready_for_field`, label 'Ready for Field', tone 'active'), `under_maintenance`, `down`, `scraped` ('Scraped', tone 'muted'), `under_inspection` ('Under Inspection', tone 'warning'), `lih` ('Lost in Hole', tone 'critical'). Update the comment.
2. `frontend/src/views/reports/AssetDistributionReport.vue:258-268` — chips switch from `row.by_operational_status.active` to `.ready_for_field` (label 'Ready for Field'), `.under_maintenance`, `.down`, `.scraped`, `.under_inspection`, `.lih`.

**Step:** `npm run type-check`; run backend `--filter=DashboardKpi` again (already green). 
**Commit:** `feat(frontend): dashboard + asset-distribution status chips for renamed values`.

### Task A7: Full backend suite

**Step:** `php artisan test --compact` — everything GREEN. If any remaining test references the old values, fix it (grep `'active'` / `'inactive'` in `backend/tests` scoped to operational-status assertions).
**Commit:** `test: full suite green after operational-status rename` (only if fixes were needed).

---

## Phase B — Asset location: Tajoura Base (TJB)

### Task B1: Migration — create location, relocate all assets, record history

**Files:**
- Create: `backend/database/migrations/2026_08_04_000002_create_tajoura_base_and_relocate_assets.php`

```php
<?php

use App\Models\Location;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $tjb = Location::firstOrCreate(
            ['code' => 'TJB'],
            ['name' => 'Tajoura Base', 'type' => 'yard', 'description' => 'Tajoura Base — primary asset storage and operational base', 'is_active' => true],
        );

        if (! DB::table('assets')->exists()) {
            return;
        }

        $now = now();
        $history = DB::table('assets')
            ->select('id', 'current_location_id')
            ->get()
            ->map(fn ($asset) => [
                'asset_id' => $asset->id,
                'from_location_id' => $asset->current_location_id,
                'to_location_id' => $tjb->id,
                'effective_at' => $now,
                'reason' => 'bulk relocation',
                'notes' => 'migration:2026-08-04 tajoura-base',
                'changed_by_user_id' => null,
                'created_at' => $now,
            ])
            ->all();

        DB::table('asset_location_histories')->insert($history);
        DB::table('assets')->update(['current_location_id' => $tjb->id]);
    }

    public function down(): void
    {
        $tjb = Location::where('code', 'TJB')->first();
        if (! $tjb) {
            return;
        }

        // Restore each asset's prior location from the history rows this migration wrote.
        $rows = DB::table('asset_location_histories')
            ->where('to_location_id', $tjb->id)
            ->where('notes', 'migration:2026-08-04 tajoura-base')
            ->get();

        foreach ($rows as $row) {
            DB::table('assets')->where('id', $row->asset_id)->update(['current_location_id' => $row->from_location_id]);
        }

        // to_location_id cascades on delete — dropping TJB removes these history rows.
        $tjb->delete();
    }
};
```

Notes: type `'yard'` keeps the Start-WO gate working (asset must be at a workshop/yard) — the LocationType vocabulary is `rig | well_site | yard | workshop | building`. If the client prefers a different type, change the single literal. `from_location_id` may be null (asset without a location) — the column is nullable.

**Step 1: Write the failing test** — `backend/tests/Feature/Migrations/TajouraBaseRelocationTest.php` (same pattern as Task A2): seed two assets at two different locations, run the migration's `up()`, assert: TJB exists with code `TJB`, all assets point at TJB, and one history row per asset with `from_location_id` = original. Then assert `down()` restores the original locations and removes TJB.
**Step 2:** Run it — RED (no migration class yet). **Step 3:** Create the migration — GREEN.
**Step 4:** `backend/database/seeders/LocationSeeder.php` — append `['name' => 'Tajoura Base', 'type' => 'yard', 'code' => 'TJB', 'description' => 'Tajoura Base — primary asset storage and operational base']` to the array (firstOrCreate by code keeps it idempotent).
**Commit:** `feat(locations): Tajoura Base (TJB) — create location, relocate all assets with history`.

### Task B2: VPS effectiveness

No deploy-script change: `deploy.sh` runs `php artisan migrate --force` on every deploy, so TJB and the status renames apply automatically on the next deploy. Verify after deploy with:
- `docker compose exec -T api php artisan tinker --execute 'echo \App\Models\Location::where("code","TJB")->value("name");'`
- `docker compose exec -T api php artisan tinker --execute 'echo \App\Models\Asset::where("current_location_id", \App\Models\Location::where("code","TJB")->value("id"))->count();'`

---

## Phase C — User provisioning: direct admin creation + activation email

Reuses the existing activation machinery (`ActivateUser`, `UserActivationToken`, `UserActivationNotification`, `FrontendUrl`, `POST /auth/activate`). The `@ldc.com.ly` domain gate is the only new business rule.

### Task C1: Config + validation rule

**Files:**
- Modify: `backend/config/atms.php` — append:
  ```php
  'allowed_email_domains' => array_map(
      'trim',
      explode(',', (string) env('ATMS_ALLOWED_EMAIL_DOMAINS', 'ldc.com.ly')),
  ),
  ```
- Create: `backend/app/Rules/AllowedEmailDomain.php`

```php
<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class AllowedEmailDomain implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $domain = strtolower((string) str($value)->after('@'));

        if (! in_array($domain, config('atms.allowed_email_domains', ['ldc.com.ly']), true)) {
            $fail('The :attribute must belong to an allowed domain ('.implode(', ', config('atms.allowed_email_domains', ['ldc.com.ly'])).').');
        }
    }
}
```

**Step:** `php artisan config:show atms.allowed_email_domains` → `['ldc.com.ly']`.
**Commit:** `feat(users): allowed-email-domain config + validation rule`.

### Task C2: CreateUser action

**Files:**
- Create: `backend/app/Actions/Users/CreateUser.php` (mirror of `ProvisionEmployeeUser` minus the employee)

```php
<?php

namespace App\Actions\Users;

use App\Actions\Auth\ActivateUser;
use App\Models\Role;
use App\Models\User;
use App\Notifications\UserActivationNotification;
use App\Support\FrontendUrl;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateUser
{
    public function __construct(private ActivateUser $activateUserAction) {}

    public function execute(string $name, string $email, Role $role): User
    {
        return DB::transaction(function () use ($name, $email, $role) {
            $user = User::create([
                'name' => $name,
                'email' => $email,
                'password' => Str::random(32),
                'role_id' => $role->id,
                'is_active' => false,
            ]);

            $token = $this->activateUserAction->issueToken($user);
            $url = FrontendUrl::to('/activate?token='.$token);
            $user->notify(new UserActivationNotification($url));

            return $user;
        });
    }
}
```

**Commit:** `feat(users): CreateUser action — random password, inactive, activation email`.

### Task C3: Controller store + route + update-email domain gate

**Files:**
- Modify: `backend/app/Http/Controllers/Admin/UserController.php` — add `use App\Actions\Users\CreateUser;`, `use App\Models\Role;`, `use App\Rules\AllowedEmailDomain;` and:

```php
public function store(Request $request, CreateUser $action): JsonResponse
{
    Gate::authorize('manage', User::class);

    $validated = $request->validate([
        'name' => ['required', 'string', 'max:255'],
        'email' => ['required', 'email', 'max:255', 'unique:users,email', new AllowedEmailDomain],
        'role_id' => ['required', 'exists:roles,id'],
    ]);

    $role = Role::findOrFail($validated['role_id']);
    $user = $action->execute($validated['name'], $validated['email'], $role);

    return response()->json(['message' => 'User created and activation email queued.', 'data' => $user->load('role')], 201);
}
```

  Also add `new AllowedEmailDomain` to the `email` rule inside `update()` (line 43) so edits cannot smuggle in a foreign domain.
- Modify: `backend/routes/api.php:88` — add before the existing user routes:
  ```php
  Route::post('/users', [UserController::class, 'store']);
  ```

**Commit:** `feat(users): POST /admin/users — direct creation with domain gate`.

### Task C4: Backend feature tests

**Files:**
- Create: `backend/tests/Feature/Admin/CreateUserTest.php` (follow `UserManagementTest.php` conventions: `RefreshDatabase` + `seed(RoleSeeder::class)` + `createAdmin()` helper; use `Notification::fake()` and `Mail`-style assertions via the notification facade):

Tests to write:
1. `test_administrator_creates_user_with_ldc_domain` — POST `/api/admin/users` with `name`, `email: john@ldc.com.ly`, `role_id` (technician). Expect 201; user in DB with `is_active = false`, `activated_at = null`; a `user_activation_tokens` row exists (type `activation`); `UserActivationNotification` was sent to that email (`Notification::assertSentTo`); the returned payload has `role.code`.
2. `test_email_with_foreign_domain_is_rejected` — `foo@example.com` → 422 with `email` error; no user row.
3. `test_allowed_domain_allowlist_accepts_exception` — `config()->set('atms.allowed_email_domains', ['ldc.com.ly', 'partner.com']);` then `x@partner.com` → 201.
4. `test_duplicate_email_is_rejected` — existing user's email → 422 `unique`.
5. `test_non_administrator_cannot_create_user` — the `nonAdminRoles` data provider from `UserManagementTest` (extend or replicate) → 403 for technician/logistics/requester, 200-family for maintenance_manager? No: `manage` policy is Administrator-only → 403 for maintenance_manager too (matches `UserPolicy::manage`). Assert 403 for all non-admin roles.
6. `test_created_user_can_activate_and_login` — create via endpoint, fetch the plain token (`user_activation_tokens` stores only hashes, so re-issue via `app(ActivateUser::class)->issueToken($user)`), POST `/api/auth/activate` with `token` + `password` → 200, user now `is_active = true`, login works.
7. `test_update_user_email_must_stay_in_allowed_domain` — PATCH `/api/admin/users/{id}` with `foo@example.com` → 422.

**Step:** `php artisan test --compact tests/Feature/Admin/CreateUserTest.php` — RED first (no `store` route), then GREEN.
**Commit:** `test(users): direct user creation + activation flow`.

### Task C5: Frontend — Create User dialog + composable + view

**Files:**
- Create: `frontend/src/components/admin/CreateUserDialog.vue` — Dialog modeled on `ProvisionUserDialog.vue`/`EditUserSheet.vue` with Name / Email / Role (Select) fields, inline `validationErrors` (keys `name`, `email`, `role_id`), a `confirmation-warning` box ("An activation email will be sent to <email>. The link expires in 24 hours."), emits `confirm: [payload: { name: string; email: string; role_id: number }]` and `cancel`.
- Modify: `frontend/src/composables/useUsers.ts` — remove `employees`, `employeesLoading`, `employeesError`, `loadEmployees`, `provisionedEmpIds`, `provisioning`, `provisionErrors`, `provisionUser`, and the employee `import` block; drop `Employee` from the type import; add:

```ts
const creating = ref(false)
const createErrors = ref<Record<string, string[]> | null>(null)

async function createUser(payload: { name: string; email: string; role_id: number }): Promise<boolean> {
  creating.value = true
  createErrors.value = null
  try {
    await api.post('/admin/users', payload)
    await loadUsers(true)
    return true
  } catch (e) {
    if (e instanceof ApiError && e.validationErrors) createErrors.value = e.validationErrors
    return false
  } finally {
    creating.value = false
  }
}
```

  `refreshAll()` becomes `await loadUsers(true)` only. Return the new bindings.
- Modify: `frontend/src/views/admin/UsersView.vue` — delete the entire Employee Directory card (lines 191-249), the `employeeColumns`, `openProvision`/`onProvisionConfirm`/`provisionTarget`/`provisionOpen` block, and the `ProvisionUserDialog` import + usage. Add an "Add User" `Button` (with `UserPlus` icon) to the System Users card header (`@click="createOpen = true"`), wire `createOpen`/`onCreateConfirm` (toast.success('User created. Activation email queued.')) and render `<CreateUserDialog :open="createOpen" :roles="assignableRoles" :loading="creating" :validation-errors="createErrors" @confirm="onCreateConfirm" @cancel="closeCreate" />`.
- Modify: `frontend/src/types/index.ts` — delete the `Employee` interface (lines 463-471) if no other consumer remains (grep `Employee` in `frontend/src` — the only consumers are `useUsers.ts` and `UsersView.vue`, both being cleaned here).

**Step:** `npm run type-check` — GREEN. 
**Commit:** `feat(frontend): create-user dialog replaces employee directory`.

### Task C6: Remove backend employee-directory infrastructure

**Files to delete:**
- `backend/app/Http/Controllers/Admin/EmployeeController.php`
- `backend/app/Contracts/Employees/EmployeeDirectorySource.php` (+ empty `Contracts/Employees/` dir)
- `backend/app/Services/Employees/CsvEmployeeDirectorySource.php`, `backend/app/Services/Employees/FakeEmployeeDirectorySource.php`
- `backend/app/Actions/Employees/ImportEmployees.php`
- `backend/app/Data/Employees/ExternalEmployeeData.php`
- `backend/app/Queries/Employees/EmployeeIndexQuery.php`
- `backend/app/Http/Resources/EmployeeResource.php`
- `backend/app/Policies/EmployeePolicy.php`
- `backend/config/employees.php`

**Files to keep (verified dependencies):** `backend/app/Models/Employee.php` and `ProvisionEmployeeUser` — migration `2026_07_29_234405_provision_ofs_manager_users.php` instantiates `ProvisionEmployeeUser`, and baseline migrations seed `employees` rows linked from `users` (`employee_id`, `emp_id`). Deleting these would break fresh installs.

**Files to modify:**
- `backend/routes/api.php:97-99` — delete the three `/admin/employees` routes and the `EmployeeController` import (line 6).
- `backend/app/Providers/AppServiceProvider.php:36-50` — delete the `EmployeeDirectorySource` singleton and its imports (lines 5, 11-12).
- `backend/app/Http/Resources/EmployeeResource.php` deletion also removes the only `EmployeeResource` reference; verify with `grep -rn "EmployeeResource\|EmployeeDirectorySource\|EmployeeController\|ExternalEmployeeData\|EmployeeIndexQuery" backend/app backend/routes` → no hits.
- `backend/.env.example` — remove `EMPLOYEE_DIRECTORY_SOURCE`, `EMPLOYEE_CSV_PATH`, `EMPLOYEE_VISIBLE_EMP_IDS`; add `ATMS_ALLOWED_EMAIL_DOMAINS=ldc.com.ly`.
- `compose.yaml:28` — remove the `EMPLOYEE_VISIBLE_EMP_IDS` line from the api service environment block.

**Step:** `php artisan route:list --path=admin` — no `/admin/employees` routes. `php artisan test --compact` — GREEN (employee tests are removed in Task C8, so run the suite after that task).
**Commit:** `refactor(employees): remove SharePoint employee-directory infrastructure`.

### Task C7: Remove frontend employee infrastructure (already done in C5 — verify)

**Step:** `grep -rn "employee\|Employee\|provision" frontend/src --include='*.vue' --include='*.ts' -i` → no hits except intentional (none expected). Delete `frontend/src/components/admin/ProvisionUserDialog.vue` (orphaned).
**Commit:** `refactor(frontend): delete ProvisionUserDialog`.

### Task C8: Delete obsolete employee tests — ⚠️ REQUIRES EXPLICIT USER APPROVAL

Per AGENTS.md ("You must not remove any tests or test files from the tests directory without approval"), deleting these needs the user's go-ahead:

- `backend/tests/Feature/Employees/EmployeeImportTest.php`
- `backend/tests/Feature/Employees/EmployeeIndexTest.php`
- `backend/tests/Feature/Employees/EmployeeProvisioningTest.php`

They test deleted endpoints/actions and cannot pass. **Do not delete until the user approves this task.** Alternative if refused: keep the files but mark them `#[Group('legacy')]`-style skipped — not recommended.

**Step:** after deletion, `php artisan test --compact` → GREEN.
**Commit:** `test(employees): remove tests for deleted employee-directory endpoints`.

---

## Phase D — WO meter reading: delta entry + auto Total

The API contract is unchanged: `POST /assets/{id}/meter-readings` still receives `reading_value` = the **absolute total**. The delta is entered in the UI; the frontend computes `total = base + delta` and sends the total. `ConfirmMeterReading`'s "not lower than latest confirmed" guard keeps holding because total ≥ last.

Base definition: reuse the existing `lastReadingForDraft` (latest reading for the type by `reading_at`, id tiebreak — confirmed or not, matching the "Last recorded:" hint already shown). First reading on an asset (no base): Total = entered delta.

### Task D1: Composables — delta semantics

**Files:**
- Modify: `frontend/src/composables/useWorkOrderDetail.ts` (~lines 280-321, 689-723)

1. Keep `lastReadingForDraft` as-is (it becomes the delta base).
2. Add a computed total:
   ```ts
   const draftTotal = computed<number | null>(() => {
     const base = lastReadingForDraft.value
     const delta = readingDraft.value.value
     if (delta == null) return null
     return base ? Math.round((base.value + delta) * 100) / 100 : delta
   })
   ```
3. Replace the `readingBelowLast` guard (lines 306-321) with a negative-delta guard:
   ```ts
   const readingDeltaNegative = computed<boolean>(() => readingDraft.value.value != null && readingDraft.value.value < 0)
   ```
   Remove `lowerReadingAcknowledged` and its watcher.
4. `doRecordReading()` (line 700-723): post `reading_value: draftTotal.value` instead of `readingDraft.value.value`; drop the `readingBelowLast`/ack check; keep the `draftTotal.value == null` block.
5. `openRecordReading()` — reset the draft as today; no change needed.
6. `openEditReading()`/`doEditReading()` — **unchanged**: the edit dialog remains an absolute-total correction of an existing row (deliberate; changing it to delta would make corrections ambiguous).

### Task D2: Record-reading dialog UI

**Files:**
- Modify: `frontend/src/views/work-orders/WorkOrderDetailView.vue:1116-1143`

1. Label `Value` → `Value since last reading`; placeholder/hint: keep the "Last recorded: …" hint block.
2. Below the value input, add a read-only Total display:
   ```html
   <div class="form-field" v-if="draftTotal != null">
     <Label>Total (current meter reading)</Label>
     <p class="reading-total-display">
       <b>{{ draftTotal.toLocaleString() }} {{ lastReadingForDraft?.unit ?? '' }}</b>
     </p>
   </div>
   ```
   (Add a small `.reading-total-display` style in `frontend/src/style.css` next to the existing `reading-last-hint`.)
3. Replace the `readingBelowLast` warning block with a negative-delta warning (`v-if="readingDeltaNegative"`, text "The value since the last reading cannot be negative.").
4. Submit-button disabled condition (line 1166-1171): replace `(readingBelowLast && !lowerReadingAcknowledged)` with `readingDeltaNegative`; keep the null checks.
5. Remove `readingValueStr`'s dependence on nothing new — it still binds `readingDraft.value` (now the delta input); no change.

### Task D3: Verification

**Steps:**
1. `npm run type-check` — GREEN (import `draftTotal`/`readingDeltaNegative` where used).
2. `php artisan test --compact tests/Feature/Assets/MeterReadingWorkflowTest.php` — GREEN **unchanged** (proves the API contract did not change).
3. Manual check in the running app: WO detail → Record meter reading → enter delta 50 with last=130 → Total shows 180; posted reading stores 180.

**Commit:** `feat(work-orders): meter-reading form enters delta, shows auto-calculated Total`.

---

## Phase E — Docs, env, deploy config

### Task E1: Environment plumbing (done in C6 — verify both)

`compose.yaml` (api env block), `backend/.env.example`, and `frontend/.env.production` (if it sets `EMPLOYEE_*` — grep first) must carry `ATMS_ALLOWED_EMAIL_DOMAINS=ldc.com.ly` and no `EMPLOYEE_*` keys. The VPS `.env` needs the new key added on next deploy (reconcile via `scripts/reconcile-env.sh` if used).

### Task E2: Documentation

**Files (modify):**
1. `docs/PRODUCT.md` — "User provisioning" section: replace "Pending implementation" phrasing with implemented flow; operational-status section: update the status list (Ready for Field, Under Maintenance, Down, Under Inspection, Scraped, Lost in Hole) and any "Active/Retired" references; meter-reading section: document the delta + auto-Total semantics in the WO form.
2. `docs/API.md` — remove the `/admin/employees` bullets (already partly rewritten — make it accurate: `POST /admin/users` now exists); note the renamed operational-status values.
3. `frontend/src/content/user-manual.md` — update the `operational_status` enum tables (lines ~509, 1068-1076, 3461-3464), the "Retired — The Terminal Operational State" section (→ Scraped), WO close/cancel status wording (`active` → `ready_for_field`), and the meter-reading "Value" description.
4. `docs/README.md` — bump "Last documentation verification" to 2026-08-04 with a note.
5. `.kilo/STATE.md` + `.kilo/TLD.md` — move the three pending items to done; record VPS-deploy verification steps.

### Task E3: Final verification + handoff

**Steps:**
1. `vendor/bin/pint --dirty --format agent` (backend formatting).
2. `php artisan test --compact` — full GREEN.
3. `npm run type-check` + `npm run build` (frontend).
4. `git status` — review diff; commit remaining docs.
5. Hand to user: push + deploy (deploy.sh runs migrations automatically); post-deploy verify with the tinker commands in Task B2 and a fresh-user creation test against the API.

---

## Open decisions for the user (confirm before execution)

1. **TJB location type** — planned as `yard` (keeps the Start-WO workshop/yard gate working). Confirm or provide the type.
2. **Test deletion (Task C8)** — removing `tests/Feature/Employees/*` needs explicit approval per AGENTS.md.
3. **Delta base** — planned as the latest reading of the type (confirmed or not), matching the existing "Last recorded:" hint; PM triggers and R-20 continue to use confirmed readings only.
