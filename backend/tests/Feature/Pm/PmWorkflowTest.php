<?php

namespace Tests\Feature\Pm;

use App\Enums\PmTriggerType;
use App\Enums\RoleCode;
use App\Models\Asset;
use App\Models\MaintenanceRequest;
use App\Models\PmRule;
use App\Models\Role;
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
            'erp_asset_id' => 'ERP-'.uniqid(),
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

    public function test_pm_rule_cannot_target_non_erp_asset(): void
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
        ])->assertStatus(422);
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
        ]);

        $this->actingAs($manager)->postJson("/api/maintenance-requests/{$mr->id}/reject", [
            'reason' => 'Not needed now',
            'suppressed_until_date' => now()->addDays(30)->toDateString(),
        ])->assertOk();

        $this->assertDatabaseHas('pm_occurrence_suppressions', [
            'pm_rule_id' => $rule->id,
            'maintenance_request_id' => $mr->id,
            'trigger_type' => PmTriggerType::DATE->value,
        ]);
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
        ]);

        $this->actingAs($admin)->postJson("/api/maintenance-requests/{$mr->id}/cancel", [
            'reason' => 'Rescheduled',
            'suppressed_until_date' => now()->addDays(15)->toDateString(),
        ])->assertOk();

        $this->assertDatabaseHas('pm_occurrence_suppressions', [
            'pm_rule_id' => $rule->id,
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
}
