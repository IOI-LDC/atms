<?php

namespace Tests\Feature\FormTemplates;

use App\Enums\RoleCode;
use App\Models\Asset;
use App\Models\FaSubclassTypeCode;
use App\Models\FormTemplate;
use App\Models\FormTemplateField;
use App\Models\MaintenanceRequest;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DeleteFormFieldSnapshotIntegrityTest extends TestCase
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

    public function test_deleting_a_template_field_keeps_captured_values_intact(): void
    {
        $subclass = 'INT';
        FaSubclassTypeCode::create(['fa_subclass_code' => $subclass, 'type_code' => 'ABC']);

        $template = FormTemplate::create(['name' => 'Integrity', 'fa_subclass_code' => $subclass, 'is_active' => true]);

        $field = FormTemplateField::create([
            'form_template_id' => $template->id,
            'uuid' => Str::uuid()->toString(),
            'label' => 'Before',
            'field_type' => 'numeric',
            'has_pre_post' => true,
            'is_required' => true,
            'sort_order' => 0,
        ]);

        $asset = Asset::create([
            'erp_asset_code' => 'AST-INT-'.uniqid(),
            'name' => 'Integrity Asset',
            'is_active' => true,
            'fa_subclass_code' => $subclass,
        ]);

        $requester = $this->createUser(RoleCode::REQUESTER);

        $mr = MaintenanceRequest::create([
            'number' => 'MR-'.str_pad((string) (MaintenanceRequest::count() + 1), 6, '0', STR_PAD_LEFT),
            'asset_id' => $asset->id,
            'status' => 'pending_review',
            'priority' => 'high',
            'description' => 'Snapshot integrity',
            'created_by' => $requester->id,
            'is_preventive' => false,
        ]);

        $this->actingAs($this->manager)->postJson("/api/maintenance-requests/{$mr->id}/approve")->assertOk();

        $wo = \App\Models\WorkOrder::where('maintenance_request_id', $mr->id)->first();
        $snapshotField = $wo->workOrderForm->fields->first();

        // Capture a value before the template field is removed.
        $snapshotField->update(['pre_value' => '50', 'post_value' => '75']);

        // Delete the template field (the soft FK nullOnDeletes the snapshot's FK).
        $this->actingAs($this->admin)
            ->deleteJson("/api/admin/wo-forms/templates/{$template->id}/fields/{$field->id}")
            ->assertOk();

        // The snapshot row survives with its captured values and copied metadata.
        $this->assertDatabaseHas('work_order_form_fields', [
            'id' => $snapshotField->id,
            'label' => 'Before',
            'field_type' => 'numeric',
            'is_required' => true,
            'pre_value' => '50',
            'post_value' => '75',
            'form_template_field_id' => null,
        ]);
    }
}
