<?php

namespace Tests\Feature\WorkOrders;

use App\Enums\RoleCode;
use App\Enums\WorkOrderStatus;
use App\Models\Asset;
use App\Models\FaSubclassTypeCode;
use App\Models\FormTemplate;
use App\Models\MaintenanceRequest;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkOrder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class UpdateWorkOrderFormFieldValueTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;
    private User $tech;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        $this->manager = User::factory()->create([
            'role_id' => Role::where('code', RoleCode::MAINTENANCE_MANAGER)->first()->id,
            'is_active' => true,
        ]);
        $this->tech = User::factory()->create([
            'role_id' => Role::where('code', RoleCode::TECHNICIAN)->first()->id,
            'is_active' => true,
        ]);
    }

    private function createUser(RoleCode $roleCode): User
    {
        return User::factory()->create([
            'role_id' => Role::where('code', $roleCode)->first()->id,
            'is_active' => true,
        ]);
    }

    private function buildAssignedFormWorkOrder(): array
    {
        $subclass = 'VAL';
        FaSubclassTypeCode::create(['fa_subclass_code' => $subclass, 'type_code' => 'ABC']);

        $template = FormTemplate::create(['name' => 'Value', 'fa_subclass_code' => $subclass, 'is_active' => true]);
        $template->fields()->create([
            'uuid' => Str::uuid()->toString(),
            'label' => 'Reading',
            'field_type' => 'numeric',
            'has_pre_post' => true,
            'is_required' => false,
            'sort_order' => 0,
        ]);

        $asset = Asset::create([
            'erp_asset_code' => 'AST-VAL-'.uniqid(),
            'name' => 'Value Asset',
            'is_active' => true,
            'fa_subclass_code' => $subclass,
        ]);

        $requester = $this->createUser(RoleCode::REQUESTER);

        $mr = MaintenanceRequest::create([
            'number' => 'MR-'.str_pad((string) (MaintenanceRequest::count() + 1), 6, '0', STR_PAD_LEFT),
            'asset_id' => $asset->id,
            'status' => 'pending_review',
            'priority' => 'medium',
            'description' => 'Values',
            'created_by' => $requester->id,
            'is_preventive' => false,
        ]);

        $this->actingAs($this->manager)->postJson("/api/maintenance-requests/{$mr->id}/approve", ['is_failure' => true])->assertOk();
        $wo = WorkOrder::where('maintenance_request_id', $mr->id)->first();

        $this->actingAs($this->manager)->postJson("/api/work-orders/{$wo->id}/assign", ['user_id' => $this->tech->id])->assertOk();
        $this->actingAs($this->tech)->postJson("/api/work-orders/{$wo->id}/start")->assertOk();

        $fieldId = $wo->fresh()->workOrderForm->fields->first()->id;

        return [$wo->fresh(), $fieldId];
    }

    public function test_assigned_technician_can_update_field_value(): void
    {
        [$wo, $fieldId] = $this->buildAssignedFormWorkOrder();

        $this->actingAs($this->tech)->patchJson("/api/work-orders/{$wo->id}/form/fields/{$fieldId}", [
            'pre_value' => '10',
            'post_value' => '20',
        ])->assertOk();

        $this->assertDatabaseHas('work_order_form_fields', [
            'id' => $fieldId,
            'pre_value' => '10',
            'post_value' => '20',
        ]);
    }

    public function test_admin_can_update_field_value(): void
    {
        [$wo, $fieldId] = $this->buildAssignedFormWorkOrder();
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);

        $this->actingAs($admin)->patchJson("/api/work-orders/{$wo->id}/form/fields/{$fieldId}", [
            'post_value' => '99',
        ])->assertOk();
    }

    public function test_manager_can_update_field_value(): void
    {
        [$wo, $fieldId] = $this->buildAssignedFormWorkOrder();

        $this->actingAs($this->manager)->patchJson("/api/work-orders/{$wo->id}/form/fields/{$fieldId}", [
            'post_value' => '88',
        ])->assertOk();
    }

    public function test_unassigned_technician_is_forbidden(): void
    {
        [$wo, $fieldId] = $this->buildAssignedFormWorkOrder();
        $otherTech = $this->createUser(RoleCode::TECHNICIAN);

        $this->actingAs($otherTech)->patchJson("/api/work-orders/{$wo->id}/form/fields/{$fieldId}", [
            'post_value' => '1',
        ])->assertForbidden();
    }

    public function test_terminal_work_order_rejects_value_updates(): void
    {
        [$wo, $fieldId] = $this->buildAssignedFormWorkOrder();

        // Close the WO (terminal) so updateExecution returns false for everyone.
        $this->actingAs($this->tech)->postJson("/api/work-orders/{$wo->id}/complete", ['completion_notes' => 'Done'])->assertOk();
        $this->actingAs($this->manager)->postJson("/api/work-orders/{$wo->id}/close")->assertOk();

        $this->assertEquals(WorkOrderStatus::CLOSED, $wo->fresh()->status);

        $this->actingAs($this->manager)->patchJson("/api/work-orders/{$wo->id}/form/fields/{$fieldId}", [
            'post_value' => '1',
        ])->assertForbidden();
    }
}
