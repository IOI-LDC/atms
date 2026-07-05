<?php

namespace Tests\Feature\WorkOrders;

use App\Enums\RoleCode;
use App\Enums\WorkOrderStatus;
use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\MaintenanceRequest;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkOrder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkOrderAssetStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function createUser(RoleCode $roleCode): User
    {
        return User::factory()->create([
            'role_id' => Role::where('code', $roleCode)->first()->id,
            'is_active' => true,
        ]);
    }

    private function createAsset(): Asset
    {
        return Asset::create([
            'erp_asset_code' => 'AST-AS-'.uniqid(),
            'name' => 'Test Asset',
            'is_active' => true,
        ]);
    }

    private function createWorkOrder(WorkOrderStatus $status, ?User $assignee = null, ?Asset $asset = null): WorkOrder
    {
        $asset = $asset ?? $this->createAsset();

        $mr = MaintenanceRequest::create([
            'number' => 'MR-'.uniqid(),
            'asset_id' => $asset->id,
            'status' => 'converted',
            'priority' => 'high',
            'description' => 'Test request',
            'created_by' => $this->createUser(RoleCode::REQUESTER)->id,
            'is_preventive' => false,
        ]);

        return WorkOrder::create([
            'number' => 'WO-'.uniqid(),
            'maintenance_request_id' => $mr->id,
            'asset_id' => $asset->id,
            'status' => $status,
            'priority' => 'high',
            'assigned_to_user_id' => $assignee?->id,
        ]);
    }

    public function test_assigned_technician_can_set_status_on_open_work_order(): void
    {
        $tech = $this->createUser(RoleCode::TECHNICIAN);
        $wo = $this->createWorkOrder(WorkOrderStatus::OPEN, $tech);

        $response = $this->actingAs($tech)->postJson("/api/work-orders/{$wo->id}/asset-status", [
            'operational_status' => 'under_maintenance',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.operational_status', 'under_maintenance');

        $this->assertEquals('under_maintenance', $wo->asset->fresh()->operational_status->value);
    }

    public function test_assigned_technician_can_set_status_on_in_progress_work_order(): void
    {
        $tech = $this->createUser(RoleCode::TECHNICIAN);
        $wo = $this->createWorkOrder(WorkOrderStatus::IN_PROGRESS, $tech);

        $this->actingAs($tech)->postJson("/api/work-orders/{$wo->id}/asset-status", [
            'operational_status' => 'down',
        ])->assertOk();

        $this->assertEquals('down', $wo->asset->fresh()->operational_status->value);
    }

    public function test_assigned_technician_can_set_status_on_completed_work_order(): void
    {
        $tech = $this->createUser(RoleCode::TECHNICIAN);
        $wo = $this->createWorkOrder(WorkOrderStatus::COMPLETED, $tech);

        $this->actingAs($tech)->postJson("/api/work-orders/{$wo->id}/asset-status", [
            'operational_status' => 'active',
        ])->assertOk();

        $this->assertEquals('active', $wo->asset->fresh()->operational_status->value);
    }

    public function test_unassigned_technician_cannot_set_asset_status(): void
    {
        $tech = $this->createUser(RoleCode::TECHNICIAN);
        $wo = $this->createWorkOrder(WorkOrderStatus::OPEN);

        $this->actingAs($tech)->postJson("/api/work-orders/{$wo->id}/asset-status", [
            'operational_status' => 'under_maintenance',
        ])->assertForbidden();
    }

    public function test_technician_assigned_to_different_work_order_is_forbidden(): void
    {
        $tech1 = $this->createUser(RoleCode::TECHNICIAN);
        $tech2 = $this->createUser(RoleCode::TECHNICIAN);

        $woOwnedByTech1 = $this->createWorkOrder(WorkOrderStatus::OPEN, $tech1);
        $this->createWorkOrder(WorkOrderStatus::OPEN, $tech2);

        $this->actingAs($tech2)->postJson("/api/work-orders/{$woOwnedByTech1->id}/asset-status", [
            'operational_status' => 'under_maintenance',
        ])->assertForbidden();
    }

    public function test_maintenance_manager_can_set_asset_status(): void
    {
        $tech = $this->createUser(RoleCode::TECHNICIAN);
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $wo = $this->createWorkOrder(WorkOrderStatus::OPEN, $tech);

        $this->actingAs($manager)->postJson("/api/work-orders/{$wo->id}/asset-status", [
            'operational_status' => 'down',
        ])->assertOk();

        $this->assertEquals('down', $wo->asset->fresh()->operational_status->value);
    }

    public function test_administrator_can_set_asset_status(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $wo = $this->createWorkOrder(WorkOrderStatus::OPEN);

        $this->actingAs($admin)->postJson("/api/work-orders/{$wo->id}/asset-status", [
            'operational_status' => 'inactive',
        ])->assertOk();

        $this->assertEquals('inactive', $wo->asset->fresh()->operational_status->value);
    }

    public function test_assigned_technician_cannot_set_status_on_closed_work_order(): void
    {
        $tech = $this->createUser(RoleCode::TECHNICIAN);
        $wo = $this->createWorkOrder(WorkOrderStatus::CLOSED, $tech);

        $this->actingAs($tech)->postJson("/api/work-orders/{$wo->id}/asset-status", [
            'operational_status' => 'under_maintenance',
        ])->assertStatus(409);
    }

    public function test_assigned_technician_cannot_set_status_on_cancelled_work_order(): void
    {
        $tech = $this->createUser(RoleCode::TECHNICIAN);
        $wo = $this->createWorkOrder(WorkOrderStatus::CANCELLED, $tech);

        $this->actingAs($tech)->postJson("/api/work-orders/{$wo->id}/asset-status", [
            'operational_status' => 'under_maintenance',
        ])->assertStatus(409);
    }

    public function test_invalid_operational_status_is_rejected(): void
    {
        $tech = $this->createUser(RoleCode::TECHNICIAN);
        $wo = $this->createWorkOrder(WorkOrderStatus::OPEN, $tech);

        $this->actingAs($tech)->postJson("/api/work-orders/{$wo->id}/asset-status", [
            'operational_status' => 'broken',
        ])->assertStatus(422);
    }

    public function test_missing_operational_status_is_rejected(): void
    {
        $tech = $this->createUser(RoleCode::TECHNICIAN);
        $wo = $this->createWorkOrder(WorkOrderStatus::OPEN, $tech);

        $this->actingAs($tech)->postJson("/api/work-orders/{$wo->id}/asset-status", [])
            ->assertStatus(422);
    }

    public function test_audit_log_entry_is_created_with_work_order_context(): void
    {
        $tech = $this->createUser(RoleCode::TECHNICIAN);
        $wo = $this->createWorkOrder(WorkOrderStatus::OPEN, $tech);
        $asset = $wo->asset;

        $this->actingAs($tech)->postJson("/api/work-orders/{$wo->id}/asset-status", [
            'operational_status' => 'under_maintenance',
        ])->assertOk();

        $log = AuditLog::where('event', 'asset.status_updated')
            ->where('subject_type', $asset->getMorphClass())
            ->where('subject_id', $asset->id)
            ->first();

        $this->assertNotNull($log, 'Expected an audit log entry for asset.status_updated.');
        $this->assertEquals($wo->id, $log->metadata['work_order_id'] ?? null);
    }

    public function test_only_operational_status_is_writable(): void
    {
        $tech = $this->createUser(RoleCode::TECHNICIAN);
        $wo = $this->createWorkOrder(WorkOrderStatus::OPEN, $tech);
        $asset = $wo->asset;
        $originalName = $asset->name;

        $this->actingAs($tech)->postJson("/api/work-orders/{$wo->id}/asset-status", [
            'operational_status' => 'down',
            'name' => 'Attempted Rename',
        ])->assertOk();

        $this->assertEquals('down', $asset->fresh()->operational_status->value);
        $this->assertEquals($originalName, $asset->fresh()->name);
    }
}
