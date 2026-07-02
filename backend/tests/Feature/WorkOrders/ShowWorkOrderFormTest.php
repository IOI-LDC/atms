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

class ShowWorkOrderFormTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        $this->admin = User::factory()->create([
            'role_id' => Role::where('code', RoleCode::ADMINISTRATOR)->first()->id,
            'is_active' => true,
        ]);
        $this->manager = User::factory()->create([
            'role_id' => Role::where('code', RoleCode::MAINTENANCE_MANAGER)->first()->id,
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

    private function buildFormWorkOrder(string $subclass): WorkOrder
    {
        FaSubclassTypeCode::create(['fa_subclass_code' => $subclass, 'type_code' => 'ABC']);

        $template = FormTemplate::create(['name' => 'Show', 'fa_subclass_code' => $subclass, 'is_active' => true]);
        $template->fields()->create([
            'uuid' => Str::uuid()->toString(),
            'label' => 'Reading',
            'field_type' => 'numeric',
            'has_pre_post' => false,
            'is_required' => false,
            'sort_order' => 0,
        ]);

        $asset = Asset::create([
            'erp_asset_code' => 'AST-SHOW-'.uniqid(),
            'name' => 'Show Asset',
            'is_active' => true,
            'fa_subclass_code' => $subclass,
        ]);

        $requester = $this->createUser(RoleCode::REQUESTER);

        $mr = MaintenanceRequest::create([
            'number' => 'MR-'.str_pad((string) (MaintenanceRequest::count() + 1), 6, '0', STR_PAD_LEFT),
            'asset_id' => $asset->id,
            'type' => 'corrective',
            'status' => 'pending_review',
            'priority' => 'medium',
            'description' => 'Show',
            'created_by' => $requester->id,
            'is_preventive' => false,
        ]);

        $this->actingAs($this->manager)->postJson("/api/maintenance-requests/{$mr->id}/approve")->assertOk();

        return WorkOrder::where('maintenance_request_id', $mr->id)->first();
    }

    public function test_show_form_returns_form_with_template_is_stale_flag(): void
    {
        $wo = $this->buildFormWorkOrder('SHOW1');

        $this->actingAs($this->admin)->getJson("/api/work-orders/{$wo->id}/form")
            ->assertOk()
            ->assertJsonPath('data.work_order_id', $wo->id)
            ->assertJsonPath('data.template_is_stale', false)
            ->assertJsonStructure(['data' => ['id', 'fields', 'template']]);
    }

    public function test_show_form_reports_stale_after_template_edit(): void
    {
        $wo = $this->buildFormWorkOrder('SHOW2');

        // Advance the template's updated_at clearly past the snapshot second
        // (pgsql stores second-precision timestamps, so touch() alone can land
        // in the same second as the snapshot and read as non-stale).
        $template = $wo->workOrderForm->template;
        $template->updated_at = now()->addMinute();
        $template->save();

        $this->actingAs($this->admin)->getJson("/api/work-orders/{$wo->id}/form")
            ->assertOk()
            ->assertJsonPath('data.template_is_stale', true);
    }

    public function test_show_form_returns_404_when_no_form_attached(): void
    {
        $subclass = 'SHOW3';
        FaSubclassTypeCode::create(['fa_subclass_code' => $subclass, 'type_code' => 'ABC']);

        $asset = Asset::create([
            'erp_asset_code' => 'AST-NOF-'.uniqid(),
            'name' => 'No Form',
            'is_active' => true,
            'fa_subclass_code' => $subclass,
        ]);

        $requester = $this->createUser(RoleCode::REQUESTER);

        $mr = MaintenanceRequest::create([
            'number' => 'MR-'.str_pad((string) (MaintenanceRequest::count() + 1), 6, '0', STR_PAD_LEFT),
            'asset_id' => $asset->id,
            'type' => 'corrective',
            'status' => 'pending_review',
            'priority' => 'medium',
            'description' => 'No form',
            'created_by' => $requester->id,
            'is_preventive' => false,
        ]);

        $this->actingAs($this->manager)->postJson("/api/maintenance-requests/{$mr->id}/approve")->assertOk();
        $wo = WorkOrder::where('maintenance_request_id', $mr->id)->first();

        $this->actingAs($this->admin)->getJson("/api/work-orders/{$wo->id}/form")->assertNotFound();
    }

    public function test_requester_is_forbidden_to_view_form(): void
    {
        $wo = $this->buildFormWorkOrder('SHOW4');
        $requester = $this->createUser(RoleCode::REQUESTER);

        $this->actingAs($requester)->getJson("/api/work-orders/{$wo->id}/form")->assertForbidden();
    }

    public function test_unassigned_technician_is_forbidden(): void
    {
        $wo = $this->buildFormWorkOrder('SHOW5');
        $unassigned = $this->createUser(RoleCode::TECHNICIAN);

        $this->actingAs($unassigned)->getJson("/api/work-orders/{$wo->id}/form")->assertForbidden();
    }

    public function test_form_is_readable_on_terminal_work_order_by_admin(): void
    {
        $wo = $this->buildFormWorkOrder('SHOW6');

        $tech = $this->createUser(RoleCode::TECHNICIAN);
        $this->actingAs($this->manager)->postJson("/api/work-orders/{$wo->id}/assign", ['user_id' => $tech->id])->assertOk();
        $this->actingAs($tech)->postJson("/api/work-orders/{$wo->id}/start")->assertOk();
        $this->actingAs($tech)->postJson("/api/work-orders/{$wo->id}/complete", ['completion_notes' => 'Done'])->assertOk();
        $this->actingAs($this->manager)->postJson("/api/work-orders/{$wo->id}/close")->assertOk();

        $this->assertEquals(WorkOrderStatus::CLOSED, $wo->fresh()->status);

        // viewForm is allowed on terminal WOs for Admin/Manager (unlike updateExecution).
        $this->actingAs($this->admin)->getJson("/api/work-orders/{$wo->id}/form")->assertOk();
        $this->actingAs($this->manager)->getJson("/api/work-orders/{$wo->id}/form")->assertOk();
    }
}
