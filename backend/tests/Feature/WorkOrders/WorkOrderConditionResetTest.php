<?php

namespace Tests\Feature\WorkOrders;

use App\Enums\LocationType;
use App\Enums\RoleCode;
use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\Location;
use App\Models\MaintenanceRequest;
use App\Models\MasterDataItem;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkOrder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Closing a work order returns the asset's condition to the default.
 *
 * The condition records what is *wrong* with an asset — Need Assembly, Missing
 * Parts, Need Inspection. Closing is the moment those stop being true, so
 * leaving the old value would have every repaired asset still advertising the
 * fault it arrived with, and the Condition column would slowly become a list of
 * things that used to be broken.
 *
 * Cancel deliberately does not reset: a cancelled job fixed nothing.
 */
class WorkOrderConditionResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function user(RoleCode $roleCode): User
    {
        return User::factory()->create([
            'role_id' => Role::where('code', $roleCode)->first()->id,
            'is_active' => true,
        ]);
    }

    /** A completed work order, ready to close, on an asset with $condition. */
    private function completedWorkOrder(string $condition): WorkOrder
    {
        $manager = $this->user(RoleCode::MAINTENANCE_MANAGER);
        $tech = $this->user(RoleCode::TECHNICIAN);

        // A workshop: starting a work order requires the asset to be at one
        // (or a yard), which StartWorkOrderLocationTest covers in its own right.
        $workshop = Location::create([
            'name' => 'Workshop-'.uniqid(),
            'type' => LocationType::WORKSHOP->value,
            'is_active' => true,
        ]);

        $asset = Asset::create([
            'erp_asset_code' => 'AST-COND-'.uniqid(),
            'name' => 'Conditioned Asset',
            'is_active' => true,
            'condition_status' => $condition,
            'current_location_id' => $workshop->id,
        ]);

        $mr = MaintenanceRequest::create([
            'number' => 'MR-'.uniqid(),
            'asset_id' => $asset->id,
            'status' => 'converted',
            'priority' => 'medium',
            'description' => 'Repair',
            'created_by' => $this->user(RoleCode::REQUESTER)->id,
            'is_preventive' => false,
        ]);

        $wo = WorkOrder::create([
            'number' => 'WO-'.uniqid(),
            'maintenance_request_id' => $mr->id,
            'asset_id' => $asset->id,
            'status' => 'open',
            'priority' => 'medium',
            'description' => 'Repair',
        ]);

        $this->actingAs($manager)->postJson("/api/work-orders/{$wo->id}/assign", ['user_id' => $tech->id])->assertOk();
        $this->actingAs($tech)->postJson("/api/work-orders/{$wo->id}/start")->assertOk();
        $this->actingAs($tech)->postJson("/api/work-orders/{$wo->id}/complete", ['completion_notes' => 'Done'])->assertOk();
        $this->attachToWorkOrder($wo);

        return $wo->fresh();
    }

    public function test_closing_resets_the_condition_to_the_default(): void
    {
        $manager = $this->user(RoleCode::MAINTENANCE_MANAGER);
        $wo = $this->completedWorkOrder('missing_parts');

        $this->actingAs($manager)->postJson("/api/work-orders/{$wo->id}/close")->assertOk();

        $this->assertSame('normal', $wo->asset->fresh()->condition_status);
    }

    public function test_the_reset_is_audited_with_both_ends_of_the_change(): void
    {
        $manager = $this->user(RoleCode::MAINTENANCE_MANAGER);
        $wo = $this->completedWorkOrder('need_assembly');

        $this->actingAs($manager)->postJson("/api/work-orders/{$wo->id}/close")->assertOk();

        $log = AuditLog::where('event', 'asset.condition_reset')->sole();
        $this->assertSame('need_assembly', $log->metadata['from']);
        $this->assertSame('normal', $log->metadata['to']);
        $this->assertSame($wo->id, $log->metadata['work_order_id']);
    }

    public function test_an_asset_already_at_the_default_is_not_rewritten(): void
    {
        $manager = $this->user(RoleCode::MAINTENANCE_MANAGER);
        $wo = $this->completedWorkOrder('normal');

        $this->actingAs($manager)->postJson("/api/work-orders/{$wo->id}/close")->assertOk();

        $this->assertSame('normal', $wo->asset->fresh()->condition_status);
        $this->assertSame(0, AuditLog::where('event', 'asset.condition_reset')->count());
    }

    /**
     * An asset flagged on its way back from the field, now being closed out, is
     * the case RQ1 (Phase 6) will tighten: once PM marking exists this should
     * only warn when no level was recorded. Until then it warns on the condition
     * alone, which is the half that is knowable.
     */
    public function test_closing_an_asset_that_needed_inspection_returns_a_warning(): void
    {
        $manager = $this->user(RoleCode::MAINTENANCE_MANAGER);
        $wo = $this->completedWorkOrder('need_inspection');

        $response = $this->actingAs($manager)->postJson("/api/work-orders/{$wo->id}/close")->assertOk();

        $this->assertCount(1, $response->json('warnings'));
        $this->assertStringContainsString('Need Inspection', $response->json('warnings.0'));

        // Non-blocking: the close still happened and the reset still applied.
        $this->assertSame('closed', $wo->fresh()->status->value);
        $this->assertSame('normal', $wo->asset->fresh()->condition_status);
    }

    public function test_an_ordinary_close_carries_no_warnings(): void
    {
        $manager = $this->user(RoleCode::MAINTENANCE_MANAGER);
        $wo = $this->completedWorkOrder('missing_parts');

        $response = $this->actingAs($manager)->postJson("/api/work-orders/{$wo->id}/close")->assertOk();

        $this->assertSame([], $response->json('warnings'));
    }

    /**
     * With no resolvable default the condition is left exactly as it was. The
     * alternative — clearing it — would destroy a real observation because a
     * vocabulary row was missing.
     */
    public function test_a_missing_default_leaves_the_condition_alone_and_warns(): void
    {
        MasterDataItem::where('group_key', MasterDataItem::ASSET_CONDITIONS)
            ->where('is_default', true)
            ->update(['is_active' => false]);

        $manager = $this->user(RoleCode::MAINTENANCE_MANAGER);
        $wo = $this->completedWorkOrder('missing_parts');

        $response = $this->actingAs($manager)->postJson("/api/work-orders/{$wo->id}/close")->assertOk();

        $this->assertSame('missing_parts', $wo->asset->fresh()->condition_status);
        $this->assertSame(1, AuditLog::where('event', 'asset.condition_reset_skipped')->count());
        $this->assertStringContainsString('no default condition is configured', $response->json('warnings.0'));
    }

    public function test_cancelling_never_resets_the_condition(): void
    {
        $manager = $this->user(RoleCode::MAINTENANCE_MANAGER);
        $wo = $this->completedWorkOrder('missing_parts');

        $this->actingAs($manager)->postJson("/api/work-orders/{$wo->id}/cancel", [
            'reason' => 'Parts never arrived.',
            'asset_status' => 'failure',
        ])->assertOk();

        $this->assertSame(
            'missing_parts',
            $wo->asset->fresh()->condition_status,
            'A cancelled job fixed nothing, so what was wrong is still wrong.',
        );
    }
}
