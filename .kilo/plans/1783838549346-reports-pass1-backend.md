# Reports Pass 1 — Backend Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add read-only, parameterised report endpoints for the 6 Must-tier reports (R-1, R-2, R-7, R-8, R-10A, R-14) under `GET /api/reports/*`, reusing the existing dashboard Query/Resource/test patterns.

**Architecture:** One `ReportController` with one method per report. One Query class per report under `app/Queries/Reports/` (mirrors `app/Queries/Dashboard/`). Item Resources for model-backed lists; plain shaped arrays for pure aggregations. One index-only migration (no structural schema changes). No model changes. Additive only (new files + `routes/api.php` edit + index migration).

**Tech Stack:** Laravel 13 / PHP 8.4 / PHPUnit 12 / Sanctum / PostgreSQL. Cursor pagination (`cursorPaginate`). Enums only (no hardcoded status strings).

**Spec source:** `docs/atms/01-product/REPORTS.md` (✅ Approved). Pass 1 = Must tier per §5.

---

## Decisions (confirmed)

| # | Decision | Resolution |
|---|---|---|
| D1 | R-1 reading-triggered PMs | **Date-triggered only.** Include `PmTriggerType::DATE` + `DATE_OR_READING` (date branch). Exclude `READING`. Projection deferred to Should tier. |
| D2 | Response shape (paginated reports) | `{ summary, data, links, meta }` via `Resource::collection($paginator)->additional(['summary' => ...])->toResponse($request)`. |
| D3 | Aging buckets (R-8, R-14) | `0-7` / `8-30` / `31-90` / `91+` days. Labels are honest: `31-90` includes day 90, so the last bucket is `91+` (= ≥91), not `90+`. Same scheme for both. |
| D4 | CSV export | **Out of Pass 1.** JSON only; CSV in a later pass. |
| D5 | Row scoping | **Org-wide, no per-role row scoping** (matches `DashboardKpiController`). Field-level visibility is NOT automatic for custom resources — see D9. |
| D6 | Indexes | One index-only migration adding composite indexes for the report query patterns (see Task 8). |
| D7 | Age sign convention | Carbon 3 `diffInDays` returns a **signed** value (past date ⇒ negative) even without the `$absolute` flag (verified: `$today->diffInDays($past) === -7`). All age calcs MUST go through `AgingBuckets::daysFrom()` (`abs()`), matching `AssetPmAssignmentResource::dateProgress`. |
| D8 | R-8 summary semantics | `summary.by_bucket` is **facet context**: always all 4 buckets over the set scoped by `location_id`/`pm_rule_id`/`priority`, **independent of the `bucket` row filter**. `summary.total` is that scoped grand total. The `bucket` filter narrows only the paginated rows. (R-14 has no bucket filter, so its summary is straightforwardly the scoped set.) |
| D9 | Field visibility | Custom report resources do NOT inherit role visibility automatically. R-8/R-14 item resources MUST `extends` their base resource (`MaintenanceRequestResource` / `WorkOrderResource`) and call `parent::toArray($request)` so role-gated fields (PM trigger fields, rejection reasons, assignee, email, timestamps, form) are honored. R-1 exposes only non-sensitive operational fields. Field-safety tests required (not just 200 access). |

## Conventions (derived from codebase — follow exactly)

- **Auth:** `Gate::authorize('viewDashboard', User::class)` in every method (spec §1; matches `DashboardKpiController`).
- **No row scoping (D5):** reports are org-wide program views. Do NOT replicate `WorkOrderIndexQuery::applyRoleScoping`.
- **Field visibility (D9):** custom report resources MUST `extends` the base resource and call `parent::toArray($request)` to inherit role gating — it is not automatic. Do not hand-roll a parallel field set.
- **Filters:** inline `$request->validate([...])` per method (matches all non-auth controllers).
- **Pagination:** `cursorPaginate($perPage)`. Validate `per_page` as `nullable|integer|min:1|max:500` (default 25) in the controller and pass it into the query — do NOT read `per_page` raw inside the query. **Always add `id` as a unique tie-breaker** to every paginated `orderBy` to keep cursor traversal deterministic across equal timestamps.
- **Age/diffInDays (D7):** never call `$today->diffInDays($pastDate)` directly — it returns a negative value. Always use `AgingBuckets::daysFrom($today, $pastDate)` (which `abs()`es it).
- **Null vs zero:** `null`/`—` when no basis to compute, `0` only for real zeros.
- **Timezone:** stored UTC; queries use `now()` and date columns directly.
- **Routes:** new `Route::prefix('reports')->group(...)` inside the existing `auth:sanctum` + `EnsureTokenAbilities` group; kebab-case; no route names.

## Response shape split

| Report | Shape | Why |
|---|---|---|
| R-1, R-2, R-7, R-10A | `{ summary, items }` (plain array) | Bounded aggregations / PHP-filtered (R-1 follows `OverduePmQuery` load+filter style). |
| R-8, R-14 | `{ summary, data, links, meta }` (cursor-paginated) | Unbounded lists, SQL-filterable. |

## Report specs

| ID | Endpoint | Default window | Filters | Summary | Items |
|---|---|---|---|---|---|
| R-1 | `GET /reports/upcoming-pm` | forward 30d (`days`) | `days`, `location_id`, `pm_rule_id` | total, by_trigger_type, by_due_week | assignment rows w/ next_due_date, days_until_due, chain_status |
| R-2 | `GET /reports/assets-by-location` | current-state | `category`, `asset_kind`, `operational_status`, `include_inactive` | total_assets (incl. unassigned), total_locations, total_booked | row-per-location + **Unassigned** bucket, w/ by_operational_status + by_asset_kind + booked_count |
| R-7 | `GET /reports/pm-compliance` | backward 90d | `from`, `to`, `group_by`(rule\|asset\|location), `location_id`, `pm_rule_id` | overall compliant/total/percentage | per-dimension compliant/total/percentage |
| R-8 | `GET /reports/overdue-pm` | current-state | `location_id`, `pm_rule_id`, `priority`, `bucket` | total + per-bucket counts | paginated overdue PM MRs w/ days_overdue, bucket |
| R-10A | `GET /reports/asset-status-distribution` | current-state | `asset_kind`, `include_inactive` | total | count per operational_status (4, fill 0) |
| R-14 | `GET /reports/wo-backlog` | current-state | `location_id`, `assigned_to`, `priority`, `status`(open\|in_progress\|both) | total + per-bucket + by_priority | paginated open/in_progress WOs w/ age_days, bucket |

### Key definitions (must match existing production logic)

- **PM eligibility (R-1):** mirror `EvaluatePmRulesJob` exactly — assignment `is_active=true` AND `pmRule.is_active=true` AND `asset.maintenance_status = ENROLLED`. (R-8 is MR-based; MRs are only generated for enrolled assets, so no extra filter needed there.)
- **R-1 next_due_date (reuse `PmDueCalculator` policy):** `last_triggered_date === null` ⇒ immediately due (due-now) ⇒ **excluded** from R-1's forward window (it is not "upcoming"). When set: `next_due = last_triggered_date + interval_days`; include iff `next_due` in `[today, today+days]`. **No `created_at` fallback.**
- **R-1 chain_status (bulk, no N+1):** load pending PM MRs + active WOs for the assignment set in 2 queries (keyed by `asset_id|pm_rule_id`). Map to: `not_yet_generated` / `generated_mr_pending` (MR `PENDING_REVIEW`) / `wo_open` (WO `OPEN`/`IN_PROGRESS`) / `wo_completed` (WO `COMPLETED`, not closed).
- **PM on-time (R-7):** identical to `ProcessPerformanceKpiQuery::pmCompliance()` — date-triggered PM MR (`is_preventive=true`, `triggered_by_date=true`), `trigger_date` in window; on-time = linked WO `status=CLOSED` AND `closed_at->toDateString() <= trigger_date`. Reading-triggered excluded.
- **Overdue PM (R-8):** PM MR (`is_preventive=true`, `triggered_by_date=true`), `trigger_date < today`, `status` not in `[REJECTED, CANCELLED]`, and (no WO OR WO `status != CLOSED`). `days_overdue = today - trigger_date`.
- **WO backlog (R-14):** WO `status` in `[OPEN, IN_PROGRESS]`. `age_days = today - created_at`.
- **R-10A inactive:** always include all 4 `OperationalStatus` values in the distribution. `include_inactive` controls only soft-deactivated (`is_active=false`) assets — default excludes them.
- **R-2 maintenance-lifecycle breakdown:** **omitted in Pass 1** (the sub_status LIH/DBR/Disposed/Scrapped breakdown is Phase 2, deferred with R-10B/R-11). R-2 Pass 1 returns `by_operational_status` + `by_asset_kind` + `booked_count` only.

---

## File inventory

**Create:**
- `app/Http/Controllers/ReportController.php`
- `app/Queries/Reports/AgingBuckets.php` (bucket label + date-bounds helper)
- `app/Queries/Reports/UpcomingPmReportQuery.php`
- `app/Queries/Reports/AssetsByLocationReportQuery.php`
- `app/Queries/Reports/PmComplianceReportQuery.php`
- `app/Queries/Reports/OverduePmReportQuery.php`
- `app/Queries/Reports/OperationalStatusDistributionReportQuery.php`
- `app/Queries/Reports/WorkOrderBacklogReportQuery.php`
- `app/Http/Resources/UpcomingPmItemResource.php`
- `app/Http/Resources/OverduePmReportItemResource.php`
- `app/Http/Resources/WorkOrderBacklogItemResource.php`
- `database/migrations/2026_07_12_xxxxxx_add_report_indexes.php` (index-only)
- `tests/Feature/Reports/{ReportAccessTest,OperationalStatusDistributionReportTest,AssetsByLocationReportTest,UpcomingPmReportTest,PmComplianceReportTest,OverduePmReportTest,WorkOrderBacklogReportTest}.php`

**Modify:**
- `routes/api.php` (add `reports` prefix + 6 GET routes inside the auth group)

**No model edits, no structural schema changes, no dependency changes.**

## Reference files to mimic

- Controller/auth: `app/Http/Controllers/DashboardKpiController.php`, `DashboardController.php`
- Query style (PHP load+filter): `app/Queries/Pm/OverduePmQuery.php`
- Query style (SQL + cursor paginate): `app/Queries/WorkOrders/WorkOrderIndexQuery.php`
- KPI definitions to reuse: `app/Queries/Dashboard/Kpis/ProcessPerformanceKpiQuery.php`
- PM eligibility + due policy: `app/Jobs/EvaluatePmRulesJob.php`, `app/Services/Pm/PmDueCalculator.php`
- Resource (field visibility): `app/Http/Resources/WorkOrderResource.php`, `MaintenanceRequestResource.php`
- Test pattern: `tests/Feature/Dashboard/DashboardKpiTest.php`

---

## Task 1: Scaffold routes, controller, shared helper, access smoke test

**Files:** Create `app/Http/Controllers/ReportController.php`, `app/Queries/Reports/AgingBuckets.php`, `tests/Feature/Reports/ReportAccessTest.php`; modify `routes/api.php`.

**Step 1 — failing access test** (`tests/Feature/Reports/ReportAccessTest.php`): mirror `DashboardKpiTest` setUp (`RefreshDatabase`, `seed(RoleSeeder::class)`, `createUser(RoleCode)`). Cases:
- `test_unauthenticated_request_is_rejected` → 401 on `/api/reports/asset-status-distribution`.
- `test_every_authenticated_role_can_view_reports` → 200 for `ADMINISTRATOR, MAINTENANCE_MANAGER, TECHNICIAN, REQUESTER, LOGISTICS`.

**Step 2 — run, expect FAIL** (route undefined).

**Step 3 — add routes** in `routes/api.php` inside the `auth:sanctum` group after the dashboard routes (line 49):

```php
Route::prefix('reports')->group(function () {
    Route::get('/upcoming-pm', [ReportController::class, 'upcomingPm']);
    Route::get('/assets-by-location', [ReportController::class, 'assetsByLocation']);
    Route::get('/pm-compliance', [ReportController::class, 'pmCompliance']);
    Route::get('/overdue-pm', [ReportController::class, 'overduePm']);
    Route::get('/asset-status-distribution', [ReportController::class, 'assetStatusDistribution']);
    Route::get('/wo-backlog', [ReportController::class, 'woBacklog']);
});
```
Add `use App\Http\Controllers\ReportController;`.

**Step 4 — `ReportController`** with 6 method stubs; each: `Gate::authorize('viewDashboard', User::class);` then `return response()->json(['summary' => [], 'items' => []]);`.

**Step 5 — `AgingBuckets`** helper:

```php
<?php

namespace App\Queries\Reports;

use Carbon\Carbon;

class AgingBuckets
{
    public const BUCKETS = ['0-7', '8-30', '31-90', '91+'];

    /** Elapsed days from $today back to $past, always non-negative.
     *  Carbon 3's diffInDays returns a SIGNED value (past => negative) even
     *  without the $absolute flag (verified: -7 for a 7-day-old date), so
     *  abs() is required. Matches AssetPmAssignmentResource::dateProgress. */
    public static function daysFrom(Carbon $today, Carbon $past): int
    {
        return (int) abs($today->diffInDays($past));
    }

    public static function bucket(int $days): string
    {
        return match (true) {
            $days <= 7 => '0-7',
            $days <= 30 => '8-30',
            $days <= 90 => '31-90',
            default => '91+',
        };
    }

    /** @return array{0: ?Carbon, 1: ?Carbon} [lower, upper] bounds for the aged column (trigger_date). */
    public static function dateBounds(string $bucket, Carbon $today): array
    {
        return match ($bucket) {
            '0-7' => [$today->copy()->subDays(7), $today->copy()->subDay()],
            '8-30' => [$today->copy()->subDays(30), $today->copy()->subDays(8)],
            '31-90' => [$today->copy()->subDays(90), $today->copy()->subDays(31)],
            '91+' => [null, $today->copy()->subDays(91)],
        };
    }
}
```

**Step 6 — run access test, expect PASS.** `php artisan test --compact tests/Feature/Reports/ReportAccessTest.php`

**Step 7 — commit.** `git add -A && git commit -m "feat(reports): scaffold ReportController, routes, aging helper"`

---

## Task 2: R-10A Operational Status Distribution (simplest — validate pattern)

**Files:** Create `app/Queries/Reports/OperationalStatusDistributionReportQuery.php`, `tests/Feature/Reports/OperationalStatusDistributionReportTest.php`; modify `ReportController.php`.

**Step 1 — failing test.** Cases:
- `test_unauthenticated_is_rejected`.
- `test_returns_all_four_statuses_with_zero_for_missing`: 2 active + 1 down → `active=2, under_maintenance=0, down=1, inactive=0`, total=3.
- `test_inactive_operational_status_is_shown_not_hidden`: an asset with `operational_status=inactive` but `is_active=true` appears in the Inactive bucket (default). **Regression for blocker 4.**
- `test_default_excludes_soft_deactivated`: asset `is_active=false` excluded by default; `include_inactive=true` includes it.
- `test_asset_kind_filter_excludes_other_kinds`.
- `test_empty_state`: all four 0, total 0.

**Step 2 — run, expect FAIL.**

**Step 3 — implement query** (exclude only `is_active=false` unless `include_inactive`; keep all 4 statuses):

```php
public function handle(array $filters): array
{
    $query = Asset::query();
    if ($filters['asset_kind'] ?? null) {
        $query->where('asset_kind', $filters['asset_kind']);
    }
    if (! ($filters['include_inactive'] ?? false)) {
        $query->where('is_active', true);
    }

    $rows = (clone $query)->selectRaw('operational_status, count(*) as count')
        ->groupBy('operational_status')->pluck('count', 'operational_status');

    $items = [];
    foreach (OperationalStatus::cases() as $status) {
        $items[] = ['status' => $status->value, 'count' => (int) ($rows[$status->value] ?? 0)];
    }

    return ['summary' => ['total' => (int) $query->count()], 'items' => $items];
}
```

**Step 4 — wire controller** (validate `asset_kind` in enum values, `include_inactive` boolean).

**Step 5 — run, expect PASS.**

**Step 6 — commit.** `git commit -m "feat(reports): R-10A operational status distribution"`

---

## Task 3: R-2 Asset Distribution by Location

**Files:** Create `app/Queries/Reports/AssetsByLocationReportQuery.php`, `tests/Feature/Reports/AssetsByLocationReportTest.php`; modify `ReportController.php`.

**Step 1 — failing test.** Cases:
- `test_groups_assets_by_location`: 2 at Loc-A, 1 at Loc-B → 2 location rows + correct counts.
- `test_unassigned_bucket_for_null_location`: 1 asset with `current_location_id=null` → an `Unassigned` row appears; `total_assets` includes it. **Regression for blocker 5.**
- `test_breaks_down_by_operational_status_and_asset_kind_and_booked`: at one location, 1 active+standalone + 1 down+component + 1 booked → breakdowns correct.
- `test_category_filter_applies`.
- `test_asset_kind_filter_applies`.
- `test_default_excludes_soft_deactivated`; `test_include_inactive`.
- `test_empty_state`.
- `test_no_maintenance_lifecycle_breakdown_in_phase1` (assert items rows do NOT contain `by_maintenance_status`/`sub_status` keys — Phase 2 reconciliation).

**Step 2 — run, expect FAIL.**

**Step 3 — implement query** (portable `SUM(CASE WHEN ...)`; `assets.current_location_id` is nullable → Unassigned bucket; enum `->value` as binding, no hardcoded status strings):

```php
$base = Asset::query()
    ->when($filters['category'] ?? null, fn ($q, $v) => $q->where('category', $v))
    ->when($filters['asset_kind'] ?? null, fn ($q, $v) => $q->where('asset_kind', $v))
    ->when($filters['operational_status'] ?? null, fn ($q, $v) => $q->where('operational_status', $v))
    ->when(! ($filters['include_inactive'] ?? false), fn ($q) => $q->where('is_active', true));

$rows = (clone $base)
    ->selectRaw('current_location_id, count(*) as asset_count')
    ->selectRaw('sum(case when operational_status = ? then 1 else 0 end) as active_count', [OperationalStatus::ACTIVE->value])
    ->selectRaw('sum(case when operational_status = ? then 1 else 0 end) as under_maintenance_count', [OperationalStatus::UNDER_MAINTENANCE->value])
    ->selectRaw('sum(case when operational_status = ? then 1 else 0 end) as down_count', [OperationalStatus::DOWN->value])
    ->selectRaw('sum(case when operational_status = ? then 1 else 0 end) as inactive_count', [OperationalStatus::INACTIVE->value])
    ->selectRaw('sum(case when asset_kind = ? then 1 else 0 end) as standalone_count', [AssetKind::ASSET->value])
    ->selectRaw('sum(case when asset_kind = ? then 1 else 0 end) as package_count', [AssetKind::PACKAGE->value])
    ->selectRaw('sum(case when asset_kind = ? then 1 else 0 end) as component_count', [AssetKind::COMPONENT->value])
    ->selectRaw('sum(case when is_booked = true then 1 else 0 end) as booked_count')
    ->groupBy('current_location_id')->get();

$locationNames = Location::whereIn('id', $rows->pluck('current_location_id')->filter())->pluck('name', 'id');

$items = $rows->map(function ($r) use ($locationNames) {
    $unassigned = $r->current_location_id === null;
    return [
        'location_id' => $r->current_location_id,
        'location_name' => $unassigned ? 'Unassigned' : ($locationNames[$r->current_location_id] ?? null),
        'is_unassigned' => $unassigned,
        'asset_count' => (int) $r->asset_count,
        'by_operational_status' => [
            'active' => (int) $r->active_count,
            'under_maintenance' => (int) $r->under_maintenance_count,
            'down' => (int) $r->down_count,
            'inactive' => (int) $r->inactive_count,
        ],
        'by_asset_kind' => [
            'standalone' => (int) $r->standalone_count,
            'package' => (int) $r->package_count,
            'component' => (int) $r->component_count,
        ],
        'booked_count' => (int) $r->booked_count,
    ];
})->values();

return [
    'summary' => [
        'total_assets' => $items->sum('asset_count'),
        'total_locations' => $items->filter(fn ($i) => ! $i['is_unassigned'])->count(),
        'total_booked' => $items->sum('booked_count'),
    ],
    'items' => $items,
];
```

> `AssetKind` enum values: confirm exact cases (`ASSET`/`PACKAGE`/`COMPONENT`) when implementing — read `app/Enums/AssetKind.php`. Adjust `->value` bindings to match.

**Step 4 — wire controller** (validate `category` string, `asset_kind` in enum, `operational_status` in enum, `include_inactive` boolean).

**Step 5 — run, expect PASS.**

**Step 6 — commit.** `git commit -m "feat(reports): R-2 asset distribution by location"`

---

## Task 4: R-1 Upcoming PM Schedule (date-triggered only, ENROLLED, bulk chain status)

**Files:** Create `app/Queries/Reports/UpcomingPmReportQuery.php`, `app/Http/Resources/UpcomingPmItemResource.php`, `tests/Feature/Reports/UpcomingPmReportTest.php`; modify `ReportController.php`.

**Step 1 — failing test.** Helpers: create `PmRule` (trigger_type=DATE, interval_days=30, is_active=true), `Asset` (maintenance_status=ENROLLED), `AssetPmAssignment` (is_active=true, last_triggered_date=today-20d ⇒ next_due=today+10d). Cases:
- `test_includes_date_triggered_pm_due_within_horizon`.
- `test_excludes_pm_due_outside_horizon` (due +45d, days=30).
- `test_excludes_reading_only_pm` (trigger_type=READING never appears).
- `test_excludes_inactive_assignment_and_rule`.
- `test_excludes_withdrawn_asset` (asset maintenance_status=WITHDRAWN → excluded). **Regression for blocker 1.**
- `test_never_triggered_is_due_now_excluded_from_upcoming` (last_triggered_date=null → not in upcoming). **Regression for blocker 3.**
- `test_excludes_already_overdue` (next_due in past).
- `test_location_filter_applies`; `test_pm_rule_filter_applies`.
- `test_chain_status_labels`: (a) no chain → `not_yet_generated`; (b) pending PM MR → `generated_mr_pending`; (c) WO OPEN → `wo_open`; (d) WO COMPLETED-not-closed → `wo_completed`; (e) WO CLOSED → `not_yet_generated` (closed = done). **Regression for blocker 2.**
- `test_chain_status_no_n_plus_1` (optional: assert query count via `DB::enableQueryLog`).
- `test_summary_counts_by_trigger_type_and_due_week`.
- `test_empty_state`.

**Step 2 — run, expect FAIL.**

**Step 3 — implement query** (eligibility mirrors `EvaluatePmRulesJob`; next_due reuses calculator null-policy; chain status bulk-loaded):

```php
public function handle(int $days, array $filters): array
{
    $today = now()->startOfDay();
    $horizon = $today->copy()->addDays($days);

    $assignments = AssetPmAssignment::where('is_active', true)
        ->whereHas('pmRule', fn ($q) => $q->where('is_active', true)
            ->whereIn('trigger_type', [PmTriggerType::DATE, PmTriggerType::DATE_OR_READING]))
        ->whereHas('asset', fn ($q) => $q->where('maintenance_status', MaintenanceStatus::ENROLLED)) // blocker 1
        ->when($filters['pm_rule_id'] ?? null, fn ($q, $v) => $q->where('pm_rule_id', $v))
        ->when($filters['location_id'] ?? null, fn ($q, $v) =>
            $q->whereHas('asset', fn ($aq) => $aq->where('current_location_id', $v)))
        ->with(['asset.currentLocation', 'pmRule'])
        ->get();

    // Bulk chain status (2 queries total) — blocker 2
    $chainStatus = $this->resolveChainStatuses($assignments);

    $rows = $assignments->map(function (AssetPmAssignment $a) use ($today, $horizon, $chainStatus) {
        if ($a->last_triggered_date === null) {
            return null; // due-now per calculator policy — not upcoming (blocker 3)
        }
        $nextDue = $a->last_triggered_date->copy()->addDays($a->pmRule->interval_days);
        if ($nextDue < $today || $nextDue > $horizon) {
            return null;
        }
        return [
            'assignment' => $a,
            'next_due_date' => $nextDue,
            'days_until_due' => abs((int) $today->diffInDays($nextDue)),
            'chain_status' => $chainStatus["{$a->asset_id}_{$a->pm_rule_id}"] ?? 'not_yet_generated',
        ];
    })->filter()->values();

    return [
        'summary' => [
            'total' => $rows->count(),
            'by_trigger_type' => $rows->countBy(fn ($r) => $r['assignment']->pmRule->trigger_type->value)->toArray(),
            'by_due_week' => $rows->countBy(fn ($r) => $r['next_due_date']->format('o-\WW'))->sortKeys()->toArray(),
        ],
        'items' => $rows,
    ];
}

private function resolveChainStatuses(Collection $assignments): array
{
    if ($assignments->isEmpty()) {
        return [];
    }
    $assetIds = $assignments->pluck('asset_id')->all();
    $ruleIds = $assignments->pluck('pm_rule_id')->unique()->all();

    $pending = MaintenanceRequest::where('is_preventive', true)
        ->where('status', MaintenanceRequestStatus::PENDING_REVIEW)
        ->whereIn('asset_id', $assetIds)->whereIn('pm_rule_id', $ruleIds)
        ->get(['asset_id', 'pm_rule_id'])
        ->keyBy(fn ($m) => "{$m->asset_id}_{$m->pm_rule_id}");

    $activeWos = WorkOrder::whereIn('asset_id', $assetIds)
        ->whereIn('status', [WorkOrderStatus::OPEN, WorkOrderStatus::IN_PROGRESS, WorkOrderStatus::COMPLETED])
        ->whereHas('maintenanceRequest', fn ($q) => $q->where('is_preventive', true)->whereIn('pm_rule_id', $ruleIds))
        ->with('maintenanceRequest:id,pm_rule_id')
        ->get()
        ->keyBy(fn ($w) => "{$w->asset_id}_{$w->maintenanceRequest->pm_rule_id}");

    $map = [];
    foreach ($pending->keys() as $key) {
        $map[$key] = 'generated_mr_pending';
    }
    foreach ($activeWos as $key => $wo) {
        if (isset($map[$key])) {
            continue; // pending MR takes precedence
        }
        $map[$key] = $wo->status === WorkOrderStatus::COMPLETED ? 'wo_completed' : 'wo_open';
    }
    return $map;
}
```

**Step 4 — `UpcomingPmItemResource`** (`$wrap = null`): shape each row — `assignment_id`, `asset` ({id,name,asset_tag,erp_asset_code}), `location` ({id,name}), `pm_rule` ({id,name}), `trigger_type`, `next_due_date` (ISO date), `days_until_due`, `chain_status`.

**Step 5 — wire controller** (validate `days` int 1..365 default 30, `location_id` exists, `pm_rule_id` exists; return `['summary'=>..., 'items' => UpcomingPmItemResource::collection($result['items'])->resolve($request)]`).

**Step 6 — run, expect PASS.**

**Step 7 — commit.** `git commit -m "feat(reports): R-1 upcoming PM schedule (enrolled, bulk chain status)"`

---

## Task 5: R-7 PM Compliance

**Files:** Create `app/Queries/Reports/PmComplianceReportQuery.php`, `tests/Feature/Reports/PmComplianceReportTest.php`; modify `ReportController.php`.

**Step 1 — failing test.** Reuse `DashboardKpiTest::createPmRequest` + `closeWorkOrder` helpers. Cases:
- `test_overall_compliance_and_per_rule_breakdown`: 2 rules; rule-A 1 on-time + 1 late, rule-B 1 on-time → overall 2/3, rule-A 1/2 (50%), rule-B 1/1 (100%).
- `test_group_by_asset`, `test_group_by_location`.
- `test_reading_triggered_excluded_from_denominator`.
- `test_window_filter_excludes_out_of_range_trigger_dates`.
- `test_empty_state` (percentage null, total 0).

**Step 2 — run, expect FAIL.**

**Step 3 — implement query** (reuse the on-time rule from `ProcessPerformanceKpiQuery::pmCompliance`; group in PHP):

```php
$due = MaintenanceRequest::where('is_preventive', true)->where('triggered_by_date', true)
    ->whereBetween('trigger_date', [$from->toDateString(), $to->toDateString()])
    ->when($filters['pm_rule_id'] ?? null, fn ($q, $v) => $q->where('pm_rule_id', $v))
    ->when($filters['location_id'] ?? null, fn ($q, $v) =>
        $q->whereHas('asset', fn ($aq) => $aq->where('current_location_id', $v)))
    ->with(['workOrder', 'pmRule', 'asset.currentLocation'])->get();

$isCompliant = fn (MaintenanceRequest $mr): bool => $mr->workOrder !== null
    && $mr->workOrder->status === WorkOrderStatus::CLOSED
    && $mr->workOrder->closed_at !== null
    && $mr->workOrder->closed_at->toDateString() <= $mr->trigger_date->toDateString();

[$keyResolver, $labelResolver] = $this->groupResolvers($groupBy); // rule→pm_rule_id, asset→asset_id, location→asset.current_location_id

$items = $due->groupBy($keyResolver)->map(function ($mrs, $key) use ($isCompliant, $labelResolver) {
    $total = $mrs->count(); $compliant = $mrs->filter($isCompliant)->count();
    return [
        'group_key' => $key,
        'group_label' => $labelResolver($key, $mrs->first()),
        'compliant' => $compliant, 'total' => $total,
        'percentage' => $total > 0 ? round($compliant / $total * 100, 1) : null,
    ];
    })->values();

$total = $due->count(); $compliant = $due->filter($isCompliant)->count();

return [
    'summary' => ['compliant' => $compliant, 'total' => $total,
        'percentage' => $total > 0 ? round($compliant / $total * 100, 1) : null],
    'items' => $items,
];
```

**Step 4 — wire controller** (validate `from`,`to` date default now-90d/now, `group_by` in: rule,asset,location, `location_id`, `pm_rule_id`).

**Step 5 — run, expect PASS.** **Step 6 — commit.** `git commit -m "feat(reports): R-7 PM compliance by dimension"`

---

## Task 6: R-8 Overdue PM (paginated, bucket filter, deterministic order)

**Files:** Create `app/Queries/Reports/OverduePmReportQuery.php`, `app/Http/Resources/OverduePmReportItemResource.php`, `tests/Feature/Reports/OverduePmReportTest.php`; modify `ReportController.php`.

**Step 1 — failing test.** Cases:
- `test_includes_past_due_pm_with_open_chain`: PM MR trigger_date=today-10d, no WO → included, days_overdue=10, bucket=`8-30`.
- `test_excludes_closed_wo`; `test_excludes_rejected_and_cancelled_mr`; `test_excludes_non_pm_and_reading_triggered`.
- `test_bucket_filter_returns_only_that_bucket` (e.g. `bucket=31-90` returns only 31-90-day-overdue MRs). **Regression for blocker 6.**
- `test_summary_is_facet_context`: with `bucket=31-90` selected, `summary.by_bucket` still shows all 4 buckets' counts and `summary.total` = scoped grand total (not just the 31-90 count). **Regression for issue 4 (D8).**
- `test_paginated_shape_has_data_links_meta`.
- `test_multi_page_traversal_with_duplicate_trigger_dates`: 5 MRs same `trigger_date`, `per_page=2`, walk `next_cursor` until exhausted, assert all 5 returned with no skips/repeats. **Regression for blocker 7.**
- `test_age_is_positive_not_negative`: 10-day-overdue MR → `days_overdue === 10`, `bucket === '8-30'` (NOT `0-7`). **Regression for issue 1 (D7 abs).**
- `test_logistics_cannot_see_pm_trigger_fields`: as Logistics, item payload omits the gated fields `trigger_date`, `triggered_by_date`, `triggered_by_reading`, `trigger_reading_value`, `is_preventive`, `rejection_reason`, `cancellation_reason`, `work_order`, `created_by`, `reviewed_by`, `has_attachments`; but `created_at` IS present (always exposed by the base `MaintenanceRequestResource`). As Admin, the gated fields are present. **Regression for issue 5 (D9).**
- `test_location_and_priority_filters`.
- `test_empty_state`.

**Step 2 — run, expect FAIL.**

**Step 3 — implement query** (the "not closed" filter; bucket filter via `AgingBuckets::dateBounds`; deterministic `orderBy(trigger_date, id)`; summary from the full filtered set):

```php
$today = now()->startOfDay();
// $perPage is validated in the controller (nullable|integer|min:1|max:500, default 25) and passed in.

$base = MaintenanceRequest::where('is_preventive', true)->where('triggered_by_date', true)
    ->where('trigger_date', '<', $today->toDateString())
    ->whereNotIn('status', [MaintenanceRequestStatus::REJECTED, MaintenanceRequestStatus::CANCELLED])
    ->where(function ($q) {
        $q->doesntHave('workOrder')
            ->orWhereHas('workOrder', fn ($wq) => $wq->where('status', '!=', WorkOrderStatus::CLOSED));
    })
    ->when($filters['location_id'] ?? null, fn ($q, $v) =>
        $q->whereHas('asset', fn ($aq) => $aq->where('current_location_id', $v)))
    ->when($filters['pm_rule_id'] ?? null, fn ($q, $v) => $q->where('pm_rule_id', $v))
    ->when($filters['priority'] ?? null, fn ($q, $v) => $q->where('priority', $v));

// Summary: bucket counts over the FULL filtered set (no pagination, no bucket filter).
$summaryRows = (clone $base)->select('trigger_date')->get();
// Summary = FACET CONTEXT (D8): bucket counts over the FULL scoped set (location/priority/pm_rule
// filters applied, but NOT the `bucket` row filter). Always returns all 4 buckets; summary.total
// is the scoped grand total, NOT the filtered-bucket count.
$perBucket = array_fill_keys(AgingBuckets::BUCKETS, 0);
foreach ($summaryRows as $mr) {
    $perBucket[AgingBuckets::bucket(AgingBuckets::daysFrom($today, $mr->trigger_date))]++;
}
$summary = ['total' => $summaryRows->count(), 'by_bucket' => $perBucket];

// Rows: apply optional bucket filter, then paginate with deterministic order.
$rowsQuery = clone $base;
if ($filters['bucket'] ?? null) {
    [$lower, $upper] = AgingBuckets::dateBounds($filters['bucket'], $today);
    $rowsQuery->when($lower, fn ($q, $v) => $q->where('trigger_date', '>=', $v->toDateString()))
        ->when($upper, fn ($q, $v) => $q->where('trigger_date', '<=', $v->toDateString()));
}
$paginator = $rowsQuery->with(['asset.currentLocation', 'pmRule', 'workOrder'])
    ->orderBy('trigger_date')->orderBy('id') // blocker 7
    ->cursorPaginate($perPage);

return ['summary' => $summary, 'paginator' => $paginator];
```

**Step 4 — `OverduePmReportItemResource`** (`extends MaintenanceRequestResource`, D9): override `toArray()` to call `parent::toArray($request)` (inherits role-gated fields — PM trigger fields/rejection/cancellation hidden from Logistics, workOrder per rules), then append `days_overdue` (`AgingBuckets::daysFrom($today, $this->trigger_date)`) and `bucket` (`AgingBuckets::bucket(...)`). Eager-load `asset.currentLocation`, `pmRule`, `workOrder` so the parent's `whenLoaded` fields render.

**Step 5 — wire controller**: validate `location_id`(exists), `pm_rule_id`(exists), `priority`(string), `bucket`(in:`0-7,8-30,31-90,91+`), `per_page`(nullable|integer|min:1|max:500); pass `$perPage` into the query; return `OverduePmReportItemResource::collection($result['paginator'])->additional(['summary' => $result['summary']])->toResponse($request)`.

**Step 6 — run, expect PASS.** **Step 7 — commit.** `git commit -m "feat(reports): R-8 overdue PM (paginated, bucket filter)"`

---

## Task 7: R-14 WO Backlog / Aging (paginated, deterministic order)

**Files:** Create `app/Queries/Reports/WorkOrderBacklogReportQuery.php`, `app/Http/Resources/WorkOrderBacklogItemResource.php`, `tests/Feature/Reports/WorkOrderBacklogReportTest.php`; modify `ReportController.php`.

**Step 1 — failing test.** Cases:
- `test_includes_open_and_in_progress_wos` (created -5d / -15d → age/bucket correct).
- `test_excludes_closed_completed_cancelled`.
- `test_status_filter_open_only` / `in_progress_only`.
- `test_summary_per_bucket_and_by_priority`.
- `test_paginated_shape`.
- `test_multi_page_traversal_with_duplicate_created_at`: 5 WOs same `created_at`, `per_page=2`, walk cursors, assert all 5 with no skips/repeats. **Regression for blocker 7.**
- `test_age_is_positive_not_negative`: WO created 15d ago → `age_days === 15`, `bucket === '8-30'` (NOT `0-7`). **Regression for issue 1 (D7 abs).**
- `test_logistics_cannot_see_wo_assignee_and_gated_timestamps`: as Logistics, item payload omits `assigned_to`, `assigned_by`, `started_at`, `completed_at`, `closed_at`, `cancelled_at`, `completion_notes`, `cancellation_reason`, `parts`, `has_attachments`, `form`, `maintenance_request`; but `created_at` IS present (always exposed by the base `WorkOrderResource`). As Admin, the gated fields are present. **Regression for issue 5 (D9).**
- `test_assigned_to_and_location_filters`.
- `test_empty_state`.

**Step 2 — run, expect FAIL.**

**Step 3 — implement query:**

```php
// $perPage is validated in the controller (nullable|integer|min:1|max:500, default 25) and passed in.
$statuses = match ($filters['status'] ?? 'both') {
    'open' => [WorkOrderStatus::OPEN],
    'in_progress' => [WorkOrderStatus::IN_PROGRESS],
    default => [WorkOrderStatus::OPEN, WorkOrderStatus::IN_PROGRESS],
};

$base = WorkOrder::whereIn('status', $statuses)
    ->when($filters['location_id'] ?? null, fn ($q, $v) =>
        $q->whereHas('asset', fn ($aq) => $aq->where('current_location_id', $v)))
    ->when($filters['assigned_to'] ?? null, fn ($q, $v) => $q->where('assigned_to_user_id', $v))
    ->when($filters['priority'] ?? null, fn ($q, $v) => $q->where('priority', $v));

$today = now()->startOfDay();
$summaryRows = (clone $base)->select('created_at', 'priority')->get();
$perBucket = array_fill_keys(AgingBuckets::BUCKETS, 0);
$byPriority = [];
foreach ($summaryRows as $wo) {
    $perBucket[AgingBuckets::bucket(AgingBuckets::daysFrom($today, $wo->created_at))]++;
    $byPriority[$wo->priority] = ($byPriority[$wo->priority] ?? 0) + 1;
}
$summary = ['total' => $summaryRows->count(), 'by_bucket' => $perBucket, 'by_priority' => $byPriority];

$paginator = (clone $base)->with(['asset.currentLocation', 'assignedTo', 'maintenanceRequest'])
    ->orderBy('created_at')->orderBy('id') // blocker 7
    ->cursorPaginate($perPage);

return ['summary' => $summary, 'paginator' => $paginator];
```

**Step 4 — `WorkOrderBacklogItemResource`** (`extends WorkOrderResource`, D9): override `toArray()` to call `parent::toArray($request)` (inherits role-gated assignee/email/timestamps/form visibility), then append `age_days` (`AgingBuckets::daysFrom($today, $this->created_at)`) and `bucket`. Eager-load `asset.currentLocation`, `assignedTo`, `maintenanceRequest`.

**Step 5 — wire controller**: validate `location_id`(exists), `assigned_to`(exists:users), `priority`(string), `status`(in:open,in_progress,both), `per_page`(nullable|integer|min:1|max:500); pass `$perPage` into the query; return `WorkOrderBacklogItemResource::collection($result['paginator'])->additional(['summary' => $result['summary']])->toResponse($request)`.

**Step 6 — run, expect PASS.** **Step 7 — commit.** `git commit -m "feat(reports): R-14 WO backlog (paginated, aging buckets)"`

---

## Task 8: Index migration (index-only)

**Files:** Create `database/migrations/2026_07_12_xxxxxx_add_report_indexes.php`.

**Context (current index audit):**
- `maintenance_requests`: only `number` unique + `asset_id` FK. **No** index on `is_preventive`/`triggered_by_date`/`trigger_date`/`status`/`pm_rule_id`.
- `work_orders`: only `number`/`maintenance_request_id`/`asset_id`. **No** index on `status`/`created_at`.
- `assets`: `maintenance_status` + `parent_asset_id` + `erp_asset_code`/`asset_tag` unique. **No** index on `current_location_id`/`operational_status`.
- `asset_pm_assignments`: `(asset_id, pm_rule_id)` unique only. **No** index for the `is_active` scan.

**Step 1 — create migration** adding composite indexes for the report query patterns (Postgres; `down()` drops them):

```php
Schema::table('maintenance_requests', function (Blueprint $t) {
    $t->index(['is_preventive', 'triggered_by_date', 'trigger_date'], 'mr_pm_due_index'); // R-7, R-8
});
Schema::table('work_orders', function (Blueprint $t) {
    $t->index(['status', 'created_at'], 'wo_status_created_index'); // R-14
});
Schema::table('assets', function (Blueprint $t) {
    $t->index('current_location_id', 'assets_location_index'); // R-2 groupBy
});
Schema::table('asset_pm_assignments', function (Blueprint $t) {
    $t->index(['is_active', 'pm_rule_id'], 'apa_active_rule_index'); // R-1
});
```

> Confirm no duplicate index names exist before running. `operational_status` is low-cardinality — intentionally not indexed (the planner can seq-scan efficiently); revisit if R-10A/R-2 show slow plans at scale.

**Step 2 — migrate + test:** `php artisan migrate --no-interaction` then `php artisan test --compact tests/Feature/Reports`.

**Step 3 — commit.** `git commit -m "perf(reports): add indexes for report query patterns"`

---

## Task 9: Finalize — pint + full suite

**Step 1:** `vendor/bin/pint --dirty --format agent`
**Step 2:** `php artisan test --compact tests/Feature/Reports`
**Step 3:** `php artisan test --compact tests/Feature/Dashboard` (regression guard — `routes/api.php` edited)
**Step 4:** offer full suite: `php artisan test --compact`
**Step 5:** `php artisan route:list --path=api/reports` (expect 6 GET routes)
**Step 6:** commit pint fixes if any.

---

## Risks & mitigations

| Risk | Mitigation |
|---|---|
| R-8/R-14 summary (full set) vs rows (paginated) computed separately → drift if data changes mid-request | Acceptable for read-only reports; wrap report read in `DB::transaction` if strict consistency required. |
| R-1 loads all eligible assignments in PHP (mirrors `OverduePmQuery`) | Accepted pattern; Task 8 indexes the `is_active` scan. Note as future SQL-projection work if scale demands. |
| Never-triggered assignments (null `last_triggered_date`) are due-now but appear in neither R-1 (forward) nor R-8 (MR-based) | By design: they are surfaced by the dashboard's `OverduePmQuery` widget, not the report module. Documented as a known gap. |
| `SUM(CASE WHEN ...)` raw SQL in R-2 | Portable PG/MySQL; enum values passed as bindings, not hardcoded. |
| Aging bucket off-by-one at boundaries (7/8, 30/31, 90/91) | `AgingBuckets::bucket` + `dateBounds` share one definition; labels honest (`91+` = ≥91); tests cover 7,8,30,31,90,91. |
| Negative age from `diffInDays` (Carbon 3 returns signed) | All age calcs via `AgingBuckets::daysFrom()` (`abs()`); `test_age_is_positive_not_negative` in R-8 & R-14. |
| Cursor order non-determinism | `orderBy(...)->orderBy('id')` on every paginated query + multi-page traversal tests with duplicate timestamps. |
| Field-level visibility leaks to unauthorized roles | R-8/R-14 item resources `extends` base resource + `parent::toArray()` (D9); field-safety tests assert Logistics can't see PM trigger fields / WO assignee. A 200-access test alone is insufficient. |
| Index migration on large tables blocks writes | Composite indexes only; schedule during low-traffic window in production (note in rollout). |

## Out of scope (explicit)

- CSV export (D4) — later pass.
- R-1 reading-triggered usage-rate projection (D1) — Should tier.
- Reports R-3, R-4, R-6, R-9, R-13, R-15–R-21 — Pass 2/3.
- R-5, R-10B, R-11, R-12 — Phase 2 (deferred in spec).
- Per-role row scoping (D5) — reports are org-wide.
- R-2 maintenance-lifecycle (sub_status) breakdown — Phase 2 (with R-10B).
- Frontend changes (backend-only).

## Validation plan

- Every report has a feature test covering: unauthenticated (401), all 5 roles (200), response structure, happy-path counts, each filter, exclusion rules, empty state.
- Regression tests for each blocker: R-1 (withdrawn exclusion, null-date exclusion, 4 chain labels, no N+1), R-10A (inactive shown), R-2 (unassigned bucket, no lifecycle breakdown), R-8 (bucket filter, multi-page traversal, facet-context summary, positive age, Logistics field-safety), R-14 (multi-page traversal, positive age, Logistics field-safety).
- Field-safety tests (D9): assert role-gated fields are hidden from unauthorized roles — NOT just a 200 access check.
- TDD: test written and confirmed failing before each implementation.
- `php artisan route:list --path=api/reports` confirms 6 routes.
- Pint formatted; dashboard tests green (regression guard on `routes/api.php`).
- Index migration applies cleanly and report tests pass against the indexed tables.
