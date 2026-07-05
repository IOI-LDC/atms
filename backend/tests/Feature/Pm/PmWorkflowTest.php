<?php

namespace Tests\Feature\Pm;

use App\Enums\PmTriggerType;
use App\Enums\RoleCode;
use App\Models\Asset;
use App\Models\AssetMeterReading;
use App\Models\AssetPmAssignment;
use App\Models\MaintenanceRequest;
use App\Models\PmOccurrenceSuppression;
use App\Models\PmRule;
use App\Models\Role;
use App\Models\UsageReadingType;
use App\Models\User;
use App\Models\WorkOrder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PmWorkflowTest extends TestCase
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
            'erp_asset_code' => 'AST-PM-'.uniqid(),
            'name' => 'PM Asset',
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

    private function assign(Asset $asset, PmRule $rule, array $overrides = []): AssetPmAssignment
    {
        return AssetPmAssignment::create(array_merge([
            'asset_id' => $asset->id,
            'pm_rule_id' => $rule->id,
            'is_active' => true,
            'assigned_by' => $this->admin->id,
        ], $overrides));
    }

    public function test_admin_can_create_pm_template(): void
    {
        $response = $this->actingAs($this->admin)->postJson('/api/pm-rules', [
            'name' => 'Monthly PM',
            'trigger_type' => 'date',
            'interval_days' => 30,
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('pm_rules', [
            'trigger_type' => PmTriggerType::DATE->value,
            'interval_days' => 30,
        ]);
    }

    public function test_only_one_active_chain_per_assignment(): void
    {
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $asset = $this->createAsset();
        $rule = $this->createRule();
        $assignment = $this->assign($asset, $rule, ['last_triggered_date' => now()->subDays(31)]);

        MaintenanceRequest::create([
            'number' => 'MR-000001',
            'asset_id' => $asset->id,
            'status' => 'pending_review',
            'priority' => 'medium',
            'created_by' => $this->admin->id,
            'is_preventive' => true,
            'pm_rule_id' => $rule->id,
        ]);

        $response = $this->actingAs($manager)
            ->postJson("/api/assets/{$asset->id}/pm-assignments/{$assignment->id}/evaluate");

        $response->assertStatus(409);
    }

    public function test_template_deactivation_blocked_when_any_assignment_has_active_chain(): void
    {
        $asset = $this->createAsset();
        $rule = $this->createRule();
        $this->assign($asset, $rule);

        MaintenanceRequest::create([
            'number' => 'MR-000001',
            'asset_id' => $asset->id,
            'status' => 'pending_review',
            'priority' => 'medium',
            'created_by' => $this->admin->id,
            'is_preventive' => true,
            'pm_rule_id' => $rule->id,
        ]);

        $this->actingAs($this->admin)->postJson("/api/pm-rules/{$rule->id}/deactivate")
            ->assertStatus(409);
    }

    public function test_template_deactivation_allowed_when_no_active_chain(): void
    {
        $asset = $this->createAsset();
        $rule = $this->createRule();
        $this->assign($asset, $rule);

        $this->actingAs($this->admin)->postJson("/api/pm-rules/{$rule->id}/deactivate")
            ->assertOk();

        $rule->refresh();
        $this->assertFalse($rule->is_active);
    }

    public function test_rejection_creates_date_suppression(): void
    {
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $asset = $this->createAsset();
        $rule = $this->createRule();
        $this->assign($asset, $rule, ['last_triggered_date' => now()->subDays(31)]);

        $mr = MaintenanceRequest::create([
            'number' => 'MR-000001',
            'asset_id' => $asset->id,
            'status' => 'pending_review',
            'priority' => 'medium',
            'created_by' => $this->admin->id,
            'is_preventive' => true,
            'pm_rule_id' => $rule->id,
            'triggered_by_date' => true,
            'trigger_date' => now()->toDateString(),
        ]);

        $this->actingAs($manager)->postJson("/api/maintenance-requests/{$mr->id}/reject", [
            'reason' => 'Not needed now',
            'suppressed_until_date' => now()->addDays(30)->toDateString(),
        ])->assertOk();

        $this->assertDatabaseHas('pm_occurrence_suppressions', [
            'pm_rule_id' => $rule->id,
            'asset_id' => $asset->id,
            'maintenance_request_id' => $mr->id,
            'triggered_by_date' => true,
            'triggered_by_reading' => false,
            'decision_type' => 'rejected',
        ]);
    }

    public function test_cancellation_creates_suppression(): void
    {
        $asset = $this->createAsset();
        $rule = $this->createRule();
        $this->assign($asset, $rule, ['last_triggered_date' => now()->subDays(31)]);

        $mr = MaintenanceRequest::create([
            'number' => 'MR-000001',
            'asset_id' => $asset->id,
            'status' => 'pending_review',
            'priority' => 'medium',
            'created_by' => $this->admin->id,
            'is_preventive' => true,
            'pm_rule_id' => $rule->id,
            'triggered_by_date' => true,
            'trigger_date' => now()->toDateString(),
        ]);

        $this->actingAs($this->admin)->postJson("/api/maintenance-requests/{$mr->id}/cancel", [
            'reason' => 'Rescheduled',
            'suppressed_until_date' => now()->addDays(15)->toDateString(),
        ])->assertOk();

        $this->assertDatabaseHas('pm_occurrence_suppressions', [
            'pm_rule_id' => $rule->id,
            'asset_id' => $asset->id,
            'decision_type' => 'cancelled',
            'triggered_by_date' => true,
            'triggered_by_reading' => false,
        ]);
    }

    public function test_inactive_assignment_not_evaluated(): void
    {
        $asset = $this->createAsset();
        $rule = $this->createRule();
        $assignment = $this->assign($asset, $rule, [
            'last_triggered_date' => now()->subDays(31),
            'is_active' => false,
        ]);

        $this->actingAs($this->admin)
            ->postJson("/api/assets/{$asset->id}/pm-assignments/{$assignment->id}/evaluate")
            ->assertStatus(409);
    }

    public function test_closing_work_order_updates_assignment_baseline(): void
    {
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $tech = $this->createUser(RoleCode::TECHNICIAN);
        $asset = $this->createAsset();
        $rule = $this->createRule();
        $assignment = $this->assign($asset, $rule, ['last_triggered_date' => now()->subDays(31)]);

        $mr = MaintenanceRequest::create([
            'number' => 'MR-000001',
            'asset_id' => $asset->id,
            'status' => 'converted',
            'priority' => 'medium',
            'created_by' => $this->admin->id,
            'reviewed_by' => $manager->id,
            'reviewed_at' => now(),
            'is_preventive' => true,
            'pm_rule_id' => $rule->id,
        ]);

        $wo = WorkOrder::create([
            'number' => 'WO-000001',
            'maintenance_request_id' => $mr->id,
            'asset_id' => $asset->id,
            'status' => 'open',
            'priority' => 'medium',
            'assigned_to_user_id' => $tech->id,
            'assigned_by_user_id' => $manager->id,
            'assigned_at' => now(),
        ]);

        $this->actingAs($tech)->postJson("/api/work-orders/{$wo->id}/start")->assertOk();
        $this->actingAs($tech)->postJson("/api/work-orders/{$wo->id}/complete", ['completion_notes' => 'Done'])->assertOk();
        $this->actingAs($manager)->postJson("/api/work-orders/{$wo->id}/close")->assertOk();

        $assignment->refresh();
        $this->assertNotNull($assignment->last_triggered_date);
        $this->assertTrue(now()->toDateString() === $assignment->last_triggered_date->toDateString());
    }

    public function test_manager_cannot_create_pm_templates(): void
    {
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);

        $this->actingAs($manager)->postJson('/api/pm-rules', [
            'name' => 'Manager PM',
            'trigger_type' => 'date',
            'interval_days' => 60,
        ])->assertForbidden();
    }

    public function test_requester_cannot_manage_pm_rules(): void
    {
        $requester = $this->createUser(RoleCode::REQUESTER);

        $this->actingAs($requester)->postJson('/api/pm-rules', [
            'name' => 'Requester PM attempt',
            'trigger_type' => 'date',
            'interval_days' => 30,
        ])->assertForbidden();
    }

    public function test_rejection_requires_suppressed_until_date_for_date_triggered(): void
    {
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $asset = $this->createAsset();
        $rule = $this->createRule();
        $this->assign($asset, $rule, ['last_triggered_date' => now()->subDays(31)]);

        $mr = MaintenanceRequest::create([
            'number' => 'MR-000001',
            'asset_id' => $asset->id,
            'status' => 'pending_review',
            'priority' => 'medium',
            'created_by' => $this->admin->id,
            'is_preventive' => true,
            'pm_rule_id' => $rule->id,
            'triggered_by_date' => true,
            'trigger_date' => now()->toDateString(),
        ]);

        $this->actingAs($manager)->postJson("/api/maintenance-requests/{$mr->id}/reject", [
            'reason' => 'Not needed now',
        ])->assertStatus(422);
    }

    public function test_suppression_copies_trigger_snapshot_from_mr(): void
    {
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $asset = $this->createAsset();
        $readingType = UsageReadingType::create(['name' => 'Hours', 'unit' => 'h']);
        $rule = $this->createRule([
            'trigger_type' => PmTriggerType::READING,
            'interval_reading' => 1000,
            'usage_reading_type_id' => $readingType->id,
        ]);
        $this->assign($asset, $rule, ['last_triggered_reading' => 5000]);

        $snapshotReading = 6100.00;

        $mr = MaintenanceRequest::create([
            'number' => 'MR-000002',
            'asset_id' => $asset->id,
            'status' => 'pending_review',
            'priority' => 'medium',
            'created_by' => $this->admin->id,
            'is_preventive' => true,
            'pm_rule_id' => $rule->id,
            'triggered_by_reading' => true,
            'trigger_reading_value' => $snapshotReading,
            'trigger_reading_type_id' => $readingType->id,
        ]);

        $this->actingAs($manager)->postJson("/api/maintenance-requests/{$mr->id}/reject", [
            'reason' => 'Deferred',
            'suppressed_until_reading' => 7000,
        ])->assertOk();

        $suppression = PmOccurrenceSuppression::where('maintenance_request_id', $mr->id)->first();
        $this->assertNotNull($suppression);
        $this->assertEquals($snapshotReading, (float) $suppression->trigger_reading_value);
        $this->assertEquals($readingType->id, $suppression->trigger_reading_type_id);
    }

    public function test_reading_triggered_evaluate_sets_trigger_reading_value(): void
    {
        $asset = $this->createAsset();
        $readingType = UsageReadingType::create(['name' => 'Hours', 'unit' => 'h']);
        $rule = $this->createRule([
            'trigger_type' => PmTriggerType::READING,
            'interval_reading' => 500,
            'usage_reading_type_id' => $readingType->id,
        ]);
        $assignment = $this->assign($asset, $rule, ['last_triggered_reading' => 1000]);

        AssetMeterReading::create([
            'asset_id' => $asset->id,
            'usage_reading_type_id' => $readingType->id,
            'reading_value' => 1600.00,
            'reading_at' => now(),
            'source' => 'manual',
            'entered_by_user_id' => $this->admin->id,
            'confirmed_by_user_id' => $this->admin->id,
            'confirmed_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)
            ->postJson("/api/assets/{$asset->id}/pm-assignments/{$assignment->id}/evaluate");
        $response->assertCreated();

        $mr = MaintenanceRequest::where('pm_rule_id', $rule->id)->first();
        $this->assertNotNull($mr);
        $this->assertEquals(1600.00, (float) $mr->trigger_reading_value);
        $this->assertEquals($readingType->id, $mr->trigger_reading_type_id);
        $this->assertTrue((bool) $mr->triggered_by_reading);
        $this->assertFalse((bool) $mr->triggered_by_date);
    }

    public function test_reactivate_pm_rule_restores_active_state(): void
    {
        $rule = $this->createRule([
            'is_active' => false,
            'deactivated_by' => $this->admin->id,
            'deactivated_at' => now(),
        ]);

        $this->actingAs($this->admin)->postJson("/api/pm-rules/{$rule->id}/reactivate")
            ->assertOk();

        $rule->refresh();
        $this->assertTrue($rule->is_active);
        $this->assertEquals($this->admin->id, $rule->reactivated_by);
        $this->assertNotNull($rule->reactivated_at);
    }

    public function test_reactivate_already_active_rule_fails(): void
    {
        $rule = $this->createRule();

        $this->actingAs($this->admin)->postJson("/api/pm-rules/{$rule->id}/reactivate")
            ->assertStatus(409);
    }

    public function test_create_pm_suppression_action_stores_data(): void
    {
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $asset = $this->createAsset();
        $readingType = UsageReadingType::create(['name' => 'Hours', 'unit' => 'h']);
        $rule = $this->createRule([
            'trigger_type' => PmTriggerType::READING,
            'interval_reading' => 1000,
            'usage_reading_type_id' => $readingType->id,
        ]);
        $this->assign($asset, $rule, ['last_triggered_reading' => 5000]);

        $mr = MaintenanceRequest::create([
            'number' => 'MR-SUP-001',
            'asset_id' => $asset->id,
            'status' => 'pending_review',
            'priority' => 'medium',
            'created_by' => $this->admin->id,
            'is_preventive' => true,
            'pm_rule_id' => $rule->id,
            'triggered_by_reading' => true,
            'trigger_reading_value' => 6100,
            'trigger_reading_type_id' => $readingType->id,
        ]);

        $this->actingAs($manager)->postJson("/api/maintenance-requests/{$mr->id}/reject", [
            'reason' => 'Deferred to next cycle',
            'suppressed_until_reading' => 7000,
        ])->assertOk();

        $suppression = PmOccurrenceSuppression::where('pm_rule_id', $rule->id)->first();
        $this->assertNotNull($suppression);
        $this->assertEquals('rejected', $suppression->decision_type);
        $this->assertEquals(7000, (float) $suppression->suppressed_until_reading);
        $this->assertTrue((bool) $suppression->triggered_by_reading);
        $this->assertFalse((bool) $suppression->triggered_by_date);
    }

    public function test_closing_wo_updates_reading_triggered_assignment_baseline(): void
    {
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $tech = $this->createUser(RoleCode::TECHNICIAN);
        $asset = $this->createAsset();
        $readingType = UsageReadingType::create(['name' => 'Hours', 'unit' => 'h']);
        $rule = $this->createRule([
            'trigger_type' => PmTriggerType::READING,
            'interval_reading' => 500,
            'usage_reading_type_id' => $readingType->id,
        ]);
        $assignment = $this->assign($asset, $rule, ['last_triggered_reading' => 1000]);

        AssetMeterReading::create([
            'asset_id' => $asset->id,
            'usage_reading_type_id' => $readingType->id,
            'reading_value' => 1600,
            'reading_at' => now(),
            'source' => 'manual',
            'entered_by_user_id' => $tech->id,
            'confirmed_by_user_id' => $manager->id,
            'confirmed_at' => now(),
        ]);

        $mr = MaintenanceRequest::create([
            'number' => 'MR-BL-001',
            'asset_id' => $asset->id,
            'status' => 'converted',
            'priority' => 'medium',
            'created_by' => $this->admin->id,
            'reviewed_by' => $manager->id,
            'reviewed_at' => now(),
            'is_preventive' => true,
            'pm_rule_id' => $rule->id,
        ]);

        $wo = WorkOrder::create([
            'number' => 'WO-BL-001',
            'maintenance_request_id' => $mr->id,
            'asset_id' => $asset->id,
            'status' => 'open',
            'priority' => 'medium',
            'assigned_to_user_id' => $tech->id,
            'assigned_by_user_id' => $manager->id,
            'assigned_at' => now(),
        ]);

        $this->actingAs($tech)->postJson("/api/work-orders/{$wo->id}/start")->assertOk();
        $this->actingAs($tech)->postJson("/api/work-orders/{$wo->id}/complete", ['completion_notes' => 'Done'])->assertOk();
        $this->actingAs($manager)->postJson("/api/work-orders/{$wo->id}/close")->assertOk();

        $assignment->refresh();
        $this->assertEquals(1600, (float) $assignment->last_triggered_reading);
        $this->assertNotNull($assignment->last_triggered_date);
    }

    public function test_date_or_reading_suppression_requires_both_boundaries(): void
    {
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $asset = $this->createAsset();
        $readingType = UsageReadingType::create(['name' => 'Hours', 'unit' => 'h']);
        $rule = $this->createRule([
            'trigger_type' => PmTriggerType::DATE_OR_READING,
            'interval_days' => 30,
            'interval_reading' => 1000,
            'usage_reading_type_id' => $readingType->id,
        ]);
        $this->assign($asset, $rule, [
            'last_triggered_date' => now()->subDays(31),
            'last_triggered_reading' => 5000,
        ]);

        $mr = MaintenanceRequest::create([
            'number' => 'MR-DUAL-001',
            'asset_id' => $asset->id,
            'status' => 'pending_review',
            'priority' => 'medium',
            'created_by' => $this->admin->id,
            'is_preventive' => true,
            'pm_rule_id' => $rule->id,
            'triggered_by_date' => true,
            'triggered_by_reading' => true,
            'trigger_date' => now()->toDateString(),
            'trigger_reading_value' => 6100,
            'trigger_reading_type_id' => $readingType->id,
        ]);

        $this->actingAs($manager)->postJson("/api/maintenance-requests/{$mr->id}/reject", [
            'reason' => 'Deferred',
            'suppressed_until_date' => now()->addDays(10)->toDateString(),
        ])->assertStatus(422)
            ->assertJsonPath('message', 'The suppressed until reading field is required.');

        $this->actingAs($manager)->postJson("/api/maintenance-requests/{$mr->id}/reject", [
            'reason' => 'Deferred',
            'suppressed_until_date' => now()->addDays(10)->toDateString(),
            'suppressed_until_reading' => 7000,
        ])->assertOk();

        $this->assertDatabaseHas('pm_occurrence_suppressions', [
            'pm_rule_id' => $rule->id,
            'triggered_by_date' => true,
            'triggered_by_reading' => true,
            'suppressed_until_reading' => 7000,
        ]);
    }

    public function test_pm_rule_can_store_maintenance_level(): void
    {
        $response = $this->actingAs($this->admin)->postJson('/api/pm-rules', [
            'name' => 'Quarterly PM',
            'maintenance_level' => 'L2',
            'trigger_type' => 'date',
            'interval_days' => 90,
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('pm_rules', ['maintenance_level' => 'L2']);
        $response->assertJsonPath('data.maintenance_level', 'L2');
    }

    public function test_manager_cannot_deactivate_pm_rule(): void
    {
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $rule = $this->createRule();

        $this->actingAs($manager)->postJson("/api/pm-rules/{$rule->id}/deactivate")
            ->assertForbidden();
    }

    public function test_maintenance_requests_can_be_filtered_by_pm_rule_id(): void
    {
        $asset = $this->createAsset();
        $rule = $this->createRule();
        $this->assign($asset, $rule);

        $pmMr = MaintenanceRequest::create([
            'number' => 'MR-FLT-001',
            'asset_id' => $asset->id,
            'status' => 'pending_review',
            'priority' => 'medium',
            'created_by' => $this->admin->id,
            'is_preventive' => true,
            'pm_rule_id' => $rule->id,
        ]);

        $otherMr = MaintenanceRequest::create([
            'number' => 'MR-FLT-002',
            'asset_id' => $asset->id,
            'status' => 'pending_review',
            'priority' => 'medium',
            'created_by' => $this->admin->id,
        ]);

        $response = $this->actingAs($this->admin)->getJson("/api/maintenance-requests?pm_rule_id={$rule->id}");

        $response->assertStatus(200);
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertContains($pmMr->id, $ids);
        $this->assertNotContains($otherMr->id, $ids);
    }

    public function test_closing_higher_level_wo_resets_lower_level_assignment_baselines(): void
    {
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $tech = $this->createUser(RoleCode::TECHNICIAN);
        $asset = $this->createAsset();

        $l1 = $this->createRule(['name' => 'L1 Monthly', 'maintenance_level' => 'L1', 'interval_days' => 30]);
        $l2 = $this->createRule(['name' => 'L2 Quarterly', 'maintenance_level' => 'L2', 'interval_days' => 90]);
        $custom = $this->createRule(['name' => 'Annual', 'maintenance_level' => 'Annual', 'interval_days' => 365]);
        $l3 = $this->createRule(['name' => 'L3 Semi-annual', 'maintenance_level' => 'L3', 'interval_days' => 180]);

        $a1 = $this->assign($asset, $l1, ['last_triggered_date' => now()->subDays(20)]);
        $a2 = $this->assign($asset, $l2, ['last_triggered_date' => now()->subDays(70)]);
        $aCustom = $this->assign($asset, $custom, ['last_triggered_date' => now()->subDays(100)]);
        $a3 = $this->assign($asset, $l3, ['last_triggered_date' => now()->subDays(181)]);

        $mr = MaintenanceRequest::create([
            'number' => 'MR-CUM-001',
            'asset_id' => $asset->id,
            'status' => 'converted',
            'priority' => 'medium',
            'created_by' => $this->admin->id,
            'reviewed_by' => $manager->id,
            'reviewed_at' => now(),
            'is_preventive' => true,
            'pm_rule_id' => $l3->id,
        ]);

        $wo = WorkOrder::create([
            'number' => 'WO-CUM-001',
            'maintenance_request_id' => $mr->id,
            'asset_id' => $asset->id,
            'status' => 'open',
            'priority' => 'medium',
            'assigned_to_user_id' => $tech->id,
            'assigned_by_user_id' => $manager->id,
            'assigned_at' => now(),
        ]);

        $this->actingAs($tech)->postJson("/api/work-orders/{$wo->id}/start")->assertOk();
        $this->actingAs($tech)->postJson("/api/work-orders/{$wo->id}/complete", ['completion_notes' => 'Done'])->assertOk();
        $this->actingAs($manager)->postJson("/api/work-orders/{$wo->id}/close")->assertOk();

        $a1->refresh();
        $a2->refresh();
        $aCustom->refresh();
        $a3->refresh();

        $this->assertTrue(now()->toDateString() === $a3->last_triggered_date->toDateString());
        $this->assertTrue(now()->toDateString() === $a1->last_triggered_date->toDateString());
        $this->assertTrue(now()->toDateString() === $a2->last_triggered_date->toDateString());
        $this->assertFalse(now()->toDateString() === $aCustom->last_triggered_date->toDateString());
    }
}
