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

class WorkOrderFormCompletionGateTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $manager;
    private User $tech;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        $this->admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $this->manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $this->tech = $this->createUser(RoleCode::TECHNICIAN);
    }

    private function createUser(RoleCode $roleCode): User
    {
        return User::factory()->create([
            'role_id' => Role::where('code', $roleCode)->first()->id,
            'is_active' => true,
        ]);
    }

    private function buildFormWorkOrder(string $subclass = 'GATE'): WorkOrder
    {
        FaSubclassTypeCode::create(['fa_subclass_code' => $subclass, 'type_code' => 'ABC']);

        $template = FormTemplate::create([
            'name' => 'Gated form',
            'fa_subclass_code' => $subclass,
            'is_active' => true,
        ]);

        // A required pre/post numeric field plus a required single boolean field.
        $template->fields()->createMany([
            [
                'uuid' => Str::uuid()->toString(),
                'label' => 'Hours reading',
                'field_type' => 'numeric',
                'has_pre_post' => true,
                'is_required' => true,
                'sort_order' => 0,
            ],
            [
                'uuid' => Str::uuid()->toString(),
                'label' => 'Cleaned?',
                'field_type' => 'boolean',
                'has_pre_post' => false,
                'is_required' => true,
                'sort_order' => 1,
            ],
        ]);

        $asset = Asset::create([
            'erp_asset_code' => 'AST-GATE-'.uniqid(),
            'name' => 'Gated Asset',
            'is_active' => true,
            'fa_subclass_code' => $subclass,
        ]);

        $requester = $this->createUser(RoleCode::REQUESTER);

        $mr = MaintenanceRequest::create([
            'number' => 'MR-'.str_pad((string) (MaintenanceRequest::count() + 1), 6, '0', STR_PAD_LEFT),
            'asset_id' => $asset->id,
            'status' => 'pending_review',
            'priority' => 'high',
            'description' => 'Gated',
            'created_by' => $requester->id,
            'is_preventive' => false,
        ]);

        $this->actingAs($this->manager)->postJson("/api/maintenance-requests/{$mr->id}/approve", ['is_failure' => true])->assertOk();

        $wo = WorkOrder::where('maintenance_request_id', $mr->id)->first();

        $this->actingAs($this->manager)->postJson("/api/work-orders/{$wo->id}/assign", ['user_id' => $this->tech->id])->assertOk();
        $this->actingAs($this->tech)->postJson("/api/work-orders/{$wo->id}/start")->assertOk();

        return $wo->fresh();
    }

    public function test_completion_blocked_with_422_when_required_fields_empty(): void
    {
        $wo = $this->buildFormWorkOrder();

        $response = $this->actingAs($this->tech)->postJson("/api/work-orders/{$wo->id}/complete", [
            'completion_notes' => 'Trying to finish',
        ]);

        // Must be 422 (not 409), carrying the missing-field list.
        $response->assertStatus(422)
            ->assertJsonPath('message', 'Required WO Form fields are unfilled.')
            ->assertJsonStructure(['missing']);

        $missing = collect($response->json('missing'));

        // The pre/post field reports both slots; the boolean field reports post.
        $this->assertTrue($missing->contains(fn ($m) => in_array('pre', $m['missing']) && in_array('post', $m['missing'])));
        $this->assertTrue($missing->contains(fn ($m) => in_array('post', $m['missing']) && ! in_array('pre', $m['missing'])));

        // The WO remains in_progress.
        $this->assertEquals(WorkOrderStatus::IN_PROGRESS, $wo->fresh()->status);
    }

    public function test_completion_succeeds_after_required_fields_filled(): void
    {
        $wo = $this->buildFormWorkOrder();
        $form = $wo->fresh()->load('workOrderForm.fields')->workOrderForm;

        $numeric = $form->fields->firstWhere('label', 'Hours reading');
        $boolean = $form->fields->firstWhere('label', 'Cleaned?');

        $this->actingAs($this->tech)->patchJson("/api/work-orders/{$wo->id}/form/fields/{$numeric->id}", [
            'pre_value' => '100',
            'post_value' => '120',
        ])->assertOk();

        $this->actingAs($this->tech)->patchJson("/api/work-orders/{$wo->id}/form/fields/{$boolean->id}", [
            'post_value' => '1',
        ])->assertOk();

        $this->actingAs($this->tech)->postJson("/api/work-orders/{$wo->id}/complete", [
            'completion_notes' => 'All filled',
        ])->assertOk();

        $this->assertEquals(WorkOrderStatus::COMPLETED, $wo->fresh()->status);
    }

    public function test_work_order_without_form_completes_normally(): void
    {
        // Subclass with no active template -> no form -> gate does not apply.
        $subclass = 'NOFORM';
        FaSubclassTypeCode::create(['fa_subclass_code' => $subclass, 'type_code' => 'ABC']);

        $asset = Asset::create([
            'erp_asset_code' => 'AST-NF-'.uniqid(),
            'name' => 'Formless',
            'is_active' => true,
            'fa_subclass_code' => $subclass,
        ]);

        $requester = $this->createUser(RoleCode::REQUESTER);

        $mr = MaintenanceRequest::create([
            'number' => 'MR-'.str_pad((string) (MaintenanceRequest::count() + 1), 6, '0', STR_PAD_LEFT),
            'asset_id' => $asset->id,
            'status' => 'pending_review',
            'priority' => 'medium',
            'description' => 'No form',
            'created_by' => $requester->id,
            'is_preventive' => false,
        ]);

        $this->actingAs($this->manager)->postJson("/api/maintenance-requests/{$mr->id}/approve", ['is_failure' => true])->assertOk();
        $wo = WorkOrder::where('maintenance_request_id', $mr->id)->first();

        $this->actingAs($this->manager)->postJson("/api/work-orders/{$wo->id}/assign", ['user_id' => $this->tech->id])->assertOk();
        $this->actingAs($this->tech)->postJson("/api/work-orders/{$wo->id}/start")->assertOk();
        $this->actingAs($this->tech)->postJson("/api/work-orders/{$wo->id}/complete", ['completion_notes' => 'Done'])->assertOk();

        $this->assertEquals(WorkOrderStatus::COMPLETED, $wo->fresh()->status);
    }
}
