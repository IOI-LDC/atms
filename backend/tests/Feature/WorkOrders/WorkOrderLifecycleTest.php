<?php

namespace Tests\Feature\WorkOrders;

use App\Enums\RoleCode;
use App\Enums\WorkOrderStatus;
use App\Models\Asset;
use App\Models\MaintenanceRequest;
use App\Models\Part;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkOrder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkOrderLifecycleTest extends TestCase
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
            'erp_asset_code' => 'AST-WO-'.uniqid(),
            'name' => 'Test Asset',
            'is_active' => true,
        ]);
    }

    private function createApprovedWorkOrder(User $requester, User $manager, ?Asset $asset = null): WorkOrder
    {
        $asset = $asset ?? $this->createAsset();

        $mr = MaintenanceRequest::create([
            'number' => 'MR-'.str_pad((string) MaintenanceRequest::count() + 1, 6, '0', STR_PAD_LEFT),
            'asset_id' => $asset->id,
            'type' => 'corrective',
            'status' => 'pending_review',
            'priority' => 'high',
            'description' => 'Test request',
            'created_by' => $requester->id,
            'is_preventive' => false,
        ]);

        $this->actingAs($manager)->postJson("/api/maintenance-requests/{$mr->id}/approve")->assertOk();

        return WorkOrder::where('maintenance_request_id', $mr->id)->first();
    }

    public function test_work_order_starts_in_open_status(): void
    {
        $requester = $this->createUser(RoleCode::REQUESTER);
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $wo = $this->createApprovedWorkOrder($requester, $manager);

        $this->assertEquals(WorkOrderStatus::OPEN, $wo->status);
        $this->assertMatchesRegularExpression('/^WO-\d{6}$/', $wo->number);
    }

    public function test_work_order_inherits_priority_snapshot(): void
    {
        $requester = $this->createUser(RoleCode::REQUESTER);
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $wo = $this->createApprovedWorkOrder($requester, $manager);

        $this->assertEquals('high', $wo->priority);
    }

    public function test_only_manager_or_admin_can_assign(): void
    {
        $requester = $this->createUser(RoleCode::REQUESTER);
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $tech = $this->createUser(RoleCode::TECHNICIAN);
        $wo = $this->createApprovedWorkOrder($requester, $manager);

        $payload = ['user_id' => $tech->id];

        $this->actingAs($tech)->postJson("/api/work-orders/{$wo->id}/assign", $payload)->assertForbidden();
        $this->actingAs($manager)->postJson("/api/work-orders/{$wo->id}/assign", $payload)->assertOk();
    }

    public function test_non_eligible_assignee_role_is_rejected(): void
    {
        $requester = $this->createUser(RoleCode::REQUESTER);
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $wo = $this->createApprovedWorkOrder($requester, $manager);

        // Requesters and other non-technical roles are never assignable.
        $this->actingAs($manager)->postJson("/api/work-orders/{$wo->id}/assign", [
            'user_id' => $requester->id,
        ])->assertStatus(409);
    }

    public function test_manager_can_be_assigned_to_work_order(): void
    {
        $requester = $this->createUser(RoleCode::REQUESTER);
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $assigneeManager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $wo = $this->createApprovedWorkOrder($requester, $manager);

        $this->actingAs($manager)->postJson("/api/work-orders/{$wo->id}/assign", [
            'user_id' => $assigneeManager->id,
        ])->assertOk();

        $this->assertEquals($assigneeManager->id, $wo->fresh()->assigned_to_user_id);
    }

    public function test_inactive_manager_cannot_be_assigned(): void
    {
        $requester = $this->createUser(RoleCode::REQUESTER);
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $inactiveManager = User::factory()->create([
            'role_id' => Role::where('code', RoleCode::MAINTENANCE_MANAGER)->first()->id,
            'is_active' => false,
        ]);
        $wo = $this->createApprovedWorkOrder($requester, $manager);

        $this->actingAs($manager)->postJson("/api/work-orders/{$wo->id}/assign", [
            'user_id' => $inactiveManager->id,
        ])->assertStatus(409);
    }

    public function test_assigned_manager_can_start_and_complete_work_order(): void
    {
        $requester = $this->createUser(RoleCode::REQUESTER);
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $wo = $this->createApprovedWorkOrder($requester, $manager);

        $this->actingAs($manager)->postJson("/api/work-orders/{$wo->id}/assign", [
            'user_id' => $manager->id,
        ])->assertOk();

        // An assigned manager must be able to drive the whole lifecycle.
        $this->actingAs($manager)->postJson("/api/work-orders/{$wo->id}/start")->assertOk();
        $this->assertEquals(WorkOrderStatus::IN_PROGRESS, $wo->fresh()->status);

        $this->actingAs($manager)->postJson("/api/work-orders/{$wo->id}/complete", [
            'completion_notes' => 'Handled by manager',
        ])->assertOk();
        $this->assertEquals(WorkOrderStatus::COMPLETED, $wo->fresh()->status);
    }

    public function test_assignment_required_before_start(): void
    {
        $requester = $this->createUser(RoleCode::REQUESTER);
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $wo = $this->createApprovedWorkOrder($requester, $manager);

        $this->actingAs($manager)->postJson("/api/work-orders/{$wo->id}/start")
            ->assertStatus(409);
    }

    public function test_start_transitions_to_in_progress(): void
    {
        $requester = $this->createUser(RoleCode::REQUESTER);
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $tech = $this->createUser(RoleCode::TECHNICIAN);
        $wo = $this->createApprovedWorkOrder($requester, $manager);

        $this->actingAs($manager)->postJson("/api/work-orders/{$wo->id}/assign", [
            'user_id' => $tech->id,
        ])->assertOk();

        $this->actingAs($tech)->postJson("/api/work-orders/{$wo->id}/start")->assertOk();

        $this->assertEquals(WorkOrderStatus::IN_PROGRESS, $wo->fresh()->status);
    }

    public function test_assigned_technician_can_edit_execution_details(): void
    {
        $requester = $this->createUser(RoleCode::REQUESTER);
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $tech = $this->createUser(RoleCode::TECHNICIAN);
        $wo = $this->createApprovedWorkOrder($requester, $manager);

        $this->actingAs($manager)->postJson("/api/work-orders/{$wo->id}/assign", [
            'user_id' => $tech->id,
        ])->assertOk();

        $this->actingAs($tech)->postJson("/api/work-orders/{$wo->id}/start")->assertOk();

        $this->actingAs($tech)->patchJson("/api/work-orders/{$wo->id}", [
            'description' => 'Updated execution notes',
        ])->assertOk();

        $this->assertEquals('Updated execution notes', $wo->fresh()->description);
    }

    public function test_unassigned_technician_cannot_edit(): void
    {
        $requester = $this->createUser(RoleCode::REQUESTER);
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $tech1 = $this->createUser(RoleCode::TECHNICIAN);
        $tech2 = $this->createUser(RoleCode::TECHNICIAN);
        $wo = $this->createApprovedWorkOrder($requester, $manager);

        $this->actingAs($manager)->postJson("/api/work-orders/{$wo->id}/assign", [
            'user_id' => $tech1->id,
        ])->assertOk();

        $this->actingAs($tech1)->postJson("/api/work-orders/{$wo->id}/start")->assertOk();

        $this->actingAs($tech2)->patchJson("/api/work-orders/{$wo->id}", [
            'description' => 'Unauthorized edit',
        ])->assertForbidden();
    }

    public function test_manager_can_edit_non_terminal_execution_details(): void
    {
        $requester = $this->createUser(RoleCode::REQUESTER);
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $tech = $this->createUser(RoleCode::TECHNICIAN);
        $wo = $this->createApprovedWorkOrder($requester, $manager);

        $this->actingAs($manager)->postJson("/api/work-orders/{$wo->id}/assign", [
            'user_id' => $tech->id,
        ])->assertOk();

        $this->actingAs($tech)->postJson("/api/work-orders/{$wo->id}/start")->assertOk();

        $this->actingAs($manager)->patchJson("/api/work-orders/{$wo->id}", [
            'description' => 'Manager updated notes',
        ])->assertOk();
    }

    public function test_assigned_technician_completes_work_order(): void
    {
        $requester = $this->createUser(RoleCode::REQUESTER);
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $tech = $this->createUser(RoleCode::TECHNICIAN);
        $wo = $this->createApprovedWorkOrder($requester, $manager);

        $this->actingAs($manager)->postJson("/api/work-orders/{$wo->id}/assign", [
            'user_id' => $tech->id,
        ])->assertOk();

        $this->actingAs($tech)->postJson("/api/work-orders/{$wo->id}/start")->assertOk();

        $this->actingAs($tech)->postJson("/api/work-orders/{$wo->id}/complete", [
            'completion_notes' => 'Repair completed successfully',
        ])->assertOk();

        $wo = $wo->fresh();
        $this->assertEquals(WorkOrderStatus::COMPLETED, $wo->status);
        $this->assertNotNull($wo->completed_at);
        $this->assertEquals($tech->id, $wo->completed_by_user_id);
    }

    public function test_completion_locks_technician_edits(): void
    {
        $requester = $this->createUser(RoleCode::REQUESTER);
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $tech = $this->createUser(RoleCode::TECHNICIAN);
        $wo = $this->createApprovedWorkOrder($requester, $manager);

        $this->actingAs($manager)->postJson("/api/work-orders/{$wo->id}/assign", ['user_id' => $tech->id])->assertOk();
        $this->actingAs($tech)->postJson("/api/work-orders/{$wo->id}/start")->assertOk();
        $this->actingAs($tech)->postJson("/api/work-orders/{$wo->id}/complete", [
            'completion_notes' => 'Done',
        ])->assertOk();

        $this->actingAs($tech)->patchJson("/api/work-orders/{$wo->id}", [
            'description' => 'Attempt after completion',
        ])->assertForbidden();
    }

    public function test_manager_closes_completed_work_order(): void
    {
        $requester = $this->createUser(RoleCode::REQUESTER);
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $tech = $this->createUser(RoleCode::TECHNICIAN);
        $wo = $this->createApprovedWorkOrder($requester, $manager);

        $this->actingAs($manager)->postJson("/api/work-orders/{$wo->id}/assign", ['user_id' => $tech->id])->assertOk();
        $this->actingAs($tech)->postJson("/api/work-orders/{$wo->id}/start")->assertOk();
        $this->actingAs($tech)->postJson("/api/work-orders/{$wo->id}/complete", [
            'completion_notes' => 'Done',
        ])->assertOk();

        $this->actingAs($manager)->postJson("/api/work-orders/{$wo->id}/close")->assertOk();

        $wo = $wo->fresh();
        $this->assertEquals(WorkOrderStatus::CLOSED, $wo->status);
        $this->assertNotNull($wo->closed_at);
        $this->assertEquals($manager->id, $wo->closed_by_user_id);
    }

    public function test_closed_work_order_rejects_every_mutation(): void
    {
        $requester = $this->createUser(RoleCode::REQUESTER);
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $tech = $this->createUser(RoleCode::TECHNICIAN);
        $wo = $this->createApprovedWorkOrder($requester, $manager);

        $this->actingAs($manager)->postJson("/api/work-orders/{$wo->id}/assign", ['user_id' => $tech->id])->assertOk();
        $this->actingAs($tech)->postJson("/api/work-orders/{$wo->id}/start")->assertOk();
        $this->actingAs($tech)->postJson("/api/work-orders/{$wo->id}/complete", ['completion_notes' => 'Done'])->assertOk();
        $this->actingAs($manager)->postJson("/api/work-orders/{$wo->id}/close")->assertOk();

        $this->actingAs($manager)->patchJson("/api/work-orders/{$wo->id}", ['description' => 'x'])->assertForbidden();
        $this->actingAs($manager)->postJson("/api/work-orders/{$wo->id}/start")->assertStatus(409);
        $this->actingAs($manager)->postJson("/api/work-orders/{$wo->id}/complete", ['completion_notes' => 'x'])->assertStatus(409);
    }

    public function test_manager_cancels_open_work_order_with_reason(): void
    {
        $requester = $this->createUser(RoleCode::REQUESTER);
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $wo = $this->createApprovedWorkOrder($requester, $manager);

        $this->actingAs($manager)->postJson("/api/work-orders/{$wo->id}/cancel", [
            'reason' => 'No longer needed',
        ])->assertOk();

        $wo = $wo->fresh();
        $this->assertEquals(WorkOrderStatus::CANCELLED, $wo->status);
        $this->assertEquals('No longer needed', $wo->cancellation_reason);
    }

    public function test_manager_cancels_in_progress_work_order(): void
    {
        $requester = $this->createUser(RoleCode::REQUESTER);
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $tech = $this->createUser(RoleCode::TECHNICIAN);
        $wo = $this->createApprovedWorkOrder($requester, $manager);

        $this->actingAs($manager)->postJson("/api/work-orders/{$wo->id}/assign", ['user_id' => $tech->id])->assertOk();
        $this->actingAs($tech)->postJson("/api/work-orders/{$wo->id}/start")->assertOk();

        $this->actingAs($manager)->postJson("/api/work-orders/{$wo->id}/cancel", [
            'reason' => 'Resource unavailable',
        ])->assertOk();
    }

    public function test_technician_cannot_cancel_work_order(): void
    {
        $requester = $this->createUser(RoleCode::REQUESTER);
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $tech = $this->createUser(RoleCode::TECHNICIAN);
        $wo = $this->createApprovedWorkOrder($requester, $manager);

        $this->actingAs($tech)->postJson("/api/work-orders/{$wo->id}/cancel", [
            'reason' => 'Attempt',
        ])->assertForbidden();
    }

    public function test_cancelled_work_order_is_terminal(): void
    {
        $requester = $this->createUser(RoleCode::REQUESTER);
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $tech = $this->createUser(RoleCode::TECHNICIAN);
        $wo = $this->createApprovedWorkOrder($requester, $manager);

        $this->actingAs($manager)->postJson("/api/work-orders/{$wo->id}/cancel", [
            'reason' => 'Cancelled',
        ])->assertOk();

        $this->actingAs($manager)->postJson("/api/work-orders/{$wo->id}/start")->assertStatus(409);
        $this->actingAs($manager)->postJson("/api/work-orders/{$wo->id}/complete", ['completion_notes' => 'x'])->assertStatus(409);
        $this->actingAs($manager)->postJson("/api/work-orders/{$wo->id}/close")->assertStatus(409);
    }

    public function test_cancellation_requires_reason(): void
    {
        $requester = $this->createUser(RoleCode::REQUESTER);
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $wo = $this->createApprovedWorkOrder($requester, $manager);

        $this->actingAs($manager)->postJson("/api/work-orders/{$wo->id}/cancel")
            ->assertStatus(422);
    }

    public function test_cannot_close_already_closed(): void
    {
        $requester = $this->createUser(RoleCode::REQUESTER);
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $tech = $this->createUser(RoleCode::TECHNICIAN);
        $wo = $this->createApprovedWorkOrder($requester, $manager);

        $this->actingAs($manager)->postJson("/api/work-orders/{$wo->id}/assign", ['user_id' => $tech->id])->assertOk();
        $this->actingAs($tech)->postJson("/api/work-orders/{$wo->id}/start")->assertOk();
        $this->actingAs($tech)->postJson("/api/work-orders/{$wo->id}/complete", ['completion_notes' => 'Done'])->assertOk();
        $this->actingAs($manager)->postJson("/api/work-orders/{$wo->id}/close")->assertOk();

        $this->actingAs($manager)->postJson("/api/work-orders/{$wo->id}/close")
            ->assertStatus(409);
    }

    public function test_cannot_reassign_inactive_technician(): void
    {
        $requester = $this->createUser(RoleCode::REQUESTER);
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $inactiveTech = User::factory()->create([
            'role_id' => Role::where('code', RoleCode::TECHNICIAN)->first()->id,
            'is_active' => false,
        ]);
        $wo = $this->createApprovedWorkOrder($requester, $manager);

        $this->actingAs($manager)->postJson("/api/work-orders/{$wo->id}/assign", [
            'user_id' => $inactiveTech->id,
        ])->assertStatus(409);
    }

    public function test_work_orders_list_endpoint(): void
    {
        $requester = $this->createUser(RoleCode::REQUESTER);
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $wo = $this->createApprovedWorkOrder($requester, $manager);

        $this->actingAs($manager)->getJson('/api/work-orders')
            ->assertOk()
            ->assertJsonStructure(['data']);
    }

    public function test_work_order_show_endpoint(): void
    {
        $requester = $this->createUser(RoleCode::REQUESTER);
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $wo = $this->createApprovedWorkOrder($requester, $manager);

        $this->actingAs($manager)->getJson("/api/work-orders/{$wo->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $wo->id);
    }

    public function test_assigned_technician_can_add_part(): void
    {
        $requester = $this->createUser(RoleCode::REQUESTER);
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $tech = $this->createUser(RoleCode::TECHNICIAN);
        $wo = $this->createApprovedWorkOrder($requester, $manager);

        $part = Part::create([
            'erp_part_id' => 'PART-'.uniqid(),
            'erp_part_code' => 'P-001',
            'name' => 'Filter Element',
            'is_active' => true,
        ]);

        $this->actingAs($manager)->postJson("/api/work-orders/{$wo->id}/assign", ['user_id' => $tech->id])->assertOk();
        $this->actingAs($tech)->postJson("/api/work-orders/{$wo->id}/start")->assertOk();

        $this->actingAs($tech)->postJson("/api/work-orders/{$wo->id}/parts", [
            'part_id' => $part->id,
            'quantity' => 2,
            'notes' => 'Replaced during service',
        ])->assertCreated();

        $this->assertDatabaseHas('work_order_parts', [
            'work_order_id' => $wo->id,
            'part_id' => $part->id,
            'quantity' => 2,
            'added_by_user_id' => $tech->id,
        ]);
    }

    public function test_part_cannot_be_added_to_completed_work_order(): void
    {
        $requester = $this->createUser(RoleCode::REQUESTER);
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $tech = $this->createUser(RoleCode::TECHNICIAN);
        $wo = $this->createApprovedWorkOrder($requester, $manager);

        $part = Part::create([
            'erp_part_id' => 'PART-'.uniqid(),
            'erp_part_code' => 'P-002',
            'name' => 'Bearing Kit',
            'is_active' => true,
        ]);

        $this->actingAs($manager)->postJson("/api/work-orders/{$wo->id}/assign", ['user_id' => $tech->id])->assertOk();
        $this->actingAs($tech)->postJson("/api/work-orders/{$wo->id}/start")->assertOk();
        $this->actingAs($tech)->postJson("/api/work-orders/{$wo->id}/complete", ['completion_notes' => 'Done'])->assertOk();

        $this->actingAs($tech)->postJson("/api/work-orders/{$wo->id}/parts", [
            'part_id' => $part->id,
            'quantity' => 1,
        ])->assertForbidden();
    }

    public function test_assigned_technician_can_remove_part(): void
    {
        $requester = $this->createUser(RoleCode::REQUESTER);
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $tech = $this->createUser(RoleCode::TECHNICIAN);
        $wo = $this->createApprovedWorkOrder($requester, $manager);

        $part = Part::create([
            'erp_part_id' => 'PART-'.uniqid(),
            'erp_part_code' => 'P-003',
            'name' => 'Seal Ring',
            'is_active' => true,
        ]);

        $this->actingAs($manager)->postJson("/api/work-orders/{$wo->id}/assign", ['user_id' => $tech->id])->assertOk();
        $this->actingAs($tech)->postJson("/api/work-orders/{$wo->id}/start")->assertOk();

        $response = $this->actingAs($tech)->postJson("/api/work-orders/{$wo->id}/parts", [
            'part_id' => $part->id,
            'quantity' => 1,
        ]);
        $response->assertCreated();
        $partLineId = $response->json('data.id');

        $this->actingAs($tech)->deleteJson("/api/work-orders/{$wo->id}/parts/{$partLineId}")
            ->assertOk();

        $this->assertDatabaseMissing('work_order_parts', ['id' => $partLineId]);
    }

    public function test_parts_are_shown_in_work_order_show(): void
    {
        $requester = $this->createUser(RoleCode::REQUESTER);
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $tech = $this->createUser(RoleCode::TECHNICIAN);
        $wo = $this->createApprovedWorkOrder($requester, $manager);

        $part = Part::create([
            'erp_part_id' => 'PART-'.uniqid(),
            'erp_part_code' => 'P-004',
            'name' => 'Gasket',
            'is_active' => true,
        ]);

        $this->actingAs($manager)->postJson("/api/work-orders/{$wo->id}/assign", ['user_id' => $tech->id])->assertOk();
        $this->actingAs($tech)->postJson("/api/work-orders/{$wo->id}/start")->assertOk();
        $this->actingAs($tech)->postJson("/api/work-orders/{$wo->id}/parts", [
            'part_id' => $part->id,
            'quantity' => 3,
        ])->assertCreated();

        $response = $this->actingAs($manager)->getJson("/api/work-orders/{$wo->id}");
        $response->assertOk();
        $this->assertCount(1, $response->json('data.parts'));
    }

    public function test_double_complete_returns_conflict(): void
    {
        $requester = $this->createUser(RoleCode::REQUESTER);
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $tech = $this->createUser(RoleCode::TECHNICIAN);
        $wo = $this->createApprovedWorkOrder($requester, $manager);

        $this->actingAs($manager)->postJson("/api/work-orders/{$wo->id}/assign", ['user_id' => $tech->id])->assertOk();
        $this->actingAs($tech)->postJson("/api/work-orders/{$wo->id}/start")->assertOk();
        $this->actingAs($tech)->postJson("/api/work-orders/{$wo->id}/complete", ['completion_notes' => 'Done'])->assertOk();

        $this->actingAs($tech)->postJson("/api/work-orders/{$wo->id}/complete", ['completion_notes' => 'Again'])
            ->assertStatus(409);
    }

    public function test_unassigned_technician_cannot_complete(): void
    {
        $requester = $this->createUser(RoleCode::REQUESTER);
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $tech1 = $this->createUser(RoleCode::TECHNICIAN);
        $tech2 = $this->createUser(RoleCode::TECHNICIAN);
        $wo = $this->createApprovedWorkOrder($requester, $manager);

        $this->actingAs($manager)->postJson("/api/work-orders/{$wo->id}/assign", ['user_id' => $tech1->id])->assertOk();
        $this->actingAs($tech1)->postJson("/api/work-orders/{$wo->id}/start")->assertOk();

        $this->actingAs($tech2)->postJson("/api/work-orders/{$wo->id}/complete", ['completion_notes' => 'Hijack'])
            ->assertForbidden();
    }
}
