<?php

namespace Tests\Feature\Reports;

use App\Enums\RoleCode;
use App\Models\Asset;
use App\Models\Location;
use App\Models\MaintenanceCategory;
use App\Models\MaintenanceRequest;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BadActorReportTest extends TestCase
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

    public function test_unauthenticated_is_rejected(): void
    {
        $this->getJson('/api/reports/bad-actors')->assertUnauthorized();
    }

    public function test_identifies_bad_actors_by_failure_count(): void
    {
        $assetA = $this->createAsset(['name' => 'Asset-A']);
        $assetB = $this->createAsset(['name' => 'Asset-B']);

        // Asset-A: 3 failures
        foreach (range(1, 3) as $i) {
            MaintenanceRequest::forceCreate([
                'number' => "MR-A{$i}",
                'asset_id' => $assetA->id,
                'status' => 'converted',
                'priority' => 'high',
                'description' => "Failure {$i}",
                'created_by' => $this->admin->id,
                'is_preventive' => false,
                'is_failure' => true,
                'created_at' => now()->subDays(10),
            ]);
        }

        // Asset-B: 1 failure
        MaintenanceRequest::forceCreate([
            'number' => 'MR-B1',
            'asset_id' => $assetB->id,
            'status' => 'converted',
            'priority' => 'high',
            'description' => 'Failure',
            'created_by' => $this->admin->id,
            'is_preventive' => false,
            'is_failure' => true,
            'created_at' => now()->subDays(10),
        ]);

        $json = $this->actingAs($this->admin)->getJson('/api/reports/bad-actors?group_by=asset')->json();

        $this->assertSame(4, $json['summary']['total_failures']);
        $this->assertCount(2, $json['items']);
        // Sorted by failure_count desc
        $this->assertSame(3, $json['items'][0]['failure_count']);
        $this->assertSame('Asset-A', $json['items'][0]['group_label']);
        $this->assertSame(1, $json['items'][1]['failure_count']);
    }

    public function test_respects_limit_parameter(): void
    {
        foreach (range(1, 5) as $i) {
            $asset = $this->createAsset(['name' => "Asset-{$i}"]);
            MaintenanceRequest::forceCreate([
                'number' => "MR-{$i}",
                'asset_id' => $asset->id,
                'status' => 'converted',
                'priority' => 'high',
                'description' => 'Failure',
                'created_by' => $this->admin->id,
                'is_preventive' => false,
                'is_failure' => true,
                'created_at' => now()->subDays(10),
            ]);
        }

        $json = $this->actingAs($this->admin)->getJson('/api/reports/bad-actors?limit=3')->json();

        $this->assertSame(5, $json['summary']['total_failures']);
        $this->assertCount(3, $json['items']);
    }

    public function test_empty_state(): void
    {
        $json = $this->actingAs($this->admin)->getJson('/api/reports/bad-actors')->json();

        $this->assertSame(0, $json['summary']['total_failures']);
        $this->assertSame([], $json['items']);
    }

    public function test_legacy_category_group_by_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->getJson('/api/reports/bad-actors?group_by=category')
            ->assertStatus(422);
    }

    public function test_groups_by_maintenance_category_code(): void
    {
        $category = MaintenanceCategory::factory()->create([
            'code' => 'DRILL_BIT',
            'name' => 'Drill Bit',
        ]);
        $asset = $this->createAsset(['maintenance_category_id' => $category->id]);
        $this->createFailure($asset, 'MR-1');

        $json = $this->actingAs($this->admin)
            ->getJson('/api/reports/bad-actors?group_by=maintenance_category')->json();

        $this->assertCount(1, $json['items']);
        $this->assertSame('DRILL_BIT', $json['items'][0]['group_key']);
        $this->assertSame('Drill Bit', $json['items'][0]['group_label']);
    }

    public function test_null_maintenance_category_uses_uncategorised_bucket(): void
    {
        $asset = $this->createAsset(['maintenance_category_id' => null]);
        $this->createFailure($asset, 'MR-1');

        $json = $this->actingAs($this->admin)
            ->getJson('/api/reports/bad-actors?group_by=maintenance_category')->json();

        $this->assertCount(1, $json['items']);
        $this->assertSame('uncategorised', $json['items'][0]['group_key']);
        $this->assertSame('Uncategorised', $json['items'][0]['group_label']);
    }

    public function test_groups_by_size_with_canonical_key_and_og_label(): void
    {
        $asset = $this->createAsset(['size_inches' => '9 5/8"']);
        $this->createFailure($asset, 'MR-1');

        $json = $this->actingAs($this->admin)
            ->getJson('/api/reports/bad-actors?group_by=size')->json();

        $this->assertCount(1, $json['items']);
        $this->assertSame('9.62500', $json['items'][0]['group_key']);
        $this->assertSame('9 5/8"', $json['items'][0]['group_label']);
    }

    public function test_null_size_uses_unspecified_bucket(): void
    {
        $asset = $this->createAsset(['size_inches' => null]);
        $this->createFailure($asset, 'MR-1');

        $json = $this->actingAs($this->admin)
            ->getJson('/api/reports/bad-actors?group_by=size')->json();

        $this->assertCount(1, $json['items']);
        $this->assertSame('unspecified', $json['items'][0]['group_key']);
        $this->assertSame('Unspecified', $json['items'][0]['group_label']);
    }

    /**
     * FA Subclass is ERP-owned, so it may not drive an ATMS report dimension.
     * Maintenance Category replaced it; this guards against reinstatement.
     */
    public function test_asset_class_group_by_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->getJson('/api/reports/bad-actors?group_by=asset_class')
            ->assertStatus(422);
    }

    public function test_maintenance_category_filter_applies(): void
    {
        $category = MaintenanceCategory::factory()->create(['code' => 'JAR', 'name' => 'Jar']);
        $inCategory = $this->createAsset(['maintenance_category_id' => $category->id]);
        $outOfCategory = $this->createAsset(['maintenance_category_id' => null]);
        $this->createFailure($inCategory, 'MR-1');
        $this->createFailure($outOfCategory, 'MR-2');

        $json = $this->actingAs($this->admin)
            ->getJson('/api/reports/bad-actors?maintenance_category_id='.$category->id)->json();

        $this->assertCount(1, $json['items']);
    }

    private function createFailure(Asset $asset, string $number): void
    {
        MaintenanceRequest::forceCreate([
            'number' => $number,
            'asset_id' => $asset->id,
            'status' => 'converted',
            'priority' => 'high',
            'description' => 'Failure',
            'created_by' => $this->admin->id,
            'is_preventive' => false,
            'is_failure' => true,
            'created_at' => now()->subDays(10),
        ]);
    }
}
