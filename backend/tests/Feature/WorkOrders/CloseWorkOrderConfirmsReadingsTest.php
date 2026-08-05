<?php

namespace Tests\Feature\WorkOrders;

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
use App\Models\WorkOrder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Closing a work order confirms the readings taken on it.
 *
 * Verification is a by-product of the close, not a task of its own — see
 * ConfirmWorkOrderReadings for the reasoning.
 */
class CloseWorkOrderConfirmsReadingsTest extends TestCase
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
            'erp_asset_code' => 'AST-CWR-'.uniqid(),
            'name' => 'Test Asset',
            'is_active' => true,
            'current_location_id' => $this->workshopLocation()->id,
        ]);
    }

    /** Drive a work order all the way to completed, ready for close. */
    private function completedWorkOrder(User $manager, User $tech, Asset $asset): WorkOrder
    {
        $requester = $this->createUser(RoleCode::REQUESTER);

        $mr = MaintenanceRequest::create([
            'number' => 'MR-'.str_pad((string) (MaintenanceRequest::count() + 1), 6, '0', STR_PAD_LEFT),
            'asset_id' => $asset->id,
            'status' => 'pending_review',
            'priority' => 'high',
            'description' => 'Test request',
            'created_by' => $requester->id,
            'is_preventive' => false,
        ]);

        $this->actingAs($manager)->postJson("/api/maintenance-requests/{$mr->id}/approve", [
            'is_failure' => true,
        ])->assertOk();

        $wo = WorkOrder::where('maintenance_request_id', $mr->id)->first();

        $this->actingAs($manager)->postJson("/api/work-orders/{$wo->id}/assign", ['user_id' => $tech->id])->assertOk();
        $this->actingAs($tech)->postJson("/api/work-orders/{$wo->id}/start")->assertOk();
        $this->actingAs($tech)->postJson("/api/work-orders/{$wo->id}/complete", [
            'completion_notes' => 'Done',
        ])->assertOk();

        return $wo->fresh();
    }

    private function reading(
        Asset $asset,
        UsageReadingType $type,
        float $value,
        string $readAt,
        ?WorkOrder $wo = null,
        bool $confirmed = false,
    ): AssetMeterReading {
        return AssetMeterReading::create([
            'asset_id' => $asset->id,
            'usage_reading_type_id' => $type->id,
            'reading_value' => $value,
            'reading_at' => $readAt,
            'source' => 'manual',
            'work_order_id' => $wo?->id,
            'confirmed_at' => $confirmed ? now() : null,
        ]);
    }

    public function test_closing_confirms_the_readings_taken_on_the_work_order(): void
    {
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $tech = $this->createUser(RoleCode::TECHNICIAN);
        $asset = $this->createAsset();
        $type = UsageReadingType::create(['name' => 'Hours', 'unit' => 'h']);

        $wo = $this->completedWorkOrder($manager, $tech, $asset);
        $reading = $this->reading($asset, $type, 1200, '2026-08-01 08:00:00', $wo);

        $this->actingAs($manager)->postJson("/api/work-orders/{$wo->id}/close")->assertOk();

        $reading = $reading->fresh();
        $this->assertNotNull($reading->confirmed_at);
        $this->assertEquals($manager->id, $reading->confirmed_by_user_id);
    }

    public function test_readings_from_other_work_orders_are_left_alone(): void
    {
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $tech = $this->createUser(RoleCode::TECHNICIAN);
        $asset = $this->createAsset();
        $type = UsageReadingType::create(['name' => 'Hours', 'unit' => 'h']);

        $wo = $this->completedWorkOrder($manager, $tech, $asset);

        // Unattached history on the same asset, plus a reading on another WO.
        $orphan = $this->reading($asset, $type, 100, '2026-07-01 08:00:00');
        $otherWo = $this->completedWorkOrder($manager, $tech, $this->createAsset());
        $otherReading = $this->reading($asset, $type, 200, '2026-07-15 08:00:00', $otherWo);

        $this->actingAs($manager)->postJson("/api/work-orders/{$wo->id}/close")->assertOk();

        $this->assertNull($orphan->fresh()->confirmed_at);
        $this->assertNull($otherReading->fresh()->confirmed_at);
    }

    /**
     * The ordering guarantee. ConfirmMeterReading rejects a reading dated before
     * the latest confirmed one, so confirming newest-first would strand every
     * older reading on the same work order.
     */
    public function test_readings_are_confirmed_oldest_first(): void
    {
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $tech = $this->createUser(RoleCode::TECHNICIAN);
        $asset = $this->createAsset();
        $type = UsageReadingType::create(['name' => 'Hours', 'unit' => 'h']);

        $wo = $this->completedWorkOrder($manager, $tech, $asset);

        // Inserted newest-first on purpose: id order is the opposite of date order.
        $newest = $this->reading($asset, $type, 1400, '2026-08-03 08:00:00', $wo);
        $middle = $this->reading($asset, $type, 1300, '2026-08-02 08:00:00', $wo);
        $oldest = $this->reading($asset, $type, 1200, '2026-08-01 08:00:00', $wo);

        $this->actingAs($manager)->postJson("/api/work-orders/{$wo->id}/close")->assertOk();

        $this->assertNotNull($oldest->fresh()->confirmed_at);
        $this->assertNotNull($middle->fresh()->confirmed_at);
        $this->assertNotNull($newest->fresh()->confirmed_at);
    }

    /**
     * The load-bearing regression: a reading that cannot pass the guard must not
     * stop a manager from closing the work order.
     */
    public function test_a_reading_that_fails_the_date_guard_is_skipped_and_the_close_succeeds(): void
    {
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $tech = $this->createUser(RoleCode::TECHNICIAN);
        $asset = $this->createAsset();
        $type = UsageReadingType::create(['name' => 'Hours', 'unit' => 'h']);

        // Already-confirmed history dated after everything on the work order.
        $this->reading($asset, $type, 5000, '2026-09-01 08:00:00', null, true);

        $wo = $this->completedWorkOrder($manager, $tech, $asset);
        $stale = $this->reading($asset, $type, 6000, '2026-08-01 08:00:00', $wo);

        $this->actingAs($manager)->postJson("/api/work-orders/{$wo->id}/close")->assertOk();

        $this->assertEquals('closed', $wo->fresh()->status->value);
        $this->assertNull($stale->fresh()->confirmed_at);
    }

    /**
     * The value guard is separate from the date guard. Delta entry makes a
     * decrease hard to reach from the work order form, but UpdateMeterReading has
     * no monotonicity check at all, so the path stays live.
     */
    public function test_a_reading_that_fails_the_value_guard_is_skipped_and_the_close_succeeds(): void
    {
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $tech = $this->createUser(RoleCode::TECHNICIAN);
        $asset = $this->createAsset();
        $type = UsageReadingType::create(['name' => 'Hours', 'unit' => 'h']);

        $this->reading($asset, $type, 5000, '2026-08-01 08:00:00', null, true);

        $wo = $this->completedWorkOrder($manager, $tech, $asset);
        $lower = $this->reading($asset, $type, 4000, '2026-08-05 08:00:00', $wo);

        $this->actingAs($manager)->postJson("/api/work-orders/{$wo->id}/close")->assertOk();

        $this->assertEquals('closed', $wo->fresh()->status->value);
        $this->assertNull($lower->fresh()->confirmed_at);
    }

    public function test_a_skipped_reading_is_audited(): void
    {
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $tech = $this->createUser(RoleCode::TECHNICIAN);
        $asset = $this->createAsset();
        $type = UsageReadingType::create(['name' => 'Hours', 'unit' => 'h']);

        $this->reading($asset, $type, 5000, '2026-09-01 08:00:00', null, true);

        $wo = $this->completedWorkOrder($manager, $tech, $asset);
        $stale = $this->reading($asset, $type, 6000, '2026-08-01 08:00:00', $wo);

        $this->actingAs($manager)->postJson("/api/work-orders/{$wo->id}/close")->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'meter_reading.confirm_skipped',
            'subject_id' => $stale->id,
        ]);
    }

    public function test_closing_is_idempotent_over_already_confirmed_readings(): void
    {
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $tech = $this->createUser(RoleCode::TECHNICIAN);
        $asset = $this->createAsset();
        $type = UsageReadingType::create(['name' => 'Hours', 'unit' => 'h']);

        $wo = $this->completedWorkOrder($manager, $tech, $asset);
        $already = $this->reading($asset, $type, 1200, '2026-08-01 08:00:00', $wo, true);
        $stamp = $already->fresh()->confirmed_at;

        $this->actingAs($manager)->postJson("/api/work-orders/{$wo->id}/close")->assertOk();

        $this->assertEquals($stamp->toIso8601String(), $already->fresh()->confirmed_at->toIso8601String());
        $this->assertDatabaseMissing('audit_logs', [
            'event' => 'meter_reading.confirm_skipped',
            'subject_id' => $already->id,
        ]);
    }

    /**
     * The ordering inside CloseWorkOrder: the PM baseline query reads the latest
     * *confirmed* reading, so a reading confirmed during this same close has to
     * land in the baseline. Moving the confirm call below the PM block breaks
     * this silently.
     */
    public function test_pm_baseline_picks_up_a_reading_confirmed_by_the_same_close(): void
    {
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $tech = $this->createUser(RoleCode::TECHNICIAN);
        $requester = $this->createUser(RoleCode::REQUESTER);
        $asset = $this->createAsset();
        $type = UsageReadingType::create(['name' => 'Hours', 'unit' => 'h']);

        $rule = PmRule::create([
            'name' => 'L1 Motors',
            'maintenance_level' => 'L1',
            'trigger_type' => PmTriggerType::DATE_OR_READING,
            'usage_reading_type_id' => $type->id,
            'interval_reading' => 1000,
            'interval_days' => 90,
            'is_active' => true,
            'created_by' => $manager->id,
        ]);

        $assignment = AssetPmAssignment::create([
            'asset_id' => $asset->id,
            'pm_rule_id' => $rule->id,
            'last_triggered_date' => now()->subDays(120)->toDateString(),
            'last_triggered_reading' => 1000,
            'is_active' => true,
            'assigned_by' => $manager->id,
        ]);

        // A preventive MR carrying the rule, so CloseWorkOrder runs the PM branch.
        $mr = MaintenanceRequest::create([
            'number' => 'MR-PM-'.uniqid(),
            'asset_id' => $asset->id,
            'status' => 'pending_review',
            'priority' => 'medium',
            'description' => 'PM due',
            'created_by' => $requester->id,
            'is_preventive' => true,
            'pm_rule_id' => $rule->id,
        ]);

        $this->actingAs($manager)->postJson("/api/maintenance-requests/{$mr->id}/approve")->assertOk();
        $wo = WorkOrder::where('maintenance_request_id', $mr->id)->first();

        $this->actingAs($manager)->postJson("/api/work-orders/{$wo->id}/assign", ['user_id' => $tech->id])->assertOk();
        $this->actingAs($tech)->postJson("/api/work-orders/{$wo->id}/start")->assertOk();
        $this->actingAs($tech)->postJson("/api/work-orders/{$wo->id}/complete", ['completion_notes' => 'Done'])->assertOk();

        $this->reading($asset, $type, 2150, '2026-08-01 08:00:00', $wo);

        $this->actingAs($manager)->postJson("/api/work-orders/{$wo->id}/close")->assertOk();

        $this->assertEquals(2150.0, (float) $assignment->fresh()->last_triggered_reading);
    }
}
