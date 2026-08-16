<?php

namespace Tests\Feature\Assets;

use App\Enums\MaintenanceRequestStatus;
use App\Enums\MaintenanceStatus;
use App\Enums\PmTriggerType;
use App\Enums\RoleCode;
use App\Enums\WorkOrderStatus;
use App\Jobs\EvaluatePmRulesJob;
use App\Models\Asset;
use App\Models\AssetPmAssignment;
use App\Models\Location;
use App\Models\MaintenanceRequest;
use App\Models\PmRule;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkOrder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `is_active = false` must block new maintenance work exactly as
 * `maintenance_status = withdrawn` does.
 *
 * This is the replacement safety net for removing the `scraped` operational
 * value: after the 2026-08-16 vocabulary change, `is_active = false` is the
 * only "out of ATMS" control, so every entry point that refused a withdrawn
 * asset must refuse a deactivated one too — including the two PM paths that
 * historically checked neither, and the PM scheduler, which checked only
 * `maintenance_status`.
 *
 * Every surface is asserted on **both** axes. A guard that covers one axis
 * and not the other is the defect this suite exists to prevent, and testing
 * only the new axis would let a regression on the old one pass unnoticed.
 */
class InactiveAssetGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────

    private function user(RoleCode $role): User
    {
        return User::factory()->create([
            'role_id' => Role::where('code', $role)->first()->id,
            'is_active' => true,
        ]);
    }

    /** An asset that blocks work because it was withdrawn from maintenance. */
    private function withdrawnAsset(): Asset
    {
        return $this->asset(['maintenance_status' => MaintenanceStatus::WITHDRAWN]);
    }

    /** An asset that blocks work because the record itself is deactivated. */
    private function deactivatedAsset(): Asset
    {
        return $this->asset(['is_active' => false]);
    }

    private function asset(array $overrides = []): Asset
    {
        return Asset::create(array_merge([
            'erp_asset_code' => 'AST-EL-'.uniqid(),
            'name' => 'Eligibility Test Asset',
            'maintenance_status' => MaintenanceStatus::ENROLLED,
            'is_active' => true,
        ], $overrides));
    }

    private function maintenanceRequest(Asset $asset, array $overrides = []): MaintenanceRequest
    {
        return MaintenanceRequest::create(array_merge([
            'number' => 'MR-'.uniqid(),
            'asset_id' => $asset->id,
            'status' => MaintenanceRequestStatus::PENDING_REVIEW,
            'priority' => 'high',
            'description' => 'Eligibility fixture',
            'created_by' => $this->user(RoleCode::REQUESTER)->id,
            'is_preventive' => false,
        ], $overrides));
    }

    private function workOrder(Asset $asset, array $overrides = []): WorkOrder
    {
        $mr = $this->maintenanceRequest($asset, ['status' => MaintenanceRequestStatus::CONVERTED]);

        return WorkOrder::create(array_merge([
            'number' => 'WO-'.uniqid(),
            'maintenance_request_id' => $mr->id,
            'asset_id' => $asset->id,
            'status' => WorkOrderStatus::OPEN,
            'priority' => 'high',
        ], $overrides));
    }

    private function pmAssignment(Asset $asset): AssetPmAssignment
    {
        $rule = PmRule::create([
            'name' => 'PM '.uniqid(),
            'maintenance_level' => 'L1',
            'trigger_type' => PmTriggerType::DATE,
            'interval_days' => 30,
            'is_active' => true,
            'created_by' => $this->user(RoleCode::ADMINISTRATOR)->id,
        ]);

        return AssetPmAssignment::create([
            'asset_id' => $asset->id,
            'pm_rule_id' => $rule->id,
            // Far enough in the past that the assignment is unambiguously due,
            // so a request that fails to appear means the guard fired, not that
            // the interval had not elapsed.
            'last_triggered_date' => now()->subDays(365)->toDateString(),
            'is_active' => true,
        ]);
    }

    private function yard(): Location
    {
        return Location::create([
            'name' => 'Guard Test Yard',
            'code' => 'GT'.random_int(10, 99),
            'type' => 'yard',
            'is_active' => true,
        ]);
    }

    // ── 1. MR create ──────────────────────────────────────────────────────────

    public function test_withdrawn_asset_blocks_mr_creation(): void
    {
        $this->actingAs($this->user(RoleCode::REQUESTER))
            ->postJson('/api/maintenance-requests/corrective', [
                'asset_id' => $this->withdrawnAsset()->id,
                'priority' => 'high',
                'description' => 'Should fail',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Cannot create a maintenance request for an asset withdrawn from maintenance.');
    }

    public function test_deactivated_asset_blocks_mr_creation(): void
    {
        $this->actingAs($this->user(RoleCode::REQUESTER))
            ->postJson('/api/maintenance-requests/corrective', [
                'asset_id' => $this->deactivatedAsset()->id,
                'priority' => 'high',
                'description' => 'Should fail',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Cannot create a maintenance request for a deactivated asset.');
    }

    // ── 2. MR approval — corrective AND preventive ────────────────────────────

    public function test_withdrawn_asset_blocks_corrective_mr_approval(): void
    {
        $mr = $this->maintenanceRequest($this->withdrawnAsset());

        $this->actingAs($this->user(RoleCode::MAINTENANCE_MANAGER))
            ->postJson("/api/maintenance-requests/{$mr->id}/approve", ['is_failure' => true])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Cannot approve a maintenance request for an asset withdrawn from maintenance.');
    }

    public function test_deactivated_asset_blocks_corrective_mr_approval(): void
    {
        $mr = $this->maintenanceRequest($this->deactivatedAsset());

        $this->actingAs($this->user(RoleCode::MAINTENANCE_MANAGER))
            ->postJson("/api/maintenance-requests/{$mr->id}/approve", ['is_failure' => true])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Cannot approve a maintenance request for a deactivated asset.');
    }

    /**
     * The preventive path is called out separately because it is the one the
     * original withdrawn guard was written without: a PM request raised before
     * the asset was withdrawn can still be sitting in the queue afterwards.
     */
    public function test_deactivated_asset_blocks_preventive_mr_approval(): void
    {
        $asset = $this->deactivatedAsset();
        $assignment = $this->pmAssignment($asset);
        $mr = $this->maintenanceRequest($asset, [
            'is_preventive' => true,
            'pm_rule_id' => $assignment->pm_rule_id,
        ]);

        $this->actingAs($this->user(RoleCode::MAINTENANCE_MANAGER))
            ->postJson("/api/maintenance-requests/{$mr->id}/approve")
            ->assertStatus(409)
            ->assertJsonPath('message', 'Cannot approve a maintenance request for a deactivated asset.');
    }

    // ── 3. WO assignment ──────────────────────────────────────────────────────

    public function test_withdrawn_asset_blocks_wo_assignment(): void
    {
        $wo = $this->workOrder($this->withdrawnAsset());

        $this->actingAs($this->user(RoleCode::MAINTENANCE_MANAGER))
            ->postJson("/api/work-orders/{$wo->id}/assign", ['user_id' => $this->user(RoleCode::TECHNICIAN)->id])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Cannot assign a work order for an asset withdrawn from maintenance.');
    }

    public function test_deactivated_asset_blocks_wo_assignment(): void
    {
        $wo = $this->workOrder($this->deactivatedAsset());

        $this->actingAs($this->user(RoleCode::MAINTENANCE_MANAGER))
            ->postJson("/api/work-orders/{$wo->id}/assign", ['user_id' => $this->user(RoleCode::TECHNICIAN)->id])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Cannot assign a work order for a deactivated asset.');
    }

    // ── 4. WO start ───────────────────────────────────────────────────────────

    public function test_withdrawn_asset_blocks_wo_start(): void
    {
        $tech = $this->user(RoleCode::TECHNICIAN);
        $asset = $this->withdrawnAsset();
        $wo = $this->workOrder($asset, ['assigned_to_user_id' => $tech->id]);

        $this->actingAs($this->user(RoleCode::MAINTENANCE_MANAGER))
            ->postJson("/api/work-orders/{$wo->id}/start", ['location_id' => $this->yard()->id])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Cannot start a work order for an asset withdrawn from maintenance.');
    }

    public function test_deactivated_asset_blocks_wo_start(): void
    {
        $tech = $this->user(RoleCode::TECHNICIAN);
        $asset = $this->deactivatedAsset();
        $wo = $this->workOrder($asset, ['assigned_to_user_id' => $tech->id]);

        $this->actingAs($this->user(RoleCode::MAINTENANCE_MANAGER))
            ->postJson("/api/work-orders/{$wo->id}/start", ['location_id' => $this->yard()->id])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Cannot start a work order for a deactivated asset.');
    }

    // ── 5. Direct PM evaluation ───────────────────────────────────────────────

    public function test_withdrawn_asset_blocks_direct_pm_evaluation(): void
    {
        $asset = $this->withdrawnAsset();
        $assignment = $this->pmAssignment($asset);

        $this->actingAs($this->user(RoleCode::MAINTENANCE_MANAGER))
            ->postJson("/api/assets/{$asset->id}/pm-assignments/{$assignment->id}/evaluate")
            ->assertStatus(409)
            ->assertJsonPath('message', 'Cannot evaluate preventive maintenance for an asset withdrawn from maintenance.');

        $this->assertDatabaseMissing('maintenance_requests', ['asset_id' => $asset->id, 'is_preventive' => true]);
    }

    public function test_deactivated_asset_blocks_direct_pm_evaluation(): void
    {
        $asset = $this->deactivatedAsset();
        $assignment = $this->pmAssignment($asset);

        $this->actingAs($this->user(RoleCode::MAINTENANCE_MANAGER))
            ->postJson("/api/assets/{$asset->id}/pm-assignments/{$assignment->id}/evaluate")
            ->assertStatus(409)
            ->assertJsonPath('message', 'Cannot evaluate preventive maintenance for a deactivated asset.');

        $this->assertDatabaseMissing('maintenance_requests', ['asset_id' => $asset->id, 'is_preventive' => true]);
    }

    // ── 6. Evaluate-all batch ─────────────────────────────────────────────────

    public function test_evaluate_all_skips_ineligible_assets_on_both_axes(): void
    {
        $withdrawn = $this->withdrawnAsset();
        $deactivated = $this->deactivatedAsset();
        $eligible = $this->asset();

        $this->pmAssignment($withdrawn);
        $this->pmAssignment($deactivated);
        $this->pmAssignment($eligible);

        $response = $this->actingAs($this->user(RoleCode::ADMINISTRATOR))
            ->postJson('/api/pm-rules/evaluate-all')
            ->assertOk();

        // The eligible asset is the control: it proves the batch ran at all,
        // so "no requests generated" cannot pass for the wrong reason.
        $this->assertSame(1, $response->json('generated'));
        $this->assertDatabaseHas('maintenance_requests', ['asset_id' => $eligible->id, 'is_preventive' => true]);
        $this->assertDatabaseMissing('maintenance_requests', ['asset_id' => $withdrawn->id]);
        $this->assertDatabaseMissing('maintenance_requests', ['asset_id' => $deactivated->id]);
    }

    // ── 7. The scheduler ──────────────────────────────────────────────────────

    /**
     * The widest hole: `scopeEvaluable` filtered on `maintenance_status` only,
     * so the daily 06:00 job kept raising PM requests for deactivated assets
     * long after every hand-written entry point refused them.
     */
    public function test_scheduled_evaluation_skips_ineligible_assets_on_both_axes(): void
    {
        $withdrawn = $this->withdrawnAsset();
        $deactivated = $this->deactivatedAsset();
        $eligible = $this->asset();

        $this->pmAssignment($withdrawn);
        $this->pmAssignment($deactivated);
        $this->pmAssignment($eligible);

        User::factory()->create([
            'email' => 'system@atms.internal',
            'role_id' => Role::where('code', RoleCode::ADMINISTRATOR)->first()->id,
            'is_active' => true,
        ]);

        (new EvaluatePmRulesJob)->handle();

        $this->assertDatabaseHas('maintenance_requests', ['asset_id' => $eligible->id, 'is_preventive' => true]);
        $this->assertDatabaseMissing('maintenance_requests', ['asset_id' => $withdrawn->id]);
        $this->assertDatabaseMissing('maintenance_requests', ['asset_id' => $deactivated->id]);
    }

    // ── 8. Asset location change ──────────────────────────────────────────────

    public function test_withdrawn_asset_blocks_location_change(): void
    {
        $asset = $this->withdrawnAsset();

        $this->actingAs($this->user(RoleCode::ADMINISTRATOR))
            ->postJson("/api/assets/{$asset->id}/location", ['location_id' => $this->yard()->id])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Cannot update location for an asset withdrawn from maintenance.');
    }

    public function test_deactivated_asset_blocks_location_change(): void
    {
        $asset = $this->deactivatedAsset();

        $this->actingAs($this->user(RoleCode::ADMINISTRATOR))
            ->postJson("/api/assets/{$asset->id}/location", ['location_id' => $this->yard()->id])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Cannot update location for a deactivated asset.');
    }

    // ── Finish, don't start ───────────────────────────────────────────────────

    /**
     * The guard must never strand work already under way. An asset withdrawn
     * or deactivated mid-repair still has a technician and a real work order
     * attached; closing it is how that ends, so close and cancel stay open.
     */
    public function test_open_work_order_can_still_be_completed_and_closed_after_deactivation(): void
    {
        $tech = $this->user(RoleCode::TECHNICIAN);
        $manager = $this->user(RoleCode::MAINTENANCE_MANAGER);
        $asset = $this->asset();
        $wo = $this->workOrder($asset, [
            'assigned_to_user_id' => $tech->id,
            'status' => WorkOrderStatus::IN_PROGRESS,
            'started_at' => now(),
        ]);

        // Deactivated *after* the work started — the realistic sequence.
        $asset->update(['is_active' => false]);

        $this->actingAs($manager)
            ->postJson("/api/work-orders/{$wo->id}/complete")
            ->assertOk();
        $this->attachToWorkOrder($wo);

        $this->actingAs($manager)
            ->postJson("/api/work-orders/{$wo->id}/close")
            ->assertOk();

        $this->assertSame(WorkOrderStatus::CLOSED, $wo->fresh()->status);
    }

    public function test_open_work_order_can_still_be_cancelled_after_withdrawal(): void
    {
        $manager = $this->user(RoleCode::MAINTENANCE_MANAGER);
        $asset = $this->asset();
        $wo = $this->workOrder($asset);

        $asset->update(['maintenance_status' => MaintenanceStatus::WITHDRAWN]);

        $this->actingAs($manager)
            ->postJson("/api/work-orders/{$wo->id}/cancel", ['reason' => 'Asset withdrawn from the programme.'])
            ->assertOk();

        $this->assertSame(WorkOrderStatus::CANCELLED, $wo->fresh()->status);
    }

    // ── The eligible case still works ─────────────────────────────────────────

    public function test_eligible_asset_still_permits_new_work(): void
    {
        $asset = $this->asset();

        $this->actingAs($this->user(RoleCode::REQUESTER))
            ->postJson('/api/maintenance-requests/corrective', [
                'asset_id' => $asset->id,
                'priority' => 'high',
                'description' => 'Should succeed',
            ])
            ->assertCreated();

        $this->actingAs($this->user(RoleCode::ADMINISTRATOR))
            ->postJson("/api/assets/{$asset->id}/location", ['location_id' => $this->yard()->id])
            ->assertOk();
    }
}
