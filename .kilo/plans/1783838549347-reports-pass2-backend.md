# Reports Pass 2 — Backend Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add 12 read-only report endpoints (R-3, R-4, R-6, R-9, R-13, R-15, R-16, R-17, R-18, R-19, R-20, R-21) under `GET /api/reports/*`, reusing Pass 1 patterns.

**Architecture:** Extend existing `ReportController` with new methods. One Query class per report under `app/Queries/Reports/`. Same conventions as Pass 1: `Gate::authorize('viewDashboard', User::class)`, `$request->validate()`, `app(QueryClass)->handle()`.

**Tech Stack:** Laravel 13 / PHP 8.4 / PHPUnit 12 / Sanctum / PostgreSQL.

**Spec source:** `docs/atms/01-product/REPORTS.md` §4-5.

---

## Decisions (confirmed)

| # | Decision | Resolution |
|---|---|---|
| D1 | R-3/R-4 reuse dashboard KPI logic | Reuse `ReliabilityKpiQuery` definitions (MTBF = window_days / failures, MTTR = mean assigned_at→closed_at hours). Add dimension grouping. |
| D2 | R-15 operational-only fence | WO counts (assigned/open/in-progress/completed/cancelled), backlog, avg duration. NO hours worked, labor cost, utilization %, efficiency scores. |
| D3 | R-6 failure-mode limitation | ATMS has no failure taxonomy. R-6 identifies bad-actor assets by count only, no Pareto of failure modes. |
| D4 | R-19/R-20 data-quality caveats | Boolean fields: true/false counts. Numeric fields: pre/post comparison within same field+unit only. No pass/fail labels. |

## Conventions (from Pass 1 — follow exactly)

- **Auth:** `Gate::authorize('viewDashboard', User::class)` in every method.
- **Filters:** inline `$request->validate([...])` per method.
- **Pagination:** `cursorPaginate($perPage)` with `id` tie-breaker. Validate `per_page` as `nullable|integer|min:1|max:500` (default 25).
- **Age/diffInDays:** Use `AgingBuckets::daysFrom($today, $pastDate)` (abs() for Carbon 3 signed diffInDays).
- **Null vs zero:** `null`/`—` when no basis to compute, `0` only for real zeros.
- **Timezone:** stored UTC; queries use `now()` and date columns directly.
- **Routes:** add to existing `Route::prefix('reports')->group(...)` inside `auth:sanctum` + `EnsureTokenAbilities` group.

## Report specs

| ID | Endpoint | Window | Filters | Summary | Items |
|---|---|---|---|---|---|
| R-3 | `GET /reports/mtbf` | backward 90d | `from`, `to`, `group_by`(asset\|category\|location) | overall mtbf_days, failure_count, failure_rate_per_day | per-dimension mtbf_days, failure_count, failure_rate |
| R-4 | `GET /reports/mttr` | backward 90d | `from`, `to`, `group_by`(asset\|category\|technician) | overall mttr_hours, repair_count | per-dimension mttr_hours, repair_count |
| R-6 | `GET /reports/bad-actors` | backward 90d | `from`, `to`, `group_by`(asset\|category\|location), `limit` | total_failures | per-dimension failure_count, sorted desc |
| R-9 | `GET /reports/pm-coverage` | current-state | `location_id`, `asset_kind` | total_assets, covered_assets, coverage_pct | uncovered assets list (paginated) |
| R-13 | `GET /reports/booking` | current-state | `location_id`, `asset_kind` | total_assets, booked_count, available_count | per-location booked/available breakdown |
| R-15 | `GET /reports/technician-workload` | backward 90d | `from`, `to`, `technician_id` | total_assigned, total_open, total_in_progress, total_completed, total_cancelled, avg_duration_hours | per-technician counts + avg duration (paginated) |
| R-16 | `GET /reports/throughput` | backward 90d | `from`, `to`, `status` | mr_created, mr_converted, wo_created, wo_closed, avg_conversion_hours | daily/weekly counts (paginated) |
| R-17 | `GET /reports/parts-consumption` | backward 90d | `from`, `to`, `part_id`, `asset_id` | total_quantity, total_line_items | per-part quantity + top consumers (paginated) |
| R-18 | `GET /reports/asset-movement` | backward 90d | `from`, `to`, `asset_id`, `from_location_id`, `to_location_id` | total_movements | movement log (paginated) |
| R-19 | `GET /reports/form-results` | backward 90d | `from`, `to`, `asset_id`, `fa_subclass_code`, `field_uuid` | total_fields, boolean_true, boolean_false, numeric_comparisons | field results (paginated) |
| R-20 | `GET /reports/meter-progression` | backward 90d | `from`, `to`, `asset_id`, `usage_reading_type_id` | total_readings, confirmed_readings | reading progression (paginated) |
| R-21 | `GET /reports/pm-suppression` | backward 90d | `from`, `to`, `pm_rule_id`, `asset_id`, `decision_type` | total_suppressions | suppression log (paginated) |

## Blocked reports (deferred — missing infrastructure)

| ID | Report | Blocker |
|---|---|---|
| R-5 | Availability/Downtime | No `asset_status_history` ledger |
| R-10B | Maintenance Lifecycle Status | No ERP-derived lifecycle state |
| R-11 | Lost/Decommissioned | No ERP-derived lifecycle state |
| R-12 | Spare Pool | No `asset_assembly_history` table |

---

## Tasks

### Task 1: R-3 MTBF by dimension

**Files:**
- Create: `backend/app/Queries/Reports/MtbfReportQuery.php`
- Modify: `backend/app/Http/Controllers/ReportController.php` (add `mtbf` method)
- Modify: `backend/routes/api.php` (add route)
- Create: `backend/tests/Feature/Reports/MtbfReportTest.php`

**Step 1: Write the failing test**

```php
public function test_unauthenticated_is_rejected(): void
{
    $this->getJson('/api/reports/mtbf')->assertUnauthorized();
}

public function test_calculates_mtbf_by_asset(): void
{
    $admin = $this->createUser(RoleCode::ADMINISTRATOR);
    $asset = $this->createAsset();
    // Create 2 corrective failures in the last 90 days
    MaintenanceRequest::forceCreate([
        'number' => 'MR-1', 'asset_id' => $asset->id, 'status' => 'converted',
        'priority' => 'high', 'description' => 'Failure 1', 'created_by' => $admin->id,
        'is_preventive' => false, 'is_failure' => true, 'created_at' => now()->subDays(10),
    ]);
    MaintenanceRequest::forceCreate([
        'number' => 'MR-2', 'asset_id' => $asset->id, 'status' => 'converted',
        'priority' => 'high', 'description' => 'Failure 2', 'created_by' => $admin->id,
        'is_preventive' => false, 'is_failure' => true, 'created_at' => now()->subDays(20),
    ]);

    $json = $this->actingAs($admin)->getJson('/api/reports/mtbf?group_by=asset')->json();

    $this->assertSame(2, $json['summary']['failure_count']);
    $this->assertNotNull($json['summary']['mtbf_days']);
}
```

**Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=MtbfReportTest`
Expected: FAIL with "Route not found" or "Method not found"

**Step 3: Implement MtbfReportQuery**

```php
<?php

namespace App\Queries\Reports;

use App\Models\MaintenanceRequest;
use Carbon\Carbon;

class MtbfReportQuery
{
    public function handle(Carbon $from, Carbon $to, string $groupBy): array
    {
        $windowDays = $from->diffInDays($to);
        
        $failures = MaintenanceRequest::where('is_failure', true)
            ->whereBetween('created_at', [$from, $to])
            ->with('asset.currentLocation')
            ->get();

        $failureCount = $failures->count();
        $mtbfDays = $failureCount > 0 ? round($windowDays / $failureCount, 2) : null;
        $failureRatePerDay = round($failureCount / $windowDays, 4);

        // Group by dimension
        $grouped = $failures->groupBy(function ($mr) use ($groupBy) {
            return match ($groupBy) {
                'asset' => $mr->asset_id,
                'category' => $mr->asset?->fa_subclass_code ?? 'unknown',
                'location' => $mr->asset?->current_location_id ?? 'unassigned',
                default => $mr->asset_id,
            };
        });

        $items = $grouped->map(function ($groupFailures, $key) use ($windowDays, $groupBy, $failures) {
            $count = $groupFailures->count();
            $mtbf = $count > 0 ? round($windowDays / $count, 2) : null;
            $rate = round($count / $windowDays, 4);
            
            $first = $groupFailures->first();
            $label = match ($groupBy) {
                'asset' => $first->asset?->name,
                'category' => $key,
                'location' => $first->asset?->currentLocation?->name ?? 'Unassigned',
                default => $key,
            };

            return [
                'group_key' => $key,
                'group_label' => $label,
                'failure_count' => $count,
                'mtbf_days' => $mtbf,
                'failure_rate_per_day' => $rate,
            ];
        })->sortByDesc('failure_count')->values();

        return [
            'summary' => [
                'mtbf_days' => $mtbfDays,
                'failure_count' => $failureCount,
                'failure_rate_per_day' => $failureRatePerDay,
            ],
            'items' => $items->all(),
        ];
    }
}
```

**Step 4: Add controller method and route**

In `ReportController.php`:
```php
public function mtbf(Request $request): \Illuminate\Http\JsonResponse
{
    Gate::authorize('viewDashboard', User::class);

    $filters = $request->validate([
        'from' => ['nullable', 'date'],
        'to' => ['nullable', 'date', 'after_or_equal:from'],
        'group_by' => ['nullable', Rule::in(['asset', 'category', 'location'])],
    ]);

    $from = isset($filters['from']) ? Carbon::parse($filters['from']) : now()->subDays(90);
    $to = isset($filters['to']) ? Carbon::parse($filters['to']) : now();

    $result = app(MtbfReportQuery::class)->handle($from, $to, $filters['group_by'] ?? 'asset');

    return response()->json($result);
}
```

In `routes/api.php` (inside reports group):
```php
Route::get('mtbf', [ReportController::class, 'mtbf']);
```

**Step 5: Run test to verify it passes**

Run: `php artisan test --compact --filter=MtbfReportTest`
Expected: PASS

**Step 6: Commit**

```bash
git add backend/app/Queries/Reports/MtbfReportQuery.php backend/app/Http/Controllers/ReportController.php backend/routes/api.php backend/tests/Feature/Reports/MtbfReportTest.php
git commit -m "feat(reports): add R-3 MTBF by dimension endpoint"
```

---

### Task 2: R-4 MTTR by dimension

**Files:**
- Create: `backend/app/Queries/Reports/MttrReportQuery.php`
- Modify: `backend/app/Http/Controllers/ReportController.php` (add `mttr` method)
- Modify: `backend/routes/api.php` (add route)
- Create: `backend/tests/Feature/Reports/MttrReportTest.php`

**Step 1: Write the failing test**

```php
public function test_calculates_mttr_by_technician(): void
{
    $admin = $this->createUser(RoleCode::ADMINISTRATOR);
    $tech = $this->createUser(RoleCode::TECHNICIAN);
    $asset = $this->createAsset();
    
    // Create corrective WO closed in last 90 days
    $mr = MaintenanceRequest::forceCreate([
        'number' => 'MR-1', 'asset_id' => $asset->id, 'status' => 'converted',
        'priority' => 'high', 'description' => 'Failure', 'created_by' => $admin->id,
        'is_preventive' => false, 'created_at' => now()->subDays(10),
    ]);
    WorkOrder::forceCreate([
        'number' => 'WO-1', 'asset_id' => $asset->id, 'maintenance_request_id' => $mr->id,
        'status' => 'closed', 'priority' => 'high', 'assigned_to_user_id' => $tech->id,
        'assigned_at' => now()->subDays(10), 'closed_at' => now()->subDays(8),
        'closed_by_user_id' => $admin->id, 'created_at' => now()->subDays(10),
    ]);

    $json = $this->actingAs($admin)->getJson('/api/reports/mttr?group_by=technician')->json();

    $this->assertSame(1, $json['summary']['repair_count']);
    $this->assertNotNull($json['summary']['mttr_hours']);
}
```

**Step 2-6: Same pattern as Task 1** (implement query, controller, route, test, commit)

---

### Task 3: R-6 Bad-Actor Analysis

**Files:**
- Create: `backend/app/Queries/Reports/BadActorReportQuery.php`
- Modify: `backend/app/Http/Controllers/ReportController.php` (add `badActors` method)
- Modify: `backend/routes/api.php` (add route)
- Create: `backend/tests/Feature/Reports/BadActorReportTest.php`

**Pattern:** Similar to R-3 but sorted by failure_count desc, with optional `limit` parameter.

---

### Task 4: R-9 PM Coverage

**Files:**
- Create: `backend/app/Queries/Reports/PmCoverageReportQuery.php`
- Modify: `backend/app/Http/Controllers/ReportController.php` (add `pmCoverage` method)
- Modify: `backend/routes/api.php` (add route)
- Create: `backend/tests/Feature/Reports/PmCoverageReportTest.php`

**Pattern:** Anti-join `assets` to `asset_pm_assignments` where `is_active = true`. Return paginated list of uncovered assets.

---

### Task 5: R-13 Booking

**Files:**
- Create: `backend/app/Queries/Reports/BookingReportQuery.php`
- Modify: `backend/app/Http/Controllers/ReportController.php` (add `booking` method)
- Modify: `backend/routes/api.php` (add route)
- Create: `backend/tests/Feature/Reports/BookingReportTest.php`

**Pattern:** Group by location, count `is_booked = true` vs `false`.

---

### Task 6: R-15 Technician Workload

**Files:**
- Create: `backend/app/Queries/Reports/TechnicianWorkloadReportQuery.php`
- Modify: `backend/app/Http/Controllers/ReportController.php` (add `technicianWorkload` method)
- Modify: `backend/routes/api.php` (add route)
- Create: `backend/tests/Feature/Reports/TechnicianWorkloadReportTest.php`

**Pattern:** Group WOs by `assigned_to_user_id`, count by status, calculate avg duration. Paginated.

---

### Task 7: R-16 Throughput

**Files:**
- Create: `backend/app/Queries/Reports/ThroughputReportQuery.php`
- Modify: `backend/app/Http/Controllers/ReportController.php` (add `throughput` method)
- Modify: `backend/routes/api.php` (add route)
- Create: `backend/tests/Feature/Reports/ThroughputReportTest.php`

**Pattern:** Count MRs/WOs by status over period, calculate avg conversion time.

---

### Task 8: R-17 Parts Consumption

**Files:**
- Create: `backend/app/Queries/Reports/PartsConsumptionReportQuery.php`
- Modify: `backend/app/Http/Controllers/ReportController.php` (add `partsConsumption` method)
- Modify: `backend/routes/api.php` (add route)
- Create: `backend/tests/Feature/Reports/PartsConsumptionReportTest.php`

**Pattern:** Aggregate `work_order_parts` by part, sum quantities.

---

### Task 9: R-18 Asset Movement

**Files:**
- Create: `backend/app/Queries/Reports/AssetMovementReportQuery.php`
- Modify: `backend/app/Http/Controllers/ReportController.php` (add `assetMovement` method)
- Modify: `backend/routes/api.php` (add route)
- Create: `backend/tests/Feature/Reports/AssetMovementReportTest.php`

**Pattern:** Query `asset_location_histories`, paginated.

---

### Task 10: R-19 Form Results

**Files:**
- Create: `backend/app/Queries/Reports/FormResultsReportQuery.php`
- Modify: `backend/app/Http/Controllers/ReportController.php` (add `formResults` method)
- Modify: `backend/routes/api.php` (add route)
- Create: `backend/tests/Feature/Reports/FormResultsReportTest.php`

**Pattern:** Query `work_order_form_fields`, paginated. Boolean: true/false counts. Numeric: pre/post comparison.

---

### Task 11: R-20 Meter Progression

**Files:**
- Create: `backend/app/Queries/Reports/MeterProgressionReportQuery.php`
- Modify: `backend/app/Http/Controllers/ReportController.php` (add `meterProgression` method)
- Modify: `backend/routes/api.php` (add route)
- Create: `backend/tests/Feature/Reports/MeterProgressionReportTest.php`

**Pattern:** Query `asset_meter_readings` where `confirmed_at` is not null, paginated.

---

### Task 12: R-21 PM Suppression

**Files:**
- Create: `backend/app/Queries/Reports/PmSuppressionReportQuery.php`
- Modify: `backend/app/Http/Controllers/ReportController.php` (add `pmSuppression` method)
- Modify: `backend/routes/api.php` (add route)
- Create: `backend/tests/Feature/Reports/PmSuppressionReportTest.php`

**Pattern:** Query `pm_occurrence_suppressions`, paginated.

---

### Task 13: Update handoff documentation

**Files:**
- Modify: `backend/docs/REPORTS_BACKEND_HANDOFF.md` (add Pass 2 endpoints)

**Step 1: Add Pass 2 endpoint specs to handoff doc**

Add sections for R-3, R-4, R-6, R-9, R-13, R-15, R-16, R-17, R-18, R-19, R-20, R-21 with the same format as Pass 1.

**Step 2: Commit**

```bash
git add backend/docs/REPORTS_BACKEND_HANDOFF.md
git commit -m "docs: update handoff with Pass 2 report endpoints"
```

---

## Execution strategy

Given the scope (12 reports), I'll use **subagent-driven development** to parallelize:

1. **Batch 1 (Tasks 1-3):** R-3, R-4, R-6 — reliability reports, can be done in parallel
2. **Batch 2 (Tasks 4-6):** R-9, R-13, R-15 — PM/workload reports, can be done in parallel
3. **Batch 3 (Tasks 7-9):** R-16, R-17, R-18 — throughput/parts/movement, can be done in parallel
4. **Batch 4 (Tasks 10-12):** R-19, R-20, R-21 — form/meter/suppression, can be done in parallel
5. **Task 13:** Documentation update (sequential, after all reports are done)

Each batch will be dispatched to fresh subagents, then I'll review and commit.
