# Task 14 Design: Role-Scoped Read APIs, Dashboard & Maintenance History

Date: 2026-06-09

## Overview

Build role-scoped read APIs with API Resources that conditionally expose fields per role, cursor-paginated query classes with filtering and sorting, a role-adaptive dashboard endpoint, and a derived maintenance history view. Refactor all existing controllers to use these Resources and Queries.

## 1. Pagination Strategy

**Cursor pagination** (`cursorPaginate()`) for all operational lists — default 25, max 100.

**Cursor-paginated:**
- `GET /assets` — AssetIndexQuery
- `GET /work-orders` — WorkOrderIndexQuery
- `GET /maintenance-requests` — MaintenanceRequestIndexQuery
- `GET /pm-rules` — PmRuleIndexQuery
- `GET /parts` — PartIndexQuery
- `GET /assets/{asset}/maintenance-history` — BuildAssetMaintenanceHistory
- `GET /admin/employees` — EmployeeIndexQuery (employees can grow)

**Unpaginated (small fixed lists):**
- `GET /roles` — 6 roles
- `GET /admin/locations` — limited set
- `GET /admin/master-data/{groupKey}` — limited set
- `GET /admin/usage-reading-types` — limited set

## 2. Query Classes

### Structure

```
app/Queries/
  Assets/AssetIndexQuery.php
  WorkOrders/WorkOrderIndexQuery.php
  MaintenanceRequests/MaintenanceRequestIndexQuery.php
  PmRules/PmRuleIndexQuery.php
  Parts/PartIndexQuery.php
  MaintenanceHistory/BuildAssetMaintenanceHistory.php
  Employees/EmployeeIndexQuery.php
```

Each query class accepts a `Request`, applies role-based scoping, filters, allowlisted sorting, and returns a cursor-paginated builder.

### Filters

| Query | Filters |
|-------|---------|
| `AssetIndexQuery` | `search` (name/code), `is_active`, `operational_status`, `category`, `location_id` |
| `WorkOrderIndexQuery` | `status`, `assigned_to`, `asset_id`, `priority`, `from`, `to` |
| `MaintenanceRequestIndexQuery` | `status`, `asset_id`, `priority`, `type`, `created_by` |
| `PmRuleIndexQuery` | `is_active`, `asset_id`, `trigger_type` |
| `PartIndexQuery` | `search` (name/code) |
| `EmployeeIndexQuery` | `search` (name/emp_id) |

### Sorting

Allowlisted `sort` param: `?sort=created_at:desc`. Invalid sort fields silently fall back to default.

| Query | Allowed sort fields | Default |
|-------|---------------------|---------|
| `AssetIndexQuery` | `name`, `erp_asset_code`, `category`, `operational_status`, `created_at` | `created_at:desc` |
| `WorkOrderIndexQuery` | `created_at`, `priority`, `status`, `started_at`, `closed_at` | `created_at:desc` |
| `MaintenanceRequestIndexQuery` | `created_at`, `priority`, `status` | `created_at:desc` |
| `PmRuleIndexQuery` | `name`, `created_at`, `is_active` | `created_at:desc` |
| `PartIndexQuery` | `name`, `erp_part_code` | `name:asc` |
| `EmployeeIndexQuery` | `name`, `emp_id` | `name:asc` |

### Role-Based Query Scoping

Applied at the query level (not just resource), ensuring rows are filtered before reaching the response:

- **Requester**: `MaintenanceRequestIndexQuery` auto-filters `created_by = auth()->id()`.
- **Technician**: `WorkOrderIndexQuery` auto-filters `assigned_to_user_id = auth()->id()`.
- **Non-admin/non-manager**: `AssetIndexQuery` auto-filters `is_active = true`.
- **Logistics**: `PartIndexQuery` returns empty (no access to parts per policy).
- **Viewer/Requester/Technician/Logistics**: `EmployeeIndexQuery` returns empty (admin-only access).

## 3. API Resources — Role Visibility Matrices

Resources receive the authenticated user and conditionally include/exclude fields.

### AssetResource

| Field | ADMIN | MGR | TECH | LOGISTICS | REQUESTER | VIEWER |
|-------|-------|-----|------|-----------|-----------|--------|
| `id`, `erp_asset_code`, `name`, `description`, `category`, `serial_number`, `model`, `manufacturer` | Yes | Yes | Yes | Yes | Yes | Yes |
| `operational_status` | Yes | Yes | Yes | Yes | Yes | Yes |
| `current_location` (name only) | Yes | Yes | Yes | Yes | Yes | Yes |
| `is_active` | Yes | Yes | No | No | No | No |
| `erp_status`, `erp_last_synced_at` | Yes | Yes | No | No | No | No |
| `erp_raw_data` | Yes | No | No | No | No | No |

### WorkOrderResource

| Field | ADMIN | MGR | TECH | LOGISTICS | REQUESTER | VIEWER |
|-------|-------|-----|------|-----------|-----------|--------|
| `id`, `number`, `status`, `priority`, `description`, `created_at` | Yes | Yes | Yes | Yes | Yes | Yes |
| `asset` (basic: id, name, code) | Yes | Yes | Yes | Yes | Yes | Yes |
| `assigned_to` (id, name) | Yes | Yes | Yes | No | No | Yes |
| `assigned_to` (email) | Yes | Yes | No | No | No | No |
| `assigned_by` | Yes | Yes | No | No | No | No |
| `parts` | Yes | Yes | Yes | No | No | Yes |
| `completion_notes`, `completed_at`, `completed_by` | Yes | Yes | Yes | No | No | Yes |
| `cancellation_reason`, `cancelled_at`, `cancelled_by` | Yes | Yes | Yes | No | No | Yes |
| `started_at`, `closed_at` | Yes | Yes | Yes | No | No | Yes |
| `attachments` | Yes | Yes | Yes | No | No | No |

### MaintenanceRequestResource

| Field | ADMIN | MGR | TECH | LOGISTICS | REQUESTER | VIEWER |
|-------|-------|-----|------|-----------|-----------|--------|
| `id`, `number`, `type`, `status`, `priority`, `description`, `created_at` | Yes | Yes | Yes | Yes | Yes | Yes |
| `asset` (basic: id, name, code) | Yes | Yes | Yes | Yes | Yes | Yes |
| `created_by` (id, name) | Yes | Yes | Yes | No | Yes (own only) | Yes |
| `created_by` (email) | Yes | Yes | No | No | No | No |
| `reviewed_by` | Yes | Yes | No | No | No | Yes |
| `rejection_reason`, `reviewed_at` | Yes | Yes | Yes | No | Yes (own only) | Yes |
| `cancellation_reason`, `cancelled_at` | Yes | Yes | Yes | No | Yes (own only) | Yes |
| `is_preventive`, PM trigger fields | Yes | Yes | No | No | No | Yes |
| `work_order` (summary) | Yes | Yes | Yes | No | Yes (own only) | Yes |
| `attachments` | Yes | Yes | Yes | No | Yes (own only) | No |

### PmRuleResource

| Field | ADMIN | MGR | TECH | LOGISTICS | REQUESTER | VIEWER |
|-------|-------|-----|------|-----------|-----------|--------|
| `id`, `name`, `description`, `trigger_type`, `is_active` | Yes | Yes | Yes | No | No | Yes |
| `interval_days`, `interval_reading` | Yes | Yes | Yes | No | No | Yes |
| `asset` (basic) | Yes | Yes | Yes | No | No | Yes |
| `last_triggered_date`, `last_triggered_reading` | Yes | Yes | Yes | No | No | Yes |
| `created_by`, `deactivated_by`, `reactivated_by` | Yes | Yes | No | No | No | No |

### PartResource

| Field | ADMIN | MGR | TECH | LOGISTICS | REQUESTER | VIEWER |
|-------|-------|-----|------|-----------|-----------|--------|
| `id`, `erp_part_code`, `name`, `description`, `unit_of_measure` | Yes | Yes | Yes | No | No | Yes |
| `category` | Yes | Yes | Yes | No | No | Yes |
| `erp_status`, `erp_last_synced_at` | Yes | Yes | No | No | No | No |
| `erp_raw_data` | Yes | No | No | No | No | No |

Logistics, Requester, Viewer do not see parts. Logistics has no parts access at query level.

### AttachmentResource

| Field | ADMIN | MGR | TECH | LOGISTICS | REQUESTER | VIEWER |
|-------|-------|-----|------|-----------|-----------|--------|
| `id`, `file_name`, `mime_type`, `size_bytes`, `created_at` | Yes | Yes | Yes | Yes | Yes | Yes |
| `download_url` | Yes | Yes | Yes | Yes | Yes (own parent only) | No |
| `uploaded_by` (name) | Yes | Yes | Yes | No | No | No |

- Viewer sees metadata only if they can view the parent entity (checked via parent policy).
- Requester sees attachments only on their own MRs.

## 4. Dashboard API — Role-Adaptive

**Endpoint:** `GET /api/dashboard` (requires `auth:sanctum`)

Returns only widgets relevant to the authenticated role. Missing widgets are omitted entirely.

| Widget | ADMIN | MGR | TECH | LOGISTICS | REQUESTER | VIEWER |
|--------|-------|-----|------|-----------|-----------|--------|
| `pending_maintenance_requests` | Yes (all) | Yes (all) | No | No | Yes (own) | Yes (all) |
| `open_work_orders` | Yes (all) | Yes (all) | Yes (assigned) | No | No | Yes (all) |
| `overdue_pm_rules` | Yes | Yes | No | No | No | Yes |
| `recently_closed_work_orders` | Yes | Yes | No | No | No | Yes |

```json
{
  "summary": {
    "pending_maintenance_requests": 5,
    "open_work_orders": 12,
    "overdue_pm_rules": 2,
    "recently_closed_work_orders": 8
  },
  "pending_maintenance_requests": [],
  "open_work_orders": [],
  "overdue_pm_rules": [],
  "recently_closed_work_orders": []
}
```

### Overdue PM Rules

Uses `PmDueCalculator::isDue()` behind a dedicated service/query method. This respects date AND reading triggers plus suppressions. Capped to top 5 results. The iteration happens inside a query method (not inline in controller) so it can be optimized later (e.g., with a cached flag or materialized view).

```php
class OverduePmQuery
{
    public function __construct(private PmDueCalculator $calculator) {}

    public function execute(int $limit = 5): Collection
    {
        return PmRule::where('is_active', true)
            ->with('asset')
            ->get()
            ->filter(fn ($rule) => $this->calculator->isDue($rule))
            ->take($limit);
    }
}
```

### Recently Closed Work Orders

`WorkOrder` where `status = CLOSED`, `closed_at >= now() - 30 days`, ordered `closed_at DESC`, capped to top 5.

### Technician's Open WOs

Filtered to `assigned_to_user_id = auth()->id()`, statuses `OPEN` and `IN_PROGRESS`.

### Requester's Pending MRs

Filtered to `created_by = auth()->id()`, status `PENDING_REVIEW`.

## 5. Maintenance History — Closed WOs Only

**Endpoint:** `GET /api/assets/{asset}/maintenance-history`

Uses `BuildAssetMaintenanceHistory` query class. Derived on-the-fly. Cursor-paginated (default 25, max 100).

```json
{
  "data": [
    {
      "date": "2026-06-01",
      "type": "corrective",
      "work_order_number": "WO-0042",
      "maintenance_request_number": "MR-0031",
      "description": "...",
      "priority": "high",
      "completed_by": { "id": 3, "name": "..." },
      "parts_used": [{ "part_name": "...", "quantity": 2 }],
      "closed_at": "2026-06-01T14:30:00Z"
    }
  ]
}
```

- **Only `CLOSED` work orders** — `COMPLETED` is excluded (not final until Manager/Admin closes).
- Joins `work_orders` with `maintenance_requests` on `maintenance_request_id`, where `work_orders.asset_id = ?` and `work_orders.status = CLOSED`.
- Sorted `closed_at DESC`.

### Access Control

| Role | Access |
|------|--------|
| ADMIN, MGR, TECH, VIEWER | Full asset maintenance history |
| LOGISTICS | No access (not relevant to logistics role) |
| REQUESTER | Only history entries related to their own maintenance requests (`maintenance_requests.created_by = auth()->id()`) |

## 6. Controllers

| Controller | Action |
|------------|--------|
| `AssetController` | Modify `index()` → AssetIndexQuery + AssetResource + cursor pagination. Modify `show()` → AssetResource. Add `maintenanceHistory()` → BuildAssetMaintenanceHistory + MaintenanceHistoryResource. |
| `WorkOrderController` | Modify `index()` → WorkOrderIndexQuery + WorkOrderResource + cursor pagination. Modify `show()` → WorkOrderResource. |
| `MaintenanceRequestController` | Modify `index()` → MaintenanceRequestIndexQuery + MaintenanceRequestResource + cursor pagination. Modify `show()` → MaintenanceRequestResource. |
| `PmRuleController` | Modify `index()` → PmRuleIndexQuery + PmRuleResource + cursor pagination. Modify `show()` → PmRuleResource. |
| `PartController` | Create new. `index()` → PartIndexQuery + PartResource + cursor pagination. |
| `DashboardController` | Create new. Single `index()` method. |
| `AttachmentController` | Wrap responses in AttachmentResource. |
| `EmployeeController` | Modify `index()` → EmployeeIndexQuery + cursor pagination. |

## 7. Complete File List

### New Files

```
app/Queries/Assets/AssetIndexQuery.php
app/Queries/WorkOrders/WorkOrderIndexQuery.php
app/Queries/MaintenanceRequests/MaintenanceRequestIndexQuery.php
app/Queries/PmRules/PmRuleIndexQuery.php
app/Queries/Parts/PartIndexQuery.php
app/Queries/MaintenanceHistory/BuildAssetMaintenanceHistory.php
app/Queries/Employees/EmployeeIndexQuery.php
app/Queries/Pm/OverduePmQuery.php
app/Http/Resources/AssetResource.php
app/Http/Resources/AssetMeterReadingResource.php
app/Http/Resources/AssetLocationHistoryResource.php
app/Http/Resources/MaintenanceRequestResource.php
app/Http/Resources/WorkOrderResource.php
app/Http/Resources/WorkOrderPartResource.php
app/Http/Resources/PmRuleResource.php
app/Http/Resources/PartResource.php
app/Http/Resources/AttachmentResource.php
app/Http/Resources/DashboardResource.php
app/Http/Resources/MaintenanceHistoryResource.php
app/Http/Controllers/DashboardController.php
app/Http/Controllers/PartController.php
tests/Feature/ReadModels/AssetResourceTest.php
tests/Feature/ReadModels/WorkOrderResourceTest.php
tests/Feature/ReadModels/MaintenanceRequestResourceTest.php
tests/Feature/ReadModels/PmRuleResourceTest.php
tests/Feature/ReadModels/PartResourceTest.php
tests/Feature/ReadModels/AttachmentResourceTest.php
tests/Feature/Dashboard/DashboardTest.php
tests/Feature/Dashboard/MaintenanceHistoryTest.php
```

### Modified Files

```
app/Http/Controllers/AssetController.php
app/Http/Controllers/WorkOrderController.php
app/Http/Controllers/MaintenanceRequestController.php
app/Http/Controllers/PmRuleController.php
app/Http/Controllers/PartController.php
app/Http/Controllers/AttachmentController.php
app/Http/Controllers/Admin/EmployeeController.php
routes/api.php
```
