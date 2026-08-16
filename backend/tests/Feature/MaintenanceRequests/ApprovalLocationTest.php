<?php

namespace Tests\Feature\MaintenanceRequests;

use App\Enums\LocationType;
use App\Enums\OperationalStatus;
use App\Enums\RoleCode;
use App\Models\Asset;
use App\Models\AssetLocationHistory;
use App\Models\AuditLog;
use App\Models\Location;
use App\Models\MaintenanceRequest;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Task 4.6 / Q4 (2026-08-16): the approver may send the asset somewhere as part
 * of approving the request.
 *
 * LDC's usual destination is the Tajoura Base yard, but that is a choice the
 * approver makes rather than a constant ATMS applies — bases change, and the
 * request that needs the asset left where it is must stay expressible. Absent
 * means "keep its current location".
 */
class ApprovalLocationTest extends TestCase
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

    private function location(string $name, LocationType $type = LocationType::YARD, bool $active = true): Location
    {
        return Location::create(['name' => $name, 'type' => $type->value, 'is_active' => $active]);
    }

    private function asset(?Location $at = null): Asset
    {
        return Asset::create([
            'erp_asset_code' => 'AST-APR-'.uniqid(),
            'name' => 'Approval Asset',
            'is_active' => true,
            'operational_status' => OperationalStatus::READY_FOR_FIELD,
            'current_location_id' => $at?->id,
        ]);
    }

    private function request(Asset $asset, bool $preventive): MaintenanceRequest
    {
        return MaintenanceRequest::create([
            'number' => 'MR-'.uniqid(),
            'asset_id' => $asset->id,
            'status' => 'pending_review',
            'priority' => 'medium',
            'description' => 'Work needed',
            'created_by' => $this->user(RoleCode::REQUESTER)->id,
            'is_preventive' => $preventive,
        ]);
    }

    /** @return array<string, array{bool}> */
    public static function requestTypeProvider(): array
    {
        return ['corrective' => [false], 'preventive' => [true]];
    }

    private function approve(User $manager, MaintenanceRequest $mr, array $payload = []): TestResponse
    {
        if (! $mr->is_preventive) {
            $payload['is_failure'] ??= true;
        }

        return $this->actingAs($manager)->postJson("/api/maintenance-requests/{$mr->id}/approve", $payload);
    }

    // ── The default: leave it where it is ───────────────────────────────────────

    public function test_approving_without_a_location_keeps_the_asset_where_it_is(): void
    {
        $yard = $this->location('Origin Yard');
        $asset = $this->asset($yard);

        $this->approve($this->user(RoleCode::MAINTENANCE_MANAGER), $this->request($asset, false))->assertOk();

        $this->assertSame($yard->id, $asset->fresh()->current_location_id);
        $this->assertSame(0, AssetLocationHistory::where('asset_id', $asset->id)->count());
    }

    // ── The explicit move ───────────────────────────────────────────────────────

    #[DataProvider('requestTypeProvider')]
    public function test_an_approver_may_move_the_asset_on_either_request_type(bool $preventive): void
    {
        $origin = $this->location('Origin Yard');
        $tajoura = $this->location('Tajoura Base');
        $asset = $this->asset($origin);

        $this->approve($this->user(RoleCode::MAINTENANCE_MANAGER), $this->request($asset, $preventive), [
            'move_to_location_id' => $tajoura->id,
        ])->assertOk();

        $this->assertSame($tajoura->id, $asset->fresh()->current_location_id);
    }

    public function test_the_move_records_location_history_and_an_audit_row(): void
    {
        $origin = $this->location('Origin Yard');
        $tajoura = $this->location('Tajoura Base');
        $asset = $this->asset($origin);
        $mr = $this->request($asset, false);

        $this->approve($this->user(RoleCode::MAINTENANCE_MANAGER), $mr, [
            'move_to_location_id' => $tajoura->id,
        ])->assertOk();

        $history = AssetLocationHistory::where('asset_id', $asset->id)->sole();
        $this->assertSame($origin->id, $history->from_location_id);
        $this->assertSame($tajoura->id, $history->to_location_id);
        $this->assertStringContainsString($mr->number, $history->reason);

        $this->assertSame(1, AuditLog::where('event', 'asset.location_updated')->count());
    }

    /**
     * The approval has just marked a corrective asset `failure`. The move must
     * not be re-read by the location rules and overwritten, which is why it
     * passes `applyStatusRules: false` exactly as `StartWorkOrder` does.
     */
    public function test_the_move_does_not_disturb_the_status_the_approval_just_set(): void
    {
        $asset = $this->asset($this->location('Origin Yard'));

        $this->approve($this->user(RoleCode::MAINTENANCE_MANAGER), $this->request($asset, false), [
            'move_to_location_id' => $this->location('Tajoura Base')->id,
        ])->assertOk();

        $this->assertSame(OperationalStatus::FAILURE, $asset->fresh()->operational_status);
    }

    /**
     * Moving an asset *to* a rig during approval must not make it `at_the_field`
     * either — the same exemption, in the direction that would otherwise look
     * like a legitimate derivation.
     */
    public function test_approving_a_move_to_a_rig_does_not_derive_at_the_field(): void
    {
        $asset = $this->asset($this->location('Origin Yard'));

        $this->approve($this->user(RoleCode::MAINTENANCE_MANAGER), $this->request($asset, true), [
            'move_to_location_id' => $this->location('Rig 9', LocationType::RIG)->id,
        ])->assertOk();

        $this->assertSame(OperationalStatus::READY_FOR_FIELD, $asset->fresh()->operational_status);
    }

    // ── Rejections roll the whole approval back ─────────────────────────────────

    public function test_an_unknown_location_is_rejected_before_anything_is_written(): void
    {
        $origin = $this->location('Origin Yard');
        $asset = $this->asset($origin);
        $mr = $this->request($asset, false);

        $this->approve($this->user(RoleCode::MAINTENANCE_MANAGER), $mr, [
            'move_to_location_id' => 999_999,
        ])->assertStatus(422);

        $this->assertSame('pending_review', $mr->fresh()->status->value);
        $this->assertSame($origin->id, $asset->fresh()->current_location_id);
    }

    /**
     * An inactive location fails inside the transaction, so the request must
     * still be pending and no work order may exist — the approval either
     * happens whole or not at all.
     */
    public function test_an_inactive_location_rolls_the_whole_approval_back(): void
    {
        $origin = $this->location('Origin Yard');
        $retired = $this->location('Closed Yard', LocationType::YARD, active: false);
        $asset = $this->asset($origin);
        $mr = $this->request($asset, false);

        $this->approve($this->user(RoleCode::MAINTENANCE_MANAGER), $mr, [
            'move_to_location_id' => $retired->id,
        ])->assertStatus(409);

        $this->assertSame('pending_review', $mr->fresh()->status->value);
        $this->assertNull($mr->fresh()->workOrder);
        $this->assertSame($origin->id, $asset->fresh()->current_location_id);
        $this->assertSame(0, AssetLocationHistory::where('asset_id', $asset->id)->count());
    }
}
