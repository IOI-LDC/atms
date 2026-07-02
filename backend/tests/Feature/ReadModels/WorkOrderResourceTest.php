<?php

namespace Tests\Feature\ReadModels;

use App\Enums\MaintenanceRequestStatus;
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
        $user = User::factory()->create(['is_active' => true]);
        $location = Location::create(['name' => 'Loc', 'type' => 'building']);
        $asset = Asset::create([
            'erp_asset_code' => 'A-001', 'name' => 'Asset',
            'is_active' => true, 'current_location_id' => $location->id,
        ]);
        $mr = MaintenanceRequest::create([
            'number' => 'MR-001', 'asset_id' => $asset->id, 'status' => MaintenanceRequestStatus::CONVERTED,
            'priority' => 'high', 'description' => 'Test MR', 'created_by' => $user->id,
        ]);

        return WorkOrder::create([
            'number' => 'WO-001', 'maintenance_request_id' => $mr->id, 'asset_id' => $asset->id,
            'status' => WorkOrderStatus::OPEN, 'priority' => 'high', 'description' => 'Test WO',
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
        $this->assertArrayHasKey('email', $data['assigned_to']);
        $this->assertArrayHasKey('assigned_by', $data);
        $this->assertArrayHasKey('parts', $data);
        $this->assertArrayHasKey('has_attachments', $data);
        $this->assertArrayHasKey('operational_status', $data['asset']);
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
        $this->assertArrayHasKey('parts', $data);
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
        $this->assertArrayNotHasKey('has_attachments', $data);
        $this->assertArrayNotHasKey('completion_notes', $data);
        $this->assertArrayNotHasKey('started_at', $data);
        $this->assertArrayNotHasKey('closed_at', $data);
    }

    public function test_requester_sees_no_assignee(): void
    {
        $requester = $this->createUser(RoleCode::REQUESTER);
        $this->createWorkOrder();

        $response = $this->actingAs($requester)->getJson('/api/work-orders');

        $response->assertStatus(200);
        $data = $response->json('data.0');
        $this->assertArrayHasKey('assigned_to', $data);
        $this->assertArrayHasKey('parts', $data);
    }

    public function test_requester_sees_assignee_name_no_email(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $requester = $this->createUser(RoleCode::REQUESTER);
        $wo = $this->createWorkOrder();
        $wo->update(['assigned_to_user_id' => $admin->id]);

        $response = $this->actingAs($requester)->getJson('/api/work-orders');

        $response->assertStatus(200);
        $data = $response->json('data.0');
        $this->assertArrayHasKey('assigned_to', $data);
        $this->assertArrayNotHasKey('email', $data['assigned_to'] ?? []);
        $this->assertArrayHasKey('parts', $data);
    }
}
