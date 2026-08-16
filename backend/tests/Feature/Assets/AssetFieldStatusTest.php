<?php

namespace Tests\Feature\Assets;

use App\Actions\Assets\CreateAsset;
use App\Enums\LocationType;
use App\Enums\OperationalStatus;
use App\Enums\RoleCode;
use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\Location;
use App\Models\MasterDataItem;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Q1 and Q2 (2026-08-16): the location-derived half of an asset's status.
 *
 * Two rules, both exercised through the API rather than the helper, because the
 * gate deliberately lives in the controllers and not in `UpdateAssetLocation` —
 * testing the helper alone would prove nothing about where the gate sits.
 *
 * Both user-facing move endpoints are covered by the same data provider. They
 * take different payloads and were written months apart, and the whole point of
 * a shared guard is that they cannot drift.
 */
class AssetFieldStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function admin(): User
    {
        return User::factory()->create([
            'role_id' => Role::where('code', RoleCode::ADMINISTRATOR)->first()->id,
            'is_active' => true,
        ]);
    }

    private function location(LocationType $type): Location
    {
        return Location::create([
            'name' => ucfirst($type->value).'-'.uniqid(),
            'type' => $type->value,
            'is_active' => true,
        ]);
    }

    private function asset(array $overrides = []): Asset
    {
        return Asset::create(array_merge([
            'erp_asset_code' => 'AST-FLD-'.uniqid(),
            'name' => 'Field Asset',
            'is_active' => true,
            'operational_status' => OperationalStatus::READY_FOR_FIELD,
            'condition_status' => 'normal',
            'current_location_id' => $this->location(LocationType::YARD)->id,
        ], $overrides));
    }

    /**
     * The two user-initiated move endpoints, as callables.
     *
     * @return array<string, array{string}>
     */
    public static function moveEndpointProvider(): array
    {
        return ['dedicated location route' => ['location'], 'asset patch' => ['patch']];
    }

    private function move(User $user, Asset $asset, Location $to, string $endpoint): TestResponse
    {
        return $endpoint === 'location'
            ? $this->actingAs($user)->postJson("/api/assets/{$asset->id}/location", ['location_id' => $to->id])
            : $this->actingAs($user)->patchJson("/api/assets/{$asset->id}", ['current_location_id' => $to->id]);
    }

    // ── Entering the field ──────────────────────────────────────────────────────

    /**
     * @return array<string, array{LocationType}>
     */
    public static function fieldLocationProvider(): array
    {
        return ['rig' => [LocationType::RIG], 'well site' => [LocationType::WELL_SITE]];
    }

    #[DataProvider('fieldLocationProvider')]
    public function test_moving_to_a_deployed_location_sets_at_the_field(LocationType $type): void
    {
        $asset = $this->asset();

        $this->move($this->admin(), $asset, $this->location($type), 'location')->assertOk();

        $this->assertSame(OperationalStatus::AT_THE_FIELD, $asset->fresh()->operational_status);
    }

    #[DataProvider('moveEndpointProvider')]
    public function test_both_endpoints_derive_at_the_field(string $endpoint): void
    {
        $asset = $this->asset();

        $this->move($this->admin(), $asset, $this->location(LocationType::RIG), $endpoint)->assertSuccessful();

        $this->assertSame(OperationalStatus::AT_THE_FIELD, $asset->fresh()->operational_status);
    }

    /**
     * The rule is keyed off `AssetDeployment`, the declared source of truth for
     * "out for work" — not a literal rig/well_site list. A yard is IDLE, so
     * moving there changes nothing about a ready asset.
     */
    public function test_moving_between_non_field_locations_changes_nothing(): void
    {
        $asset = $this->asset();

        $this->move($this->admin(), $asset, $this->location(LocationType::BUILDING), 'location')->assertOk();

        $this->assertSame(OperationalStatus::READY_FOR_FIELD, $asset->fresh()->operational_status);
        $this->assertSame('normal', $asset->fresh()->condition_status);
    }

    // ── Q2: leaving the field ───────────────────────────────────────────────────

    #[DataProvider('moveEndpointProvider')]
    public function test_returning_from_the_field_flags_the_asset_for_inspection(string $endpoint): void
    {
        $asset = $this->asset(['operational_status' => OperationalStatus::AT_THE_FIELD]);

        $this->move($this->admin(), $asset, $this->location(LocationType::YARD), $endpoint)->assertSuccessful();

        $asset->refresh();
        $this->assertSame(OperationalStatus::READY_FOR_FIELD, $asset->operational_status);
        $this->assertSame('need_inspection', $asset->condition_status);
    }

    /**
     * The status change is the operationally important half. If an Admin has
     * retired the `need_inspection` row the asset has still come back, so the
     * move completes and the omission is audited rather than swallowed.
     */
    public function test_a_missing_inspection_condition_is_audited_not_silently_skipped(): void
    {
        MasterDataItem::where('group_key', MasterDataItem::ASSET_CONDITIONS)
            ->where('value', 'need_inspection')
            ->update(['is_active' => false]);

        $asset = $this->asset(['operational_status' => OperationalStatus::AT_THE_FIELD]);

        $this->move($this->admin(), $asset, $this->location(LocationType::YARD), 'location')->assertOk();

        $asset->refresh();
        $this->assertSame(OperationalStatus::READY_FOR_FIELD, $asset->operational_status);
        $this->assertSame('normal', $asset->condition_status, 'The old condition stands rather than being cleared.');
        $this->assertSame(1, AuditLog::where('event', 'asset.condition_flag_skipped')->count());
    }

    // ── Q1: which manual moves are allowed ──────────────────────────────────────

    /**
     * @return array<string, array{OperationalStatus, string}>
     */
    public static function blockedManualMoveProvider(): array
    {
        return [
            'failure' => [OperationalStatus::FAILURE, 'Cannot move an asset in failure by hand.'],
            'under maintenance' => [OperationalStatus::UNDER_MAINTENANCE, 'Cannot move an asset that is under maintenance.'],
        ];
    }

    #[DataProvider('blockedManualMoveProvider')]
    public function test_a_broken_or_in_progress_asset_cannot_be_moved_by_hand(OperationalStatus $status, string $expected): void
    {
        $asset = $this->asset(['operational_status' => $status]);
        $destination = $this->location(LocationType::YARD);

        foreach (['location', 'patch'] as $endpoint) {
            $response = $this->move($this->admin(), $asset, $destination, $endpoint)->assertStatus(409);
            $this->assertStringContainsString($expected, $response->json('message'));
        }

        $this->assertNotSame($destination->id, $asset->fresh()->current_location_id);
    }

    #[DataProvider('moveEndpointProvider')]
    public function test_an_asset_at_the_field_cannot_be_moved_straight_to_a_workshop(string $endpoint): void
    {
        $asset = $this->asset(['operational_status' => OperationalStatus::AT_THE_FIELD]);

        $this->move($this->admin(), $asset, $this->location(LocationType::WORKSHOP), $endpoint)
            ->assertStatus(409);
    }

    /**
     * @return array<string, array{LocationType}>
     */
    public static function returnDestinationProvider(): array
    {
        return ['yard' => [LocationType::YARD], 'building' => [LocationType::BUILDING]];
    }

    #[DataProvider('returnDestinationProvider')]
    public function test_an_asset_at_the_field_may_come_back_to_a_yard_or_building(LocationType $type): void
    {
        $asset = $this->asset(['operational_status' => OperationalStatus::AT_THE_FIELD]);

        $this->move($this->admin(), $asset, $this->location($type), 'location')->assertOk();

        $this->assertSame(OperationalStatus::READY_FOR_FIELD, $asset->fresh()->operational_status);
    }

    public function test_a_ready_asset_may_be_moved_anywhere(): void
    {
        foreach (LocationType::cases() as $type) {
            $asset = $this->asset();
            $this->move($this->admin(), $asset, $this->location($type), 'location')->assertOk();
        }
    }

    // ── Creation ────────────────────────────────────────────────────────────────

    public function test_an_asset_created_at_a_rig_is_at_the_field(): void
    {
        $asset = app(CreateAsset::class)->execute([
            'erp_asset_code' => 'AST-NEW-RIG',
            'name' => 'Born Deployed',
            'current_location_id' => $this->location(LocationType::RIG)->id,
        ]);

        $this->assertSame(OperationalStatus::AT_THE_FIELD, $asset->operational_status);
    }

    /**
     * Derived, not merely defaulted: an explicit status loses to the location,
     * because a record claiming "ready for field" while sitting on a rig is the
     * exact inconsistency this rule exists to prevent.
     */
    public function test_the_location_overrides_an_explicit_status_on_creation(): void
    {
        $asset = app(CreateAsset::class)->execute([
            'erp_asset_code' => 'AST-NEW-RIG-2',
            'name' => 'Born Deployed',
            'operational_status' => OperationalStatus::READY_FOR_FIELD->value,
            'current_location_id' => $this->location(LocationType::RIG)->id,
        ]);

        $this->assertSame(OperationalStatus::AT_THE_FIELD, $asset->operational_status);
    }
}
