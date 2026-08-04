<?php

namespace Tests\Feature\Reports;

use App\Enums\RoleCode;
use App\Models\Asset;
use App\Models\Location;
use App\Models\MaintenanceCategory;
use App\Models\Role;
use App\Models\UsageReadingType;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cross-cutting behaviour of `?format=csv`, which every report shares through
 * CsvReportStreamer. Per-report column contracts live with their own tests.
 */
class ReportCsvExportTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $role = Role::where('code', RoleCode::ADMINISTRATOR->value)->firstOrFail();
        $this->admin = User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
    }

    private function csv(string $url): string
    {
        $response = $this->actingAs($this->admin)->get($url);
        $response->assertOk();

        return $response->streamedContent();
    }

    /** Export is not a side door — it sits behind the same auth as the JSON. */
    public function test_export_requires_authentication(): void
    {
        $this->getJson('/api/reports/asset-status?format=csv')->assertUnauthorized();
    }

    public function test_sends_csv_headers_with_a_dated_filename(): void
    {
        $response = $this->actingAs($this->admin)->get('/api/reports/asset-status?format=csv');

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertMatchesRegularExpression(
            '/attachment; filename="asset-status-\d{4}-\d{2}-\d{2}\.csv"/',
            $response->headers->get('content-disposition') ?? '',
        );
    }

    /** Without the BOM, Excel renders Arabic asset names as mojibake. */
    public function test_starts_with_a_utf8_bom(): void
    {
        $this->assertStringStartsWith(
            "\xEF\xBB\xBF",
            $this->csv('/api/reports/asset-status?format=csv'),
        );
    }

    public function test_writes_human_headers_and_row_values(): void
    {
        $category = MaintenanceCategory::factory()->create(['code' => 'RIGS', 'name' => 'Rigs']);
        $location = Location::create(['name' => 'Yard-A', 'type' => 'yard']);
        Asset::create([
            'erp_asset_code' => 'A-1',
            'name' => 'Top Drive',
            'asset_tag' => 'TD-001',
            'is_active' => true,
            'maintenance_category_id' => $category->id,
            'current_location_id' => $location->id,
        ]);

        $csv = $this->csv('/api/reports/asset-status?format=csv');
        [$header, $row] = explode("\n", trim($csv));

        // fputcsv quotes any field containing a space, which is valid CSV.
        $this->assertStringContainsString('"Asset Tag",Name,Kind,"Maintenance Category"', $header);
        // Headers are for a person in Excel, not the API contract.
        $this->assertStringNotContainsString('asset_tag', $header);

        $this->assertStringContainsString('TD-001', $row);
        $this->assertStringContainsString('Top Drive', $row);
        $this->assertStringContainsString('Rigs', $row);
        $this->assertStringContainsString('Yard-A', $row);
    }

    /** Booleans read as words, not 1/0, because a person reads this file. */
    public function test_formats_booleans_as_yes_and_no(): void
    {
        Asset::create([
            'erp_asset_code' => 'A-2',
            'name' => 'Unbooked Asset',
            'is_active' => true,
        ]);

        $this->assertStringContainsString('No', $this->csv('/api/reports/asset-status?format=csv'));
    }

    public function test_export_honours_the_same_filters_as_the_table(): void
    {
        $rigs = MaintenanceCategory::factory()->create(['code' => 'RIGS', 'name' => 'Rigs']);
        $pumps = MaintenanceCategory::factory()->create(['code' => 'PUMPS', 'name' => 'Pumps']);
        Asset::create(['erp_asset_code' => 'A-3', 'name' => 'Rig Asset', 'is_active' => true, 'maintenance_category_id' => $rigs->id]);
        Asset::create(['erp_asset_code' => 'A-4', 'name' => 'Pump Asset', 'is_active' => true, 'maintenance_category_id' => $pumps->id]);

        $csv = $this->csv('/api/reports/asset-status?format=csv&maintenance_category_id='.$rigs->id);

        $this->assertStringContainsString('Rig Asset', $csv);
        $this->assertStringNotContainsString('Pump Asset', $csv);
    }

    /** The export is the whole result set, not the page the table happens to show. */
    public function test_export_is_not_limited_to_one_page(): void
    {
        foreach (range(1, 12) as $i) {
            Asset::create([
                'erp_asset_code' => 'A-BULK-'.$i,
                'name' => 'Bulk Asset '.$i,
                'is_active' => true,
            ]);
        }

        $csv = $this->csv('/api/reports/asset-status?format=csv&per_page=5');

        // 12 rows + header, ignoring the trailing newline.
        $this->assertCount(13, array_filter(explode("\n", trim($csv))));
    }

    public function test_aggregate_reports_export_their_grouped_rows(): void
    {
        $location = Location::create(['name' => 'Yard-B', 'type' => 'yard']);
        Asset::create(['erp_asset_code' => 'A-5', 'name' => 'Asset', 'is_active' => true, 'current_location_id' => $location->id]);

        $csv = $this->csv('/api/reports/asset-distribution?format=csv');

        $this->assertStringContainsString('Location,Assets,"Ready for Field","Under Maintenance",Down,Scraped,"Under Inspection","Lost in Hole"', $csv);
        $this->assertStringContainsString('Yard-B', $csv);
    }

    /** The grouped column is named after the dimension that produced it. */
    public function test_aggregate_export_header_follows_the_dimension(): void
    {
        $category = MaintenanceCategory::factory()->create(['code' => 'RIGS', 'name' => 'Rigs']);
        Asset::create(['erp_asset_code' => 'A-6', 'name' => 'Asset', 'is_active' => true, 'maintenance_category_id' => $category->id]);

        $csv = $this->csv('/api/reports/asset-distribution?format=csv&group_by=maintenance_category');

        $this->assertStringContainsString('"Maintenance Category",Assets', $csv);
    }

    /** Units are not interchangeable, so the usage header always carries one. */
    public function test_usage_export_header_carries_the_unit(): void
    {
        UsageReadingType::create(['name' => 'Operating Hours', 'unit' => 'hours', 'is_active' => true]);

        $csv = $this->csv('/api/reports/asset-usage?format=csv');

        $this->assertStringContainsString('"Usage (hours)"', $csv);
    }

    public function test_json_remains_the_default_shape(): void
    {
        $this->actingAs($this->admin)
            ->getJson('/api/reports/asset-status')
            ->assertOk()
            ->assertJsonStructure(['data', 'summary']);
    }
}
