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

class OperationalStatusDistributionReportTest extends TestCase
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

    private function createAsset(array $overrides = []): Asset
    {
        $location = Location::create(['name' => 'Loc-'.uniqid(), 'type' => 'building']);

        return Asset::create(array_merge([
            'erp_asset_code' => 'A-'.uniqid(),
            'name' => 'Asset',
            'is_active' => true,
            'current_location_id' => $location->id,
        ], $overrides));
    }

    private function counts(array $items): array
    {
        return collect($items)->pluck('count', 'status')->all();
    }

    public function test_unauthenticated_is_rejected(): void
    {
        $this->getJson('/api/reports/asset-status-distribution')->assertUnauthorized();
    }

    /**
     * The contract is "every status the enum defines, zero-filled" — not a
     * fixed count. Deriving the expectation from the enum keeps this honest
     * across the vocabulary transition, where 4a widens the set and 4b narrows
     * it again; a hardcoded list would just need rewriting at each step while
     * asserting less.
     */
    public function test_returns_every_defined_status_with_zero_for_missing(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $this->createAsset(['operational_status' => OperationalStatus::READY_FOR_FIELD]);
        $this->createAsset(['operational_status' => OperationalStatus::READY_FOR_FIELD]);
        $this->createAsset(['operational_status' => OperationalStatus::DOWN]);

        $json = $this->actingAs($admin)->getJson('/api/reports/asset-status-distribution')->json();
        $counts = $this->counts($json['items']);

        $this->assertSame(3, $json['summary']['total']);
        $this->assertSame(
            array_map(fn (OperationalStatus $s) => $s->value, OperationalStatus::cases()),
            array_keys($counts),
            'Every enum case must appear, in declaration order.',
        );
        $this->assertSame(2, $counts['ready_for_field']);
        $this->assertSame(1, $counts['down']);
        $this->assertSame(0, $counts['under_maintenance']);
    }

    public function test_scraped_operational_status_is_shown_not_hidden(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        // operational_status=scraped but is_active=true must still appear.
        $this->createAsset([
            'operational_status' => OperationalStatus::SCRAPED,
            'is_active' => true,
        ]);

        $json = $this->actingAs($admin)->getJson('/api/reports/asset-status-distribution')->json();

        $this->assertSame(1, $json['summary']['total']);
        $this->assertSame(1, $this->counts($json['items'])['scraped']);
    }

    public function test_default_excludes_soft_deactivated(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $this->createAsset(['operational_status' => OperationalStatus::READY_FOR_FIELD, 'is_active' => true]);
        $this->createAsset(['operational_status' => OperationalStatus::DOWN, 'is_active' => false]);

        $defaultJson = $this->actingAs($admin)->getJson('/api/reports/asset-status-distribution')->json();
        $this->assertSame(1, $defaultJson['summary']['total']);
        $this->assertSame(1, $this->counts($defaultJson['items'])['ready_for_field']);
        $this->assertSame(0, $this->counts($defaultJson['items'])['down']);

        $includedJson = $this->actingAs($admin)
            ->getJson('/api/reports/asset-status-distribution?include_inactive=1')->json();
        $this->assertSame(2, $includedJson['summary']['total']);
        $this->assertSame(1, $this->counts($includedJson['items'])['down']);
    }

    public function test_asset_kind_filter_excludes_other_kinds(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $this->createAsset([
            'operational_status' => OperationalStatus::READY_FOR_FIELD,
            'asset_kind' => AssetKind::PACKAGE,
        ]);
        $this->createAsset([
            'operational_status' => OperationalStatus::DOWN,
            'asset_kind' => AssetKind::COMPONENT,
        ]);

        $json = $this->actingAs($admin)
            ->getJson('/api/reports/asset-status-distribution?asset_kind=package')->json();

        $this->assertSame(1, $json['summary']['total']);
        $this->assertSame(1, $this->counts($json['items'])['ready_for_field']);
        $this->assertSame(0, $this->counts($json['items'])['down']);
    }

    public function test_empty_state(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);

        $json = $this->actingAs($admin)->getJson('/api/reports/asset-status-distribution')->json();

        $counts = $this->counts($json['items']);

        $this->assertSame(0, $json['summary']['total']);
        $this->assertSame(
            array_map(fn (OperationalStatus $s) => $s->value, OperationalStatus::cases()),
            array_keys($counts),
        );
        $this->assertSame([0], array_values(array_unique($counts)), 'An empty register is all zeros.');
    }
}
