<?php

namespace Tests\Feature\Assets;

use App\Models\Asset;
use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SyncAssetNamesTest extends TestCase
{
    use RefreshDatabase;

    private ?string $file = null;

    protected function tearDown(): void
    {
        if ($this->file !== null && is_file($this->file)) {
            unlink($this->file);
        }

        parent::tearDown();
    }

    /** @param  array<int, array{0: string, 1: string, 2: string}>  $rows  code, name, size */
    private function workbook(array $rows): string
    {
        $this->file = tempnam(sys_get_temp_dir(), 'atms-sync-');
        $handle = fopen($this->file, 'w');
        fputcsv($handle, ['erp_asset_code', 'name', 'size'], escape: '');
        foreach ($rows as $row) {
            fputcsv($handle, $row, escape: '');
        }
        fclose($handle);

        return $this->file;
    }

    public function test_updates_name_and_size_and_leaves_everything_else(): void
    {
        $asset = Asset::create([
            'erp_asset_code' => 'FA1',
            'name' => '9 5/8" M7 MOTOR ASSEMBLY',
            'serial_number' => 'SN-1',
            'is_active' => true,
        ]);

        $this->artisan('atms:sync-asset-names', ['file' => $this->workbook([['FA1', '9 5/8" MOTOR ASSEMBLY M7 WENZEL', '9 5/8"']])])
            ->assertSuccessful();

        $asset->refresh();
        $this->assertSame('9 5/8" MOTOR ASSEMBLY M7 WENZEL', $asset->name);
        $this->assertSame('9.62500', $asset->size_inches->canonical());
        $this->assertSame('SN-1', $asset->serial_number);
        $this->assertSame(1, AuditLog::where('event', 'asset.workbook_sync')->count());
    }

    public function test_dry_run_writes_nothing(): void
    {
        $asset = Asset::create(['erp_asset_code' => 'FA1', 'name' => 'Old', 'is_active' => true]);

        $this->artisan('atms:sync-asset-names', ['file' => $this->workbook([['FA1', 'New', '8"']]), '--dry-run' => true])
            ->assertSuccessful();

        $this->assertSame('Old', $asset->fresh()->name);
        $this->assertNull($asset->fresh()->size_inches);
        $this->assertSame(0, AuditLog::count());
    }

    public function test_blank_cells_never_clear_existing_values(): void
    {
        $asset = Asset::create(['erp_asset_code' => 'FA1', 'name' => 'Keep', 'size_inches' => '8', 'is_active' => true]);

        $this->artisan('atms:sync-asset-names', ['file' => $this->workbook([['FA1', '', '']])])->assertSuccessful();

        $this->assertSame('Keep', $asset->fresh()->name);
        $this->assertSame('8.00000', $asset->fresh()->size_inches->canonical());
    }

    public function test_assets_outside_the_workbook_are_untouched(): void
    {
        $extra = Asset::create(['erp_asset_code' => 'EXTRA', 'name' => 'Extra', 'is_active' => true]);

        $this->artisan('atms:sync-asset-names', ['file' => $this->workbook([['NOPE', 'X', '']])])->assertSuccessful();

        $this->assertSame('Extra', $extra->fresh()->name);
    }

    public function test_one_bad_size_rejects_the_whole_file(): void
    {
        $asset = Asset::create(['erp_asset_code' => 'FA1', 'name' => 'Old', 'is_active' => true]);
        Asset::create(['erp_asset_code' => 'FA2', 'name' => 'Old2', 'is_active' => true]);

        $this->artisan('atms:sync-asset-names', ['file' => $this->workbook([['FA1', 'New', '8"'], ['FA2', 'New2', 'banana']])])
            ->assertFailed();

        $this->assertSame('Old', $asset->fresh()->name);
    }
}
