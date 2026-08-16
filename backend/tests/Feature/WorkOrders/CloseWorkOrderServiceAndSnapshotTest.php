<?php

namespace Tests\Feature\WorkOrders;

use App\Enums\MaintenanceRequestStatus;
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
use App\Models\WorkOrderMeterSnapshot;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Two things that happen when a work order closes:
 *
 *  - the asset's meter position is snapshotted per reading type, which is what
 *    makes "usage since the last repair" answerable at all;
 *  - the closer may declare that a preventive service was performed alongside a
 *    repair, resetting that schedule's baselines and retiring the PM request
 *    already raised for it.
 */
class CloseWorkOrderServiceAndSnapshotTest extends TestCase
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
            'erp_asset_code' => 'AST-SVC-'.uniqid(),
            'name' => 'Test Asset',
            'is_active' => true,
            'current_location_id' => $this->workshopLocation()->id,
        ]);
    }

    private function completedWorkOrder(User $manager, User $tech, Asset $asset): WorkOrder
    {
        $requester = $this->createUser(RoleCode::REQUESTER);

        $mr = MaintenanceRequest::create([
            'number' => 'MR-'.uniqid(),
            'asset_id' => $asset->id,
            'status' => 'pending_review',
            'priority' => 'high',
            'description' => 'Broken',
            'created_by' => $requester->id,
            'is_preventive' => false,
        ]);

        $this->actingAs($manager)->postJson("/api/maintenance-requests/{$mr->id}/approve", [
            'is_failure' => true,
        ])->assertOk();

        $wo = WorkOrder::where('maintenance_request_id', $mr->id)->first();

        $this->actingAs($manager)->postJson("/api/work-orders/{$wo->id}/assign", ['user_id' => $tech->id])->assertOk();
        $this->actingAs($tech)->postJson("/api/work-orders/{$wo->id}/start")->assertOk();
        $this->actingAs($tech)->postJson("/api/work-orders/{$wo->id}/complete", ['completion_notes' => 'Done'])->assertOk();
        $this->attachToWorkOrder($wo);

        return $wo->fresh();
    }

    private function confirmedReading(Asset $a, UsageReadingType $t, float $v, string $at, User $by): AssetMeterReading
    {
        return AssetMeterReading::create([
            'asset_id' => $a->id,
            'usage_reading_type_id' => $t->id,
            'reading_value' => $v,
            'reading_at' => $at,
            'source' => 'manual',
            'confirmed_at' => now(),
            'confirmed_by_user_id' => $by->id,
        ]);
    }

    private function rule(User $by, UsageReadingType $type, string $level, float $interval, int $days): PmRule
    {
        return PmRule::create([
            'name' => "{$level} Motors",
            'maintenance_level' => $level,
            'trigger_type' => PmTriggerType::DATE_OR_READING,
            'usage_reading_type_id' => $type->id,
            'interval_reading' => $interval,
            'interval_days' => $days,
            'is_active' => true,
            'created_by' => $by->id,
        ]);
    }

    private function assignment(Asset $a, PmRule $r, User $by, ?float $baseline = 1000): AssetPmAssignment
    {
        return AssetPmAssignment::create([
            'asset_id' => $a->id,
            'pm_rule_id' => $r->id,
            'last_triggered_date' => now()->subDays(200)->toDateString(),
            'last_triggered_reading' => $baseline,
            'is_active' => true,
            'assigned_by' => $by->id,
        ]);
    }

    // ── Meter snapshot ───────────────────────────────────────────────────────

    public function test_close_snapshots_one_row_per_reading_type(): void
    {
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $tech = $this->createUser(RoleCode::TECHNICIAN);
        $asset = $this->createAsset();

        $hours = UsageReadingType::create(['name' => 'Operating Hours', 'unit' => 'h']);
        $km = UsageReadingType::create(['name' => 'Kilometer Driven', 'unit' => 'km']);

        $this->confirmedReading($asset, $hours, 1240, '2026-08-01 08:00:00', $manager);
        $this->confirmedReading($asset, $km, 88000, '2026-08-01 08:00:00', $manager);

        $wo = $this->completedWorkOrder($manager, $tech, $asset);
        $this->actingAs($manager)->postJson("/api/work-orders/{$wo->id}/close")->assertOk();

        $snapshots = WorkOrderMeterSnapshot::where('work_order_id', $wo->id)->get();

        $this->assertCount(2, $snapshots, 'A single column pair would have lost one of these.');
        $this->assertEquals(1240.0, (float) $snapshots->firstWhere('usage_reading_type_id', $hours->id)->reading_value);
        $this->assertEquals(88000.0, (float) $snapshots->firstWhere('usage_reading_type_id', $km->id)->reading_value);
    }

    /**
     * The snapshot must run after readings are confirmed, or it captures the
     * previous meter position and understates every interval measured from it.
     */
    public function test_snapshot_captures_a_reading_confirmed_by_this_same_close(): void
    {
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $tech = $this->createUser(RoleCode::TECHNICIAN);
        $asset = $this->createAsset();
        $hours = UsageReadingType::create(['name' => 'Operating Hours', 'unit' => 'h']);

        $this->confirmedReading($asset, $hours, 1000, '2026-07-01 08:00:00', $manager);

        $wo = $this->completedWorkOrder($manager, $tech, $asset);

        // Unverified at close time — ConfirmWorkOrderReadings confirms it first.
        AssetMeterReading::create([
            'asset_id' => $asset->id,
            'usage_reading_type_id' => $hours->id,
            'reading_value' => 1240,
            'reading_at' => '2026-08-01 08:00:00',
            'source' => 'manual',
            'work_order_id' => $wo->id,
        ]);

        $this->actingAs($manager)->postJson("/api/work-orders/{$wo->id}/close")->assertOk();

        $snapshot = WorkOrderMeterSnapshot::where('work_order_id', $wo->id)->first();
        $this->assertEquals(1240.0, (float) $snapshot->reading_value);
    }

    public function test_no_snapshot_when_the_asset_has_no_confirmed_readings(): void
    {
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $tech = $this->createUser(RoleCode::TECHNICIAN);
        $asset = $this->createAsset();

        $wo = $this->completedWorkOrder($manager, $tech, $asset);
        $this->actingAs($manager)->postJson("/api/work-orders/{$wo->id}/close")->assertOk();

        $this->assertSame(0, WorkOrderMeterSnapshot::where('work_order_id', $wo->id)->count());
    }

    // ── Declared service on a repair ─────────────────────────────────────────

    public function test_declaring_a_service_resets_the_date_and_reading_baselines(): void
    {
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $tech = $this->createUser(RoleCode::TECHNICIAN);
        $asset = $this->createAsset();
        $hours = UsageReadingType::create(['name' => 'Operating Hours', 'unit' => 'h']);

        $l1 = $this->assignment($asset, $this->rule($manager, $hours, 'L1', 1000, 90), $manager);
        $this->confirmedReading($asset, $hours, 2400, '2026-08-01 08:00:00', $manager);

        $wo = $this->completedWorkOrder($manager, $tech, $asset);

        $this->actingAs($manager)->postJson("/api/work-orders/{$wo->id}/close", [
            'serviced_pm_assignment_id' => $l1->id,
        ])->assertOk();

        $l1 = $l1->fresh();
        $this->assertEquals(2400.0, (float) $l1->last_triggered_reading);
        $this->assertEquals(now()->toDateString(), $l1->last_triggered_date->toDateString());
    }

    public function test_declaring_a_higher_level_service_cascades_to_lower_levels(): void
    {
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $tech = $this->createUser(RoleCode::TECHNICIAN);
        $asset = $this->createAsset();
        $hours = UsageReadingType::create(['name' => 'Operating Hours', 'unit' => 'h']);

        $l1 = $this->assignment($asset, $this->rule($manager, $hours, 'L1', 1000, 90), $manager);
        $l2 = $this->assignment($asset, $this->rule($manager, $hours, 'L2', 2000, 180), $manager);

        $this->confirmedReading($asset, $hours, 2400, '2026-08-01 08:00:00', $manager);
        $wo = $this->completedWorkOrder($manager, $tech, $asset);

        $this->actingAs($manager)->postJson("/api/work-orders/{$wo->id}/close", [
            'serviced_pm_assignment_id' => $l2->id,
        ])->assertOk();

        $this->assertEquals(2400.0, (float) $l2->fresh()->last_triggered_reading);
        $this->assertEquals(2400.0, (float) $l1->fresh()->last_triggered_reading, 'L1 should reset beneath L2.');
    }

    public function test_declaring_a_service_retires_the_pending_pm_request(): void
    {
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $tech = $this->createUser(RoleCode::TECHNICIAN);
        $requester = $this->createUser(RoleCode::REQUESTER);
        $asset = $this->createAsset();
        $hours = UsageReadingType::create(['name' => 'Operating Hours', 'unit' => 'h']);

        $rule = $this->rule($manager, $hours, 'L1', 1000, 90);
        $l1 = $this->assignment($asset, $rule, $manager);
        $this->confirmedReading($asset, $hours, 2400, '2026-08-01 08:00:00', $manager);

        // The PM engine already raised this for the level about to be serviced.
        $pmMr = MaintenanceRequest::create([
            'number' => 'MR-PM-'.uniqid(),
            'asset_id' => $asset->id,
            'status' => MaintenanceRequestStatus::PENDING_REVIEW,
            'priority' => 'medium',
            'description' => 'L1 due',
            'created_by' => $requester->id,
            'is_preventive' => true,
            'pm_rule_id' => $rule->id,
            'triggered_by_date' => true,
            'triggered_by_reading' => true,
            'trigger_date' => now()->toDateString(),
            'trigger_reading_value' => 2400,
            'trigger_reading_type_id' => $hours->id,
        ]);

        $wo = $this->completedWorkOrder($manager, $tech, $asset);

        $this->actingAs($manager)->postJson("/api/work-orders/{$wo->id}/close", [
            'serviced_pm_assignment_id' => $l1->id,
        ])->assertOk();

        $this->assertEquals(MaintenanceRequestStatus::CANCELLED, $pmMr->fresh()->status);
        $this->assertStringContainsString($wo->number, $pmMr->fresh()->cancellation_reason);

        // Reported as work done elsewhere, not as a skipped service.
        $suppression = PmOccurrenceSuppression::where('maintenance_request_id', $pmMr->id)->first();
        $this->assertNotNull($suppression);
        $this->assertEquals('performed_under_repair', $suppression->decision_type);
    }

    public function test_an_assignment_from_another_asset_is_refused(): void
    {
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $tech = $this->createUser(RoleCode::TECHNICIAN);
        $asset = $this->createAsset();
        $other = $this->createAsset();
        $hours = UsageReadingType::create(['name' => 'Operating Hours', 'unit' => 'h']);

        $foreign = $this->assignment($other, $this->rule($manager, $hours, 'L1', 1000, 90), $manager);
        $wo = $this->completedWorkOrder($manager, $tech, $asset);

        $this->actingAs($manager)->postJson("/api/work-orders/{$wo->id}/close", [
            'serviced_pm_assignment_id' => $foreign->id,
        ])->assertStatus(409);

        $this->assertEquals('completed', $wo->fresh()->status->value, 'A refused close must not half-apply.');
    }

    public function test_an_inactive_assignment_is_refused(): void
    {
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $tech = $this->createUser(RoleCode::TECHNICIAN);
        $asset = $this->createAsset();
        $hours = UsageReadingType::create(['name' => 'Operating Hours', 'unit' => 'h']);

        $l1 = $this->assignment($asset, $this->rule($manager, $hours, 'L1', 1000, 90), $manager);
        $l1->update(['is_active' => false]);

        $wo = $this->completedWorkOrder($manager, $tech, $asset);

        $this->actingAs($manager)->postJson("/api/work-orders/{$wo->id}/close", [
            'serviced_pm_assignment_id' => $l1->id,
        ])->assertStatus(409);
    }

    public function test_closing_without_declaring_a_service_leaves_assignments_alone(): void
    {
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $tech = $this->createUser(RoleCode::TECHNICIAN);
        $asset = $this->createAsset();
        $hours = UsageReadingType::create(['name' => 'Operating Hours', 'unit' => 'h']);

        $l1 = $this->assignment($asset, $this->rule($manager, $hours, 'L1', 1000, 90), $manager);
        $this->confirmedReading($asset, $hours, 2400, '2026-08-01 08:00:00', $manager);

        $wo = $this->completedWorkOrder($manager, $tech, $asset);
        $this->actingAs($manager)->postJson("/api/work-orders/{$wo->id}/close")->assertOk();

        $this->assertEquals(1000.0, (float) $l1->fresh()->last_triggered_reading);
    }
}
