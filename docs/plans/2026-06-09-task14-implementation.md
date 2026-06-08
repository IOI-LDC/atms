# Task 14: Role-Scoped Read APIs, Dashboard & Maintenance History — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Wrap all read endpoints in role-scoped API Resources, add cursor-paginated query classes with filtering and sorting, build a role-adaptive dashboard, and add a derived maintenance history endpoint.

**Architecture:** Query classes handle role-based row scoping, filtering, sorting, and cursor pagination. API Resources handle field-level visibility per role. Dashboard is a single role-adaptive endpoint. Maintenance history is derived from closed work orders only.

**Tech Stack:** Laravel 13, PostgreSQL, Eloquent cursor pagination, PmDueCalculator

---

### Task 1: Create AssetResource with role-scoped fields

**Files:**
- Create: `backend/app/Http/Resources/AssetResource.php`
- Test: `backend/tests/Feature/ReadModels/AssetResourceTest.php`

**Step 1: Write failing tests**

Create `backend/tests/Feature/ReadModels/AssetResourceTest.php`:

```php
<?php

namespace Tests\Feature\ReadModels;

use App\Enums\RoleCode;
use App\Models\Asset;
use App\Models\Location;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssetResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function createUser(RoleCode $roleCode): User
    {
        $role = Role::where('code', $roleCode->value)->firstOrFail();
        return User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
    }

    private function createAsset(): Asset
    {
        $location = Location::create(['name' => 'Test Location', 'type' => 'building']);
        return Asset::create([
            'erp_asset_id' => 'ERP-001',
            'erp_asset_code' => 'A-001',
            'name' => 'Test Asset',
            'description' => 'A test asset',
            'category' => 'HVAC',
            'serial_number' => 'SN-001',
            'model' => 'Model-X',
            'manufacturer' => 'Mfg-Co',
            'current_location_id' => $location->id,
            'operational_status' => 'operational',
            'erp_status' => 'active',
            'erp_raw_data' => ['internal' => 'data'],
            'erp_last_synced_at' => now(),
            'is_active' => true,
        ]);
    }

    public function test_admin_sees_all_asset_fields(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $asset = $this->createAsset();

        $response = $this->actingAs($admin)->getJson('/api/assets');

        $response->assertStatus(200);
        $data = $response->json('data.0');
        $this->assertArrayHasKey('erp_raw_data', $data);
        $this->assertArrayHasKey('erp_status', $data);
        $this->assertArrayHasKey('erp_last_synced_at', $data);
        $this->assertArrayHasKey('is_active', $data);
        $this->assertArrayHasKey('serial_number', $data);
    }

    public function test_manager_sees_erp_status_but_not_raw_data(): void
    {
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $asset = $this->createAsset();

        $response = $this->actingAs($manager)->getJson('/api/assets');

        $response->assertStatus(200);
        $data = $response->json('data.0');
        $this->assertArrayNotHasKey('erp_raw_data', $data);
        $this->assertArrayHasKey('erp_status', $data);
        $this->assertArrayHasKey('is_active', $data);
    }

    public function test_technician_sees_basic_fields_only(): void
    {
        $tech = $this->createUser(RoleCode::TECHNICIAN);
        $asset = $this->createAsset();

        $response = $this->actingAs($tech)->getJson('/api/assets');

        $response->assertStatus(200);
        $data = $response->json('data.0');
        $this->assertArrayNotHasKey('erp_raw_data', $data);
        $this->assertArrayNotHasKey('erp_status', $data);
        $this->assertArrayNotHasKey('erp_last_synced_at', $data);
        $this->assertArrayNotHasKey('is_active', $data);
        $this->assertArrayHasKey('name', $data);
        $this->assertArrayHasKey('erp_asset_code', $data);
        $this->assertArrayHasKey('operational_status', $data);
    }

    public function test_logistics_sees_basic_fields_only(): void
    {
        $logistics = $this->createUser(RoleCode::LOGISTICS);
        $asset = $this->createAsset();

        $response = $this->actingAs($logistics)->getJson('/api/assets');

        $response->assertStatus(200);
        $data = $response->json('data.0');
        $this->assertArrayNotHasKey('erp_raw_data', $data);
        $this->assertArrayNotHasKey('erp_status', $data);
        $this->assertArrayNotHasKey('erp_last_synced_at', $data);
        $this->assertArrayNotHasKey('is_active', $data);
    }

    public function test_requester_sees_basic_fields_only(): void
    {
        $requester = $this->createUser(RoleCode::REQUESTER);
        $asset = $this->createAsset();

        $response = $this->actingAs($requester)->getJson('/api/assets');

        $response->assertStatus(200);
        $data = $response->json('data.0');
        $this->assertArrayNotHasKey('erp_raw_data', $data);
        $this->assertArrayNotHasKey('erp_status', $data);
        $this->assertArrayNotHasKey('erp_last_synced_at', $data);
        $this->assertArrayNotHasKey('is_active', $data);
    }

    public function test_viewer_sees_basic_fields_only(): void
    {
        $viewer = $this->createUser(RoleCode::VIEWER);
        $asset = $this->createAsset();

        $response = $this->actingAs($viewer)->getJson('/api/assets');

        $response->assertStatus(200);
        $data = $response->json('data.0');
        $this->assertArrayNotHasKey('erp_raw_data', $data);
        $this->assertArrayNotHasKey('erp_status', $data);
        $this->assertArrayNotHasKey('erp_last_synced_at', $data);
        $this->assertArrayNotHasKey('is_active', $data);
    }

    public function test_non_admin_non_manager_only_sees_active_assets(): void
    {
        $viewer = $this->createUser(RoleCode::VIEWER);
        $location = Location::create(['name' => 'Loc', 'type' => 'building']);
        Asset::create([
            'erp_asset_id' => 'ERP-002',
            'erp_asset_code' => 'A-002',
            'name' => 'Active',
            'is_active' => true,
            'current_location_id' => $location->id,
        ]);
        Asset::create([
            'erp_asset_id' => 'ERP-003',
            'erp_asset_code' => 'A-003',
            'name' => 'Inactive',
            'is_active' => false,
            'current_location_id' => $location->id,
        ]);

        $response = $this->actingAs($viewer)->getJson('/api/assets');

        $response->assertStatus(200);
        $names = collect($response->json('data'))->pluck('name');
        $this->assertContains('Active', $names);
        $this->assertNotContains('Inactive', $names);
    }

    public function test_admin_sees_inactive_assets(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $location = Location::create(['name' => 'Loc', 'type' => 'building']);
        Asset::create([
            'erp_asset_id' => 'ERP-004',
            'erp_asset_code' => 'A-004',
            'name' => 'Inactive',
            'is_active' => false,
            'current_location_id' => $location->id,
        ]);

        $response = $this->actingAs($admin)->getJson('/api/assets');

        $response->assertStatus(200);
        $names = collect($response->json('data'))->pluck('name');
        $this->assertContains('Inactive', $names);
    }
}
```

**Step 2: Run tests to verify failure**

Run: `docker compose run --rm api php artisan test tests/Feature/ReadModels/AssetResourceTest`
Expected: PASS (but returns raw model data, not yet wrapped in Resource)

**Step 3: Create AssetResource**

Create `backend/app/Http/Resources/AssetResource.php`:

```php
<?php

namespace App\Http\Resources;

use App\Enums\RoleCode;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AssetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $isAdmin = $user->hasRole(RoleCode::ADMINISTRATOR);
        $isManager = $user->hasRole(RoleCode::MAINTENANCE_MANAGER);

        $data = [
            'id' => $this->id,
            'erp_asset_code' => $this->erp_asset_code,
            'name' => $this->name,
            'description' => $this->description,
            'category' => $this->category,
            'serial_number' => $this->serial_number,
            'model' => $this->model,
            'manufacturer' => $this->manufacturer,
            'operational_status' => $this->operational_status,
            'current_location' => $this->whenLoaded('currentLocation', fn () => [
                'id' => $this->currentLocation?->id,
                'name' => $this->currentLocation?->name,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];

        if ($isAdmin || $isManager) {
            $data['is_active'] = $this->is_active;
            $data['erp_status'] = $this->erp_status;
            $data['erp_last_synced_at'] = $this->erp_last_synced_at?->toIso8601String();
        }

        if ($isAdmin) {
            $data['erp_raw_data'] = $this->erp_raw_data;
        }

        return $data;
    }
}
```

**Step 4: Update AssetController to use AssetResource**

Modify `backend/app/Http/Controllers/AssetController.php`. Replace the full file:

```php
<?php

namespace App\Http\Controllers;

use App\Http\Resources\AssetResource;
use App\Models\Asset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class AssetController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Asset::class);

        $user = $request->user();
        $query = Asset::query()->with('currentLocation');

        if (! $user->hasRole(\App\Enums\RoleCode::ADMINISTRATOR) && ! $user->hasRole(\App\Enums\RoleCode::MAINTENANCE_MANAGER)) {
            $query->where('is_active', true);
        }

        $perPage = min((int) $request->input('per_page', 25), 100);
        $logs = $query->cursorPaginate($perPage);

        return AssetResource::collection($logs)->toResponse($request);
    }

    public function show(Request $request, Asset $asset): JsonResponse
    {
        Gate::authorize('view', $asset);

        $asset->load('currentLocation');

        return (new AssetResource($asset))->toResponse($request);
    }

    public function meterReadings(Asset $asset): JsonResponse
    {
        Gate::authorize('view', $asset);

        return response()->json(['data' => $asset->meterReadings()->orderByDesc('reading_at')->get()]);
    }

    public function locationHistory(Asset $asset): JsonResponse
    {
        Gate::authorize('view', $asset);

        return response()->json(['data' => $asset->locationHistories()->orderByDesc('effective_at')->get()]);
    }
}
```

**Step 5: Run tests to verify**

Run: `docker compose run --rm api php artisan test tests/Feature/ReadModels/AssetResourceTest`
Expected: All PASS

**Step 6: Run full test suite regression**

Run: `docker compose run --rm api php artisan test`
Expected: All existing tests still pass

**Step 7: Commit**

```bash
git add backend
git commit -m "feat: add AssetResource with role-scoped fields and cursor pagination"
```

---

### Task 2: Create WorkOrderResource with role-scoped fields

**Files:**
- Create: `backend/app/Http/Resources/WorkOrderResource.php`
- Create: `backend/app/Http/Resources/WorkOrderPartResource.php`
- Test: `backend/tests/Feature/ReadModels/WorkOrderResourceTest.php`

**Step 1: Write failing tests**

Create `backend/tests/Feature/ReadModels/WorkOrderResourceTest.php`:

```php
<?php

namespace Tests\Feature\ReadModels;

use App\Enums\RoleCode;
use App\Enums\WorkOrderStatus;
use App\Models\Asset;
use App\Models\Location;
use App\Models\Part;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderPart;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkOrderResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function createUser(RoleCode $roleCode): User
    {
        $role = Role::where('code', $roleCode->value)->firstOrFail();
        return User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
    }

    private function createWorkOrder(): WorkOrder
    {
        $location = Location::create(['name' => 'Loc', 'type' => 'building']);
        $asset = Asset::create([
            'erp_asset_id' => 'ERP-001', 'erp_asset_code' => 'A-001', 'name' => 'Asset',
            'is_active' => true, 'current_location_id' => $location->id,
        ]);
        return WorkOrder::create([
            'number' => 'WO-001', 'asset_id' => $asset->id, 'status' => WorkOrderStatus::OPEN,
            'priority' => 'high', 'description' => 'Test WO',
        ]);
    }

    public function test_admin_sees_all_wo_fields(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $wo = $this->createWorkOrder();
        $wo->update(['assigned_to_user_id' => $admin->id, 'assigned_by_user_id' => $admin->id]);

        $response = $this->actingAs($admin)->getJson('/api/work-orders');

        $response->assertStatus(200);
        $data = $response->json('data.0');
        $this->assertArrayHasKey('assigned_to', $data);
        $this->assertArrayHasKey('assigned_by', $data);
        $this->assertArrayHasKey('parts', $data);
    }

    public function test_technician_sees_assigned_to_name_no_email(): void
    {
        $tech = $this->createUser(RoleCode::TECHNICIAN);
        $wo = $this->createWorkOrder();
        $wo->update(['assigned_to_user_id' => $tech->id]);

        $response = $this->actingAs($tech)->getJson('/api/work-orders');

        $response->assertStatus(200);
        $data = $response->json('data.0');
        $this->assertArrayHasKey('assigned_to', $data);
        $this->assertArrayNotHasKey('email', $data['assigned_to'] ?? []);
        $this->assertArrayNotHasKey('assigned_by', $data);
    }

    public function test_logistics_sees_no_assignee_or_parts(): void
    {
        $logistics = $this->createUser(RoleCode::LOGISTICS);
        $this->createWorkOrder();

        $response = $this->actingAs($logistics)->getJson('/api/work-orders');

        $response->assertStatus(200);
        $data = $response->json('data.0');
        $this->assertArrayNotHasKey('assigned_to', $data);
        $this->assertArrayNotHasKey('assigned_by', $data);
        $this->assertArrayNotHasKey('parts', $data);
        $this->assertArrayNotHasKey('attachments', $data);
        $this->assertArrayNotHasKey('completion_notes', $data);
    }

    public function test_requester_sees_no_assignee(): void
    {
        $requester = $this->createUser(RoleCode::REQUESTER);
        $this->createWorkOrder();

        $response = $this->actingAs($requester)->getJson('/api/work-orders');

        $response->assertStatus(200);
        $data = $response->json('data.0');
        $this->assertArrayNotHasKey('assigned_to', $data);
        $this->assertArrayNotHasKey('parts', $data);
    }

    public function test_viewer_sees_assignee_name_no_email(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $viewer = $this->createUser(RoleCode::VIEWER);
        $wo = $this->createWorkOrder();
        $wo->update(['assigned_to_user_id' => $admin->id]);

        $response = $this->actingAs($viewer)->getJson('/api/work-orders');

        $response->assertStatus(200);
        $data = $response->json('data.0');
        $this->assertArrayHasKey('assigned_to', $data);
        $this->assertArrayNotHasKey('email', $data['assigned_to'] ?? []);
    }
}
```

**Step 2: Run tests to verify failure**

Run: `docker compose run --rm api php artisan test tests/Feature/ReadModels/WorkOrderResourceTest`
Expected: Some tests fail (logistics sees assigned_to, requester sees assigned_to, etc.)

**Step 3: Create WorkOrderPartResource**

Create `backend/app/Http/Resources/WorkOrderPartResource.php`:

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkOrderPartResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'part' => [
                'id' => $this->part?->id,
                'name' => $this->part?->name,
                'erp_part_code' => $this->part?->erp_part_code,
                'unit_of_measure' => $this->part?->unit_of_measure,
            ],
            'quantity' => (float) $this->quantity,
            'notes' => $this->notes,
        ];
    }
}
```

**Step 4: Create WorkOrderResource**

Create `backend/app/Http/Resources/WorkOrderResource.php`:

```php
<?php

namespace App\Http\Resources;

use App\Enums\RoleCode;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $isAdmin = $user->hasRole(RoleCode::ADMINISTRATOR);
        $isManager = $user->hasRole(RoleCode::MAINTENANCE_MANAGER);
        $isTech = $user->hasRole(RoleCode::TECHNICIAN);
        $isLogistics = $user->hasRole(RoleCode::LOGISTICS);

        $showAssignee = $isAdmin || $isManager || $isTech;
        $showAssigneeEmail = $isAdmin || $isManager;
        $showAssignedBy = $isAdmin || $isManager;
        $showParts = $isAdmin || $isManager || $isTech;
        $showExecution = $isAdmin || $isManager || $isTech;
        $showAttachments = $isAdmin || $isManager || $isTech;

        $data = [
            'id' => $this->id,
            'number' => $this->number,
            'status' => $this->status?->value,
            'priority' => $this->priority,
            'description' => $this->description,
            'created_at' => $this->created_at?->toIso8601String(),
            'asset' => $this->whenLoaded('asset', fn () => [
                'id' => $this->asset?->id,
                'name' => $this->asset?->name,
                'erp_asset_code' => $this->asset?->erp_asset_code,
            ]),
        ];

        if ($showAssignee && $this->relationLoaded('assignedTo')) {
            $assignedTo = [
                'id' => $this->assignedTo?->id,
                'name' => $this->assignedTo?->name,
            ];
            if ($showAssigneeEmail) {
                $assignedTo['email'] = $this->assignedTo?->email;
            }
            $data['assigned_to'] = $assignedTo;
        }

        if ($showAssignedBy && $this->relationLoaded('assignedBy')) {
            $data['assigned_by'] = [
                'id' => $this->assignedBy?->id,
                'name' => $this->assignedBy?->name,
            ];
        }

        if ($showExecution) {
            $data['started_at'] = $this->started_at?->toIso8601String();
            $data['completed_at'] = $this->completed_at?->toIso8601String();
            $data['completion_notes'] = $this->completion_notes;
            $data['closed_at'] = $this->closed_at?->toIso8601String();
            $data['cancelled_at'] = $this->cancelled_at?->toIso8601String();
            $data['cancellation_reason'] = $this->cancellation_reason;
        }

        if ($showParts && $this->relationLoaded('parts')) {
            $data['parts'] = WorkOrderPartResource::collection($this->whenLoaded('parts'));
        }

        if ($showAttachments) {
            $data['has_attachments'] = $this->whenLoaded('attachments', fn () => $this->attachments->count());
        }

        return $data;
    }
}
```

**Step 5: Update WorkOrderController to use WorkOrderResource**

Modify `backend/app/Http/Controllers/WorkOrderController.php` — update `index()` and `show()`:

Replace the `index()` method body with:
```php
public function index(Request $request): JsonResponse
{
    Gate::authorize('viewAny', WorkOrder::class);

    $perPage = min((int) $request->input('per_page', 25), 100);
    $logs = WorkOrder::with(['asset', 'assignedTo', 'maintenanceRequest'])
        ->orderByDesc('created_at')
        ->cursorPaginate($perPage);

    return WorkOrderResource::collection($logs)->toResponse($request);
}
```

Replace the `show()` method body with:
```php
public function show(WorkOrder $workOrder): JsonResponse
{
    Gate::authorize('view', $workOrder);

    $workOrder->load(['asset', 'assignedTo', 'maintenanceRequest', 'assignedBy', 'parts.part']);

    return (new WorkOrderResource($workOrder))->toResponse($request);
}
```

Add imports at the top:
```php
use App\Http\Resources\WorkOrderResource;
```

**Step 6: Run tests**

Run: `docker compose run --rm api php artisan test tests/Feature/ReadModels/WorkOrderResourceTest`
Expected: All PASS

**Step 7: Run full regression**

Run: `docker compose run --rm api php artisan test`
Expected: All PASS

**Step 8: Commit**

```bash
git add backend
git commit -m "feat: add WorkOrderResource and WorkOrderPartResource with role-scoped fields"
```

---

### Task 3: Create MaintenanceRequestResource with role-scoped fields

**Files:**
- Create: `backend/app/Http/Resources/MaintenanceRequestResource.php`
- Test: `backend/tests/Feature/ReadModels/MaintenanceRequestResourceTest.php`

**Step 1: Write failing tests**

Create `backend/tests/Feature/ReadModels/MaintenanceRequestResourceTest.php`:

```php
<?php

namespace Tests\Feature\ReadModels;

use App\Enums\RoleCode;
use App\Models\Asset;
use App\Models\Location;
use App\Models\Role;
use App\Models\User;
use App\Models\MaintenanceRequest;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MaintenanceRequestResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function createUser(RoleCode $roleCode): User
    {
        $role = Role::where('code', $roleCode->value)->firstOrFail();
        return User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
    }

    private function createMaintenanceRequest(int $createdBy): MaintenanceRequest
    {
        $location = Location::create(['name' => 'Loc', 'type' => 'building']);
        $asset = Asset::create([
            'erp_asset_id' => 'ERP-001', 'erp_asset_code' => 'A-001', 'name' => 'Asset',
            'is_active' => true, 'current_location_id' => $location->id,
        ]);
        return MaintenanceRequest::create([
            'number' => 'MR-001', 'asset_id' => $asset->id, 'type' => 'corrective',
            'status' => 'pending_review', 'priority' => 'high', 'description' => 'Test MR',
            'created_by' => $createdBy, 'is_preventive' => false,
        ]);
    }

    public function test_admin_sees_all_mr_fields(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $this->createMaintenanceRequest($admin->id);

        $response = $this->actingAs($admin)->getJson('/api/maintenance-requests');

        $response->assertStatus(200);
        $data = $response->json('data.0');
        $this->assertArrayHasKey('created_by', $data);
        $this->assertArrayHasKey('email', $data['created_by']);
        $this->assertArrayHasKey('is_preventive', $data);
    }

    public function test_requester_sees_own_mr_with_created_by(): void
    {
        $requester = $this->createUser(RoleCode::REQUESTER);
        $this->createMaintenanceRequest($requester->id);

        $response = $this->actingAs($requester)->getJson('/api/maintenance-requests');

        $response->assertStatus(200);
        $data = $response->json('data.0');
        $this->assertArrayHasKey('created_by', $data);
        $this->assertArrayNotHasKey('email', $data['created_by'] ?? []);
    }

    public function test_requester_only_sees_own_requests(): void
    {
        $requester = $this->createUser(RoleCode::REQUESTER);
        $other = $this->createUser(RoleCode::REQUESTER);
        $this->createMaintenanceRequest($requester->id);
        $this->createMaintenanceRequest($other->id);

        $response = $this->actingAs($requester)->getJson('/api/maintenance-requests');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
    }

    public function test_logistics_sees_no_created_by(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $logistics = $this->createUser(RoleCode::LOGISTICS);
        $this->createMaintenanceRequest($admin->id);

        $response = $this->actingAs($logistics)->getJson('/api/maintenance-requests');

        $response->assertStatus(200);
        $data = $response->json('data.0');
        $this->assertArrayNotHasKey('created_by', $data);
    }

    public function test_viewer_sees_no_attachments(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $viewer = $this->createUser(RoleCode::VIEWER);
        $this->createMaintenanceRequest($admin->id);

        $response = $this->actingAs($viewer)->getJson('/api/maintenance-requests');

        $response->assertStatus(200);
        $data = $response->json('data.0');
        $this->assertArrayNotHasKey('attachments', $data);
    }
}
```

**Step 2: Run tests**

Run: `docker compose run --rm api php artisan test tests/Feature/ReadModels/MaintenanceRequestResourceTest`

**Step 3: Create MaintenanceRequestResource**

Create `backend/app/Http/Resources/MaintenanceRequestResource.php`:

```php
<?php

namespace App\Http\Resources;

use App\Enums\RoleCode;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MaintenanceRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $isAdmin = $user->hasRole(RoleCode::ADMINISTRATOR);
        $isManager = $user->hasRole(RoleCode::MAINTENANCE_MANAGER);
        $isTech = $user->hasRole(RoleCode::TECHNICIAN);
        $isRequester = $user->hasRole(RoleCode::REQUESTER);
        $isOwn = $this->created_by === $user->id;

        $showCreatedBy = $isAdmin || $isManager || $isTech || ($isRequester && $isOwn);
        $showCreatedByEmail = $isAdmin || $isManager;
        $showReviewedBy = $isAdmin || $isManager;
        $showReviewerPublic = !$isRequester || $isOwn;
        $showPmFields = $isAdmin || $isManager;
        $showWorkOrder = $isAdmin || $isManager || $isTech || ($isRequester && $isOwn);
        $showAttachments = $isAdmin || $isManager || $isTech || ($isRequester && $isOwn);

        $data = [
            'id' => $this->id,
            'number' => $this->number,
            'type' => $this->type,
            'status' => $this->status?->value,
            'priority' => $this->priority,
            'description' => $this->description,
            'created_at' => $this->created_at?->toIso8601String(),
            'asset' => $this->whenLoaded('asset', fn () => [
                'id' => $this->asset?->id,
                'name' => $this->asset?->name,
                'erp_asset_code' => $this->asset?->erp_asset_code,
            ]),
        ];

        if ($showCreatedBy && $this->relationLoaded('createdBy')) {
            $createdBy = [
                'id' => $this->createdBy?->id,
                'name' => $this->createdBy?->name,
            ];
            if ($showCreatedByEmail) {
                $createdBy['email'] = $this->createdBy?->email;
            }
            $data['created_by'] = $createdBy;
        }

        if ($showReviewedBy && $this->relationLoaded('reviewedBy')) {
            $data['reviewed_by'] = [
                'id' => $this->reviewedBy?->id,
                'name' => $this->reviewedBy?->name,
            ];
        }

        if ($this->rejection_reason && ($isAdmin || $isManager || $isTech || ($isRequester && $isOwn) || $user->hasRole(RoleCode::VIEWER))) {
            $data['rejection_reason'] = $this->rejection_reason;
        }

        if ($this->cancellation_reason && ($isAdmin || $isManager || $isTech || ($isRequester && $isOwn) || $user->hasRole(RoleCode::VIEWER))) {
            $data['cancellation_reason'] = $this->cancellation_reason;
        }

        if ($showPmFields) {
            $data['is_preventive'] = $this->is_preventive;
            $data['triggered_by_date'] = $this->triggered_by_date;
            $data['triggered_by_reading'] = $this->triggered_by_reading;
            $data['trigger_date'] = $this->trigger_date?->toDateString();
            $data['trigger_reading_value'] = $this->trigger_reading_value;
        }

        if ($showWorkOrder && $this->relationLoaded('workOrder')) {
            $data['work_order'] = $this->whenLoaded('workOrder', fn () => $this->workOrder ? [
                'id' => $this->workOrder->id,
                'number' => $this->workOrder->number,
                'status' => $this->workOrder->status?->value,
            ] : null);
        }

        return $data;
    }
}
```

**Step 4: Update MaintenanceRequestController**

Modify `index()`:
```php
public function index(Request $request): JsonResponse
{
    Gate::authorize('viewAny', MaintenanceRequest::class);

    $user = $request->user();

    $query = MaintenanceRequest::with(['asset', 'createdBy']);

    if ($user->hasRole(RoleCode::REQUESTER)) {
        $query->where('created_by', $user->id);
    }

    $perPage = min((int) $request->input('per_page', 25), 100);
    $logs = $query->orderByDesc('created_at')->cursorPaginate($perPage);

    return MaintenanceRequestResource::collection($logs)->toResponse($request);
}
```

Modify `show()`:
```php
public function show(MaintenanceRequest $maintenanceRequest): JsonResponse
{
    Gate::authorize('view', $maintenanceRequest);

    $maintenanceRequest->load(['asset', 'createdBy', 'reviewedBy', 'workOrder']);

    return (new MaintenanceRequestResource($maintenanceRequest))->toResponse($request);
}
```

Add import:
```php
use App\Http\Resources\MaintenanceRequestResource;
```

**Step 5: Run tests and commit**

Run: `docker compose run --rm api php artisan test tests/Feature/ReadModels/MaintenanceRequestResourceTest`
Run: `docker compose run --rm api php artisan test`
```bash
git add backend
git commit -m "feat: add MaintenanceRequestResource with role-scoped fields"
```

---

### Task 4: Create PmRuleResource, PartResource, and AttachmentResource

**Files:**
- Create: `backend/app/Http/Resources/PmRuleResource.php`
- Create: `backend/app/Http/Resources/PartResource.php`
- Create: `backend/app/Http/Resources/AttachmentResource.php`
- Create: `backend/app/Http/Controllers/PartController.php`
- Test: `backend/tests/Feature/ReadModels/PmRuleResourceTest.php`
- Test: `backend/tests/Feature/ReadModels/PartResourceTest.php`
- Test: `backend/tests/Feature/ReadModels/AttachmentResourceTest.php`

**Step 1: Create PmRuleResource**

Create `backend/app/Http/Resources/PmRuleResource.php`:

```php
<?php

namespace App\Http\Resources;

use App\Enums\RoleCode;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PmRuleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $isAdmin = $user->hasRole(RoleCode::ADMINISTRATOR);
        $isManager = $user->hasRole(RoleCode::MAINTENANCE_MANAGER);
        $showAdminFields = $isAdmin || $isManager;

        $data = [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'trigger_type' => $this->trigger_type?->value,
            'is_active' => $this->is_active,
            'interval_days' => $this->interval_days,
            'interval_reading' => $this->interval_reading ? (float) $this->interval_reading : null,
            'last_triggered_date' => $this->last_triggered_date?->toDateString(),
            'last_triggered_reading' => $this->last_triggered_reading ? (float) $this->last_triggered_reading : null,
            'created_at' => $this->created_at?->toIso8601String(),
            'asset' => $this->whenLoaded('asset', fn () => [
                'id' => $this->asset?->id,
                'name' => $this->asset?->name,
                'erp_asset_code' => $this->asset?->erp_asset_code,
            ]),
        ];

        if ($showAdminFields) {
            $data['created_by'] = $this->whenLoaded('createdBy', fn () => [
                'id' => $this->createdBy?->id,
                'name' => $this->createdBy?->name,
            ]);
        }

        return $data;
    }
}
```

**Step 2: Create PartResource**

Create `backend/app/Http/Resources/PartResource.php`:

```php
<?php

namespace App\Http\Resources;

use App\Enums\RoleCode;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PartResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $isAdmin = $user->hasRole(RoleCode::ADMINISTRATOR);
        $isManager = $user->hasRole(RoleCode::MAINTENANCE_MANAGER);

        $data = [
            'id' => $this->id,
            'erp_part_code' => $this->erp_part_code,
            'name' => $this->name,
            'description' => $this->description,
            'unit_of_measure' => $this->unit_of_measure,
            'category' => $this->category,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at?->toIso8601String(),
        ];

        if ($isAdmin || $isManager) {
            $data['erp_status'] = $this->erp_status;
            $data['erp_last_synced_at'] = $this->erp_last_synced_at?->toIso8601String();
        }

        if ($isAdmin) {
            $data['erp_raw_data'] = $this->erp_raw_data;
        }

        return $data;
    }
}
```

**Step 3: Create AttachmentResource**

Create `backend/app/Http/Resources/AttachmentResource.php`:

```php
<?php

namespace App\Http\Resources;

use App\Enums\RoleCode;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AttachmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $isAdmin = $user->hasRole(RoleCode::ADMINISTRATOR);
        $isManager = $user->hasRole(RoleCode::MAINTENANCE_MANAGER);

        $data = [
            'id' => $this->id,
            'file_name' => $this->original_name,
            'mime_type' => $this->mime_type,
            'size_bytes' => $this->size_bytes,
            'description' => $this->description,
            'created_at' => $this->created_at?->toIso8601String(),
        ];

        $canDownload = $isAdmin || $isManager
            || $user->hasRole(RoleCode::TECHNICIAN)
            || $user->hasRole(RoleCode::LOGISTICS);

        if ($canDownload) {
            $data['download_url'] = url("/api/attachments/{$this->id}/download");
        }

        if ($isAdmin || $isManager) {
            $data['uploaded_by'] = $this->whenLoaded('uploadedBy', fn () => [
                'id' => $this->uploadedBy?->id,
                'name' => $this->uploadedBy?->name,
            ]);
        }

        return $data;
    }
}
```

**Step 4: Create PartController**

Create `backend/app/Http/Controllers/PartController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Http\Resources\PartResource;
use App\Models\Part;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PartController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Part::query()->where('is_active', true);

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(fn ($q) => $q->where('name', 'ilike', "%{$search}%")->orWhere('erp_part_code', 'ilike', "%{$search}%"));
        }

        $perPage = min((int) $request->input('per_page', 25), 100);
        $logs = $query->orderBy('name')->cursorPaginate($perPage);

        return PartResource::collection($logs)->toResponse($request);
    }
}
```

**Step 5: Update PmRuleController index/show, AttachmentController index methods, and routes**

Update `PmRuleController` `index()`:
```php
public function index(Request $request): JsonResponse
{
    Gate::authorize('viewAny', PmRule::class);

    $perPage = min((int) $request->input('per_page', 25), 100);
    $logs = PmRule::with(['asset', 'usageReadingType', 'createdBy'])
        ->orderByDesc('created_at')
        ->cursorPaginate($perPage);

    return PmRuleResource::collection($logs)->toResponse($request);
}
```

Update `PmRuleController` `show()`:
```php
public function show(PmRule $pmRule): JsonResponse
{
    Gate::authorize('view', $pmRule);

    $pmRule->load(['asset', 'usageReadingType', 'createdBy', 'suppressions']);

    return (new PmRuleResource($pmRule))->toResponse($request);
}
```

Add import to `PmRuleController`:
```php
use App\Http\Resources\PmRuleResource;
```

Update all `indexFor*` methods in `AttachmentController` to wrap in `AttachmentResource`:
```php
use App\Http\Resources\AttachmentResource;
```

Replace each `return response()->json(['data' => $entity->attachments()->get()]);` with:
```php
return AttachmentResource::collection($entity->attachments()->get())->toResponse($request);
```
(Repeat for each indexForAsset, indexForPart, indexForMaintenanceRequest, indexForWorkOrder — adding `Request $request` parameter to each.)

Add parts route to `routes/api.php` inside the `auth:sanctum` group:
```php
Route::get('/parts', [PartController::class, 'index']);
```

Add import:
```php
use App\Http\Controllers\PartController;
```

**Step 6: Write tests for PmRuleResource**

Create `backend/tests/Feature/ReadModels/PmRuleResourceTest.php`:

```php
<?php

namespace Tests\Feature\ReadModels;

use App\Enums\RoleCode;
use App\Models\Asset;
use App\Models\Location;
use App\Models\PmRule;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PmRuleResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function createUser(RoleCode $roleCode): User
    {
        $role = Role::where('code', $roleCode->value)->firstOrFail();
        return User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
    }

    private function createPmRule(): PmRule
    {
        $location = Location::create(['name' => 'Loc', 'type' => 'building']);
        $asset = Asset::create([
            'erp_asset_id' => 'ERP-001', 'erp_asset_code' => 'A-001', 'name' => 'Asset',
            'is_active' => true, 'current_location_id' => $location->id,
        ]);
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        return PmRule::create([
            'asset_id' => $asset->id, 'name' => 'Test Rule', 'trigger_type' => 'date',
            'interval_days' => 30, 'is_active' => true, 'created_by' => $admin->id,
        ]);
    }

    public function test_logistics_cannot_see_pm_rules(): void
    {
        $logistics = $this->createUser(RoleCode::LOGISTICS);
        $this->createPmRule();

        $response = $this->actingAs($logistics)->getJson('/api/pm-rules');

        $response->assertStatus(403);
    }

    public function test_requester_cannot_see_pm_rules(): void
    {
        $requester = $this->createUser(RoleCode::REQUESTER);
        $this->createPmRule();

        $response = $this->actingAs($requester)->getJson('/api/pm-rules');

        $response->assertStatus(403);
    }

    public function test_admin_sees_created_by_in_pm_rules(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $this->createPmRule();

        $response = $this->actingAs($admin)->getJson('/api/pm-rules');

        $response->assertStatus(200);
        $data = $response->json('data.0');
        $this->assertArrayHasKey('created_by', $data);
    }

    public function test_technician_sees_no_created_by(): void
    {
        $tech = $this->createUser(RoleCode::TECHNICIAN);
        $this->createPmRule();

        $response = $this->actingAs($tech)->getJson('/api/pm-rules');

        $response->assertStatus(200);
        $data = $response->json('data.0');
        $this->assertArrayNotHasKey('created_by', $data);
    }
}
```

**Step 7: Run all tests**

Run: `docker compose run --rm api php artisan test tests/Feature/ReadModels`
Run: `docker compose run --rm api php artisan test`

**Step 8: Commit**

```bash
git add backend
git commit -m "feat: add PmRule, Part, Attachment resources; PartController; resource wrapping"
```

---

### Task 5: Create Query classes with filtering, sorting, and role scoping

**Files:**
- Create: `backend/app/Queries/Assets/AssetIndexQuery.php`
- Create: `backend/app/Queries/WorkOrders/WorkOrderIndexQuery.php`
- Create: `backend/app/Queries/MaintenanceRequests/MaintenanceRequestIndexQuery.php`
- Create: `backend/app/Queries/PmRules/PmRuleIndexQuery.php`
- Create: `backend/app/Queries/Parts/PartIndexQuery.php`
- Create: `backend/app/Queries/Employees/EmployeeIndexQuery.php`

**Step 1: Create AssetIndexQuery**

Create `backend/app/Queries/Assets/AssetIndexQuery.php`:

```php
<?php

namespace App\Queries\Assets;

use App\Enums\RoleCode;
use App\Models\Asset;
use Illuminate\Http\Request;

class AssetIndexQuery
{
    protected array $allowedSorts = [
        'name' => 'name',
        'erp_asset_code' => 'erp_asset_code',
        'category' => 'category',
        'operational_status' => 'operational_status',
        'created_at' => 'created_at',
    ];

    public function build(Request $request): \Illuminate\Contracts\Pagination\CursorPaginator
    {
        $user = $request->user();
        $query = Asset::query()->with('currentLocation');

        if (! $user->hasRole(RoleCode::ADMINISTRATOR) && ! $user->hasRole(RoleCode::MAINTENANCE_MANAGER)) {
            $query->where('is_active', true);
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(fn ($q) => $q
                ->where('name', 'ilike', "%{$search}%")
                ->orWhere('erp_asset_code', 'ilike', "%{$search}%")
            );
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->filled('operational_status')) {
            $query->where('operational_status', $request->input('operational_status'));
        }

        if ($request->filled('category')) {
            $query->where('category', $request->input('category'));
        }

        if ($request->filled('location_id')) {
            $query->where('current_location_id', $request->input('location_id'));
        }

        $this->applySort($query, $request);

        $perPage = min((int) $request->input('per_page', 25), 100);

        return $query->cursorPaginate($perPage);
    }

    protected function applySort($query, Request $request): void
    {
        $sort = $request->input('sort', 'created_at:desc');
        [$field, $direction] = array_pad(explode(':', $sort, 2), 2, 'desc');

        if (isset($this->allowedSorts[$field])) {
            $query->orderBy($this->allowedSorts[$field], $direction === 'asc' ? 'asc' : 'desc');
        } else {
            $query->orderByDesc('created_at');
        }
    }
}
```

**Step 2: Create WorkOrderIndexQuery**

Create `backend/app/Queries/WorkOrders/WorkOrderIndexQuery.php`:

```php
<?php

namespace App\Queries\WorkOrders;

use App\Enums\RoleCode;
use App\Models\WorkOrder;
use Illuminate\Http\Request;

class WorkOrderIndexQuery
{
    protected array $allowedSorts = [
        'created_at' => 'created_at',
        'priority' => 'priority',
        'status' => 'status',
        'started_at' => 'started_at',
        'closed_at' => 'closed_at',
    ];

    public function build(Request $request): \Illuminate\Contracts\Pagination\CursorPaginator
    {
        $user = $request->user();
        $query = WorkOrder::query()->with(['asset', 'assignedTo', 'maintenanceRequest']);

        if ($user->hasRole(RoleCode::TECHNICIAN)) {
            $query->where('assigned_to_user_id', $user->id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('assigned_to')) {
            $query->where('assigned_to_user_id', $request->input('assigned_to'));
        }

        if ($request->filled('asset_id')) {
            $query->where('asset_id', $request->input('asset_id'));
        }

        if ($request->filled('priority')) {
            $query->where('priority', $request->input('priority'));
        }

        if ($request->filled('from')) {
            $query->where('created_at', '>=', $request->input('from'));
        }

        if ($request->filled('to')) {
            $query->where('created_at', '<=', $request->input('to'));
        }

        $this->applySort($query, $request);

        $perPage = min((int) $request->input('per_page', 25), 100);

        return $query->cursorPaginate($perPage);
    }

    protected function applySort($query, Request $request): void
    {
        $sort = $request->input('sort', 'created_at:desc');
        [$field, $direction] = array_pad(explode(':', $sort, 2), 2, 'desc');

        if (isset($this->allowedSorts[$field])) {
            $query->orderBy($this->allowedSorts[$field], $direction === 'asc' ? 'asc' : 'desc');
        } else {
            $query->orderByDesc('created_at');
        }
    }
}
```

**Step 3: Create MaintenanceRequestIndexQuery**

Create `backend/app/Queries/MaintenanceRequests/MaintenanceRequestIndexQuery.php`:

```php
<?php

namespace App\Queries\MaintenanceRequests;

use App\Enums\RoleCode;
use App\Models\MaintenanceRequest;
use Illuminate\Http\Request;

class MaintenanceRequestIndexQuery
{
    protected array $allowedSorts = [
        'created_at' => 'created_at',
        'priority' => 'priority',
        'status' => 'status',
    ];

    public function build(Request $request): \Illuminate\Contracts\Pagination\CursorPaginator
    {
        $user = $request->user();
        $query = MaintenanceRequest::query()->with(['asset', 'createdBy']);

        if ($user->hasRole(RoleCode::REQUESTER)) {
            $query->where('created_by', $user->id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('asset_id')) {
            $query->where('asset_id', $request->input('asset_id'));
        }

        if ($request->filled('priority')) {
            $query->where('priority', $request->input('priority'));
        }

        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }

        if ($request->filled('created_by')) {
            $query->where('created_by', $request->input('created_by'));
        }

        $this->applySort($query, $request);

        $perPage = min((int) $request->input('per_page', 25), 100);

        return $query->cursorPaginate($perPage);
    }

    protected function applySort($query, Request $request): void
    {
        $sort = $request->input('sort', 'created_at:desc');
        [$field, $direction] = array_pad(explode(':', $sort, 2), 2, 'desc');

        if (isset($this->allowedSorts[$field])) {
            $query->orderBy($this->allowedSorts[$field], $direction === 'asc' ? 'asc' : 'desc');
        } else {
            $query->orderByDesc('created_at');
        }
    }
}
```

**Step 4: Create PmRuleIndexQuery**

Create `backend/app/Queries/PmRules/PmRuleIndexQuery.php`:

```php
<?php

namespace App\Queries\PmRules;

use App\Models\PmRule;
use Illuminate\Http\Request;

class PmRuleIndexQuery
{
    protected array $allowedSorts = [
        'name' => 'name',
        'created_at' => 'created_at',
        'is_active' => 'is_active',
    ];

    public function build(Request $request): \Illuminate\Contracts\Pagination\CursorPaginator
    {
        $query = PmRule::query()->with(['asset', 'usageReadingType', 'createdBy']);

        if ($request->filled('is_active')) {
            $query->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->filled('asset_id')) {
            $query->where('asset_id', $request->input('asset_id'));
        }

        if ($request->filled('trigger_type')) {
            $query->where('trigger_type', $request->input('trigger_type'));
        }

        $this->applySort($query, $request);

        $perPage = min((int) $request->input('per_page', 25), 100);

        return $query->cursorPaginate($perPage);
    }

    protected function applySort($query, Request $request): void
    {
        $sort = $request->input('sort', 'created_at:desc');
        [$field, $direction] = array_pad(explode(':', $sort, 2), 2, 'desc');

        if (isset($this->allowedSorts[$field])) {
            $query->orderBy($this->allowedSorts[$field], $direction === 'asc' ? 'asc' : 'desc');
        } else {
            $query->orderByDesc('created_at');
        }
    }
}
```

**Step 5: Create PartIndexQuery**

Create `backend/app/Queries/Parts/PartIndexQuery.php`:

```php
<?php

namespace App\Queries\Parts;

use App\Enums\RoleCode;
use App\Models\Part;
use Illuminate\Http\Request;

class PartIndexQuery
{
    protected array $allowedSorts = [
        'name' => 'name',
        'erp_part_code' => 'erp_part_code',
    ];

    public function build(Request $request): \Illuminate\Contracts\Pagination\CursorPaginator
    {
        $user = $request->user();
        $query = Part::query()->where('is_active', true);

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(fn ($q) => $q
                ->where('name', 'ilike', "%{$search}%")
                ->orWhere('erp_part_code', 'ilike', "%{$search}%")
            );
        }

        $this->applySort($query, $request);

        $perPage = min((int) $request->input('per_page', 25), 100);

        return $query->cursorPaginate($perPage);
    }

    protected function applySort($query, Request $request): void
    {
        $sort = $request->input('sort', 'name:asc');
        [$field, $direction] = array_pad(explode(':', $sort, 2), 2, 'asc');

        if (isset($this->allowedSorts[$field])) {
            $query->orderBy($this->allowedSorts[$field], $direction === 'asc' ? 'asc' : 'desc');
        } else {
            $query->orderBy('name');
        }
    }
}
```

**Step 6: Create EmployeeIndexQuery**

Create `backend/app/Queries/Employees/EmployeeIndexQuery.php`:

```php
<?php

namespace App\Queries\Employees;

use App\Models\Employee;
use Illuminate\Http\Request;

class EmployeeIndexQuery
{
    protected array $allowedSorts = [
        'name' => 'name',
        'emp_id' => 'emp_id',
    ];

    public function build(Request $request): \Illuminate\Contracts\Pagination\CursorPaginator
    {
        $query = Employee::query();

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(fn ($q) => $q
                ->where('name', 'ilike', "%{$search}%")
                ->orWhere('emp_id', 'ilike', "%{$search}%")
            );
        }

        $this->applySort($query, $request);

        $perPage = min((int) $request->input('per_page', 25), 100);

        return $query->cursorPaginate($perPage);
    }

    protected function applySort($query, Request $request): void
    {
        $sort = $request->input('sort', 'name:asc');
        [$field, $direction] = array_pad(explode(':', $sort, 2), 2, 'asc');

        if (isset($this->allowedSorts[$field])) {
            $query->orderBy($this->allowedSorts[$field], $direction === 'asc' ? 'asc' : 'desc');
        } else {
            $query->orderBy('name');
        }
    }
}
```

**Step 7: Wire query classes into controllers**

Update each controller's `index()` to use the query class:

**AssetController** — replace `index()` body:
```php
public function index(Request $request): JsonResponse
{
    Gate::authorize('viewAny', Asset::class);

    $results = app(AssetIndexQuery::class)->build($request);

    return AssetResource::collection($results)->toResponse($request);
}
```
Add import: `use App\Queries\Assets\AssetIndexQuery;`

**WorkOrderController** — replace `index()` body:
```php
public function index(Request $request): JsonResponse
{
    Gate::authorize('viewAny', WorkOrder::class);

    $results = app(WorkOrderIndexQuery::class)->build($request);

    return WorkOrderResource::collection($results)->toResponse($request);
}
```
Add import: `use App\Queries\WorkOrders\WorkOrderIndexQuery;`

**MaintenanceRequestController** — replace `index()` body:
```php
public function index(Request $request): JsonResponse
{
    Gate::authorize('viewAny', MaintenanceRequest::class);

    $results = app(MaintenanceRequestIndexQuery::class)->build($request);

    return MaintenanceRequestResource::collection($results)->toResponse($request);
}
```
Add import: `use App\Queries\MaintenanceRequests\MaintenanceRequestIndexQuery;`

**PmRuleController** — replace `index()` body:
```php
public function index(Request $request): JsonResponse
{
    Gate::authorize('viewAny', PmRule::class);

    $results = app(PmRuleIndexQuery::class)->build($request);

    return PmRuleResource::collection($results)->toResponse($request);
}
```
Add import: `use App\Queries\PmRules\PmRuleIndexQuery;`

**PartController** — replace `index()` body:
```php
public function index(Request $request): JsonResponse
{
    $results = app(PartIndexQuery::class)->build($request);

    return PartResource::collection($results)->toResponse($request);
}
```
Add import: `use App\Queries\Parts\PartIndexQuery;`

**EmployeeController** — replace `index()` body:
```php
public function index(Request $request): JsonResponse
{
    Gate::authorize('viewAny', Employee::class);

    $results = app(EmployeeIndexQuery::class)->build($request);

    return response()->json($results);
}
```
Add import: `use App\Queries\Employees\EmployeeIndexQuery;`

**Step 8: Run full test suite**

Run: `docker compose run --rm api php artisan test`

**Step 9: Commit**

```bash
git add backend
git commit -m "feat: add query classes with filtering, sorting, cursor pagination, role scoping"
```

---

### Task 6: Create BuildAssetMaintenanceHistory and OverduePmQuery

**Files:**
- Create: `backend/app/Queries/MaintenanceHistory/BuildAssetMaintenanceHistory.php`
- Create: `backend/app/Queries/Pm/OverduePmQuery.php`
- Create: `backend/app/Http/Resources/MaintenanceHistoryResource.php`
- Test: `backend/tests/Feature/Dashboard/MaintenanceHistoryTest.php`

**Step 1: Write failing tests**

Create `backend/tests/Feature/Dashboard/MaintenanceHistoryTest.php`:

```php
<?php

namespace Tests\Feature\Dashboard;

use App\Enums\RoleCode;
use App\Enums\WorkOrderStatus;
use App\Models\Asset;
use App\Models\Location;
use App\Models\MaintenanceRequest;
use App\Models\Part;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderPart;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MaintenanceHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function createUser(RoleCode $roleCode): User
    {
        $role = Role::where('code', $roleCode->value)->firstOrFail();
        return User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
    }

    private function createAsset(): Asset
    {
        $location = Location::create(['name' => 'Loc', 'type' => 'building']);
        return Asset::create([
            'erp_asset_id' => 'ERP-001', 'erp_asset_code' => 'A-001', 'name' => 'Asset',
            'is_active' => true, 'current_location_id' => $location->id,
        ]);
    }

    public function test_maintenance_history_returns_closed_work_orders(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $asset = $this->createAsset();

        $mr = MaintenanceRequest::create([
            'number' => 'MR-001', 'asset_id' => $asset->id, 'type' => 'corrective',
            'status' => 'converted', 'priority' => 'high', 'description' => 'Test',
            'created_by' => $admin->id, 'is_preventive' => false,
        ]);

        $wo = WorkOrder::create([
            'number' => 'WO-001', 'asset_id' => $asset->id, 'maintenance_request_id' => $mr->id,
            'status' => WorkOrderStatus::CLOSED, 'priority' => 'high', 'description' => 'Test WO',
            'closed_by_user_id' => $admin->id, 'closed_at' => now(),
        ]);

        $response = $this->actingAs($admin)->getJson("/api/assets/{$asset->id}/maintenance-history");

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $data = $response->json('data.0');
        $this->assertEquals('WO-001', $data['work_order_number']);
        $this->assertEquals('MR-001', $data['maintenance_request_number']);
        $this->assertEquals('corrective', $data['type']);
    }

    public function test_maintenance_history_excludes_completed_but_not_closed(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $asset = $this->createAsset();

        $mr = MaintenanceRequest::create([
            'number' => 'MR-002', 'asset_id' => $asset->id, 'type' => 'corrective',
            'status' => 'converted', 'priority' => 'high', 'description' => 'Test',
            'created_by' => $admin->id, 'is_preventive' => false,
        ]);

        WorkOrder::create([
            'number' => 'WO-002', 'asset_id' => $asset->id, 'maintenance_request_id' => $mr->id,
            'status' => WorkOrderStatus::COMPLETED, 'priority' => 'high',
            'completed_by_user_id' => $admin->id, 'completed_at' => now(),
        ]);

        $response = $this->actingAs($admin)->getJson("/api/assets/{$asset->id}/maintenance-history");

        $response->assertStatus(200);
        $response->assertJsonCount(0, 'data');
    }

    public function test_requester_only_sees_own_request_history(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $requester = $this->createUser(RoleCode::REQUESTER);
        $asset = $this->createAsset();

        $ownMr = MaintenanceRequest::create([
            'number' => 'MR-OWN', 'asset_id' => $asset->id, 'type' => 'corrective',
            'status' => 'converted', 'priority' => 'high', 'description' => 'Own',
            'created_by' => $requester->id, 'is_preventive' => false,
        ]);
        WorkOrder::create([
            'number' => 'WO-OWN', 'asset_id' => $asset->id, 'maintenance_request_id' => $ownMr->id,
            'status' => WorkOrderStatus::CLOSED, 'priority' => 'high',
            'closed_by_user_id' => $admin->id, 'closed_at' => now(),
        ]);

        $otherMr = MaintenanceRequest::create([
            'number' => 'MR-OTHER', 'asset_id' => $asset->id, 'type' => 'corrective',
            'status' => 'converted', 'priority' => 'high', 'description' => 'Other',
            'created_by' => $admin->id, 'is_preventive' => false,
        ]);
        WorkOrder::create([
            'number' => 'WO-OTHER', 'asset_id' => $asset->id, 'maintenance_request_id' => $otherMr->id,
            'status' => WorkOrderStatus::CLOSED, 'priority' => 'high',
            'closed_by_user_id' => $admin->id, 'closed_at' => now(),
        ]);

        $response = $this->actingAs($requester)->getJson("/api/assets/{$asset->id}/maintenance-history");

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $this->assertEquals('MR-OWN', $response->json('data.0.maintenance_request_number'));
    }

    public function test_logistics_cannot_access_maintenance_history(): void
    {
        $logistics = $this->createUser(RoleCode::LOGISTICS);
        $asset = $this->createAsset();

        $response = $this->actingAs($logistics)->getJson("/api/assets/{$asset->id}/maintenance-history");

        $response->assertStatus(403);
    }
}
```

**Step 2: Run tests to verify failure**

Run: `docker compose run --rm api php artisan test tests/Feature/Dashboard/MaintenanceHistoryTest`
Expected: FAIL (route does not exist)

**Step 3: Create BuildAssetMaintenanceHistory**

Create `backend/app/Queries/MaintenanceHistory/BuildAssetMaintenanceHistory.php`:

```php
<?php

namespace App\Queries\MaintenanceHistory;

use App\Enums\RoleCode;
use App\Enums\WorkOrderStatus;
use App\Models\Asset;
use Illuminate\Http\Request;

class BuildAssetMaintenanceHistory
{
    public function build(Asset $asset, Request $request): \Illuminate\Contracts\Pagination\CursorPaginator
    {
        $user = $request->user();

        $query = $asset->workOrders()
            ->with(['maintenanceRequest', 'parts.part'])
            ->where('status', WorkOrderStatus::CLOSED);

        if ($user->hasRole(RoleCode::REQUESTER)) {
            $query->whereHas('maintenanceRequest', fn ($q) => $q->where('created_by', $user->id));
        }

        $perPage = min((int) $request->input('per_page', 25), 100);

        return $query->orderByDesc('closed_at')->cursorPaginate($perPage);
    }
}
```

**Step 4: Create MaintenanceHistoryResource**

Create `backend/app/Http/Resources/MaintenanceHistoryResource.php`:

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MaintenanceHistoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'date' => $this->closed_at?->toDateString(),
            'type' => $this->maintenanceRequest?->type,
            'work_order_number' => $this->number,
            'maintenance_request_number' => $this->maintenanceRequest?->number,
            'description' => $this->description,
            'priority' => $this->priority,
            'completed_by' => $this->whenLoaded('parts', fn () => null),
            'parts_used' => $this->whenLoaded('parts', fn () => $this->parts->map(fn ($p) => [
                'part_name' => $p->part?->name,
                'quantity' => (float) $p->quantity,
            ])),
            'closed_at' => $this->closed_at?->toIso8601String(),
        ];
    }
}
```

**Step 5: Add maintenanceHistory to AssetController**

Add method to `AssetController`:

```php
public function maintenanceHistory(Request $request, Asset $asset)
{
    Gate::authorize('view', $asset);

    $user = $request->user();
    if ($user->hasRole(\App\Enums\RoleCode::LOGISTICS)) {
        abort(403);
    }

    $results = app(\App\Queries\MaintenanceHistory\BuildAssetMaintenanceHistory::class)->build($asset, $request);

    return MaintenanceHistoryResource::collection($results)->toResponse($request);
}
```

Add import:
```php
use App\Http\Resources\MaintenanceHistoryResource;
```

**Step 6: Create OverduePmQuery**

Create `backend/app/Queries/Pm/OverduePmQuery.php`:

```php
<?php

namespace App\Queries\Pm;

use App\Models\PmRule;
use App\Services\Pm\PmDueCalculator;
use Illuminate\Support\Collection;

class OverduePmQuery
{
    public function __construct(private PmDueCalculator $calculator) {}

    public function execute(int $limit = 5): Collection
    {
        return PmRule::where('is_active', true)
            ->with('asset')
            ->get()
            ->filter(fn ($rule) => $this->calculator->isDue($rule))
            ->take($limit)
            ->values();
    }
}
```

**Step 7: Add route**

Add to `routes/api.php` inside the `auth:sanctum` group:
```php
Route::get('/assets/{asset}/maintenance-history', [AssetController::class, 'maintenanceHistory']);
```

**Step 8: Run tests**

Run: `docker compose run --rm api php artisan test tests/Feature/Dashboard/MaintenanceHistoryTest`
Expected: All PASS

**Step 9: Commit**

```bash
git add backend
git commit -m "feat: add maintenance history endpoint and OverduePmQuery"
```

---

### Task 7: Create DashboardController with role-adaptive widgets

**Files:**
- Create: `backend/app/Http/Controllers/DashboardController.php`
- Create: `backend/app/Http/Resources/DashboardResource.php`
- Test: `backend/tests/Feature/Dashboard/DashboardTest.php`

**Step 1: Write failing tests**

Create `backend/tests/Feature/Dashboard/DashboardTest.php`:

```php
<?php

namespace Tests\Feature\Dashboard;

use App\Enums\RoleCode;
use App\Enums\WorkOrderStatus;
use App\Models\Asset;
use App\Models\Location;
use App\Models\MaintenanceRequest;
use App\Models\PmRule;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkOrder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function createUser(RoleCode $roleCode): User
    {
        $role = Role::where('code', $roleCode->value)->firstOrFail();
        return User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
    }

    private function createAsset(): Asset
    {
        $location = Location::create(['name' => 'Loc', 'type' => 'building']);
        return Asset::create([
            'erp_asset_id' => 'ERP-001', 'erp_asset_code' => 'A-001', 'name' => 'Asset',
            'is_active' => true, 'current_location_id' => $location->id,
        ]);
    }

    public function test_admin_sees_all_dashboard_widgets(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);

        $response = $this->actingAs($admin)->getJson('/api/dashboard');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'summary' => [
                'pending_maintenance_requests',
                'open_work_orders',
                'overdue_pm_rules',
                'recently_closed_work_orders',
            ],
            'pending_maintenance_requests',
            'open_work_orders',
            'overdue_pm_rules',
            'recently_closed_work_orders',
        ]);
    }

    public function test_technician_sees_only_open_work_orders(): void
    {
        $tech = $this->createUser(RoleCode::TECHNICIAN);

        $response = $this->actingAs($tech)->getJson('/api/dashboard');

        $response->assertStatus(200);
        $response->assertJsonMissing(['pending_maintenance_requests']);
        $response->assertJsonMissing(['overdue_pm_rules']);
        $response->assertJsonMissing(['recently_closed_work_orders']);
        $response->assertJsonStructure(['open_work_orders']);
    }

    public function test_logistics_sees_empty_dashboard(): void
    {
        $logistics = $this->createUser(RoleCode::LOGISTICS);

        $response = $this->actingAs($logistics)->getJson('/api/dashboard');

        $response->assertStatus(200);
        $response->assertJsonMissing(['pending_maintenance_requests']);
        $response->assertJsonMissing(['open_work_orders']);
        $response->assertJsonMissing(['overdue_pm_rules']);
        $response->assertJsonMissing(['recently_closed_work_orders']);
    }

    public function test_requester_sees_only_own_pending_mrs(): void
    {
        $requester = $this->createUser(RoleCode::REQUESTER);
        $other = $this->createUser(RoleCode::REQUESTER);
        $asset = $this->createAsset();

        MaintenanceRequest::create([
            'number' => 'MR-OWN', 'asset_id' => $asset->id, 'type' => 'corrective',
            'status' => 'pending_review', 'priority' => 'high', 'description' => 'Own',
            'created_by' => $requester->id, 'is_preventive' => false,
        ]);
        MaintenanceRequest::create([
            'number' => 'MR-OTHER', 'asset_id' => $asset->id, 'type' => 'corrective',
            'status' => 'pending_review', 'priority' => 'high', 'description' => 'Other',
            'created_by' => $other->id, 'is_preventive' => false,
        ]);

        $response = $this->actingAs($requester)->getJson('/api/dashboard');

        $response->assertStatus(200);
        $mrs = $response->json('pending_maintenance_requests');
        $this->assertCount(1, $mrs);
        $this->assertEquals('MR-OWN', $mrs[0]['number']);
    }

    public function test_dashboard_summary_counts_are_correct(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $asset = $this->createAsset();

        MaintenanceRequest::create([
            'number' => 'MR-001', 'asset_id' => $asset->id, 'type' => 'corrective',
            'status' => 'pending_review', 'priority' => 'high', 'description' => 'Test',
            'created_by' => $admin->id, 'is_preventive' => false,
        ]);
        WorkOrder::create([
            'number' => 'WO-001', 'asset_id' => $asset->id,
            'status' => WorkOrderStatus::OPEN, 'priority' => 'high',
        ]);
        WorkOrder::create([
            'number' => 'WO-002', 'asset_id' => $asset->id,
            'status' => WorkOrderStatus::CLOSED, 'priority' => 'high',
            'closed_by_user_id' => $admin->id, 'closed_at' => now(),
        ]);

        $response = $this->actingAs($admin)->getJson('/api/dashboard');

        $response->assertStatus(200);
        $summary = $response->json('summary');
        $this->assertEquals(1, $summary['pending_maintenance_requests']);
        $this->assertEquals(1, $summary['open_work_orders']);
        $this->assertEquals(1, $summary['recently_closed_work_orders']);
    }
}
```

**Step 2: Run tests to verify failure**

Run: `docker compose run --rm api php artisan test tests/Feature/Dashboard/DashboardTest`
Expected: FAIL (route does not exist)

**Step 3: Create DashboardController**

Create `backend/app/Http/Controllers/DashboardController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Enums\RoleCode;
use App\Enums\WorkOrderStatus;
use App\Http\Resources\MaintenanceRequestResource;
use App\Http\Resources\PmRuleResource;
use App\Http\Resources\WorkOrderResource;
use App\Models\MaintenanceRequest;
use App\Models\WorkOrder;
use App\Queries\Pm\OverduePmQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $isAdmin = $user->hasRole(RoleCode::ADMINISTRATOR);
        $isManager = $user->hasRole(RoleCode::MAINTENANCE_MANAGER);
        $isTech = $user->hasRole(RoleCode::TECHNICIAN);
        $isRequester = $user->hasRole(RoleCode::REQUESTER);
        $isViewer = $user->hasRole(RoleCode::VIEWER);

        $summary = [];
        $widgets = [];

        $showPendingMrs = $isAdmin || $isManager || $isRequester || $isViewer;
        $showOpenWos = $isAdmin || $isManager || $isTech || $isViewer;
        $showOverduePm = $isAdmin || $isManager || $isViewer;
        $showRecentlyClosed = $isAdmin || $isManager || $isViewer;

        if ($showPendingMrs) {
            $mrQuery = MaintenanceRequest::with(['asset', 'createdBy'])
                ->where('status', 'pending_review');

            if ($isRequester) {
                $mrQuery->where('created_by', $user->id);
            }

            $pendingMrs = (clone $mrQuery)->count();
            $summary['pending_maintenance_requests'] = $pendingMrs;

            $mrList = (clone $mrQuery)->orderByDesc('created_at')->limit(5)->get();
            $widgets['pending_maintenance_requests'] = MaintenanceRequestResource::collection($mrList)->resolve($request);
        }

        if ($showOpenWos) {
            $woQuery = WorkOrder::with(['asset', 'assignedTo', 'maintenanceRequest'])
                ->whereIn('status', [WorkOrderStatus::OPEN, WorkOrderStatus::IN_PROGRESS]);

            if ($isTech) {
                $woQuery->where('assigned_to_user_id', $user->id);
            }

            $openWos = (clone $woQuery)->count();
            $summary['open_work_orders'] = $openWos;

            $woList = (clone $woQuery)->orderByDesc('created_at')->limit(5)->get();
            $widgets['open_work_orders'] = WorkOrderResource::collection($woList)->resolve($request);
        }

        if ($showOverduePm) {
            $overdueRules = app(OverduePmQuery::class)->execute(5);
            $summary['overdue_pm_rules'] = $overdueRules->count();
            $widgets['overdue_pm_rules'] = PmRuleResource::collection($overdueRules)->resolve($request);
        }

        if ($showRecentlyClosed) {
            $recentlyClosedQuery = WorkOrder::with(['asset', 'assignedTo', 'maintenanceRequest'])
                ->where('status', WorkOrderStatus::CLOSED)
                ->where('closed_at', '>=', now()->subDays(30));

            $recentlyClosedCount = (clone $recentlyClosedQuery)->count();
            $summary['recently_closed_work_orders'] = $recentlyClosedCount;

            $recentlyClosed = (clone $recentlyClosedQuery)->orderByDesc('closed_at')->limit(5)->get();
            $widgets['recently_closed_work_orders'] = WorkOrderResource::collection($recentlyClosed)->resolve($request);
        }

        return response()->json(array_merge(['summary' => $summary], $widgets));
    }
}
```

**Step 4: Add route**

Add to `routes/api.php` inside the `auth:sanctum` group:
```php
Route::get('/dashboard', [DashboardController::class, 'index']);
```

Add import:
```php
use App\Http\Controllers\DashboardController;
```

**Step 5: Run tests**

Run: `docker compose run --rm api php artisan test tests/Feature/Dashboard/DashboardTest`
Expected: All PASS

**Step 6: Run full regression**

Run: `docker compose run --rm api php artisan test`
Expected: All PASS

**Step 7: Commit**

```bash
git add backend
git commit -m "feat: add role-adaptive dashboard endpoint"
```

---

### Task 8: Run full regression and finalize

**Step 1: Run full test suite**

Run: `docker compose run --rm api php artisan test`
Expected: All PASS

**Step 2: Run linting**

Run: `docker compose run --rm api ./vendor/bin/pint --test`
Expected: No issues

**Step 3: Final commit if any cleanup needed**

```bash
git add backend
git commit -m "style: fix code style for Task 14 read APIs"
```
