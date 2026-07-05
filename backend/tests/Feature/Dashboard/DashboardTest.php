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
            'erp_asset_code' => 'A-001', 'name' => 'Asset',
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
                'overdue_pm_assignments',
                'recently_closed_work_orders',
            ],
            'pending_maintenance_requests',
            'open_work_orders',
            'overdue_pm_assignments',
            'recently_closed_work_orders',
        ]);
    }

    public function test_technician_sees_only_open_work_orders(): void
    {
        $tech = $this->createUser(RoleCode::TECHNICIAN);

        $response = $this->actingAs($tech)->getJson('/api/dashboard');

        $response->assertStatus(200);
        $json = $response->json();
        $this->assertArrayNotHasKey('pending_maintenance_requests', $json);
        $this->assertArrayNotHasKey('overdue_pm_assignments', $json);
        $this->assertArrayNotHasKey('recently_closed_work_orders', $json);
        $this->assertArrayHasKey('open_work_orders', $json);
    }

    public function test_logistics_sees_empty_dashboard(): void
    {
        $logistics = $this->createUser(RoleCode::LOGISTICS);

        $response = $this->actingAs($logistics)->getJson('/api/dashboard');

        $response->assertStatus(200);
        $json = $response->json();
        $this->assertArrayNotHasKey('pending_maintenance_requests', $json);
        $this->assertArrayNotHasKey('open_work_orders', $json);
        $this->assertArrayNotHasKey('overdue_pm_assignments', $json);
        $this->assertArrayNotHasKey('recently_closed_work_orders', $json);
    }

    public function test_requester_sees_only_own_pending_mrs(): void
    {
        $requester = $this->createUser(RoleCode::REQUESTER);
        $other = $this->createUser(RoleCode::REQUESTER);
        $asset = $this->createAsset();

        MaintenanceRequest::create([
            'number' => 'MR-OWN', 'asset_id' => $asset->id,
            'status' => 'pending_review', 'priority' => 'high', 'description' => 'Own',
            'created_by' => $requester->id, 'is_preventive' => false,
        ]);
        MaintenanceRequest::create([
            'number' => 'MR-OTHER', 'asset_id' => $asset->id,
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
            'number' => 'MR-001', 'asset_id' => $asset->id,
            'status' => 'pending_review', 'priority' => 'high', 'description' => 'Test',
            'created_by' => $admin->id, 'is_preventive' => false,
        ]);
        $mrOpen = MaintenanceRequest::create([
            'number' => 'MR-002', 'asset_id' => $asset->id,
            'status' => 'converted', 'priority' => 'high', 'description' => 'Open WO',
            'created_by' => $admin->id, 'is_preventive' => false,
        ]);
        $mrClosed = MaintenanceRequest::create([
            'number' => 'MR-003', 'asset_id' => $asset->id,
            'status' => 'converted', 'priority' => 'high', 'description' => 'Closed WO',
            'created_by' => $admin->id, 'is_preventive' => false,
        ]);
        WorkOrder::create([
            'number' => 'WO-001', 'asset_id' => $asset->id,
            'maintenance_request_id' => $mrOpen->id,
            'status' => WorkOrderStatus::OPEN, 'priority' => 'high',
        ]);
        WorkOrder::create([
            'number' => 'WO-002', 'asset_id' => $asset->id,
            'maintenance_request_id' => $mrClosed->id,
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

    public function test_requester_sees_widgets_except_no_logistics_style_exclusions(): void
    {
        $requester = $this->createUser(RoleCode::REQUESTER);

        $response = $this->actingAs($requester)->getJson('/api/dashboard');

        $response->assertStatus(200);
        $json = $response->json();
        $this->assertArrayHasKey('pending_maintenance_requests', $json);
        $this->assertArrayHasKey('open_work_orders', $json);
        $this->assertArrayHasKey('overdue_pm_assignments', $json);
        $this->assertArrayHasKey('recently_closed_work_orders', $json);
    }
}
