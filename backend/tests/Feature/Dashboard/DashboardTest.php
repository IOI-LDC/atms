<?php

namespace Tests\Feature\Dashboard;

use App\Enums\PmTriggerType;
use App\Enums\RoleCode;
use App\Enums\WorkOrderStatus;
use App\Models\Asset;
use App\Models\AssetPmAssignment;
use App\Models\Location;
use App\Models\MaintenanceCategory;
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
            'erp_asset_code' => 'A-001', 'name' => 'Asset',
            'is_active' => true, 'current_location_id' => $location->id,
        ]);
    }

    /**
     * An asset carrying every identity field so the embedded Asset Identity
     * payload can be asserted in full (and the ERP code proven absent).
     */
    private function createIdentifiedAsset(): Asset
    {
        $location = Location::firstOrCreate(['name' => 'Loc', 'type' => 'building']);
        $category = MaintenanceCategory::factory()->create([
            'code' => 'MUD_MOTOR',
            'name' => 'Mud Motor',
        ]);

        return Asset::create([
            'erp_asset_code' => 'ERP-SECRET',
            'name' => 'Mud Motor Alpha',
            'asset_tag' => 'TAG-001',
            'serial_number' => 'SN-001',
            'size_inches' => '6 3/4"',
            'maintenance_category_id' => $category->id,
            'is_active' => true,
            'current_location_id' => $location->id,
        ]);
    }

    /**
     * Assert the embedded asset is the full shared Asset Identity shape and
     * never leaks the ERP asset code.
     */
    private function assertFullAssetIdentity(array $asset): void
    {
        $this->assertSame('Mud Motor Alpha', $asset['name']);
        $this->assertSame('TAG-001', $asset['asset_tag']);
        $this->assertSame('SN-001', $asset['serial_number']);
        $this->assertSame('6 3/4"', $asset['size']);
        $this->assertSame('6.75000', $asset['size_inches']);
        $this->assertSame('MUD_MOTOR', $asset['maintenance_category']['code']);
        $this->assertSame('Mud Motor', $asset['maintenance_category']['name']);
        $this->assertArrayNotHasKey('erp_asset_code', $asset);
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

    public function test_pending_mrs_embed_full_asset_identity(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $asset = $this->createIdentifiedAsset();

        MaintenanceRequest::create([
            'number' => 'MR-ID', 'asset_id' => $asset->id,
            'status' => 'pending_review', 'priority' => 'high', 'description' => 'Identity',
            'created_by' => $admin->id, 'is_preventive' => false,
        ]);

        $mrs = $this->actingAs($admin)->getJson('/api/dashboard')->json('pending_maintenance_requests');

        $this->assertCount(1, $mrs);
        $this->assertArrayHasKey('id', $mrs[0]['asset']);
        $this->assertFullAssetIdentity($mrs[0]['asset']);
    }

    public function test_open_wos_embed_full_asset_identity(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $asset = $this->createIdentifiedAsset();
        $mr = MaintenanceRequest::create([
            'number' => 'MR-WO', 'asset_id' => $asset->id,
            'status' => 'converted', 'priority' => 'high', 'description' => 'Open',
            'created_by' => $admin->id, 'is_preventive' => false,
        ]);
        WorkOrder::create([
            'number' => 'WO-ID', 'asset_id' => $asset->id,
            'maintenance_request_id' => $mr->id,
            'status' => WorkOrderStatus::OPEN, 'priority' => 'high',
        ]);

        $wos = $this->actingAs($admin)->getJson('/api/dashboard')->json('open_work_orders');

        $this->assertCount(1, $wos);
        $this->assertArrayHasKey('id', $wos[0]['asset']);
        $this->assertFullAssetIdentity($wos[0]['asset']);
    }

    public function test_overdue_pm_assignments_embed_full_asset_identity(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $asset = $this->createIdentifiedAsset();
        $rule = PmRule::create([
            'name' => 'Monthly PM', 'trigger_type' => PmTriggerType::DATE,
            'interval_days' => 30, 'is_active' => true, 'created_by' => $admin->id,
        ]);
        AssetPmAssignment::create([
            'asset_id' => $asset->id, 'pm_rule_id' => $rule->id,
            'is_active' => true, 'assigned_by' => $admin->id,
            'last_triggered_date' => now()->subDays(60),
        ]);

        $assignments = $this->actingAs($admin)->getJson('/api/dashboard')->json('overdue_pm_assignments');

        $this->assertCount(1, $assignments);
        $this->assertArrayHasKey('id', $assignments[0]['asset']);
        $this->assertFullAssetIdentity($assignments[0]['asset']);
    }

    public function test_recently_closed_wos_embed_full_asset_identity(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $asset = $this->createIdentifiedAsset();
        $mr = MaintenanceRequest::create([
            'number' => 'MR-CLOSED', 'asset_id' => $asset->id,
            'status' => 'converted', 'priority' => 'high', 'description' => 'Closed',
            'created_by' => $admin->id, 'is_preventive' => false,
        ]);
        WorkOrder::create([
            'number' => 'WO-CLOSED', 'asset_id' => $asset->id,
            'maintenance_request_id' => $mr->id,
            'status' => WorkOrderStatus::CLOSED, 'priority' => 'high',
            'closed_by_user_id' => $admin->id, 'closed_at' => now(),
        ]);

        $wos = $this->actingAs($admin)->getJson('/api/dashboard')->json('recently_closed_work_orders');

        $this->assertCount(1, $wos);
        $this->assertArrayHasKey('id', $wos[0]['asset']);
        $this->assertFullAssetIdentity($wos[0]['asset']);
    }
}
