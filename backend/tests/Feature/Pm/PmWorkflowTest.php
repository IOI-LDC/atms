<?php

namespace Tests\Feature\Pm;

use App\Enums\PmTriggerType;
use App\Enums\RoleCode;
use App\Models\Asset;
use App\Models\AssetMeterReading;
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
            'erp_asset_code' => 'AST-PM-'.uniqid(),
            'name' => 'PM Asset',
            'is_active' => true,
        ]);
    }

    public function test_pm_rule_targets_one_erp_linked_asset(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $asset = $this->createAsset();

        $response = $this->actingAs($admin)->postJson('/api/pm-rules', [
            'asset_id' => $asset->id,
            'name' => 'Monthly PM',
            'trigger_type' => 'date',
            'interval_days' => 30,
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('pm_rules', [
            'asset_id' => $asset->id,
            'trigger_type' => PmTriggerType::DATE->value,
            'interval_days' => 30,
        ]);
    }

    public function test_pm_rule_can_target_any_atms_managed_asset(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $asset = Asset::create([
            'erp_asset_code' => 'AST-NO-ERP-'.uniqid(),
            'name' => 'Non-ERP Asset',
            'is_active' => true,
        ]);

        $this->actingAs($admin)->postJson('/api/pm-rules', [
            'asset_id' => $asset->id,
            'name' => 'Monthly PM',
            'trigger_type' => 'date',
            'interval_days' => 30,
        ])->assertStatus(201);
    }

    public function test_only_one_active_chain_per_rule(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $asset = $this->createAsset();
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);

        $rule = PmRule::create([
            'asset_id' => $asset->id,
            'name' => 'Monthly PM',
            'trigger_type' => PmTriggerType::DATE,
            'interval_days' => 30,
            'last_triggered_date' => now()->subDays(31),
            'is_active' => true,
            'created_by' => $admin->id,
        ]);

        // Create first preventive MR (pending)
        $mr = MaintenanceRequest::create([
            'number' => 'MR-000001',
            'asset_id' => $asset->id,
            'type' => 'preventive',
            'status' => 'pending_review',
            'priority' => 'medium',
            'created_by' => $admin->id,
            'is_preventive' => true,
            'pm_rule_id' => $rule->id,
        ]);

        // Evaluate should not create another request since chain is active
        $response = $this->actingAs($manager)->postJson("/api/pm-rules/{$rule->id}/evaluate");
        $response->assertStatus(409);
    }

    public function test_deactivation_blocked_during_active_chain(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $asset = $this->createAsset();

        $rule = PmRule::create([
            'asset_id' => $asset->id,
            'name' => 'Monthly PM',
            'trigger_type' => PmTriggerType::DATE,
            'interval_days' => 30,
            'is_active' => true,
            'created_by' => $admin->id,
        ]);

        MaintenanceRequest::create([
            'number' => 'MR-000001',
            'asset_id' => $asset->id,
            'type' => 'preventive',
            'status' => 'pending_review',
            'priority' => 'medium',
            'created_by' => $admin->id,
            'is_preventive' => true,
            'pm_rule_id' => $rule->id,
        ]);

        $this->actingAs($admin)->postJson("/api/pm-rules/{$rule->id}/deactivate")
            ->assertStatus(409);
    }

    public function test_rejection_creates_date_suppression(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $asset = $this->createAsset();

        $rule = PmRule::create([
            'asset_id' => $asset->id,
            'name' => 'Monthly PM',
            'trigger_type' => PmTriggerType::DATE,
            'interval_days' => 30,
            'last_triggered_date' => now()->subDays(31),
            'is_active' => true,
            'created_by' => $admin->id,
        ]);

        $mr = MaintenanceRequest::create([
            'number' => 'MR-000001',
            'asset_id' => $asset->id,
            'type' => 'preventive',
            'status' => 'pending_review',
            'priority' => 'medium',
            'created_by' => $admin->id,
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
            'maintenance_request_id' => $mr->id,
            'triggered_by_date' => true,
            'triggered_by_reading' => false,
            'decision_type' => 'rejected',
        ]);

        $suppression = PmOccurrenceSuppression::where('pm_rule_id', $rule->id)
            ->where('maintenance_request_id', $mr->id)
            ->first();
        $this->assertNotNull($suppression);
        $this->assertTrue($suppression->trigger_date->isToday());
    }

    public function test_cancellation_creates_suppression(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $asset = $this->createAsset();

        $rule = PmRule::create([
            'asset_id' => $asset->id,
            'name' => 'Monthly PM',
            'trigger_type' => PmTriggerType::DATE,
            'interval_days' => 30,
            'last_triggered_date' => now()->subDays(31),
            'is_active' => true,
            'created_by' => $admin->id,
        ]);

        $mr = MaintenanceRequest::create([
            'number' => 'MR-000001',
            'asset_id' => $asset->id,
            'type' => 'preventive',
            'status' => 'pending_review',
            'priority' => 'medium',
            'created_by' => $admin->id,
            'is_preventive' => true,
            'pm_rule_id' => $rule->id,
            'triggered_by_date' => true,
            'trigger_date' => now()->toDateString(),
        ]);

        $this->actingAs($admin)->postJson("/api/maintenance-requests/{$mr->id}/cancel", [
            'reason' => 'Rescheduled',
            'suppressed_until_date' => now()->addDays(15)->toDateString(),
        ])->assertOk();

        $this->assertDatabaseHas('pm_occurrence_suppressions', [
            'pm_rule_id' => $rule->id,
            'decision_type' => 'cancelled',
            'triggered_by_date' => true,
            'triggered_by_reading' => false,
        ]);
    }

    public function test_inactive_rule_not_evaluated(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $asset = $this->createAsset();

        $rule = PmRule::create([
            'asset_id' => $asset->id,
            'name' => 'Inactive PM',
            'trigger_type' => PmTriggerType::DATE,
            'interval_days' => 30,
            'last_triggered_date' => now()->subDays(31),
            'is_active' => false,
            'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)->postJson("/api/pm-rules/{$rule->id}/evaluate")
            ->assertStatus(409);
    }

    public function test_closing_work_order_updates_pm_baseline(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $tech = $this->createUser(RoleCode::TECHNICIAN);
        $asset = $this->createAsset();

        $rule = PmRule::create([
            'asset_id' => $asset->id,
            'name' => 'Monthly PM',
            'trigger_type' => PmTriggerType::DATE,
            'interval_days' => 30,
            'last_triggered_date' => now()->subDays(31),
            'is_active' => true,
            'created_by' => $admin->id,
        ]);

        $mr = MaintenanceRequest::create([
            'number' => 'MR-000001',
            'asset_id' => $asset->id,
            'type' => 'preventive',
            'status' => 'converted',
            'priority' => 'medium',
            'created_by' => $admin->id,
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

        $rule->refresh();
        $this->assertNotNull($rule->last_triggered_date);
        $this->assertTrue(now()->toDateString() === $rule->last_triggered_date->toDateString());
    }

    public function test_manager_can_manage_pm_rules(): void
    {
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $asset = $this->createAsset();

        $this->actingAs($manager)->postJson('/api/pm-rules', [
            'asset_id' => $asset->id,
            'name' => 'Manager PM',
            'trigger_type' => 'date',
            'interval_days' => 60,
        ])->assertCreated();
    }

    public function test_requester_cannot_manage_pm_rules(): void
    {
        $requester = $this->createUser(RoleCode::REQUESTER);
        $asset = $this->createAsset();

        $this->actingAs($requester)->postJson('/api/pm-rules', [
            'asset_id' => $asset->id,
            'name' => 'Requester PM attempt',
            'trigger_type' => 'date',
            'interval_days' => 30,
        ])->assertForbidden();
    }

    public function test_rejection_requires_suppressed_until_date_for_date_triggered(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $asset = $this->createAsset();

        $rule = PmRule::create([
            'asset_id' => $asset->id,
            'name' => 'Monthly PM',
            'trigger_type' => PmTriggerType::DATE,
            'interval_days' => 30,
            'last_triggered_date' => now()->subDays(31),
            'is_active' => true,
            'created_by' => $admin->id,
        ]);

        $mr = MaintenanceRequest::create([
            'number' => 'MR-000001',
            'asset_id' => $asset->id,
            'type' => 'preventive',
            'status' => 'pending_review',
            'priority' => 'medium',
            'created_by' => $admin->id,
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
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $asset = $this->createAsset();
        $readingType = UsageReadingType::create(['name' => 'Hours', 'unit' => 'h']);

        $rule = PmRule::create([
            'asset_id' => $asset->id,
            'name' => 'Reading PM',
            'trigger_type' => PmTriggerType::READING,
            'interval_reading' => 1000,
            'usage_reading_type_id' => $readingType->id,
            'last_triggered_reading' => 5000,
            'is_active' => true,
            'created_by' => $admin->id,
        ]);

        $snapshotDate = now()->subDays(2)->toDateString();
        $snapshotReading = 6100.00;

        $mr = MaintenanceRequest::create([
            'number' => 'MR-000002',
            'asset_id' => $asset->id,
            'type' => 'preventive',
            'status' => 'pending_review',
            'priority' => 'medium',
            'created_by' => $admin->id,
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
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $asset = $this->createAsset();
        $readingType = UsageReadingType::create(['name' => 'Hours', 'unit' => 'h']);

        $rule = PmRule::create([
            'asset_id' => $asset->id,
            'name' => 'Reading PM',
            'trigger_type' => PmTriggerType::READING,
            'interval_reading' => 500,
            'usage_reading_type_id' => $readingType->id,
            'last_triggered_reading' => 1000,
            'is_active' => true,
            'created_by' => $admin->id,
        ]);

        AssetMeterReading::create([
            'asset_id' => $asset->id,
            'usage_reading_type_id' => $readingType->id,
            'reading_value' => 1600.00,
            'reading_at' => now(),
            'source' => 'manual',
            'entered_by_user_id' => $admin->id,
            'confirmed_by_user_id' => $admin->id,
            'confirmed_at' => now(),
        ]);

        $response = $this->actingAs($admin)->postJson("/api/pm-rules/{$rule->id}/evaluate");
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
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $asset = $this->createAsset();

        $rule = PmRule::create([
            'asset_id' => $asset->id,
            'name' => 'Monthly PM',
            'trigger_type' => PmTriggerType::DATE,
            'interval_days' => 30,
            'is_active' => false,
            'created_by' => $admin->id,
            'deactivated_by' => $admin->id,
            'deactivated_at' => now(),
        ]);

        $this->actingAs($admin)->postJson("/api/pm-rules/{$rule->id}/reactivate")
            ->assertOk();

        $rule->refresh();
        $this->assertTrue($rule->is_active);
        $this->assertEquals($admin->id, $rule->reactivated_by);
        $this->assertNotNull($rule->reactivated_at);
    }

    public function test_reactivate_already_active_rule_fails(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $asset = $this->createAsset();

        $rule = PmRule::create([
            'asset_id' => $asset->id,
            'name' => 'Monthly PM',
            'trigger_type' => PmTriggerType::DATE,
            'interval_days' => 30,
            'is_active' => true,
            'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)->postJson("/api/pm-rules/{$rule->id}/reactivate")
            ->assertStatus(409);
    }

    public function test_create_pm_suppression_action_stores_data(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $asset = $this->createAsset();
        $readingType = UsageReadingType::create(['name' => 'Hours', 'unit' => 'h']);

        $rule = PmRule::create([
            'asset_id' => $asset->id,
            'name' => 'Reading PM',
            'trigger_type' => PmTriggerType::READING,
            'interval_reading' => 1000,
            'usage_reading_type_id' => $readingType->id,
            'last_triggered_reading' => 5000,
            'is_active' => true,
            'created_by' => $admin->id,
        ]);

        $mr = MaintenanceRequest::create([
            'number' => 'MR-SUP-001',
            'asset_id' => $asset->id,
            'type' => 'preventive',
            'status' => 'pending_review',
            'priority' => 'medium',
            'created_by' => $admin->id,
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

    public function test_closing_wo_updates_reading_triggered_pm_baseline(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $tech = $this->createUser(RoleCode::TECHNICIAN);
        $asset = $this->createAsset();
        $readingType = UsageReadingType::create(['name' => 'Hours', 'unit' => 'h']);

        $rule = PmRule::create([
            'asset_id' => $asset->id,
            'name' => 'Reading PM',
            'trigger_type' => PmTriggerType::READING,
            'interval_reading' => 500,
            'usage_reading_type_id' => $readingType->id,
            'last_triggered_reading' => 1000,
            'is_active' => true,
            'created_by' => $admin->id,
        ]);

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
            'type' => 'preventive',
            'status' => 'converted',
            'priority' => 'medium',
            'created_by' => $admin->id,
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

        $rule->refresh();
        $this->assertEquals(1600, (float) $rule->last_triggered_reading);
        $this->assertNotNull($rule->last_triggered_date);
    }

    public function test_date_or_reading_suppression_requires_both_boundaries(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $asset = $this->createAsset();
        $readingType = UsageReadingType::create(['name' => 'Hours', 'unit' => 'h']);

        $rule = PmRule::create([
            'asset_id' => $asset->id,
            'name' => 'Dual trigger PM',
            'trigger_type' => PmTriggerType::DATE_OR_READING,
            'interval_days' => 30,
            'interval_reading' => 1000,
            'usage_reading_type_id' => $readingType->id,
            'last_triggered_date' => now()->subDays(31),
            'last_triggered_reading' => 5000,
            'is_active' => true,
            'created_by' => $admin->id,
        ]);

        $mr = MaintenanceRequest::create([
            'number' => 'MR-DUAL-001',
            'asset_id' => $asset->id,
            'type' => 'preventive',
            'status' => 'pending_review',
            'priority' => 'medium',
            'created_by' => $admin->id,
            'is_preventive' => true,
            'pm_rule_id' => $rule->id,
            'triggered_by_date' => true,
            'triggered_by_reading' => true,
            'trigger_date' => now()->toDateString(),
            'trigger_reading_value' => 6100,
            'trigger_reading_type_id' => $readingType->id,
        ]);

        // Rejecting with ONLY date boundary fails because reading also triggered
        $this->actingAs($manager)->postJson("/api/maintenance-requests/{$mr->id}/reject", [
            'reason' => 'Deferred',
            'suppressed_until_date' => now()->addDays(10)->toDateString(),
        ])->assertStatus(422)
            ->assertJsonPath('message', 'The suppressed until reading field is required.');

        // Rejecting with BOTH boundaries succeeds
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
}
