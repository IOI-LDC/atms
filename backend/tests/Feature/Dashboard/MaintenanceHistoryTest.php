<?php

namespace Tests\Feature\Dashboard;

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
            'erp_asset_code' => 'A-001', 'name' => 'Asset',
            'is_active' => true, 'current_location_id' => $location->id,
        ]);
    }

    public function test_maintenance_history_returns_closed_work_orders(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $asset = $this->createAsset();

        $mr = MaintenanceRequest::create([
            'number' => 'MR-001', 'asset_id' => $asset->id,
            'status' => 'converted', 'priority' => 'high', 'description' => 'Test',
            'created_by' => $admin->id, 'is_preventive' => false,
        ]);

        WorkOrder::create([
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
            'number' => 'MR-002', 'asset_id' => $asset->id,
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
            'number' => 'MR-OWN', 'asset_id' => $asset->id,
            'status' => 'converted', 'priority' => 'high', 'description' => 'Own',
            'created_by' => $requester->id, 'is_preventive' => false,
        ]);
        WorkOrder::create([
            'number' => 'WO-OWN', 'asset_id' => $asset->id, 'maintenance_request_id' => $ownMr->id,
            'status' => WorkOrderStatus::CLOSED, 'priority' => 'high',
            'closed_by_user_id' => $admin->id, 'closed_at' => now(),
        ]);

        $otherMr = MaintenanceRequest::create([
            'number' => 'MR-OTHER', 'asset_id' => $asset->id,
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

    public function test_maintenance_history_has_no_completed_by_field(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $asset = $this->createAsset();

        $mr = MaintenanceRequest::create([
            'number' => 'MR-003', 'asset_id' => $asset->id,
            'status' => 'converted', 'priority' => 'high', 'description' => 'Test',
            'created_by' => $admin->id, 'is_preventive' => false,
        ]);
        WorkOrder::create([
            'number' => 'WO-003', 'asset_id' => $asset->id, 'maintenance_request_id' => $mr->id,
            'status' => WorkOrderStatus::CLOSED, 'priority' => 'high',
            'closed_by_user_id' => $admin->id, 'closed_at' => now(),
        ]);

        $response = $this->actingAs($admin)->getJson("/api/assets/{$asset->id}/maintenance-history");

        $response->assertStatus(200);
        $data = $response->json('data.0');
        $this->assertArrayNotHasKey('completed_by', $data);
    }
}
