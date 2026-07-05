<?php

namespace Tests\Feature\Pm;

use App\Enums\PmTriggerType;
use App\Enums\RoleCode;
use App\Models\Asset;
use App\Models\AssetPmAssignment;
use App\Models\MaintenanceRequest;
use App\Models\PmOccurrenceSuppression;
use App\Models\PmRule;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssignmentSuppressionTest extends TestCase
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

    private function createAsset(): Asset
    {
        return Asset::create(['erp_asset_code' => 'AST-'.uniqid(), 'name' => 'Asset', 'is_active' => true]);
    }

    public function test_deactivating_assignment_clears_active_date_suppression_window(): void
    {
        $asset = $this->createAsset();
        $rule = PmRule::create(['name' => 'Monthly', 'trigger_type' => PmTriggerType::DATE, 'interval_days' => 30, 'is_active' => true, 'created_by' => $this->admin->id]);
        $assignment = AssetPmAssignment::create(['asset_id' => $asset->id, 'pm_rule_id' => $rule->id, 'is_active' => true, 'assigned_by' => $this->admin->id, 'last_triggered_date' => now()->subDays(31)]);

        $mr = MaintenanceRequest::create([
            'number' => 'MR-1', 'asset_id' => $asset->id, 'status' => 'rejected',
            'priority' => 'medium', 'created_by' => $this->admin->id, 'is_preventive' => true, 'pm_rule_id' => $rule->id,
        ]);

        PmOccurrenceSuppression::create([
            'pm_rule_id' => $rule->id, 'asset_id' => $asset->id, 'maintenance_request_id' => $mr->id,
            'trigger_type' => PmTriggerType::DATE, 'decision_type' => 'rejected',
            'triggered_by_date' => true, 'triggered_by_reading' => false,
            'suppressed_until_date' => now()->addYear(),
            'decided_by' => $this->admin->id, 'decided_at' => now(), 'reason' => 'Deferred',
        ]);

        $this->actingAs($this->admin)->postJson("/api/assets/{$asset->id}/pm-assignments/{$assignment->id}/deactivate")
            ->assertOk();

        $suppression = PmOccurrenceSuppression::where('pm_rule_id', $rule->id)->first();
        $this->assertNull($suppression->suppressed_until_date);
        $this->assertNull($suppression->suppressed_until_reading);
    }

    public function test_future_dated_window_does_not_block_freshly_reactivated_assignment(): void
    {
        $asset = $this->createAsset();
        $rule = PmRule::create(['name' => 'Monthly', 'trigger_type' => PmTriggerType::DATE, 'interval_days' => 30, 'is_active' => true, 'created_by' => $this->admin->id]);
        $assignment = AssetPmAssignment::create(['asset_id' => $asset->id, 'pm_rule_id' => $rule->id, 'is_active' => true, 'assigned_by' => $this->admin->id, 'last_triggered_date' => now()->subDays(31)]);

        $mr = MaintenanceRequest::create([
            'number' => 'MR-2', 'asset_id' => $asset->id, 'status' => 'rejected',
            'priority' => 'medium', 'created_by' => $this->admin->id, 'is_preventive' => true, 'pm_rule_id' => $rule->id,
        ]);

        PmOccurrenceSuppression::create([
            'pm_rule_id' => $rule->id, 'asset_id' => $asset->id, 'maintenance_request_id' => $mr->id,
            'trigger_type' => PmTriggerType::DATE, 'decision_type' => 'rejected',
            'triggered_by_date' => true, 'triggered_by_reading' => false,
            'suppressed_until_date' => now()->addYear(),
            'decided_by' => $this->admin->id, 'decided_at' => now(), 'reason' => 'Deferred',
        ]);

        // Deactivate clears the future window...
        $this->actingAs($this->admin)->postJson("/api/assets/{$asset->id}/pm-assignments/{$assignment->id}/deactivate")->assertOk();
        // ...so reactivation + immediate evaluation generates an MR (not blocked).
        $this->actingAs($this->admin)->postJson("/api/assets/{$asset->id}/pm-assignments/{$assignment->id}/reactivate")->assertOk();

        $this->actingAs($this->admin)->postJson("/api/assets/{$asset->id}/pm-assignments/{$assignment->id}/evaluate")
            ->assertCreated();
    }
}
