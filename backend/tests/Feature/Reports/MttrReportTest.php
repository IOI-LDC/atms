<?php

namespace Tests\Feature\Reports;

use App\Enums\RoleCode;
use App\Enums\WorkOrderStatus;
use App\Models\Asset;
use App\Models\Location;
use App\Models\MaintenanceRequest;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkOrder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MttrReportTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->admin = $this->createUser(RoleCode::ADMINISTRATOR);
    }

    private function createUser(RoleCode $roleCode): User
    {
        $role = Role::where('code', $roleCode->value)->firstOrFail();

        return User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
    }

    private function createAsset(array $overrides = []): Asset
    {
        $location = Location::create(['name' => 'Loc-'.uniqid(), 'type' => 'building']);

        return Asset::create(array_merge([
            'erp_asset_code' => 'A-'.uniqid(),
            'name' => 'Asset',
            'is_active' => true,
            'current_location_id' => $location->id,
        ], $overrides));
    }

    public function test_unauthenticated_is_rejected(): void
    {
        $this->getJson('/api/reports/mttr')->assertUnauthorized();
    }

    public function test_calculates_mttr_by_technician(): void
    {
        $tech = $this->createUser(RoleCode::TECHNICIAN);
        $asset = $this->createAsset();

        // Create corrective WO closed in last 90 days (assigned 48h before closed)
        $mr = MaintenanceRequest::forceCreate([
            'number' => 'MR-1',
            'asset_id' => $asset->id,
            'status' => 'converted',
            'priority' => 'high',
            'description' => 'Failure',
            'created_by' => $this->admin->id,
            'is_preventive' => false,
            'created_at' => now()->subDays(10),
        ]);
        WorkOrder::forceCreate([
            'number' => 'WO-1',
            'asset_id' => $asset->id,
            'maintenance_request_id' => $mr->id,
            'status' => WorkOrderStatus::CLOSED,
            'priority' => 'high',
            'assigned_to_user_id' => $tech->id,
            'assigned_at' => now()->subDays(10),
            'closed_at' => now()->subDays(8),
            'closed_by_user_id' => $this->admin->id,
            'created_at' => now()->subDays(10),
        ]);

        $json = $this->actingAs($this->admin)->getJson('/api/reports/mttr?group_by=technician')->json();

        $this->assertSame(1, $json['summary']['repair_count']);
        $this->assertNotNull($json['summary']['mttr_hours']);
        // 48 hours between assigned_at and closed_at
        $this->assertEqualsWithDelta(48.0, $json['summary']['mttr_hours'], 0.1);
    }

    public function test_excludes_open_work_orders(): void
    {
        $tech = $this->createUser(RoleCode::TECHNICIAN);
        $asset = $this->createAsset();

        $mr = MaintenanceRequest::forceCreate([
            'number' => 'MR-1',
            'asset_id' => $asset->id,
            'status' => 'converted',
            'priority' => 'high',
            'description' => 'Failure',
            'created_by' => $this->admin->id,
            'is_preventive' => false,
            'created_at' => now()->subDays(10),
        ]);
        WorkOrder::forceCreate([
            'number' => 'WO-1',
            'asset_id' => $asset->id,
            'maintenance_request_id' => $mr->id,
            'status' => WorkOrderStatus::OPEN,
            'priority' => 'high',
            'assigned_to_user_id' => $tech->id,
            'assigned_at' => now()->subDays(10),
            'created_at' => now()->subDays(10),
        ]);

        $json = $this->actingAs($this->admin)->getJson('/api/reports/mttr')->json();

        $this->assertSame(0, $json['summary']['repair_count']);
        $this->assertNull($json['summary']['mttr_hours']);
    }

    public function test_excludes_preventive_work_orders(): void
    {
        $tech = $this->createUser(RoleCode::TECHNICIAN);
        $asset = $this->createAsset();

        $mr = MaintenanceRequest::forceCreate([
            'number' => 'MR-1',
            'asset_id' => $asset->id,
            'status' => 'converted',
            'priority' => 'high',
            'description' => 'PM',
            'created_by' => $this->admin->id,
            'is_preventive' => true,
            'created_at' => now()->subDays(10),
        ]);
        WorkOrder::forceCreate([
            'number' => 'WO-1',
            'asset_id' => $asset->id,
            'maintenance_request_id' => $mr->id,
            'status' => WorkOrderStatus::CLOSED,
            'priority' => 'high',
            'assigned_to_user_id' => $tech->id,
            'assigned_at' => now()->subDays(10),
            'closed_at' => now()->subDays(8),
            'closed_by_user_id' => $this->admin->id,
            'created_at' => now()->subDays(10),
        ]);

        $json = $this->actingAs($this->admin)->getJson('/api/reports/mttr')->json();

        $this->assertSame(0, $json['summary']['repair_count']);
    }

    public function test_empty_state(): void
    {
        $json = $this->actingAs($this->admin)->getJson('/api/reports/mttr')->json();

        $this->assertSame(0, $json['summary']['repair_count']);
        $this->assertNull($json['summary']['mttr_hours']);
        $this->assertSame([], $json['items']);
    }
}
