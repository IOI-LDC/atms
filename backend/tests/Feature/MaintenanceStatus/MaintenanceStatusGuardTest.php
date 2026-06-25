<?php

namespace Tests\Feature\MaintenanceStatus;

use App\Enums\MaintenanceStatus;
use App\Enums\RoleCode;
use App\Enums\WorkOrderStatus;
use App\Models\Asset;
use App\Models\MaintenanceRequest;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkOrder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MaintenanceStatusGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function createUser(RoleCode $role): User
    {
        return User::factory()->create([
            'role_id' => Role::where('code', $role)->first()->id,
            'is_active' => true,
        ]);
    }

    private function createAsset(MaintenanceStatus $status = MaintenanceStatus::ACTIVE): Asset
    {
        return Asset::create([
            'erp_asset_code' => 'AST-MS-'.uniqid(),
            'name' => 'Status Test Asset',
            'maintenance_status' => $status,
            'is_active' => true,
        ]);
    }

    public function test_admin_can_change_maintenance_status(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $asset = $this->createAsset();

        $this->actingAs($admin)->patchJson("/api/assets/{$asset->id}", [
            'maintenance_status' => 'Inactive',
            'maintenance_sub_status' => 'Disposed',
        ])->assertOk();

        $asset->refresh();
        $this->assertEquals(MaintenanceStatus::INACTIVE, $asset->maintenance_status);
    }

    public function test_technician_cannot_change_maintenance_status(): void
    {
        $tech = $this->createUser(RoleCode::TECHNICIAN);
        $asset = $this->createAsset();

        $this->actingAs($tech)->patchJson("/api/assets/{$asset->id}", [
            'maintenance_status' => 'Inactive',
        ])->assertForbidden();
    }

    public function test_inactive_asset_rejects_corrective_mr_creation(): void
    {
        $requester = $this->createUser(RoleCode::REQUESTER);
        $asset = $this->createAsset(MaintenanceStatus::INACTIVE);

        $this->actingAs($requester)->postJson('/api/maintenance-requests/corrective', [
            'asset_id' => $asset->id,
            'priority' => 'high',
            'description' => 'Should fail',
        ])->assertStatus(422)
            ->assertJsonPath('message', 'Cannot create a maintenance request for an inactive asset.');
    }

    public function test_inactive_asset_rejects_wo_assignment(): void
    {
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $tech = $this->createUser(RoleCode::TECHNICIAN);
        $asset = $this->createAsset(MaintenanceStatus::INACTIVE);

        $mr = MaintenanceRequest::create([
            'number' => 'MR-'.uniqid(),
            'asset_id' => $asset->id,
            'type' => 'corrective',
            'status' => 'converted',
            'priority' => 'high',
            'description' => 'Test',
            'created_by' => $this->createUser(RoleCode::REQUESTER)->id,
            'is_preventive' => false,
        ]);

        $wo = WorkOrder::create([
            'number' => 'WO-'.uniqid(),
            'maintenance_request_id' => $mr->id,
            'asset_id' => $asset->id,
            'status' => WorkOrderStatus::OPEN,
            'priority' => 'high',
        ]);

        $this->actingAs($manager)->postJson("/api/work-orders/{$wo->id}/assign", [
            'user_id' => $tech->id,
        ])->assertStatus(409)
            ->assertJsonPath('message', 'Cannot assign a work order for an inactive asset.');
    }

    public function test_inactive_asset_rejects_mr_approval(): void
    {
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $asset = $this->createAsset(MaintenanceStatus::INACTIVE);

        $mr = MaintenanceRequest::create([
            'number' => 'MR-'.uniqid(),
            'asset_id' => $asset->id,
            'type' => 'corrective',
            'status' => 'pending_review',
            'priority' => 'high',
            'description' => 'Test',
            'created_by' => $this->createUser(RoleCode::REQUESTER)->id,
            'is_preventive' => false,
        ]);

        $this->actingAs($manager)->postJson("/api/maintenance-requests/{$mr->id}/approve")
            ->assertStatus(409)
            ->assertJsonPath('message', 'Cannot approve a maintenance request for an inactive asset.');
    }
}
