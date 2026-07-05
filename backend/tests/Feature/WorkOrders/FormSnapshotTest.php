<?php

namespace Tests\Feature\WorkOrders;

use App\Enums\RoleCode;
use App\Models\Asset;
use App\Models\FaSubclassTypeCode;
use App\Models\FormTemplate;
use App\Models\MaintenanceRequest;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkOrderForm;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class FormSnapshotTest extends TestCase
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

    private function createAssetWithSubclass(string $code): Asset
    {
        FaSubclassTypeCode::create([
            'fa_subclass_code' => $code,
            'type_code' => 'ABC',
        ]);

        return Asset::create([
            'erp_asset_code' => 'AST-SNAP-'.uniqid(),
            'name' => 'Snap Asset',
            'is_active' => true,
            'fa_subclass_code' => $code,
        ]);
    }

    private function createActiveTemplate(string $code): FormTemplate
    {
        $template = FormTemplate::create([
            'name' => 'Inspection',
            'fa_subclass_code' => $code,
            'is_active' => true,
        ]);

        $template->fields()->createMany([
            [
                'uuid' => Str::uuid()->toString(),
                'label' => 'Hours reading',
                'field_type' => 'numeric',
                'has_pre_post' => true,
                'unit' => 'hours',
                'is_required' => true,
                'sort_order' => 0,
            ],
            [
                'uuid' => Str::uuid()->toString(),
                'label' => 'Cleaned?',
                'field_type' => 'boolean',
                'has_pre_post' => false,
                'is_required' => false,
                'sort_order' => 1,
            ],
        ]);

        return $template->fresh()->load('fields');
    }

    public function test_approving_request_snapshots_active_template_into_work_order(): void
    {
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $requester = $this->createUser(RoleCode::REQUESTER);

        $asset = $this->createAssetWithSubclass('MM');
        $template = $this->createActiveTemplate('MM');

        $mr = MaintenanceRequest::create([
            'number' => 'MR-'.str_pad((string) (MaintenanceRequest::count() + 1), 6, '0', STR_PAD_LEFT),
            'asset_id' => $asset->id,
            'status' => 'pending_review',
            'priority' => 'high',
            'description' => 'Needs a form',
            'created_by' => $requester->id,
            'is_preventive' => false,
        ]);

        $this->actingAs($manager)->postJson("/api/maintenance-requests/{$mr->id}/approve")->assertOk();

        $wo = \App\Models\WorkOrder::where('maintenance_request_id', $mr->id)->first();

        // The form instance was created with a soft FK to the template.
        $this->assertDatabaseHas('work_order_forms', [
            'work_order_id' => $wo->id,
            'form_template_id' => $template->id,
        ]);

        $form = WorkOrderForm::where('work_order_id', $wo->id)->first();

        // Both fields were copied as self-contained snapshots.
        $this->assertCount(2, $form->fields);

        // Captured metadata is independent of the template (self-contained).
        $this->assertDatabaseHas('work_order_form_fields', [
            'work_order_form_id' => $form->id,
            'label' => 'Hours reading',
            'field_type' => 'numeric',
            'has_pre_post' => true,
            'unit' => 'hours',
            'is_required' => true,
        ]);

        // Captured value columns start empty.
        $this->assertNull($form->fields->first()->pre_value);
        $this->assertNull($form->fields->first()->post_value);
    }

    public function test_asset_without_active_template_creates_no_form(): void
    {
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $requester = $this->createUser(RoleCode::REQUESTER);

        // Subclass exists but has no active template.
        $asset = $this->createAssetWithSubclass('NOTPL');

        $mr = MaintenanceRequest::create([
            'number' => 'MR-'.str_pad((string) (MaintenanceRequest::count() + 1), 6, '0', STR_PAD_LEFT),
            'asset_id' => $asset->id,
            'status' => 'pending_review',
            'priority' => 'high',
            'description' => 'No form expected',
            'created_by' => $requester->id,
            'is_preventive' => false,
        ]);

        $this->actingAs($manager)->postJson("/api/maintenance-requests/{$mr->id}/approve")->assertOk();

        $wo = \App\Models\WorkOrder::where('maintenance_request_id', $mr->id)->first();

        $this->assertDatabaseMissing('work_order_forms', ['work_order_id' => $wo->id]);
    }

    public function test_form_is_embedded_in_work_order_show_for_allowed_roles(): void
    {
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $requester = $this->createUser(RoleCode::REQUESTER);

        $asset = $this->createAssetWithSubclass('EMB');
        $this->createActiveTemplate('EMB');

        $mr = MaintenanceRequest::create([
            'number' => 'MR-'.str_pad((string) (MaintenanceRequest::count() + 1), 6, '0', STR_PAD_LEFT),
            'asset_id' => $asset->id,
            'status' => 'pending_review',
            'priority' => 'high',
            'description' => 'Embedded form',
            'created_by' => $requester->id,
            'is_preventive' => false,
        ]);

        $this->actingAs($manager)->postJson("/api/maintenance-requests/{$mr->id}/approve")->assertOk();

        $wo = \App\Models\WorkOrder::where('maintenance_request_id', $mr->id)->first();

        $this->actingAs($manager)->getJson("/api/work-orders/{$wo->id}")
            ->assertOk()
            ->assertJsonStructure(['data' => ['form' => ['id', 'fields']]]);
    }
}
