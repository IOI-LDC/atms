# Asset Location Filter Fix Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Make the documented `GET /api/assets?location_id={id}` filter return assets whose current location matches the requested location without changing the public API contract.

**Architecture:** Preserve `location_id` as the public query parameter and translate it inside the existing `AssetIndexQuery` to the actual `assets.current_location_id` foreign-key column. Keep the current role scoping, eager loading, cursor pagination, frontend client-side filtering, and response format unchanged.

**Tech Stack:** PHP 8.4, Laravel 13, Eloquent, PostgreSQL, PHPUnit 12.

**Current status:** The query correction and regression tests are applied on `main`.
Focused verification is intentionally left pending for the delivery team to run.

---

## Scope

### Included

- Correct the database column used by the existing backend location filter.
- Add HTTP-level regression coverage for location filtering.
- Prove that location filtering composes with the existing role-based active-asset scope.
- Run the focused PHPUnit test and Laravel Pint.

### Excluded

- Renaming the public query parameter to `current_location_id`.
- Changing the Vue Assets page, which intentionally filters its fully loaded rows in memory.
- Adding query-parameter validation or a Form Request for all asset-list filters.
- Adding a PostgreSQL index or migration for `current_location_id`.
- Changing API documentation; it already correctly documents `location_id` as the current-location filter.

## Current Defect

`AssetIndexQuery::applyFilters()` accepts the documented `location_id` query parameter but applies it to a nonexistent `assets.location_id` column:

```php
if ($request->filled('location_id')) {
    $query->where('location_id', $request->input('location_id'));
}
```

The assets table stores the relationship in `current_location_id`. A direct request containing `location_id` therefore fails at the database layer. The current Vue page does not expose the failure because it filters `current_location.id` client-side after fetching the asset list.

---

### Task 1: Add the failing endpoint regression tests

**Files:**

- Modify: `backend/tests/Feature/ReadModels/AssetResourceTest.php`

**Step 1: Add a test proving the public filter selects the current location**

Add this PHPUnit test to `AssetResourceTest`:

```php
public function test_assets_can_be_filtered_by_current_location(): void
{
    $admin = $this->createUser(RoleCode::ADMINISTRATOR);
    $selectedLocation = Location::create([
        'name' => 'Selected Location',
        'type' => 'building',
    ]);
    $otherLocation = Location::create([
        'name' => 'Other Location',
        'type' => 'building',
    ]);

    $selectedAsset = Asset::create([
        'erp_asset_code' => 'A-LOC-001',
        'name' => 'Selected Asset',
        'current_location_id' => $selectedLocation->id,
        'is_active' => true,
    ]);
    $otherAsset = Asset::create([
        'erp_asset_code' => 'A-LOC-002',
        'name' => 'Other Asset',
        'current_location_id' => $otherLocation->id,
        'is_active' => true,
    ]);

    $response = $this->actingAs($admin)
        ->getJson("/api/assets?location_id={$selectedLocation->id}");

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $selectedAsset->id)
        ->assertJsonPath('data.0.current_location.id', $selectedLocation->id)
        ->assertJsonMissing(['id' => $otherAsset->id]);
}
```

**Step 2: Add a test proving role scoping still applies**

```php
public function test_location_filter_preserves_requester_active_asset_scope(): void
{
    $requester = $this->createUser(RoleCode::REQUESTER);
    $location = Location::create([
        'name' => 'Scoped Location',
        'type' => 'building',
    ]);

    $activeAsset = Asset::create([
        'erp_asset_code' => 'A-LOC-003',
        'name' => 'Active Scoped Asset',
        'current_location_id' => $location->id,
        'is_active' => true,
    ]);
    $inactiveAsset = Asset::create([
        'erp_asset_code' => 'A-LOC-004',
        'name' => 'Inactive Scoped Asset',
        'current_location_id' => $location->id,
        'is_active' => false,
    ]);

    $response = $this->actingAs($requester)
        ->getJson("/api/assets?location_id={$location->id}");

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $activeAsset->id)
        ->assertJsonMissing(['id' => $inactiveAsset->id]);
}
```

The existing unfiltered asset-list tests already cover omitted-filter behaviour; do not duplicate them.

**Step 3: Run the focused tests and verify the new tests fail**

Run from the repository root:

```bash
docker compose exec api php artisan test --compact tests/Feature/ReadModels/AssetResourceTest.php
```

Expected result: the two new tests fail because the generated SQL references the nonexistent `assets.location_id` column. Existing tests should remain green.

---

### Task 2: Correct the query column

**Files:**

- Modify: `backend/app/Queries/Assets/AssetIndexQuery.php:63`
- Test: `backend/tests/Feature/ReadModels/AssetResourceTest.php`

**Step 1: Apply the minimal query correction**

Replace:

```php
$query->where('location_id', $request->input('location_id'));
```

with:

```php
$query->where('current_location_id', $request->input('location_id'));
```

Do not rename the request parameter. `location_id` remains the documented external contract; `current_location_id` is the persistence detail.

**Step 2: Run the focused test file**

```bash
docker compose exec api php artisan test --compact tests/Feature/ReadModels/AssetResourceTest.php
```

Expected result: all tests in `AssetResourceTest.php` pass, including the two new regression tests.

**Step 3: Format the modified PHP files**

```bash
docker compose exec api vendor/bin/pint --dirty --format agent
```

Expected result: Pint completes successfully and only formats the PHP files modified for this fix, if necessary.

**Step 4: Re-run the focused test after formatting**

```bash
docker compose exec api php artisan test --compact tests/Feature/ReadModels/AssetResourceTest.php
```

Expected result: all focused tests pass.

**Step 5: Review the final diff**

```bash
git diff --check
git diff -- backend/app/Queries/Assets/AssetIndexQuery.php backend/tests/Feature/ReadModels/AssetResourceTest.php
```

Expected result: no whitespace errors; the diff contains only the corrected column and focused regression tests.

**Step 6: Commit only when explicitly requested**

Do not stage or commit as part of implementation unless the user explicitly requests it. If requested, use:

```bash
git add backend/app/Queries/Assets/AssetIndexQuery.php backend/tests/Feature/ReadModels/AssetResourceTest.php
git commit -m "fix: filter assets by current location"
```

---

## Acceptance Criteria

- `GET /api/assets?location_id={id}` returns HTTP 200.
- Only assets whose `current_location_id` matches `{id}` are returned.
- The public `location_id` parameter remains unchanged.
- Existing role scoping still excludes inactive assets for Technician, Logistics, Requester, and other non-Admin/non-Manager roles.
- The response continues to include the eager-loaded `current_location` resource.
- The Vue Assets page remains unchanged.
- No migration or API-documentation change is introduced.
- The focused PHPUnit test file passes and Pint completes successfully.
