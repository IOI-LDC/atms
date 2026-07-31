# Maintenance Category Normalization Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Merge the duplicate APS category, normalize the VERTEX and SUB FLOW categories, and prevent malformed category codes from returning.

**Architecture:** Ship the existing-data correction as an idempotent Laravel data migration so local, test, and production databases receive the same change through `php artisan migrate --force`. Centralize category-code generation in `MaintenanceCategory::codeFor()` and make both controlled importers use it; the Vue frontend needs no source change because it reads category codes and names from the API.

**Tech Stack:** PHP 8.4, Laravel 13, PostgreSQL, PHPUnit 12, Vue 3.5, TypeScript, Vite.

---

### Task 1: Centralize Maintenance Category code normalization

**Files:**
- Create: `backend/tests/Unit/Models/MaintenanceCategoryTest.php`
- Modify: `backend/app/Models/MaintenanceCategory.php`
- Modify: `backend/app/Console/Commands/ImportAssetsCommand.php`
- Modify: `backend/app/Console/Commands/ImportPartsCommand.php`
- Modify: `backend/tests/Feature/Parts/PartMigrationImportTest.php`
- Create: `backend/tests/Feature/Assets/AssetMigrationImportTest.php`

**Step 1: Generate the PHPUnit unit test**

Run:

```bash
cd backend
php artisan make:test --phpunit --unit Models/MaintenanceCategoryTest --no-interaction
```

**Step 2: Write the failing normalizer test**

Add assertions equivalent to:

```php
public function test_code_for_collapses_separator_runs_and_trims_them(): void
{
    $this->assertSame('MWD_APS', MaintenanceCategory::codeFor('MWD / APS'));
    $this->assertSame('MWD_VERTEX', MaintenanceCategory::codeFor('MWD---VERTEX'));
    $this->assertSame('MWD_SUB_FLOW', MaintenanceCategory::codeFor('  MWD / SUB FLOW  '));
}
```

**Step 3: Run the unit test and confirm the current defect**

Run:

```bash
php artisan test --compact tests/Unit/Models/MaintenanceCategoryTest.php
```

Expected: FAIL because `MWD / APS` currently produces `MWD__APS`.

**Step 4: Implement the canonical normalizer**

Change `MaintenanceCategory::codeFor()` to:

```php
public static function codeFor(string $name): string
{
    $code = preg_replace('/[^A-Z0-9]+/', '_', mb_strtoupper($name)) ?? '';

    return trim($code, '_');
}
```

**Step 5: Run the unit test**

Run:

```bash
php artisan test --compact tests/Unit/Models/MaintenanceCategoryTest.php
```

Expected: PASS.

**Step 6: Add a failing Asset import regression test**

Generate the feature test:

```bash
php artisan make:test --phpunit Assets/AssetMigrationImportTest --no-interaction
```

The test must create one existing Asset, write a temporary CSV with the command's
required headers and `maintenance_category` set to `MWD / APS`, run
`atms:import-assets`, and assert that the assigned category code is `MWD_APS`.

**Step 7: Run the Asset import test**

Run:

```bash
php artisan test --compact tests/Feature/Assets/AssetMigrationImportTest.php
```

Expected: FAIL because `ImportAssetsCommand::syncCategories()` still creates
`MWD__APS`.

**Step 8: Route both importers through the shared normalizer**

- In `ImportAssetsCommand::syncCategories()`, replace the inline string
  transformation with `MaintenanceCategory::codeFor($name)`.
- In `ImportPartsCommand`, replace calls to the private `categoryCode()` method
  with `MaintenanceCategory::codeFor()` and remove the duplicate private method.
- Extend `PartMigrationImportTest` with `MWD / APS` so the canonical code remains
  covered in the Parts import path.

**Step 9: Run the importer tests**

Run:

```bash
php artisan test --compact tests/Feature/Assets/AssetMigrationImportTest.php
php artisan test --compact tests/Feature/Parts/PartMigrationImportTest.php
```

Expected: PASS.

**Step 10: Commit the normalizer work**

Stage only the files from this task and commit:

```bash
git commit -m "fix: normalize maintenance category codes"
```

### Task 2: Add the deploy-time data cleanup migration

**Files:**
- Create: `backend/database/migrations/<timestamp>_normalize_maintenance_category_codes.php`
- Create: `backend/tests/Feature/Migrations/NormalizeMaintenanceCategoryCodesTest.php`

**Step 1: Generate an empty data migration**

Run:

```bash
cd backend
php artisan make:migration normalize_maintenance_category_codes --no-interaction
```

Do not modify the deployed table-creation migrations.

**Step 2: Generate and write the migration regression test**

Run:

```bash
php artisan make:test --phpunit Migrations/NormalizeMaintenanceCategoryCodesTest --no-interaction
```

The test must:

1. Create `MWD_APS` and `MWD__APS`.
2. Assign a Part to `MWD_APS` and Assets to `MWD__APS`,
   `MWD__VERTEX`, and `SUB_FLOW__MWD`.
3. Load the generated migration with:

```php
$path = collect(glob(database_path('migrations/*_normalize_maintenance_category_codes.php')))
    ->sole();
$migration = require $path;
$migration->up();
```

4. Assert:

```php
$this->assertDatabaseMissing('maintenance_categories', ['code' => 'MWD__APS']);
$this->assertDatabaseHas('maintenance_categories', ['code' => 'MWD_APS', 'name' => 'MWD / APS']);
$this->assertDatabaseHas('maintenance_categories', ['code' => 'MWD_VERTEX', 'name' => 'MWD / VERTEX']);
$this->assertDatabaseHas('maintenance_categories', ['code' => 'MWD_SUB_FLOW', 'name' => 'MWD / SUB FLOW']);
```

5. Assert every Asset and Part still points to the correct canonical category.
6. Call `up()` again and assert the same result to prove idempotency.

**Step 3: Run the migration test**

Run:

```bash
php artisan test --compact tests/Feature/Migrations/NormalizeMaintenanceCategoryCodesTest.php
```

Expected: FAIL because the generated migration does not yet change the data.

**Step 4: Implement the migration**

Inside `up()`, use `DB::transaction()` and a private merge helper that:

- finds the legacy and canonical category rows by code;
- renames the legacy row in place when the canonical row does not exist;
- otherwise repoints both `assets.maintenance_category_id` and
  `parts.maintenance_category_id` to the canonical row, updates the canonical
  name, and deletes the legacy row;
- safely does nothing when neither row exists.

Apply these mappings:

```php
[
    ['MWD__APS', 'MWD_APS', 'MWD / APS'],
    ['MWD__VERTEX', 'MWD_VERTEX', 'MWD / VERTEX'],
    ['SUB_FLOW__MWD', 'MWD_SUB_FLOW', 'MWD / SUB FLOW'],
]
```

Keep `down()` intentionally empty with a PHPDoc explanation: merging the APS
rows destroys the provenance needed for a truthful rollback, so any reversal
must be a forward-fix migration.

**Step 5: Run the migration test**

Run:

```bash
php artisan test --compact tests/Feature/Migrations/NormalizeMaintenanceCategoryCodesTest.php
```

Expected: PASS.

**Step 6: Commit the migration work**

Stage only the generated migration and its test, then commit:

```bash
git commit -m "fix: clean maintenance category data"
```

### Task 3: Apply and verify the cleanup locally

**Files:**
- Verify: `deploy.sh`
- Verify: `docs/OPERATIONS.md`

**Step 1: Confirm the production deploy runs migrations**

Run:

```bash
rg -n "php artisan migrate --force" deploy.sh docs/OPERATIONS.md
bash -n deploy.sh
```

Expected: `deploy.sh` runs `docker compose exec -T api php artisan migrate --force`
before the production parts import, and the shell syntax check exits successfully.

**Step 2: Apply the new migration to the current database**

Run:

```bash
cd backend
php artisan migrate --force --no-interaction
```

Expected: the new normalization migration reports `DONE`.

**Step 3: Verify the persisted canonical rows and associations**

Use Laravel Boost's read-only database query to assert:

- `MWD__APS`, `MWD__VERTEX`, and `SUB_FLOW__MWD` are absent;
- `MWD_APS`, `MWD_VERTEX`, and `MWD_SUB_FLOW` exist;
- counts are 43 Assets and 3 Parts for `MWD_APS`, 11 Assets for
  `MWD_VERTEX`, and 13 Assets for `MWD_SUB_FLOW`;
- `MWD_SUB_FLOW.name` is `MWD / SUB FLOW`.

### Task 4: Final verification

**Files:**
- Verify all modified backend files.
- Verify the frontend consumes API data without legacy literals.

**Step 1: Run the focused backend tests**

Run:

```bash
cd backend
php artisan test --compact tests/Unit/Models/MaintenanceCategoryTest.php
php artisan test --compact tests/Feature/Assets/AssetMigrationImportTest.php
php artisan test --compact tests/Feature/Parts/PartMigrationImportTest.php
php artisan test --compact tests/Feature/Migrations/NormalizeMaintenanceCategoryCodesTest.php
php artisan test --compact tests/Feature/Admin/MaintenanceCategoryTest.php
php artisan test --compact tests/Feature/ListOptions/ListOptionControllerTest.php
```

Expected: all pass with zero failures.

**Step 2: Format PHP**

Run:

```bash
cd backend
vendor/bin/pint --dirty --format agent
```

Expected: exit code 0.

**Step 3: Re-run the focused backend tests after formatting**

Run the same six PHPUnit commands from Step 1.

Expected: all pass with zero failures.

**Step 4: Sweep backend and frontend source**

Run from the repository root:

```bash
rg -n --glob '!vendor/**' --glob '!node_modules/**' \
  'MWD__APS|MWD__VERTEX|SUB_FLOW__MWD' backend/app frontend/src
```

Expected: no matches.

**Step 5: Build the frontend**

Run:

```bash
cd frontend
npm run build
```

Expected: the TypeScript and Vite production build exits successfully.

**Step 6: Run repository integrity checks**

Run:

```bash
git diff --check
git status --short
```

Expected: no whitespace errors; only the intended cleanup files plus the
user's pre-existing in-progress changes are present.
