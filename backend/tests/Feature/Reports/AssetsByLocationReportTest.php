<?php

namespace Tests\Feature\Reports;

use App\Enums\AssetKind;
use App\Enums\OperationalStatus;
use App\Enums\RoleCode;
use App\Models\Asset;
use App\Models\Location;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssetsByLocationReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function createUser(RoleCode $roleCode): User
    {
        $role = Role::where('code', $roleCode->value)->firstOrFail();

        return User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
    }

    private function createLocation(string $name): Location
    {
        return Location::create(['name' => $name, 'type' => 'building']);
    }

    private function createAsset(array $overrides = []): Asset
    {
        return Asset::create(array_merge([
            'erp_asset_code' => 'A-'.uniqid(),
            'name' => 'Asset',
            'is_active' => true,
        ], $overrides));
    }

    private function findRow(array $items, ?int $locationId): ?array
    {
        return collect($items)->firstWhere('location_id', $locationId);
    }

    public function test_unauthenticated_is_rejected(): void
    {
        $this->getJson('/api/reports/assets-by-location')->assertUnauthorized();
    }

    public function test_groups_assets_by_location(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $locA = $this->createLocation('Loc-A');
        $locB = $this->createLocation('Loc-B');
        $this->createAsset(['current_location_id' => $locA->id]);
        $this->createAsset(['current_location_id' => $locA->id]);
        $this->createAsset(['current_location_id' => $locB->id]);

        $json = $this->actingAs($admin)->getJson('/api/reports/assets-by-location')->json();

        $this->assertSame(3, $json['summary']['total_assets']);
        $this->assertSame(2, $json['summary']['total_locations']);
        $this->assertSame(2, $this->findRow($json['items'], $locA->id)['asset_count']);
        $this->assertSame(1, $this->findRow($json['items'], $locB->id)['asset_count']);
    }

    public function test_unassigned_bucket_for_null_location(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $locA = $this->createLocation('Loc-A');
        $this->createAsset(['current_location_id' => $locA->id]);
        $this->createAsset(['current_location_id' => null]);

        $json = $this->actingAs($admin)->getJson('/api/reports/assets-by-location')->json();

        $this->assertSame(2, $json['summary']['total_assets']);
        // Unassigned bucket is not counted as a location.
        $this->assertSame(1, $json['summary']['total_locations']);
        $unassigned = collect($json['items'])->firstWhere('is_unassigned', true);
        $this->assertNotNull($unassigned);
        $this->assertSame('Unassigned', $unassigned['location_name']);
        $this->assertSame(1, $unassigned['asset_count']);
    }

    public function test_breaks_down_by_operational_status_and_asset_kind_and_booked(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $loc = $this->createLocation('Loc-A');
        $this->createAsset([
            'current_location_id' => $loc->id,
            'operational_status' => OperationalStatus::ACTIVE,
            'asset_kind' => AssetKind::ASSET,
        ]);
        $this->createAsset([
            'current_location_id' => $loc->id,
            'operational_status' => OperationalStatus::DOWN,
            'asset_kind' => AssetKind::COMPONENT,
        ]);
        $this->createAsset([
            'current_location_id' => $loc->id,
            'operational_status' => OperationalStatus::ACTIVE,
            'asset_kind' => AssetKind::PACKAGE,
            'is_booked' => true,
        ]);

        $row = $this->findRow(
            $this->actingAs($admin)->getJson('/api/reports/assets-by-location')->json('items'),
            $loc->id
        );

        $this->assertSame(3, $row['asset_count']);
        $this->assertSame(
            ['active' => 2, 'under_maintenance' => 0, 'down' => 1, 'inactive' => 0],
            $row['by_operational_status']
        );
        $this->assertSame(
            ['standalone' => 1, 'package' => 1, 'component' => 1],
            $row['by_asset_kind']
        );
        $this->assertSame(1, $row['booked_count']);
    }

    public function test_fa_subclass_code_filter_applies(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $loc = $this->createLocation('Loc-A');
        $this->createAsset(['current_location_id' => $loc->id, 'fa_subclass_code' => 'HVAC']);
        $this->createAsset(['current_location_id' => $loc->id, 'fa_subclass_code' => 'HVAC']);
        $this->createAsset(['current_location_id' => $loc->id, 'fa_subclass_code' => 'Pumps']);

        $json = $this->actingAs($admin)->getJson('/api/reports/assets-by-location?fa_subclass_code=HVAC')->json();

        $this->assertSame(2, $json['summary']['total_assets']);
    }

    public function test_asset_kind_filter_applies(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $loc = $this->createLocation('Loc-A');
        $this->createAsset(['current_location_id' => $loc->id, 'asset_kind' => AssetKind::PACKAGE]);
        $this->createAsset(['current_location_id' => $loc->id, 'asset_kind' => AssetKind::COMPONENT]);

        $json = $this->actingAs($admin)->getJson('/api/reports/assets-by-location?asset_kind=package')->json();

        $this->assertSame(1, $json['summary']['total_assets']);
    }

    public function test_default_excludes_soft_deactivated(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $loc = $this->createLocation('Loc-A');
        $this->createAsset(['current_location_id' => $loc->id, 'is_active' => true]);
        $this->createAsset(['current_location_id' => $loc->id, 'is_active' => false]);

        $defaultJson = $this->actingAs($admin)->getJson('/api/reports/assets-by-location')->json();
        $this->assertSame(1, $defaultJson['summary']['total_assets']);

        $includedJson = $this->actingAs($admin)->getJson('/api/reports/assets-by-location?include_inactive=1')->json();
        $this->assertSame(2, $includedJson['summary']['total_assets']);
    }

    public function test_empty_state(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);

        $json = $this->actingAs($admin)->getJson('/api/reports/assets-by-location')->json();

        $this->assertSame(0, $json['summary']['total_assets']);
        $this->assertSame(0, $json['summary']['total_locations']);
        $this->assertSame(0, $json['summary']['total_booked']);
        $this->assertSame([], $json['items']);
    }

    public function test_no_maintenance_lifecycle_breakdown_in_phase1(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $loc = $this->createLocation('Loc-A');
        $this->createAsset(['current_location_id' => $loc->id]);

        $items = $this->actingAs($admin)->getJson('/api/reports/assets-by-location')->json('items');

        $this->assertArrayNotHasKey('by_maintenance_status', $items[0]);
        $this->assertArrayNotHasKey('sub_status', $items[0]);
    }

    public function test_operational_status_filter_applies(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $loc = $this->createLocation('Loc-A');
        $this->createAsset([
            'current_location_id' => $loc->id,
            'operational_status' => OperationalStatus::ACTIVE,
        ]);
        $this->createAsset([
            'current_location_id' => $loc->id,
            'operational_status' => OperationalStatus::DOWN,
        ]);

        $json = $this->actingAs($admin)
            ->getJson('/api/reports/assets-by-location?operational_status=active')->json();

        $this->assertSame(1, $json['summary']['total_assets']);
        $row = $this->findRow($json['items'], $loc->id);
        $this->assertSame(1, $row['asset_count']);
        $this->assertSame(1, $row['by_operational_status']['active']);
        $this->assertSame(0, $row['by_operational_status']['down']);
    }
}
