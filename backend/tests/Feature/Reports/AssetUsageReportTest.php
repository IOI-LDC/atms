<?php

namespace Tests\Feature\Reports;

use App\Enums\RoleCode;
use App\Models\Asset;
use App\Models\AssetMeterReading;
use App\Models\Location;
use App\Models\MaintenanceCategory;
use App\Models\Role;
use App\Models\UsageReadingType;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssetUsageReportTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected UsageReadingType $hours;

    protected UsageReadingType $kilometres;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $this->hours = UsageReadingType::create(['name' => 'Operating Hours', 'unit' => 'hours', 'is_active' => true]);
        $this->kilometres = UsageReadingType::create(['name' => 'Kilometer Driven', 'unit' => 'kilometer', 'is_active' => true]);
    }

    private function createUser(RoleCode $roleCode): User
    {
        $role = Role::where('code', $roleCode->value)->firstOrFail();

        return User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
    }

    private function createAsset(array $overrides = []): Asset
    {
        return Asset::create(array_merge([
            'erp_asset_code' => 'A-'.uniqid(),
            'name' => 'Asset-'.uniqid(),
            'is_active' => true,
        ], $overrides));
    }

    /** Readings default to confirmed — an unconfirmed reading is a claim, not a fact. */
    private function reading(
        Asset $asset,
        UsageReadingType $type,
        float $value,
        string $at,
        bool $confirmed = true,
    ): AssetMeterReading {
        return AssetMeterReading::create([
            'asset_id' => $asset->id,
            'usage_reading_type_id' => $type->id,
            'reading_value' => $value,
            'reading_at' => $at,
            'source' => 'manual',
            'entered_by_user_id' => $this->admin->id,
            'confirmed_at' => $confirmed ? now() : null,
            'confirmed_by_user_id' => $confirmed ? $this->admin->id : null,
        ]);
    }

    public function test_unauthenticated_is_rejected(): void
    {
        $this->getJson('/api/reports/asset-usage')->assertUnauthorized();
    }

    public function test_all_authenticated_roles_can_view(): void
    {
        foreach (RoleCode::cases() as $roleCode) {
            $this->actingAs($this->createUser($roleCode))
                ->getJson('/api/reports/asset-usage')
                ->assertOk();
        }
    }

    public function test_ranks_assets_by_usage_within_the_window(): void
    {
        $busy = $this->createAsset(['name' => 'Busy Rig']);
        $quiet = $this->createAsset(['name' => 'Quiet Rig']);

        $this->reading($busy, $this->hours, 100, '2026-01-01 08:00:00');
        $this->reading($busy, $this->hours, 900, '2026-03-01 08:00:00');
        $this->reading($quiet, $this->hours, 500, '2026-01-01 08:00:00');
        $this->reading($quiet, $this->hours, 560, '2026-03-01 08:00:00');

        $json = $this->actingAs($this->admin)->getJson(
            '/api/reports/asset-usage?usage_reading_type_id='.$this->hours->id
            .'&from=2026-01-01&to=2026-03-31'
        )->json();

        $this->assertSame('hours', $json['reading_type']['unit']);
        $this->assertSame('asset', $json['group_by']);
        $this->assertSame('Busy Rig', $json['items'][0]['group_label']);
        $this->assertEqualsWithDelta(800.0, $json['items'][0]['usage'], 0.01);
        $this->assertSame('Quiet Rig', $json['items'][1]['group_label']);
        $this->assertEqualsWithDelta(60.0, $json['items'][1]['usage'], 0.01);
        $this->assertEqualsWithDelta(860.0, $json['summary']['total_usage'], 0.01);
    }

    /**
     * The case a naive max-minus-min gets wrong: an asset with one reading
     * before the window and one inside it has used everything in between.
     */
    public function test_baseline_comes_from_the_last_reading_before_the_window(): void
    {
        $asset = $this->createAsset();
        $this->reading($asset, $this->hours, 1000, '2025-12-15 08:00:00');
        $this->reading($asset, $this->hours, 1250, '2026-02-01 08:00:00');

        $json = $this->actingAs($this->admin)->getJson(
            '/api/reports/asset-usage?usage_reading_type_id='.$this->hours->id
            .'&from=2026-01-01&to=2026-03-31'
        )->json();

        $this->assertEqualsWithDelta(250.0, $json['items'][0]['usage'], 0.01);
        $this->assertEqualsWithDelta(1250.0, $json['items'][0]['latest_reading'], 0.01);
    }

    /** A newly-metered asset has no prior reading, so the window's floor is the baseline. */
    public function test_asset_first_metered_inside_the_window_uses_window_floor(): void
    {
        $asset = $this->createAsset();
        $this->reading($asset, $this->hours, 40, '2026-01-10 08:00:00');
        $this->reading($asset, $this->hours, 190, '2026-02-10 08:00:00');

        $json = $this->actingAs($this->admin)->getJson(
            '/api/reports/asset-usage?usage_reading_type_id='.$this->hours->id
            .'&from=2026-01-01&to=2026-03-31'
        )->json();

        $this->assertEqualsWithDelta(150.0, $json['items'][0]['usage'], 0.01);
    }

    public function test_unconfirmed_readings_are_ignored(): void
    {
        $asset = $this->createAsset();
        $this->reading($asset, $this->hours, 100, '2026-01-01 08:00:00');
        $this->reading($asset, $this->hours, 999, '2026-02-01 08:00:00', confirmed: false);
        $this->reading($asset, $this->hours, 150, '2026-02-15 08:00:00');

        $json = $this->actingAs($this->admin)->getJson(
            '/api/reports/asset-usage?usage_reading_type_id='.$this->hours->id
            .'&from=2026-01-01&to=2026-03-31'
        )->json();

        $this->assertEqualsWithDelta(50.0, $json['items'][0]['usage'], 0.01);
        $this->assertEqualsWithDelta(150.0, $json['items'][0]['latest_reading'], 0.01);
    }

    /** Units differ, so a reading type never bleeds into another one's ranking. */
    public function test_reading_types_are_isolated(): void
    {
        $asset = $this->createAsset();
        $this->reading($asset, $this->hours, 100, '2026-01-01 08:00:00');
        $this->reading($asset, $this->hours, 300, '2026-02-01 08:00:00');
        $this->reading($asset, $this->kilometres, 5000, '2026-01-01 08:00:00');
        $this->reading($asset, $this->kilometres, 5900, '2026-02-01 08:00:00');

        $byHours = $this->actingAs($this->admin)->getJson(
            '/api/reports/asset-usage?usage_reading_type_id='.$this->hours->id.'&from=2026-01-01&to=2026-03-31'
        )->json();
        $byKm = $this->actingAs($this->admin)->getJson(
            '/api/reports/asset-usage?usage_reading_type_id='.$this->kilometres->id.'&from=2026-01-01&to=2026-03-31'
        )->json();

        $this->assertEqualsWithDelta(200.0, $byHours['items'][0]['usage'], 0.01);
        $this->assertSame('hours', $byHours['reading_type']['unit']);
        $this->assertEqualsWithDelta(900.0, $byKm['items'][0]['usage'], 0.01);
        $this->assertSame('kilometer', $byKm['reading_type']['unit']);
    }

    public function test_groups_by_maintenance_category(): void
    {
        $rigs = MaintenanceCategory::factory()->create(['code' => 'RIGS', 'name' => 'Rigs']);
        $a = $this->createAsset(['maintenance_category_id' => $rigs->id]);
        $b = $this->createAsset(['maintenance_category_id' => $rigs->id]);
        $uncategorised = $this->createAsset(['maintenance_category_id' => null]);

        foreach ([[$a, 100, 300], [$b, 0, 50], [$uncategorised, 10, 20]] as [$asset, $start, $end]) {
            $this->reading($asset, $this->hours, $start, '2026-01-01 08:00:00');
            $this->reading($asset, $this->hours, $end, '2026-02-01 08:00:00');
        }

        $json = $this->actingAs($this->admin)->getJson(
            '/api/reports/asset-usage?usage_reading_type_id='.$this->hours->id
            .'&group_by=maintenance_category&from=2026-01-01&to=2026-03-31'
        )->json();

        $this->assertSame('maintenance_category', $json['group_by']);
        $rigsRow = collect($json['items'])->firstWhere('group_key', $rigs->id);
        $this->assertEqualsWithDelta(250.0, $rigsRow['usage'], 0.01);
        $this->assertSame(2, $rigsRow['asset_count']);
        $this->assertSame('Rigs', $rigsRow['group_label']);

        $noneRow = collect($json['items'])->firstWhere('is_unassigned', true);
        $this->assertSame('Uncategorised', $noneRow['group_label']);
    }

    public function test_groups_by_size_with_og_notation(): void
    {
        $a = $this->createAsset(['size_inches' => '6.75000']);
        $b = $this->createAsset(['size_inches' => '6.75000']);

        foreach ([$a, $b] as $asset) {
            $this->reading($asset, $this->hours, 0, '2026-01-01 08:00:00');
            $this->reading($asset, $this->hours, 100, '2026-02-01 08:00:00');
        }

        $json = $this->actingAs($this->admin)->getJson(
            '/api/reports/asset-usage?usage_reading_type_id='.$this->hours->id
            .'&group_by=size&from=2026-01-01&to=2026-03-31'
        )->json();

        $this->assertSame('6 3/4"', $json['items'][0]['group_label']);
        $this->assertEqualsWithDelta(200.0, $json['items'][0]['usage'], 0.01);
        $this->assertSame(2, $json['items'][0]['asset_count']);
    }

    public function test_location_and_category_filters_apply(): void
    {
        $loc = Location::create(['name' => 'Yard-A', 'type' => 'yard']);
        $atYard = $this->createAsset(['current_location_id' => $loc->id]);
        $elsewhere = $this->createAsset(['current_location_id' => null]);

        foreach ([$atYard, $elsewhere] as $asset) {
            $this->reading($asset, $this->hours, 0, '2026-01-01 08:00:00');
            $this->reading($asset, $this->hours, 100, '2026-02-01 08:00:00');
        }

        $json = $this->actingAs($this->admin)->getJson(
            '/api/reports/asset-usage?usage_reading_type_id='.$this->hours->id
            .'&location_id='.$loc->id.'&from=2026-01-01&to=2026-03-31'
        )->json();

        $this->assertCount(1, $json['items']);
        $this->assertEqualsWithDelta(100.0, $json['summary']['total_usage'], 0.01);
    }

    public function test_limit_caps_rows_but_summary_covers_everything(): void
    {
        foreach (range(1, 5) as $i) {
            $asset = $this->createAsset();
            $this->reading($asset, $this->hours, 0, '2026-01-01 08:00:00');
            $this->reading($asset, $this->hours, $i * 10, '2026-02-01 08:00:00');
        }

        $json = $this->actingAs($this->admin)->getJson(
            '/api/reports/asset-usage?usage_reading_type_id='.$this->hours->id
            .'&limit=2&from=2026-01-01&to=2026-03-31'
        )->json();

        $this->assertCount(2, $json['items']);
        $this->assertEqualsWithDelta(50.0, $json['items'][0]['usage'], 0.01);
        // 10+20+30+40+50 — the summary is not truncated by the limit.
        $this->assertEqualsWithDelta(150.0, $json['summary']['total_usage'], 0.01);
    }

    public function test_defaults_to_first_active_reading_type(): void
    {
        $json = $this->actingAs($this->admin)->getJson('/api/reports/asset-usage')->json();

        $this->assertSame($this->hours->id, $json['reading_type']['id']);
    }

    public function test_rejects_unknown_dimension_and_unknown_reading_type(): void
    {
        $this->actingAs($this->admin)
            ->getJson('/api/reports/asset-usage?group_by=asset_class')
            ->assertStatus(422);

        $this->actingAs($this->admin)
            ->getJson('/api/reports/asset-usage?usage_reading_type_id=99999')
            ->assertStatus(422);
    }

    public function test_empty_state(): void
    {
        $json = $this->actingAs($this->admin)->getJson(
            '/api/reports/asset-usage?usage_reading_type_id='.$this->hours->id
        )->json();

        $this->assertSame([], $json['items']);
        $this->assertEqualsWithDelta(0.0, $json['summary']['total_usage'], 0.01);
        $this->assertSame(0, $json['summary']['assets_with_usage']);
    }
}
