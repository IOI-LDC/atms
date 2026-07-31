<?php

namespace Tests\Feature\WorkOrders;

use App\Enums\RoleCode;
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

class BulkUpdateWorkOrderFormFieldValuesTest extends TestCase
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

    /**
     * A started work order whose form carries three fields.
     *
     * @return array{0: WorkOrder, 1: \Illuminate\Support\Collection}
     */
    private function buildAssignedFormWorkOrder(): array
    {
        // Idempotent: a test may build two work orders, and both the subclass
        // code and its active template are unique per subclass.
        $subclass = 'BULK';
        FaSubclassTypeCode::firstOrCreate(['fa_subclass_code' => $subclass], ['type_code' => 'BLK']);

        $template = FormTemplate::firstOrCreate(
            ['name' => 'Bulk', 'fa_subclass_code' => $subclass],
            ['is_active' => true],
        );

        if ($template->fields()->count() === 0) {
            foreach (['Inlet Pressure', 'Outlet Pressure', 'Seal Intact'] as $index => $label) {
                $template->fields()->create([
                    'uuid' => Str::uuid()->toString(),
                    'label' => $label,
                    'field_type' => $index === 2 ? 'boolean' : 'numeric',
                    'has_pre_post' => $index !== 2,
                    'is_required' => false,
                    'sort_order' => $index,
                ]);
            }
        }

        $asset = Asset::create([
            'erp_asset_code' => 'AST-BULK-'.uniqid(),
            'name' => 'Bulk Asset',
            'is_active' => true,
            'fa_subclass_code' => $subclass,
        ]);

        $mr = MaintenanceRequest::create([
            'number' => 'MR-'.str_pad((string) (MaintenanceRequest::count() + 1), 6, '0', STR_PAD_LEFT),
            'asset_id' => $asset->id,
            'status' => 'pending_review',
            'priority' => 'medium',
            'description' => 'Bulk values',
            'created_by' => $this->createUser(RoleCode::REQUESTER)->id,
            'is_preventive' => false,
        ]);

        $this->actingAs($this->manager)->postJson("/api/maintenance-requests/{$mr->id}/approve", ['is_failure' => true])->assertOk();
        $wo = WorkOrder::where('maintenance_request_id', $mr->id)->first();

        $this->actingAs($this->manager)->postJson("/api/work-orders/{$wo->id}/assign", ['user_id' => $this->tech->id])->assertOk();
        $this->actingAs($this->tech)->postJson("/api/work-orders/{$wo->id}/start")->assertOk();

        return [$wo->fresh(), $wo->fresh()->workOrderForm->fields];
    }

    public function test_assigned_technician_saves_many_fields_in_one_request(): void
    {
        [$wo, $fields] = $this->buildAssignedFormWorkOrder();

        $response = $this->actingAs($this->tech)->patchJson("/api/work-orders/{$wo->id}/form/fields", [
            'fields' => [
                ['id' => $fields[0]->id, 'pre_value' => '10', 'post_value' => '20', 'notes' => 'Within range'],
                ['id' => $fields[1]->id, 'pre_value' => '30', 'post_value' => '40'],
                ['id' => $fields[2]->id, 'post_value' => '1'],
            ],
        ]);

        $response->assertOk();

        $this->assertDatabaseHas('work_order_form_fields', [
            'id' => $fields[0]->id, 'pre_value' => '10', 'post_value' => '20', 'notes' => 'Within range',
        ]);
        $this->assertDatabaseHas('work_order_form_fields', [
            'id' => $fields[1]->id, 'pre_value' => '30', 'post_value' => '40',
        ]);
        $this->assertDatabaseHas('work_order_form_fields', [
            'id' => $fields[2]->id, 'post_value' => '1',
        ]);
    }

    /** The client refreshes from the save response instead of re-fetching. */
    public function test_response_returns_the_updated_form(): void
    {
        [$wo, $fields] = $this->buildAssignedFormWorkOrder();

        $response = $this->actingAs($this->tech)->patchJson("/api/work-orders/{$wo->id}/form/fields", [
            'fields' => [['id' => $fields[0]->id, 'post_value' => '55']],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.work_order_id', $wo->id)
            ->assertJsonStructure(['data' => ['id', 'template_is_stale', 'fields']]);

        $returned = collect($response->json('data.fields'))->firstWhere('id', $fields[0]->id);
        $this->assertSame('55', $returned['post_value']);
    }

    /** An absent slot key keeps the stored value, as on the single-field PATCH. */
    public function test_absent_keys_keep_their_stored_values(): void
    {
        [$wo, $fields] = $this->buildAssignedFormWorkOrder();

        $this->actingAs($this->tech)->patchJson("/api/work-orders/{$wo->id}/form/fields", [
            'fields' => [['id' => $fields[0]->id, 'pre_value' => '10', 'post_value' => '20', 'notes' => 'Keep me']],
        ])->assertOk();

        $this->actingAs($this->tech)->patchJson("/api/work-orders/{$wo->id}/form/fields", [
            'fields' => [['id' => $fields[0]->id, 'post_value' => '99']],
        ])->assertOk();

        $this->assertDatabaseHas('work_order_form_fields', [
            'id' => $fields[0]->id, 'pre_value' => '10', 'post_value' => '99', 'notes' => 'Keep me',
        ]);
    }

    /** An explicit null clears a value — distinct from omitting the key. */
    public function test_explicit_null_clears_a_value(): void
    {
        [$wo, $fields] = $this->buildAssignedFormWorkOrder();

        $this->actingAs($this->tech)->patchJson("/api/work-orders/{$wo->id}/form/fields", [
            'fields' => [['id' => $fields[0]->id, 'notes' => 'Remove me']],
        ])->assertOk();

        $this->actingAs($this->tech)->patchJson("/api/work-orders/{$wo->id}/form/fields", [
            'fields' => [['id' => $fields[0]->id, 'notes' => null]],
        ])->assertOk();

        $this->assertDatabaseHas('work_order_form_fields', [
            'id' => $fields[0]->id, 'notes' => null,
        ]);
    }

    public function test_a_validation_failure_writes_nothing_at_all(): void
    {
        [$wo, $fields] = $this->buildAssignedFormWorkOrder();

        $this->actingAs($this->tech)->patchJson("/api/work-orders/{$wo->id}/form/fields", [
            'fields' => [
                ['id' => $fields[0]->id, 'post_value' => '20'],
                ['id' => $fields[1]->id, 'post_value' => ['not', 'a', 'string']],
            ],
        ])->assertStatus(422);

        $this->assertDatabaseHas('work_order_form_fields', [
            'id' => $fields[0]->id, 'post_value' => null,
        ]);
    }

    /** A field dropped by a template sync must not half-write the batch. */
    public function test_an_unknown_field_id_rejects_the_whole_batch(): void
    {
        [$wo, $fields] = $this->buildAssignedFormWorkOrder();

        $this->actingAs($this->tech)->patchJson("/api/work-orders/{$wo->id}/form/fields", [
            'fields' => [
                ['id' => $fields[0]->id, 'post_value' => '20'],
                ['id' => 999999, 'post_value' => '30'],
            ],
        ])->assertStatus(409);

        $this->assertDatabaseHas('work_order_form_fields', [
            'id' => $fields[0]->id, 'post_value' => null,
        ]);
    }

    /** A field belonging to a different work order's form is equally rejected. */
    public function test_a_field_from_another_work_order_is_rejected(): void
    {
        [$wo, $fields] = $this->buildAssignedFormWorkOrder();
        [, $otherFields] = $this->buildAssignedFormWorkOrder();

        $this->actingAs($this->manager)->patchJson("/api/work-orders/{$wo->id}/form/fields", [
            'fields' => [['id' => $otherFields[0]->id, 'post_value' => '20']],
        ])->assertStatus(409);

        $this->assertDatabaseHas('work_order_form_fields', [
            'id' => $otherFields[0]->id, 'post_value' => null,
        ]);
    }

    public function test_the_same_field_twice_in_one_batch_is_rejected(): void
    {
        [$wo, $fields] = $this->buildAssignedFormWorkOrder();

        $this->actingAs($this->tech)->patchJson("/api/work-orders/{$wo->id}/form/fields", [
            'fields' => [
                ['id' => $fields[0]->id, 'post_value' => '20'],
                ['id' => $fields[0]->id, 'post_value' => '30'],
            ],
        ])->assertStatus(422);
    }

    public function test_an_empty_batch_is_rejected(): void
    {
        [$wo] = $this->buildAssignedFormWorkOrder();

        $this->actingAs($this->tech)->patchJson("/api/work-orders/{$wo->id}/form/fields", [
            'fields' => [],
        ])->assertStatus(422);
    }

    public function test_one_audit_entry_is_written_per_save(): void
    {
        [$wo, $fields] = $this->buildAssignedFormWorkOrder();

        $this->actingAs($this->tech)->patchJson("/api/work-orders/{$wo->id}/form/fields", [
            'fields' => [
                ['id' => $fields[0]->id, 'post_value' => '20'],
                ['id' => $fields[1]->id, 'post_value' => '30'],
            ],
        ])->assertOk();

        $this->assertSame(1, \App\Models\AuditLog::where('event', 'work_order_form.field_values_updated')->count());
    }

    /** A save that changes nothing should not pollute the audit log. */
    public function test_a_no_op_save_writes_no_audit_entry(): void
    {
        [$wo, $fields] = $this->buildAssignedFormWorkOrder();

        $this->actingAs($this->tech)->patchJson("/api/work-orders/{$wo->id}/form/fields", [
            'fields' => [['id' => $fields[0]->id, 'post_value' => '20']],
        ])->assertOk();

        $this->actingAs($this->tech)->patchJson("/api/work-orders/{$wo->id}/form/fields", [
            'fields' => [['id' => $fields[0]->id, 'post_value' => '20']],
        ])->assertOk();

        $this->assertSame(1, \App\Models\AuditLog::where('event', 'work_order_form.field_values_updated')->count());
    }

    public function test_admin_and_manager_can_save(): void
    {
        [$wo, $fields] = $this->buildAssignedFormWorkOrder();
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);

        $this->actingAs($admin)->patchJson("/api/work-orders/{$wo->id}/form/fields", [
            'fields' => [['id' => $fields[0]->id, 'post_value' => '1']],
        ])->assertOk();

        $this->actingAs($this->manager)->patchJson("/api/work-orders/{$wo->id}/form/fields", [
            'fields' => [['id' => $fields[0]->id, 'post_value' => '2']],
        ])->assertOk();
    }

    public function test_unassigned_technician_is_forbidden(): void
    {
        [$wo, $fields] = $this->buildAssignedFormWorkOrder();
        $otherTech = $this->createUser(RoleCode::TECHNICIAN);

        $this->actingAs($otherTech)->patchJson("/api/work-orders/{$wo->id}/form/fields", [
            'fields' => [['id' => $fields[0]->id, 'post_value' => '1']],
        ])->assertForbidden();
    }

    public function test_requester_is_forbidden(): void
    {
        [$wo, $fields] = $this->buildAssignedFormWorkOrder();
        $requester = $this->createUser(RoleCode::REQUESTER);

        $this->actingAs($requester)->patchJson("/api/work-orders/{$wo->id}/form/fields", [
            'fields' => [['id' => $fields[0]->id, 'post_value' => '1']],
        ])->assertForbidden();
    }

    public function test_terminal_work_order_rejects_saves(): void
    {
        [$wo, $fields] = $this->buildAssignedFormWorkOrder();

        $this->actingAs($this->tech)->postJson("/api/work-orders/{$wo->id}/complete", ['completion_notes' => 'Done'])->assertOk();
        $this->actingAs($this->manager)->postJson("/api/work-orders/{$wo->id}/close")->assertOk();

        $this->actingAs($this->manager)->patchJson("/api/work-orders/{$wo->id}/form/fields", [
            'fields' => [['id' => $fields[0]->id, 'post_value' => '1']],
        ])->assertForbidden();
    }

    /** Matches the single-field rule: the assignee loses write access at completed. */
    public function test_assigned_technician_cannot_save_after_completion(): void
    {
        [$wo, $fields] = $this->buildAssignedFormWorkOrder();

        $this->actingAs($this->tech)->postJson("/api/work-orders/{$wo->id}/complete", ['completion_notes' => 'Done'])->assertOk();

        $this->actingAs($this->tech)->patchJson("/api/work-orders/{$wo->id}/form/fields", [
            'fields' => [['id' => $fields[0]->id, 'post_value' => '1']],
        ])->assertForbidden();
    }
}
