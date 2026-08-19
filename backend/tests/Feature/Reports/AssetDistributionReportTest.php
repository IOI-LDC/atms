<?php

namespace Tests\Feature\Reports;

use App\Enums\AssetKind;
use App\Enums\BookingStatus;
use App\Enums\OperationalStatus;
use App\Enums\RoleCode;
use App\Models\Asset;
use App\Models\Booking;
use App\Models\Location;
use App\Models\MaintenanceCategory;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssetDistributionReportTest extends TestCase
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

    /** Match a row by the key of its first (or only) grouped dimension. */
    private function findRow(array $items, int|string|null $groupKey): ?array
    {
        return collect($items)->first(fn (array $i) => ($i['groups'][0]['key'] ?? null) === $groupKey);
    }

    /** @return array<int, string> the labels of one row's grouped dimensions */
    private function labels(array $row): array
    {
        return array_column($row['groups'], 'label');
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
        $this->assertSame(2, $json['summary']['total_groups']);
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
        // The null bucket is a row but not an actionable group.
        $this->assertSame(1, $json['summary']['total_groups']);
        $unassigned = collect($json['items'])->first(fn (array $i) => $i['groups'][0]['is_unassigned']);
        $this->assertNotNull($unassigned);
        $this->assertSame('Unassigned', $unassigned['groups'][0]['label']);
        $this->assertSame(1, $unassigned['asset_count']);
    }

    public function test_breaks_down_by_operational_status_and_asset_kind_and_booked(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $loc = $this->createLocation('Loc-A');
        $this->createAsset([
            'current_location_id' => $loc->id,
            'operational_status' => OperationalStatus::READY_FOR_FIELD,
            'asset_kind' => AssetKind::ASSET,
        ]);
        $this->createAsset([
            'current_location_id' => $loc->id,
            'operational_status' => OperationalStatus::FAILURE,
            'asset_kind' => AssetKind::COMPONENT,
        ]);
        $bookedAsset = $this->createAsset([
            'current_location_id' => $loc->id,
            'operational_status' => OperationalStatus::READY_FOR_FIELD,
            'asset_kind' => AssetKind::PACKAGE,
        ]);

        Booking::create([
            'asset_id' => $bookedAsset->id,
            'booked_by' => $admin->id,
            'booked_from' => now()->subDay()->toDateString(),
            'booked_until' => now()->addDays(30)->toDateString(),
            'status' => BookingStatus::ACTIVE,
        ]);

        $row = $this->findRow(
            $this->actingAs($admin)->getJson('/api/reports/assets-by-location')->json('items'),
            $loc->id
        );

        $this->assertSame(3, $row['asset_count']);
        $this->assertSame(
            ['ready_for_field' => 2, 'under_maintenance' => 0, 'failure' => 1, 'at_the_field' => 0],
            $row['by_operational_status']
        );
        $this->assertSame(
            ['standalone' => 1, 'package' => 1, 'component' => 1],
            $row['by_asset_kind']
        );
        $this->assertSame(1, $row['booked_count']);
    }

    public function test_maintenance_category_filter_applies(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $loc = $this->createLocation('Loc-A');
        $hvac = MaintenanceCategory::factory()->create(['code' => 'HVAC', 'name' => 'HVAC']);
        $pumps = MaintenanceCategory::factory()->create(['code' => 'PUMPS', 'name' => 'Pumps']);
        $this->createAsset(['current_location_id' => $loc->id, 'maintenance_category_id' => $hvac->id]);
        $this->createAsset(['current_location_id' => $loc->id, 'maintenance_category_id' => $hvac->id]);
        $this->createAsset(['current_location_id' => $loc->id, 'maintenance_category_id' => $pumps->id]);

        $json = $this->actingAs($admin)->getJson('/api/reports/assets-by-location?maintenance_category_id='.$hvac->id)->json();

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
        $this->assertSame(0, $json['summary']['total_groups']);
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

    public function test_groups_by_maintenance_category(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $hvac = MaintenanceCategory::factory()->create(['code' => 'HVAC', 'name' => 'HVAC']);
        $pumps = MaintenanceCategory::factory()->create(['code' => 'PUMPS', 'name' => 'Pumps']);
        $this->createAsset(['maintenance_category_id' => $hvac->id]);
        $this->createAsset(['maintenance_category_id' => $hvac->id]);
        $this->createAsset(['maintenance_category_id' => $pumps->id]);
        $this->createAsset();

        $json = $this->actingAs($admin)
            ->getJson('/api/reports/asset-distribution?group_by=maintenance_category')->json();

        $this->assertSame(['maintenance_category'], $json['group_by']);
        $this->assertSame(4, $json['summary']['total_assets']);
        // Three real categories: an unclassified asset lands in Unclassified,
        // which counts as a group because it is one.
        $this->assertSame(3, $json['summary']['total_groups']);
        $this->assertSame(2, $this->findRow($json['items'], $hvac->id)['asset_count']);
        $this->assertSame('HVAC', $this->findRow($json['items'], $hvac->id)['groups'][0]['label']);

        $this->assertNull(collect($json['items'])->first(fn (array $i) => $i['groups'][0]['is_unassigned']));
        $unclassified = collect($json['items'])->first(fn (array $i) => $i['groups'][0]['label'] === 'Unclassified');
        $this->assertSame(1, $unclassified['asset_count']);
    }

    public function test_groups_by_size_with_og_notation_labels(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $this->createAsset(['size_inches' => '6.75000']);
        $this->createAsset(['size_inches' => '6.75000']);
        $this->createAsset(['size_inches' => '9.62500']);
        $this->createAsset(['size_inches' => null]);

        $json = $this->actingAs($admin)
            ->getJson('/api/reports/asset-distribution?group_by=size')->json();

        $this->assertSame(4, $json['summary']['total_assets']);
        $this->assertSame(2, $json['summary']['total_groups']);

        $labels = collect($json['items'])->map(fn (array $i) => $i['groups'][0]['label'])->all();
        $this->assertContains('6 3/4"', $labels);
        $this->assertContains('9 5/8"', $labels);
        $this->assertContains('Unspecified', $labels);
    }

    /** Sizes sort numerically, not as text — 10" must not sort before 6 3/4". */
    public function test_size_groups_sort_numerically(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $this->createAsset(['size_inches' => '10.00000']);
        $this->createAsset(['size_inches' => '6.75000']);

        $items = $this->actingAs($admin)
            ->getJson('/api/reports/asset-distribution?group_by=size')->json('items');

        $this->assertSame('6 3/4"', $items[0]['groups'][0]['label']);
        $this->assertSame('10"', $items[1]['groups'][0]['label']);
    }

    public function test_defaults_to_location_and_rejects_unknown_dimension(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);

        $this->assertSame(
            ['location'],
            $this->actingAs($admin)->getJson('/api/reports/asset-distribution')->json('group_by')
        );

        // FA Subclass is ERP-owned and must never become a dimension again.
        $this->actingAs($admin)
            ->getJson('/api/reports/asset-distribution?group_by=asset_class')
            ->assertStatus(422);
    }

    /** The pre-dimension path still resolves, so existing links keep working. */
    public function test_legacy_assets_by_location_path_still_works(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $loc = $this->createLocation('Loc-A');
        $this->createAsset(['current_location_id' => $loc->id]);

        $json = $this->actingAs($admin)->getJson('/api/reports/assets-by-location')->json();

        $this->assertSame(['location'], $json['group_by']);
        $this->assertSame(1, $json['summary']['total_assets']);
    }

    /**
     * The headline case: one row per distinct category + size + location
     * combination, which is what makes the export a pivot table.
     */
    public function test_groups_by_category_size_and_location_together(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $motors = MaintenanceCategory::factory()->create(['code' => 'MUD_MOTOR', 'name' => 'Mud Motor']);
        $jars = MaintenanceCategory::factory()->create(['code' => 'JARS', 'name' => 'Jars']);
        $rig = $this->createLocation('Rig-7');
        $yard = $this->createLocation('Yard-A');

        // Two assets share every dimension, so they collapse into one row.
        $this->createAsset(['maintenance_category_id' => $motors->id, 'size_inches' => '6.75000', 'current_location_id' => $rig->id]);
        $this->createAsset(['maintenance_category_id' => $motors->id, 'size_inches' => '6.75000', 'current_location_id' => $rig->id]);
        // Same category and size, different location — a separate row.
        $this->createAsset(['maintenance_category_id' => $motors->id, 'size_inches' => '6.75000', 'current_location_id' => $yard->id]);
        // Same category and location, different size — a separate row.
        $this->createAsset(['maintenance_category_id' => $motors->id, 'size_inches' => '9.62500', 'current_location_id' => $rig->id]);
        $this->createAsset(['maintenance_category_id' => $jars->id, 'size_inches' => '6.75000', 'current_location_id' => $yard->id]);

        $json = $this->actingAs($admin)->getJson(
            '/api/reports/asset-distribution'
            .'?group_by[]=maintenance_category&group_by[]=size&group_by[]=location'
        )->json();

        $this->assertSame(['maintenance_category', 'size', 'location'], $json['group_by']);
        $this->assertSame(5, $json['summary']['total_assets']);
        $this->assertCount(4, $json['items']);

        // Columns come back in the order requested.
        $combos = collect($json['items'])->map(fn (array $i) => $this->labels($i))->all();
        $this->assertContains(['Mud Motor', '6 3/4"', 'Rig-7'], $combos);
        $this->assertContains(['Mud Motor', '6 3/4"', 'Yard-A'], $combos);
        $this->assertContains(['Mud Motor', '9 5/8"', 'Rig-7'], $combos);
        $this->assertContains(['Jars', '6 3/4"', 'Yard-A'], $combos);

        $collapsed = collect($json['items'])
            ->first(fn (array $i) => $this->labels($i) === ['Mud Motor', '6 3/4"', 'Rig-7']);
        $this->assertSame(2, $collapsed['asset_count']);
    }

    /** Dimension order drives column order, so the caller controls the nesting. */
    public function test_dimension_order_is_respected(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $category = MaintenanceCategory::factory()->create(['code' => 'RIGS', 'name' => 'Rigs']);
        $loc = $this->createLocation('Rig-7');
        $this->createAsset(['maintenance_category_id' => $category->id, 'current_location_id' => $loc->id]);

        $json = $this->actingAs($admin)->getJson(
            '/api/reports/asset-distribution?group_by[]=location&group_by[]=maintenance_category'
        )->json();

        $this->assertSame(['location', 'maintenance_category'], $json['group_by']);
        $this->assertSame(['Rig-7', 'Rigs'], $this->labels($json['items'][0]));
    }

    /** A partly-unidentified combination still shows, with named null buckets. */
    public function test_null_buckets_appear_per_dimension(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $this->createAsset(['size_inches' => null, 'current_location_id' => null]);

        $json = $this->actingAs($admin)->getJson(
            '/api/reports/asset-distribution'
            .'?group_by[]=maintenance_category&group_by[]=size&group_by[]=location'
        )->json();

        $this->assertSame(['Unclassified', 'Unspecified', 'Unassigned'], $this->labels($json['items'][0]));
        $this->assertSame(1, $json['summary']['total_assets']);
        // Not an actionable group, so it is a visible row but not counted.
        $this->assertSame(0, $json['summary']['total_groups']);
    }

    public function test_duplicate_dimensions_collapse_and_unknown_ones_are_rejected(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);

        $this->assertSame(
            ['size'],
            $this->actingAs($admin)
                ->getJson('/api/reports/asset-distribution?group_by[]=size&group_by[]=size')
                ->json('group_by'),
        );

        $this->actingAs($admin)
            ->getJson('/api/reports/asset-distribution?group_by[]=asset_class')
            ->assertStatus(422);
    }

    public function test_operational_status_filter_applies(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $loc = $this->createLocation('Loc-A');
        $this->createAsset([
            'current_location_id' => $loc->id,
            'operational_status' => OperationalStatus::READY_FOR_FIELD,
        ]);
        $this->createAsset([
            'current_location_id' => $loc->id,
            'operational_status' => OperationalStatus::FAILURE,
        ]);

        $json = $this->actingAs($admin)
            ->getJson('/api/reports/assets-by-location?operational_status=ready_for_field')->json();

        $this->assertSame(1, $json['summary']['total_assets']);
        $row = $this->findRow($json['items'], $loc->id);
        $this->assertSame(1, $row['asset_count']);
        $this->assertSame(1, $row['by_operational_status']['ready_for_field']);
        $this->assertSame(0, $row['by_operational_status']['failure']);
    }

    // ── Condition as a dimension ────────────────────────────────────────────────

    /**
     * Condition is a **grouping dimension**, not a set of per-condition count
     * columns.
     *
     * The vocabulary is Admin-editable, so count columns would change shape
     * whenever LDC add or retire a value — a CSV whose header depends on the
     * database contents is one nobody can build a spreadsheet against. As a
     * dimension it behaves exactly like location, category and size, and both
     * the JSON and the file stay stable.
     */
    public function test_groups_by_condition(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $this->createAsset(['condition_status' => 'need_inspection']);
        $this->createAsset(['condition_status' => 'need_inspection']);
        $this->createAsset(['condition_status' => 'missing_parts']);

        $json = $this->actingAs($admin)
            ->getJson('/api/reports/asset-distribution?group_by=condition')->json();

        $this->assertSame(['condition'], $json['group_by']);
        $this->assertSame(3, $json['summary']['total_assets']);

        $row = $this->findRow($json['items'], 'need_inspection');
        $this->assertSame(2, $row['asset_count']);
        $this->assertSame('Need Inspection', $row['groups'][0]['label']);
    }

    /**
     * "Unrecorded" is not "Normal". An asset nobody has assessed is a different
     * statement from one assessed as fine, and collapsing the two would let a
     * gap in the data read as a clean bill of health.
     */
    public function test_assets_with_no_condition_fall_into_an_unrecorded_bucket(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $this->createAsset(['condition_status' => 'normal']);
        $this->createAsset(['condition_status' => null]);

        $json = $this->actingAs($admin)
            ->getJson('/api/reports/asset-distribution?group_by=condition')->json();

        $unrecorded = $this->findRow($json['items'], null);
        $this->assertSame(1, $unrecorded['asset_count']);
        $this->assertSame('Unrecorded', $unrecorded['groups'][0]['label']);
        $this->assertTrue($unrecorded['groups'][0]['is_unassigned']);

        $this->assertSame('Normal', $this->findRow($json['items'], 'normal')['groups'][0]['label']);
    }

    public function test_condition_filter_applies(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $loc = $this->createLocation('Loc-A');
        $this->createAsset(['current_location_id' => $loc->id, 'condition_status' => 'missing_parts']);
        $this->createAsset(['current_location_id' => $loc->id, 'condition_status' => 'normal']);

        $json = $this->actingAs($admin)
            ->getJson('/api/reports/asset-distribution?condition_status=missing_parts')->json();

        $this->assertSame(1, $json['summary']['total_assets']);
        $this->assertSame(1, $this->findRow($json['items'], $loc->id)['asset_count']);
    }

    /** Four dimensions now, so all four must be combinable at once. */
    public function test_condition_combines_with_the_other_three_dimensions(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $category = MaintenanceCategory::factory()->create(['code' => 'MOTOR', 'name' => 'Mud Motor']);
        $loc = $this->createLocation('Yard-A');
        $this->createAsset([
            'current_location_id' => $loc->id,
            'maintenance_category_id' => $category->id,
            'size_inches' => '6.75000',
            'condition_status' => 'missing_parts',
        ]);

        $json = $this->actingAs($admin)->getJson(
            '/api/reports/asset-distribution?group_by[]=maintenance_category&group_by[]=size'
            .'&group_by[]=location&group_by[]=condition'
        )->assertOk()->json();

        $this->assertSame(
            ['Mud Motor', '6 3/4"', 'Yard-A', 'Missing Parts'],
            $this->labels($json['items'][0]),
        );
    }

    public function test_the_csv_export_carries_a_condition_column(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $this->createAsset(['condition_status' => 'need_assembly']);

        $csv = $this->actingAs($admin)
            ->get('/api/reports/asset-distribution?group_by=condition&format=csv')
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('Condition', $csv);
        $this->assertStringContainsString('Need Assembly', $csv);
    }
}
