<?php

namespace Tests\Feature\ReadModels;

use App\Enums\RoleCode;
use App\Models\Asset;
use App\Models\Location;
use App\Models\MaintenanceRequest;
use App\Models\Role;
use App\Models\User;
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
        $uniq = uniqid();
        $location = Location::create(['name' => 'Loc-'.$uniq, 'type' => 'building']);
        $asset = Asset::create([
            'erp_asset_id' => 'ERP-'.$uniq, 'erp_asset_code' => 'A-'.$uniq, 'name' => 'Asset',
            'is_active' => true, 'current_location_id' => $location->id,
        ]);

        return MaintenanceRequest::create([
            'number' => 'MR-'.$uniq, 'asset_id' => $asset->id, 'type' => 'corrective',
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
        $this->assertArrayNotHasKey('is_preventive', $data);
        $this->assertArrayNotHasKey('has_attachments', $data);
    }

    public function test_viewer_sees_no_attachments(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $viewer = $this->createUser(RoleCode::VIEWER);
        $this->createMaintenanceRequest($admin->id);

        $response = $this->actingAs($viewer)->getJson('/api/maintenance-requests');

        $response->assertStatus(200);
        $data = $response->json('data.0');
        $this->assertArrayNotHasKey('has_attachments', $data);
        $this->assertArrayHasKey('is_preventive', $data);
    }

    public function test_admin_sees_has_attachments(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $this->createMaintenanceRequest($admin->id);

        $response = $this->actingAs($admin)->getJson('/api/maintenance-requests');

        $response->assertStatus(200);
        $data = $response->json('data.0');
        $this->assertArrayHasKey('has_attachments', $data);
    }
}
