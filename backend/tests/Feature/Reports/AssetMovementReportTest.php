<?php

namespace Tests\Feature\Reports;

use App\Enums\RoleCode;
use App\Models\Asset;
use App\Models\AssetLocationHistory;
use App\Models\Location;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssetMovementReportTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->admin = $this->createUser(RoleCode::ADMINISTRATOR);
    }

    private function createUser(RoleCode $roleCode): User
    {
        $role = Role::where('code', $roleCode->value)->firstOrFail();

        return User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
    }

    private function createLocation(array $overrides = []): Location
    {
        return Location::create(array_merge([
            'name' => 'Location-'.uniqid(),
            'type' => 'building',
            'is_active' => true,
        ], $overrides));
    }

    private function createAsset(array $overrides = []): Asset
    {
        $location = $this->createLocation();

        return Asset::create(array_merge([
            'erp_asset_code' => 'A-'.uniqid(),
            'name' => 'Asset',
            'is_active' => true,
            'current_location_id' => $location->id,
        ], $overrides));
    }

    private function createMovement(array $overrides = []): AssetLocationHistory
    {
        $asset = $this->createAsset();
        $fromLocation = $this->createLocation(['name' => 'From-'.uniqid()]);
        $toLocation = $this->createLocation(['name' => 'To-'.uniqid()]);

        return AssetLocationHistory::create(array_merge([
            'asset_id' => $asset->id,
            'from_location_id' => $fromLocation->id,
            'to_location_id' => $toLocation->id,
            'effective_at' => now(),
            'reason' => 'transfer',
            'changed_by_user_id' => $this->admin->id,
        ], $overrides));
    }

    public function test_unauthenticated_is_rejected(): void
    {
        $this->getJson('/api/reports/asset-movement')->assertUnauthorized();
    }

    public function test_calculates_asset_movements(): void
    {
        $asset1 = $this->createAsset(['name' => 'Asset-1']);
        $asset2 = $this->createAsset(['name' => 'Asset-2']);
        $locA = $this->createLocation(['name' => 'Location-A']);
        $locB = $this->createLocation(['name' => 'Location-B']);
        $locC = $this->createLocation(['name' => 'Location-C']);

        // Asset1: 2 movements
        AssetLocationHistory::create([
            'asset_id' => $asset1->id,
            'from_location_id' => $locA->id,
            'to_location_id' => $locB->id,
            'effective_at' => now()->subDays(5),
            'reason' => 'transfer',
            'changed_by_user_id' => $this->admin->id,
        ]);
        AssetLocationHistory::create([
            'asset_id' => $asset1->id,
            'from_location_id' => $locB->id,
            'to_location_id' => $locC->id,
            'effective_at' => now()->subDays(2),
            'reason' => 'maintenance',
            'changed_by_user_id' => $this->admin->id,
        ]);

        // Asset2: 1 movement
        AssetLocationHistory::create([
            'asset_id' => $asset2->id,
            'from_location_id' => $locA->id,
            'to_location_id' => $locB->id,
            'effective_at' => now()->subDays(10),
            'reason' => 'transfer',
            'changed_by_user_id' => $this->admin->id,
        ]);

        $json = $this->actingAs($this->admin)->getJson('/api/reports/asset-movement')->json();

        $this->assertSame(3, $json['summary']['total_movements']);
        $this->assertSame(2, $json['summary']['unique_assets_moved']);
        $this->assertCount(3, $json['data']);

        // Check most recent movement is first
        $this->assertSame($asset1->id, $json['data'][0]['asset_id']);
        $this->assertSame('maintenance', $json['data'][0]['reason']);
    }

    public function test_respects_date_window(): void
    {
        $asset = $this->createAsset();
        $locA = $this->createLocation();
        $locB = $this->createLocation();

        // Recent movement (within 90 days)
        AssetLocationHistory::create([
            'asset_id' => $asset->id,
            'from_location_id' => $locA->id,
            'to_location_id' => $locB->id,
            'effective_at' => now()->subDays(10),
            'reason' => 'transfer',
            'changed_by_user_id' => $this->admin->id,
        ]);

        // Old movement (outside 90 days)
        AssetLocationHistory::create([
            'asset_id' => $asset->id,
            'from_location_id' => $locB->id,
            'to_location_id' => $locA->id,
            'effective_at' => now()->subDays(100),
            'reason' => 'transfer',
            'changed_by_user_id' => $this->admin->id,
        ]);

        $json = $this->actingAs($this->admin)->getJson('/api/reports/asset-movement')->json();

        // Default 90-day window should only include recent movement
        $this->assertSame(1, $json['summary']['total_movements']);
    }

    public function test_filters_by_asset(): void
    {
        $asset1 = $this->createAsset(['name' => 'Asset-1']);
        $asset2 = $this->createAsset(['name' => 'Asset-2']);
        $locA = $this->createLocation();
        $locB = $this->createLocation();

        AssetLocationHistory::create([
            'asset_id' => $asset1->id,
            'from_location_id' => $locA->id,
            'to_location_id' => $locB->id,
            'effective_at' => now()->subDays(5),
            'reason' => 'transfer',
            'changed_by_user_id' => $this->admin->id,
        ]);
        AssetLocationHistory::create([
            'asset_id' => $asset2->id,
            'from_location_id' => $locA->id,
            'to_location_id' => $locB->id,
            'effective_at' => now()->subDays(5),
            'reason' => 'transfer',
            'changed_by_user_id' => $this->admin->id,
        ]);

        $json = $this->actingAs($this->admin)
            ->getJson('/api/reports/asset-movement?asset_id='.$asset1->id)
            ->json();

        $this->assertSame(1, $json['summary']['total_movements']);
        $this->assertSame($asset1->id, $json['data'][0]['asset_id']);
    }

    public function test_filters_by_from_location(): void
    {
        $asset = $this->createAsset();
        $locA = $this->createLocation(['name' => 'Location-A']);
        $locB = $this->createLocation(['name' => 'Location-B']);
        $locC = $this->createLocation(['name' => 'Location-C']);

        AssetLocationHistory::create([
            'asset_id' => $asset->id,
            'from_location_id' => $locA->id,
            'to_location_id' => $locB->id,
            'effective_at' => now()->subDays(5),
            'reason' => 'transfer',
            'changed_by_user_id' => $this->admin->id,
        ]);
        AssetLocationHistory::create([
            'asset_id' => $asset->id,
            'from_location_id' => $locB->id,
            'to_location_id' => $locC->id,
            'effective_at' => now()->subDays(3),
            'reason' => 'transfer',
            'changed_by_user_id' => $this->admin->id,
        ]);

        $json = $this->actingAs($this->admin)
            ->getJson('/api/reports/asset-movement?from_location_id='.$locA->id)
            ->json();

        $this->assertSame(1, $json['summary']['total_movements']);
        $this->assertSame($locA->id, $json['data'][0]['from_location']['id']);
    }

    public function test_filters_by_to_location(): void
    {
        $asset = $this->createAsset();
        $locA = $this->createLocation(['name' => 'Location-A']);
        $locB = $this->createLocation(['name' => 'Location-B']);
        $locC = $this->createLocation(['name' => 'Location-C']);

        AssetLocationHistory::create([
            'asset_id' => $asset->id,
            'from_location_id' => $locA->id,
            'to_location_id' => $locB->id,
            'effective_at' => now()->subDays(5),
            'reason' => 'transfer',
            'changed_by_user_id' => $this->admin->id,
        ]);
        AssetLocationHistory::create([
            'asset_id' => $asset->id,
            'from_location_id' => $locB->id,
            'to_location_id' => $locC->id,
            'effective_at' => now()->subDays(3),
            'reason' => 'transfer',
            'changed_by_user_id' => $this->admin->id,
        ]);

        $json = $this->actingAs($this->admin)
            ->getJson('/api/reports/asset-movement?to_location_id='.$locB->id)
            ->json();

        $this->assertSame(1, $json['summary']['total_movements']);
        $this->assertSame($locB->id, $json['data'][0]['to_location']['id']);
    }

    public function test_pagination_traversal(): void
    {
        $asset = $this->createAsset();
        $locA = $this->createLocation();
        $locB = $this->createLocation();

        // Create 3 movements
        for ($i = 1; $i <= 3; $i++) {
            AssetLocationHistory::create([
                'asset_id' => $asset->id,
                'from_location_id' => $locA->id,
                'to_location_id' => $locB->id,
                'effective_at' => now()->subDays($i),
                'reason' => 'transfer',
                'changed_by_user_id' => $this->admin->id,
            ]);
        }

        // First page with per_page=2
        $json = $this->actingAs($this->admin)
            ->getJson('/api/reports/asset-movement?per_page=2')
            ->json();

        $this->assertCount(2, $json['data']);
        $this->assertNotNull($json['meta']['next_cursor']);

        // Second page using cursor
        $cursor = $json['meta']['next_cursor'];
        $json2 = $this->actingAs($this->admin)
            ->getJson('/api/reports/asset-movement?per_page=2&cursor='.$cursor)
            ->json();

        $this->assertCount(1, $json2['data']);
        $this->assertNull($json2['meta']['next_cursor']);
    }

    public function test_deterministic_ordering_with_equal_timestamps(): void
    {
        $asset = $this->createAsset();
        $locA = $this->createLocation();
        $locB = $this->createLocation();
        $sameTime = now()->subDays(5);

        // Create 3 movements with the same effective_at timestamp
        $movement1 = AssetLocationHistory::create([
            'asset_id' => $asset->id,
            'from_location_id' => $locA->id,
            'to_location_id' => $locB->id,
            'effective_at' => $sameTime,
            'reason' => 'transfer-1',
            'changed_by_user_id' => $this->admin->id,
        ]);
        $movement2 = AssetLocationHistory::create([
            'asset_id' => $asset->id,
            'from_location_id' => $locA->id,
            'to_location_id' => $locB->id,
            'effective_at' => $sameTime,
            'reason' => 'transfer-2',
            'changed_by_user_id' => $this->admin->id,
        ]);
        $movement3 = AssetLocationHistory::create([
            'asset_id' => $asset->id,
            'from_location_id' => $locA->id,
            'to_location_id' => $locB->id,
            'effective_at' => $sameTime,
            'reason' => 'transfer-3',
            'changed_by_user_id' => $this->admin->id,
        ]);

        $json = $this->actingAs($this->admin)->getJson('/api/reports/asset-movement')->json();

        $this->assertCount(3, $json['data']);
        // Should be ordered by id desc (most recent first)
        $this->assertSame($movement3->id, $json['data'][0]['id']);
        $this->assertSame($movement2->id, $json['data'][1]['id']);
        $this->assertSame($movement1->id, $json['data'][2]['id']);
    }

    public function test_empty_state(): void
    {
        $json = $this->actingAs($this->admin)->getJson('/api/reports/asset-movement')->json();

        $this->assertSame(0, $json['summary']['total_movements']);
        $this->assertSame(0, $json['summary']['unique_assets_moved']);
        $this->assertSame([], $json['data']);
    }
}
