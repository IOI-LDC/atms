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

class SyncWorkOrderFormTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

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

    private function buildSyncScenario(string $subclass): array
    {
        FaSubclassTypeCode::create(['fa_subclass_code' => $subclass, 'type_code' => 'ABC']);

        $template = FormTemplate::create(['name' => 'Sync', 'fa_subclass_code' => $subclass, 'is_active' => true]);

        return [$subclass, $template];
    }

    private function createWorkOrderWithSubclass(string $subclass, User $manager): WorkOrder
    {
        $asset = Asset::create([
            'erp_asset_code' => 'AST-SYNC-'.uniqid(),
            'name' => 'Sync Asset',
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
            'description' => 'Sync',
            'created_by' => $requester->id,
            'is_preventive' => false,
        ]);

        $this->actingAs($manager)->postJson("/api/maintenance-requests/{$mr->id}/approve")->assertOk();

        return WorkOrder::where('maintenance_request_id', $mr->id)->first();
    }

    public function test_sync_preserves_matched_values_appends_new_and_drops_removed(): void
    {
        [$subclass, $template] = $this->buildSyncScenario('SYNC1');

        $uuidA = Str::uuid()->toString();
        $uuidB = Str::uuid()->toString();
        $uuidC = Str::uuid()->toString();

        // Original template: fields A + B.
        $template->fields()->createMany([
            ['form_template_id' => $template->id, 'uuid' => $uuidA, 'label' => 'A', 'field_type' => 'numeric', 'has_pre_post' => false, 'is_required' => false, 'sort_order' => 0],
            ['form_template_id' => $template->id, 'uuid' => $uuidB, 'label' => 'B', 'field_type' => 'text', 'has_pre_post' => false, 'is_required' => false, 'sort_order' => 1],
        ]);

        $wo = $this->createWorkOrderWithSubclass($subclass, $this->manager);
        $form = $wo->workOrderForm;

        // Capture a value on field A (matched-by-uuid must survive sync).
        $form->fields->firstWhere('uuid', $uuidA)->update(['post_value' => '42']);

        // Latest template: keep A (matched), add C (new), drop B (removed).
        $template->fields()->where('uuid', $uuidB)->delete();
        $template->fields()->create([
            'form_template_id' => $template->id,
            'uuid' => $uuidC,
            'label' => 'C',
            'field_type' => 'boolean',
            'has_pre_post' => false,
            'is_required' => false,
            'sort_order' => 2,
        ]);

        $template->touch();

        $response = $this->actingAs($this->manager)->postJson("/api/work-orders/{$wo->id}/form/sync")
            ->assertOk();

        // Only A + C remain; B was dropped.
        $uuids = collect($response->json('data.fields'))->pluck('uuid')->all();
        $this->assertEqualsCanonicalizing([$uuidA, $uuidC], $uuids);
        $this->assertNotContains($uuidB, $uuids);

        // A's captured value survived.
        $this->assertDatabaseHas('work_order_form_fields', [
            'work_order_form_id' => $form->id,
            'uuid' => $uuidA,
            'post_value' => '42',
        ]);

        // C was appended empty.
        $this->assertDatabaseHas('work_order_form_fields', [
            'work_order_form_id' => $form->id,
            'uuid' => $uuidC,
            'post_value' => null,
        ]);

        // Sync clears any prior dismissal.
        $this->assertNull($form->fresh()->sync_dismissed_at);
    }

    public function test_defer_sync_records_dismissal_timestamp(): void
    {
        [$subclass, $template] = $this->buildSyncScenario('SYNC2');
        $template->fields()->create([
            'form_template_id' => $template->id,
            'uuid' => Str::uuid()->toString(),
            'label' => 'X',
            'field_type' => 'numeric',
            'has_pre_post' => false,
            'is_required' => false,
            'sort_order' => 0,
        ]);

        $wo = $this->createWorkOrderWithSubclass($subclass, $this->manager);

        $this->actingAs($this->manager)->postJson("/api/work-orders/{$wo->id}/form/defer-sync")
            ->assertOk();

        $this->assertNotNull($wo->workOrderForm->fresh()->sync_dismissed_at);
    }

    public function test_sync_clears_prior_dismissal(): void
    {
        [$subclass, $template] = $this->buildSyncScenario('SYNC3');
        $template->fields()->create([
            'form_template_id' => $template->id,
            'uuid' => Str::uuid()->toString(),
            'label' => 'Y',
            'field_type' => 'numeric',
            'has_pre_post' => false,
            'is_required' => false,
            'sort_order' => 0,
        ]);

        $wo = $this->createWorkOrderWithSubclass($subclass, $this->manager);

        // Defer first, then sync.
        $this->actingAs($this->manager)->postJson("/api/work-orders/{$wo->id}/form/defer-sync")->assertOk();
        $this->assertNotNull($wo->workOrderForm->fresh()->sync_dismissed_at);

        $this->actingAs($this->manager)->postJson("/api/work-orders/{$wo->id}/form/sync")->assertOk();
        $this->assertNull($wo->workOrderForm->fresh()->sync_dismissed_at);
    }

    public function test_sync_returns_409_when_no_active_template_exists_for_subclass(): void
    {
        [$subclass, $template] = $this->buildSyncScenario('SYNC4');
        $template->fields()->create([
            'form_template_id' => $template->id,
            'uuid' => Str::uuid()->toString(),
            'label' => 'Z',
            'field_type' => 'numeric',
            'has_pre_post' => false,
            'is_required' => false,
            'sort_order' => 0,
        ]);

        $wo = $this->createWorkOrderWithSubclass($subclass, $this->manager);
        $this->assertNotNull($wo->workOrderForm);

        // Deactivate the only active template for this subclass.
        $template->update(['is_active' => false]);

        $this->actingAs($this->manager)->postJson("/api/work-orders/{$wo->id}/form/sync")
            ->assertStatus(409);
    }
}
