<?php

namespace Tests\Feature\Assets;

use App\Models\Asset;
use App\Models\MaintenanceCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssetMigrationImportTest extends TestCase
{
    use RefreshDatabase;

    private ?string $temporaryFile = null;

    protected function tearDown(): void
    {
        if ($this->temporaryFile !== null && is_file($this->temporaryFile)) {
            unlink($this->temporaryFile);
        }

        parent::tearDown();
    }

    public function test_import_uses_the_canonical_maintenance_category_code(): void
    {
        $asset = Asset::create([
            'erp_asset_code' => 'AST-MWD-001',
            'name' => 'APS Tool',
            'is_active' => true,
        ]);
        $this->temporaryFile = $this->writeCsv([
            'asset_tag' => '',
            'erp_asset_code' => $asset->erp_asset_code,
            'name' => $asset->name,
            'maintenance_category' => 'MWD / APS',
            'asset_kind' => '',
            'serial_number' => '',
            'size' => '',
            'model' => '',
            'manufacturer_code' => '',
            'fa_subclass_code' => '',
            'operational_status' => '',
            'maintenance_status' => '',
        ]);

        $this->artisan('atms:import-assets', ['file' => $this->temporaryFile])
            ->assertSuccessful();

        $this->assertDatabaseHas('maintenance_categories', ['code' => 'MWD_APS']);
        $category = MaintenanceCategory::where('code', 'MWD_APS')->firstOrFail();

        $this->assertSame($category->id, $asset->refresh()->maintenance_category_id);
    }

    public function test_import_rejects_a_category_name_that_cannot_produce_a_valid_code(): void
    {
        $asset = Asset::create([
            'erp_asset_code' => 'AST-INVALID-CATEGORY',
            'name' => 'Invalid Category Tool',
            'is_active' => true,
        ]);
        $this->temporaryFile = $this->writeCsv([
            'asset_tag' => '',
            'erp_asset_code' => $asset->erp_asset_code,
            'name' => $asset->name,
            'maintenance_category' => '///',
            'asset_kind' => '',
            'serial_number' => '',
            'size' => '',
            'model' => '',
            'manufacturer_code' => '',
            'fa_subclass_code' => '',
            'operational_status' => '',
            'maintenance_status' => '',
        ]);

        $this->artisan('atms:import-assets', ['file' => $this->temporaryFile])
            ->expectsOutputToContain('Maintenance Category cannot produce a valid code')
            ->assertFailed();

        $this->assertSame(
            MaintenanceCategory::where('code', MaintenanceCategory::UNCLASSIFIED_CODE)->value('id'),
            $asset->refresh()->maintenance_category_id,
        );
        // Only the sentinel — the rejected import created no vocabulary.
        $this->assertDatabaseCount('maintenance_categories', 1);
    }

    /**
     * @param  array<string, string>  $row
     */
    private function writeCsv(array $row): string
    {
        $path = tempnam(sys_get_temp_dir(), 'atms-assets-');
        $this->assertNotFalse($path);

        $handle = fopen($path, 'w');
        $this->assertNotFalse($handle);
        fputcsv($handle, array_keys($row), escape: '');
        fputcsv($handle, array_values($row), escape: '');
        fclose($handle);

        return $path;
    }
}
