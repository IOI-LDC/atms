<?php

namespace Tests\Feature\WorkOrders;

use App\Enums\LocationType;
use App\Enums\OperationalStatus;
use App\Enums\RoleCode;
use App\Enums\WorkOrderStatus;
use App\Models\Asset;
use App\Models\Location;
use App\Models\MaintenanceRequest;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkOrder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Starting a work order requires the asset to be at a workshop or yard.
 *
 * An asset recorded at a rig while it is being repaired is counted as DEPLOYED
 * by `AssetDeployment`, so the utilisation figures report it as earning. The
 * move happens in the real world; these tests pin that the system records it.
 */
class StartWorkOrderLocationTest extends TestCase
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

    private function location(string $code, LocationType $type, bool $active = true): Location
    {
        return Location::create([
            'name' => ucfirst($type->value).' '.$code,
            'code' => $code,
            'type' => $type->value,
            'is_active' => $active,
        ]);
    }

    private function assetAt(?Location $location): Asset
    {
        return Asset::create([
            'erp_asset_code' => 'AST-LOC-'.uniqid(),
            'name' => 'Located Asset',
            'is_active' => true,
            'current_location_id' => $location?->id,
        ]);
    }

    /** Returns an open work order already assigned to $tech. */
    private function assignedWorkOrder(Asset $asset, User $manager, User $tech): WorkOrder
    {
        $requester = $this->createUser(RoleCode::REQUESTER);

        $mr = MaintenanceRequest::create([
            'number' => 'MR-'.str_pad((string) (MaintenanceRequest::count() + 1), 6, '0', STR_PAD_LEFT),
            'asset_id' => $asset->id,
            'status' => 'pending_review',
            'priority' => 'high',
            'description' => 'Needs work',
            'created_by' => $requester->id,
            'is_preventive' => false,
        ]);

        $this->actingAs($manager)->postJson("/api/maintenance-requests/{$mr->id}/approve", [
            'is_failure' => true,
        ])->assertOk();

        $wo = WorkOrder::where('maintenance_request_id', $mr->id)->first();

        $this->actingAs($manager)->postJson("/api/work-orders/{$wo->id}/assign", [
            'user_id' => $tech->id,
        ])->assertOk();

        return $wo->fresh();
    }

    public function test_start_is_rejected_when_the_asset_is_on_a_rig(): void
    {
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $tech = $this->createUser(RoleCode::TECHNICIAN);
        $rig = $this->location('RA', LocationType::RIG);
        $asset = $this->assetAt($rig);
        $wo = $this->assignedWorkOrder($asset, $manager, $tech);

        $this->actingAs($tech)->postJson("/api/work-orders/{$wo->id}/start")
            ->assertStatus(409)
            ->assertJsonPath('message', 'This asset is at Rig RA. Select the workshop or yard where the work is being performed before starting this work order.');

        // Nothing moved, nothing started.
        $this->assertEquals(WorkOrderStatus::OPEN, $wo->fresh()->status);
        $this->assertNull($wo->fresh()->started_at);
        $this->assertEquals($rig->id, $asset->fresh()->current_location_id);
        $this->assertDatabaseCount('asset_location_histories', 0);
    }

    public function test_start_is_rejected_when_the_asset_has_no_location(): void
    {
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $tech = $this->createUser(RoleCode::TECHNICIAN);
        $wo = $this->assignedWorkOrder($this->assetAt(null), $manager, $tech);

        $this->actingAs($tech)->postJson("/api/work-orders/{$wo->id}/start")
            ->assertStatus(409)
            ->assertJsonPath('message', 'This asset has no recorded location. Select the workshop or yard where the work is being performed before starting this work order.');
    }

    /** The permission decision: the assigned technician performs the move, not logistics. */
    public function test_assigned_technician_can_move_the_asset_and_start(): void
    {
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $tech = $this->createUser(RoleCode::TECHNICIAN);
        $rig = $this->location('RA', LocationType::RIG);
        $workshop = $this->location('WS', LocationType::WORKSHOP);
        $asset = $this->assetAt($rig);
        $wo = $this->assignedWorkOrder($asset, $manager, $tech);

        $this->actingAs($tech)->postJson("/api/work-orders/{$wo->id}/start", [
            'location_id' => $workshop->id,
        ])->assertOk();

        $this->assertEquals(WorkOrderStatus::IN_PROGRESS, $wo->fresh()->status);
        $this->assertEquals($workshop->id, $asset->fresh()->current_location_id);

        $this->assertDatabaseHas('asset_location_histories', [
            'asset_id' => $asset->id,
            'from_location_id' => $rig->id,
            'to_location_id' => $workshop->id,
            'reason' => 'Started work order '.$wo->number,
            'changed_by_user_id' => $tech->id,
        ]);
    }

    public function test_a_yard_is_an_acceptable_destination(): void
    {
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $tech = $this->createUser(RoleCode::TECHNICIAN);
        $asset = $this->assetAt($this->location('WX', LocationType::WELL_SITE));
        $yard = $this->location('MY', LocationType::YARD);
        $wo = $this->assignedWorkOrder($asset, $manager, $tech);

        $this->actingAs($tech)->postJson("/api/work-orders/{$wo->id}/start", [
            'location_id' => $yard->id,
        ])->assertOk();

        $this->assertEquals($yard->id, $asset->fresh()->current_location_id);
    }

    public function test_an_asset_already_at_a_yard_starts_without_a_move(): void
    {
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $tech = $this->createUser(RoleCode::TECHNICIAN);
        $yard = $this->location('MY', LocationType::YARD);
        $asset = $this->assetAt($yard);
        $wo = $this->assignedWorkOrder($asset, $manager, $tech);

        $this->actingAs($tech)->postJson("/api/work-orders/{$wo->id}/start")->assertOk();

        $this->assertEquals(WorkOrderStatus::IN_PROGRESS, $wo->fresh()->status);
        $this->assertEquals($yard->id, $asset->fresh()->current_location_id);
        $this->assertDatabaseCount('asset_location_histories', 0);
    }

    public function test_an_asset_already_at_a_workshop_starts_without_a_move(): void
    {
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $tech = $this->createUser(RoleCode::TECHNICIAN);
        $asset = $this->assetAt($this->location('WS', LocationType::WORKSHOP));
        $wo = $this->assignedWorkOrder($asset, $manager, $tech);

        $this->actingAs($tech)->postJson("/api/work-orders/{$wo->id}/start")->assertOk();

        $this->assertEquals(WorkOrderStatus::IN_PROGRESS, $wo->fresh()->status);
        $this->assertDatabaseCount('asset_location_histories', 0);
    }

    public function test_a_rig_cannot_be_chosen_as_the_work_location(): void
    {
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $tech = $this->createUser(RoleCode::TECHNICIAN);
        $asset = $this->assetAt($this->location('RA', LocationType::RIG));
        $otherRig = $this->location('RB', LocationType::RIG);
        $wo = $this->assignedWorkOrder($asset, $manager, $tech);

        $this->actingAs($tech)->postJson("/api/work-orders/{$wo->id}/start", [
            'location_id' => $otherRig->id,
        ])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Work orders are performed at a workshop or yard. "Rig RB" is neither.');

        $this->assertEquals(WorkOrderStatus::OPEN, $wo->fresh()->status);
    }

    /** A building is idle storage, not a place work happens. */
    public function test_a_building_is_not_a_work_location(): void
    {
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $tech = $this->createUser(RoleCode::TECHNICIAN);
        $asset = $this->assetAt($this->location('MB', LocationType::BUILDING));
        $wo = $this->assignedWorkOrder($asset, $manager, $tech);

        $this->actingAs($tech)->postJson("/api/work-orders/{$wo->id}/start")->assertStatus(409);
    }

    public function test_an_inactive_location_is_rejected(): void
    {
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $tech = $this->createUser(RoleCode::TECHNICIAN);
        $asset = $this->assetAt($this->location('RA', LocationType::RIG));
        $closed = $this->location('WS', LocationType::WORKSHOP, active: false);
        $wo = $this->assignedWorkOrder($asset, $manager, $tech);

        $this->actingAs($tech)->postJson("/api/work-orders/{$wo->id}/start", [
            'location_id' => $closed->id,
        ])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Cannot move an asset to an inactive location.');
    }

    public function test_an_unknown_location_id_is_a_validation_error(): void
    {
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $tech = $this->createUser(RoleCode::TECHNICIAN);
        $asset = $this->assetAt($this->location('RA', LocationType::RIG));
        $wo = $this->assignedWorkOrder($asset, $manager, $tech);

        $this->actingAs($tech)->postJson("/api/work-orders/{$wo->id}/start", [
            'location_id' => 999999,
        ])->assertStatus(422);
    }

    public function test_the_move_and_the_start_are_both_audited(): void
    {
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $tech = $this->createUser(RoleCode::TECHNICIAN);
        $asset = $this->assetAt($this->location('RA', LocationType::RIG));
        $workshop = $this->location('WS', LocationType::WORKSHOP);
        $wo = $this->assignedWorkOrder($asset, $manager, $tech);

        $this->actingAs($tech)->postJson("/api/work-orders/{$wo->id}/start", [
            'location_id' => $workshop->id,
        ])->assertOk();

        $this->assertDatabaseHas('audit_logs', ['event' => 'work_order.started']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'asset.location_updated']);
    }

    /** The guard runs before any mutation, so an unassigned WO still fails on assignment. */
    public function test_existing_guards_still_take_precedence(): void
    {
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $requester = $this->createUser(RoleCode::REQUESTER);
        $asset = $this->assetAt($this->location('RA', LocationType::RIG));

        $mr = MaintenanceRequest::create([
            'number' => 'MR-000001',
            'asset_id' => $asset->id,
            'status' => 'pending_review',
            'priority' => 'high',
            'description' => 'Needs work',
            'created_by' => $requester->id,
            'is_preventive' => false,
        ]);

        $this->actingAs($manager)->postJson("/api/maintenance-requests/{$mr->id}/approve", [
            'is_failure' => true,
        ])->assertOk();

        $wo = WorkOrder::where('maintenance_request_id', $mr->id)->first();

        $this->actingAs($manager)->postJson("/api/work-orders/{$wo->id}/start")
            ->assertStatus(409)
            ->assertJsonPath('message', 'Work order must be assigned before starting.');
    }

    /** The work order detail payload carries the location the page needs. */
    public function test_the_work_order_payload_exposes_the_assets_current_location(): void
    {
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $tech = $this->createUser(RoleCode::TECHNICIAN);
        $rig = $this->location('RA', LocationType::RIG);
        $wo = $this->assignedWorkOrder($this->assetAt($rig), $manager, $tech);

        $this->actingAs($tech)->getJson("/api/work-orders/{$wo->id}")
            ->assertOk()
            ->assertJsonPath('data.asset.current_location.id', $rig->id)
            ->assertJsonPath('data.asset.current_location.name', 'Rig RA')
            ->assertJsonPath('data.asset.current_location.code', 'RA')
            ->assertJsonPath('data.asset.current_location.type', 'rig');
    }

    public function test_current_location_is_null_when_the_asset_has_none(): void
    {
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $tech = $this->createUser(RoleCode::TECHNICIAN);
        $wo = $this->assignedWorkOrder($this->assetAt(null), $manager, $tech);

        $this->actingAs($tech)->getJson("/api/work-orders/{$wo->id}")
            ->assertOk()
            ->assertJsonPath('data.asset.current_location', null);
    }

    // ── D6: the work order owns the status of the asset it moves ────────────────

    /**
     * Starting a work order moves the asset to the workshop, which for a
     * user-initiated move would be a field exit and would stamp
     * `need_inspection`. It must not here: a technician is about to inspect the
     * asset as part of the job, and closing the work order resets the condition
     * anyway — so the flag would appear and vanish having told nobody anything.
     *
     * Confirmed with the user on 2026-08-16 (decision D6).
     */
    public function test_starting_a_work_order_does_not_flag_the_asset_for_inspection(): void
    {
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $tech = $this->createUser(RoleCode::TECHNICIAN);
        $rig = $this->location('RB', LocationType::RIG);
        $workshop = $this->location('WS', LocationType::WORKSHOP);

        $asset = $this->assetAt($rig);
        $asset->update([
            'operational_status' => OperationalStatus::AT_THE_FIELD,
            'condition_status' => 'normal',
        ]);

        $wo = $this->assignedWorkOrder($asset, $manager, $tech);

        $this->actingAs($tech)->postJson("/api/work-orders/{$wo->id}/start", [
            'location_id' => $workshop->id,
        ])->assertOk();

        $asset->refresh();
        $this->assertSame($workshop->id, $asset->current_location_id, 'The move still happens.');
        $this->assertSame('normal', $asset->condition_status, 'But it is not read as a field exit.');
        $this->assertSame(
            OperationalStatus::UNDER_MAINTENANCE,
            $asset->operational_status,
            'The work order sets the status, not the location rule.',
        );
    }
}
