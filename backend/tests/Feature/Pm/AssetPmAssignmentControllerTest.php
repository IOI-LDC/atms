<?php

namespace Tests\Feature\Pm;

use App\Enums\PmTriggerType;
use App\Enums\RoleCode;
use App\Models\Asset;
use App\Models\AssetMeterReading;
use App\Models\AssetPmAssignment;
use App\Models\MaintenanceRequest;
use App\Models\PmRule;
use App\Models\Role;
use App\Models\UsageReadingType;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssetPmAssignmentControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->admin = User::factory()->create([
            'role_id' => Role::where('code', RoleCode::ADMINISTRATOR)->first()->id,
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

    private function createAsset(): Asset
    {
        return Asset::create([
            'erp_asset_code' => 'AST-'.uniqid(),
            'name' => 'Asset',
            'is_active' => true,
        ]);
    }

    private function createRule(array $overrides = []): PmRule
    {
        return PmRule::create(array_merge([
            'name' => 'Monthly PM',
            'trigger_type' => PmTriggerType::DATE,
            'interval_days' => 30,
            'is_active' => true,
            'created_by' => $this->admin->id,
        ], $overrides));
    }

    public function test_manager_can_assign_template_to_asset(): void
    {
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $asset = $this->createAsset();
        $rule = $this->createRule();

        $response = $this->actingAs($manager)->postJson("/api/assets/{$asset->id}/pm-assignments", [
            'pm_rule_id' => $rule->id,
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('asset_pm_assignments', [
            'asset_id' => $asset->id,
            'pm_rule_id' => $rule->id,
            'is_active' => true,
        ]);
    }

    public function test_assignment_initial_baseline_set_on_create(): void
    {
        $asset = $this->createAsset();
        $rule = $this->createRule();

        $response = $this->actingAs($this->admin)->postJson("/api/assets/{$asset->id}/pm-assignments", [
            'pm_rule_id' => $rule->id,
        ]);

        $response->assertCreated();
        $this->assertSame(now()->toDateString(), $response->json('data.last_triggered_date'));
    }

    public function test_assignment_initial_reading_baseline_set_from_latest_confirmed(): void
    {
        $asset = $this->createAsset();
        $readingType = UsageReadingType::create(['name' => 'Hours', 'unit' => 'h']);
        AssetMeterReading::create([
            'asset_id' => $asset->id,
            'usage_reading_type_id' => $readingType->id,
            'reading_value' => 4200,
            'reading_at' => now(),
            'source' => 'manual',
            'entered_by_user_id' => $this->admin->id,
            'confirmed_by_user_id' => $this->admin->id,
            'confirmed_at' => now(),
        ]);
        $rule = $this->createRule([
            'trigger_type' => PmTriggerType::READING,
            'interval_reading' => 500,
            'usage_reading_type_id' => $readingType->id,
        ]);

        $response = $this->actingAs($this->admin)->postJson("/api/assets/{$asset->id}/pm-assignments", [
            'pm_rule_id' => $rule->id,
        ]);

        $response->assertCreated();
        $this->assertEquals(4200, (float) $response->json('data.last_triggered_reading'));
    }

    public function test_cannot_assign_same_template_twice_to_same_asset(): void
    {
        $asset = $this->createAsset();
        $rule = $this->createRule();

        $this->actingAs($this->admin)->postJson("/api/assets/{$asset->id}/pm-assignments", ['pm_rule_id' => $rule->id])->assertCreated();
        $this->actingAs($this->admin)->postJson("/api/assets/{$asset->id}/pm-assignments", ['pm_rule_id' => $rule->id])->assertStatus(409);
    }

    public function test_cannot_assign_inactive_template(): void
    {
        $asset = $this->createAsset();
        $rule = $this->createRule(['is_active' => false]);

        $this->actingAs($this->admin)->postJson("/api/assets/{$asset->id}/pm-assignments", ['pm_rule_id' => $rule->id])
            ->assertStatus(422);
    }

    public function test_list_assignments_for_asset(): void
    {
        $asset = $this->createAsset();
        $rule = $this->createRule();
        AssetPmAssignment::create(['asset_id' => $asset->id, 'pm_rule_id' => $rule->id, 'is_active' => true, 'assigned_by' => $this->admin->id]);

        $response = $this->actingAs($this->admin)->getJson("/api/assets/{$asset->id}/pm-assignments");

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    public function test_list_returns_active_only_by_default_with_inactive_toggle(): void
    {
        $asset = $this->createAsset();
        $rule = $this->createRule();
        AssetPmAssignment::create(['asset_id' => $asset->id, 'pm_rule_id' => $rule->id, 'is_active' => true, 'assigned_by' => $this->admin->id]);
        AssetPmAssignment::create(['asset_id' => $asset->id, 'pm_rule_id' => $this->createRule(['name' => 'R2'])->id, 'is_active' => false, 'assigned_by' => $this->admin->id]);

        $active = $this->actingAs($this->admin)->getJson("/api/assets/{$asset->id}/pm-assignments")->json('data');
        $this->assertCount(1, $active);

        $all = $this->actingAs($this->admin)->getJson("/api/assets/{$asset->id}/pm-assignments?is_active=0")->json('data');
        $this->assertCount(1, $all);
    }

    public function test_evaluate_due_assignment_generates_mr(): void
    {
        $asset = $this->createAsset();
        $rule = $this->createRule();
        $assignment = AssetPmAssignment::create(['asset_id' => $asset->id, 'pm_rule_id' => $rule->id, 'is_active' => true, 'assigned_by' => $this->admin->id, 'last_triggered_date' => now()->subDays(31)]);

        $response = $this->actingAs($this->admin)->postJson("/api/assets/{$asset->id}/pm-assignments/{$assignment->id}/evaluate");

        $response->assertCreated();
        $this->assertDatabaseHas('maintenance_requests', [
            'asset_id' => $asset->id,
            'pm_rule_id' => $rule->id,
            'is_preventive' => true,
        ]);
    }

    public function test_evaluate_not_due_assignment_returns_200(): void
    {
        $asset = $this->createAsset();
        $rule = $this->createRule();
        $assignment = AssetPmAssignment::create(['asset_id' => $asset->id, 'pm_rule_id' => $rule->id, 'is_active' => true, 'assigned_by' => $this->admin->id, 'last_triggered_date' => now()->subDays(5)]);

        $this->actingAs($this->admin)->postJson("/api/assets/{$asset->id}/pm-assignments/{$assignment->id}/evaluate")
            ->assertOk();
    }

    public function test_evaluate_with_active_chain_returns_409(): void
    {
        $asset = $this->createAsset();
        $rule = $this->createRule();
        $assignment = AssetPmAssignment::create(['asset_id' => $asset->id, 'pm_rule_id' => $rule->id, 'is_active' => true, 'assigned_by' => $this->admin->id, 'last_triggered_date' => now()->subDays(31)]);

        MaintenanceRequest::create([
            'number' => 'MR-CHAIN', 'asset_id' => $asset->id,
            'status' => 'pending_review', 'priority' => 'medium', 'created_by' => $this->admin->id,
            'is_preventive' => true, 'pm_rule_id' => $rule->id,
        ]);

        $this->actingAs($this->admin)->postJson("/api/assets/{$asset->id}/pm-assignments/{$assignment->id}/evaluate")
            ->assertStatus(409);
    }

    public function test_manager_can_deactivate_and_reactivate_assignment(): void
    {
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $asset = $this->createAsset();
        $rule = $this->createRule();
        $assignment = AssetPmAssignment::create(['asset_id' => $asset->id, 'pm_rule_id' => $rule->id, 'is_active' => true, 'assigned_by' => $this->admin->id]);

        $this->actingAs($manager)->postJson("/api/assets/{$asset->id}/pm-assignments/{$assignment->id}/deactivate")->assertOk();
        $this->assertFalse($assignment->fresh()->is_active);

        $this->actingAs($manager)->postJson("/api/assets/{$asset->id}/pm-assignments/{$assignment->id}/reactivate")->assertOk();
        $this->assertTrue($assignment->fresh()->is_active);
    }

    public function test_requester_cannot_assign_templates(): void
    {
        $requester = $this->createUser(RoleCode::REQUESTER);
        $asset = $this->createAsset();
        $rule = $this->createRule();

        $this->actingAs($requester)->postJson("/api/assets/{$asset->id}/pm-assignments", ['pm_rule_id' => $rule->id])
            ->assertForbidden();
    }

    public function test_manager_cannot_update_template(): void
    {
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $rule = $this->createRule();

        $this->actingAs($manager)->patchJson("/api/pm-rules/{$rule->id}", ['name' => 'Changed'])
            ->assertForbidden();
    }

    public function test_route_scoping_rejects_cross_asset_assignment_access(): void
    {
        $assetA = $this->createAsset();
        $assetB = $this->createAsset();
        $rule = $this->createRule();
        $assignment = AssetPmAssignment::create(['asset_id' => $assetA->id, 'pm_rule_id' => $rule->id, 'is_active' => true, 'assigned_by' => $this->admin->id]);

        $this->actingAs($this->admin)->getJson("/api/assets/{$assetB->id}/pm-assignments/{$assignment->id}")
            ->assertNotFound();
    }

    public function test_evaluate_all_returns_structured_counts(): void
    {
        $asset = $this->createAsset();
        $rule = $this->createRule();
        AssetPmAssignment::create(['asset_id' => $asset->id, 'pm_rule_id' => $rule->id, 'is_active' => true, 'assigned_by' => $this->admin->id, 'last_triggered_date' => now()->subDays(31)]);

        $response = $this->actingAs($this->admin)->postJson('/api/pm-rules/evaluate-all');

        $response->assertOk();
        $this->assertSame(1, $response->json('evaluated'));
        $this->assertSame(1, $response->json('generated'));
    }

    public function test_template_rule_assignments_endpoint(): void
    {
        $asset = $this->createAsset();
        $rule = $this->createRule();
        AssetPmAssignment::create(['asset_id' => $asset->id, 'pm_rule_id' => $rule->id, 'is_active' => true, 'assigned_by' => $this->admin->id]);

        $response = $this->actingAs($this->admin)->getJson("/api/pm-rules/{$rule->id}/assignments");

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }
}
