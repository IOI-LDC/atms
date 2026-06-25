# WO Detail Page — Backend Prerequisites

Two small backend changes required before the Work Order detail page (frontend) can ship.

---

## Task 1 — Broaden GET /api/admin/users to Maintenance Manager

**Why:** The WO detail page has a technician picker that calls `GET /api/admin/users`. Today
`UserPolicy::viewAny` returns Admin-only, so a Maintenance Manager assigning a WO gets 403.
Managers already have implicit user access via the assign-WO capability (RBAC line 48).

### Files

| File | Change |
|---|---|
| `backend/app/Policies/UserPolicy.php` | `viewAny` — add `\|\| $user->hasRole(RoleCode::MAINTENANCE_MANAGER)` |
| `backend/tests/Feature/Admin/UserManagementTest.php` | Refactor `test_non_administrator_cannot_list_users` to assert 200 for Manager, 403 for all other non-admin roles |

### Policy change (UserPolicy::viewAny)

```php
public function viewAny(User $user): bool
{
    return $user->hasRole(RoleCode::ADMINISTRATOR)
        || $user->hasRole(RoleCode::MAINTENANCE_MANAGER);
}
```

### Test refactor

Current test uses a VIEWER role to assert 403 on `GET /api/admin/users`. After the change,
Viewer, Technician, Logistics, and Requester still get 403. Only Manager and Admin get 200.

Refactor with a `@dataProvider` covering all non-admin roles, asserting 403 for all except
the Manager role (which gets 200).

### Acceptance

- Manager `GET /api/admin/users` → 200 (list of users with role)
- Technician/Logistics/Requester/Viewer → 403 (unchanged)
- `view`, `manage`, `update` remain Admin-only (unchanged)
- All other user-management mutations still Admin-only (unchanged)

---

## Task 2 — WO-scoped endpoint to set asset operational status

**Why:** WORKFLOWS.md step 9 says the technician updates the asset status during WO execution
(e.g., "Under Maintenance" when starting, "Active"/"Down" on completion). But `AssetPolicy::update`
is Admin/Manager-only, so the assigned technician cannot set `operational_status` via `PATCH /assets/{id}`.
Rather than loosen the general asset-edit gate, add a WO-scoped action that permits only the assigned
technician (and Admin/Manager) to change only `operational_status`, only while the WO is non-terminal.

### Files

| File | Change |
|---|---|
| `backend/app/Policies/WorkOrderPolicy.php` | New method `setAssetStatus` — Admin, Manager, or assigned Technician |
| `backend/app/Http/Controllers/WorkOrderController.php` | New method `setAssetStatus` — validate, guard, update, audit, respond |
| `backend/routes/api.php` | New route `POST /api/work-orders/{workOrder}/asset-status` |
| `backend/tests/Feature/WorkOrders/WorkOrderAssetStatusTest.php` | New test file |

### Policy (WorkOrderPolicy::setAssetStatus)

No terminal guard here — that lives in the controller for a 409 response.

```php
public function setAssetStatus(User $user, WorkOrder $workOrder): bool
{
    if ($user->hasRole(RoleCode::ADMINISTRATOR) || $user->hasRole(RoleCode::MAINTENANCE_MANAGER)) {
        return true;
    }

    if ($user->hasRole(RoleCode::TECHNICIAN)) {
        return $workOrder->assigned_to_user_id === $user->id;
    }

    return false;
}
```

### Controller (WorkOrderController::setAssetStatus)

Inline logic (no Action class — single-column update). Imports needed:
`App\Enums\WorkOrderStatus`, `App\Http\Resources\AssetResource`, `App\Services\Audit\AuditLogger`.

```php
public function setAssetStatus(Request $request, WorkOrder $workOrder): JsonResponse
{
    Gate::authorize('setAssetStatus', $workOrder);

    $validated = $request->validate([
        'operational_status' => ['required', 'string', 'in:active,under_maintenance,down,inactive'],
    ]);

    if (in_array($workOrder->status, [WorkOrderStatus::CLOSED, WorkOrderStatus::CANCELLED], true)) {
        return response()->json([
            'message' => 'Cannot update asset status on a closed or cancelled work order.',
        ], 409);
    }

    $asset = $workOrder->asset;

    if (! $asset) {
        return response()->json([
            'message' => 'Work order has no associated asset.',
        ], 422);
    }

    $logger = app(AuditLogger::class);
    $before = $asset->toArray();
    $asset->update(['operational_status' => $validated['operational_status']]);
    $after = $asset->fresh()->toArray();
    $logger->log('asset.status_updated', $asset, $before, $after, [
        'work_order_id' => $workOrder->id,
    ]);

    $resource = new AssetResource($asset->fresh()->load('currentLocation'));

    return response()->json([
        'message' => 'Asset status updated.',
        'data' => $resource->toArray($request),
    ]);
}
```

### Route

Add inside `Route::middleware('auth:sanctum')` group, alongside the other work-order routes:

```php
Route::post('/work-orders/{workOrder}/asset-status', [WorkOrderController::class, 'setAssetStatus']);
```

### Integration note

Update `docs/03-backend/RBAC.md` to add a row for this permission: "Set asset operational status via WO" — Administrator, Maintenance Manager, Technician (assigned only).

### Tests (WorkOrderAssetStatusTest)

Cover the following scenarios:

| Scenario | Expected |
|---|---|
| Assigned technician on open WO sets status to `under_maintenance` | 200, asset status changed |
| Assigned technician on in_progress WO sets status to `down` | 200 |
| Unassigned technician on any WO | 403 |
| Technician assigned to a different WO | 403 |
| Maintenance Manager on any WO | 200 |
| Administrator on any WO | 200 |
| Assigned technician on closed WO | 409 |
| Assigned technician on cancelled WO | 409 |
| Request `operational_status` is invalid (e.g., `broken`) | 422 |
| Request `operational_status` is missing | 422 |
| Audit log entry created with `asset.status_updated` event and `work_order_id` in metadata | verified |

### Acceptance

- Assigned Technician on open/in_progress/completed WO → 200, `operational_status` changes, audit row written
- Unassigned Technician → 403
- Technician assigned to a different WO → 403
- Manager/Admin → 200
- Closed or cancelled WO → 409
- Invalid status value → 422
- No other asset fields are writable via this endpoint

---

## Execution order

1. Task 1 — `UserPolicy` + test update
2. Task 2 — `WorkOrderPolicy` + `WorkOrderController` + route + test
3. Update `docs/03-backend/RBAC.md` for both changes
4. Run `php artisan test --filter="UserManagement|WorkOrder"` to verify
