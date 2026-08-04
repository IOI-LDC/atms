<?php

namespace Tests\Feature\Migrations;

use App\Models\Location;
use App\Models\MaintenanceCategory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TajouraBaseRelocationTest extends TestCase
{
    use RefreshDatabase;

    private const MARKER = 'migration:2026-08-04 tajoura-base';

    public function test_migration_creates_tjb_and_relocates_all_assets(): void
    {
        [$assetAId, $assetBId, $locationAId, $locationBId] = $this->seedAssets();

        $this->migration()->up();

        $this->assertDatabaseHas('locations', [
            'code' => 'TJB',
            'name' => 'Tajoura Base',
            'type' => 'yard',
            'is_active' => true,
        ]);

        $tjbId = Location::where('code', 'TJB')->sole()->id;

        $this->assertDatabaseHas('assets', [
            'erp_asset_code' => 'AST-TJB-1',
            'current_location_id' => $tjbId,
        ]);
        $this->assertDatabaseHas('assets', [
            'erp_asset_code' => 'AST-TJB-2',
            'current_location_id' => $tjbId,
        ]);

        $this->assertDatabaseHas('asset_location_histories', [
            'asset_id' => $assetAId,
            'from_location_id' => $locationAId,
            'to_location_id' => $tjbId,
            'reason' => 'bulk relocation',
            'notes' => self::MARKER,
        ]);
        $this->assertDatabaseHas('asset_location_histories', [
            'asset_id' => $assetBId,
            'from_location_id' => $locationBId,
            'to_location_id' => $tjbId,
            'reason' => 'bulk relocation',
            'notes' => self::MARKER,
        ]);

        // effective_at feeds the R-18 asset-movement report date window — a null
        // here would silently drop rows from the report.
        $this->assertSame(2, DB::table('asset_location_histories')
            ->where('notes', self::MARKER)
            ->whereNotNull('effective_at')
            ->count());
    }

    public function test_migration_handles_asset_without_prior_location(): void
    {
        [$assetAId, $assetBId, , ] = $this->seedAssets();

        $maintenanceCategory = MaintenanceCategory::factory()->create();
        $now = now();

        DB::table('assets')->insert([
            'erp_asset_code' => 'AST-TJB-NOLOC',
            'name' => 'AST-TJB-NOLOC',
            'maintenance_category_id' => $maintenanceCategory->id,
            'is_active' => true,
            'operational_status' => 'ready_for_field',
            'current_location_id' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $assetCId = DB::table('assets')->where('erp_asset_code', 'AST-TJB-NOLOC')->value('id');

        $migration = $this->migration();
        $migration->up();

        $tjbId = Location::where('code', 'TJB')->sole()->id;

        $this->assertDatabaseHas('assets', [
            'id' => $assetCId,
            'current_location_id' => $tjbId,
        ]);
        $this->assertDatabaseHas('asset_location_histories', [
            'asset_id' => $assetCId,
            'from_location_id' => null,
            'to_location_id' => $tjbId,
            'reason' => 'bulk relocation',
            'notes' => self::MARKER,
        ]);

        $migration->down();

        $this->assertDatabaseMissing('locations', ['code' => 'TJB']);
        $this->assertDatabaseHas('assets', [
            'id' => $assetCId,
            'current_location_id' => null,
        ]);
    }

    public function test_migration_is_idempotent(): void
    {
        [$assetAId, $assetBId, , ] = $this->seedAssets();

        $migration = $this->migration();
        $migration->up();
        $migration->up();

        $tjbId = Location::where('code', 'TJB')->sole()->id;

        $this->assertSame(2, DB::table('asset_location_histories')->where('notes', self::MARKER)->count());
        $this->assertDatabaseHas('assets', [
            'id' => $assetAId,
            'current_location_id' => $tjbId,
        ]);
        $this->assertDatabaseHas('assets', [
            'id' => $assetBId,
            'current_location_id' => $tjbId,
        ]);
    }

    public function test_down_restores_original_locations(): void
    {
        [$assetAId, $assetBId, $locationAId, $locationBId] = $this->seedAssets();

        $migration = $this->migration();
        $migration->up();
        $migration->down();

        $this->assertDatabaseMissing('locations', ['code' => 'TJB']);
        $this->assertDatabaseHas('assets', [
            'id' => $assetAId,
            'current_location_id' => $locationAId,
        ]);
        $this->assertDatabaseHas('assets', [
            'id' => $assetBId,
            'current_location_id' => $locationBId,
        ]);
        $this->assertSame(0, DB::table('asset_location_histories')->where('notes', self::MARKER)->count());
    }

    /**
     * @return array{0: int, 1: int, 2: int, 3: int} asset ids, then location ids
     */
    private function seedAssets(): array
    {
        $maintenanceCategory = MaintenanceCategory::factory()->create();
        $locationA = Location::create([
            'name' => 'Location A',
            'type' => 'yard',
            'code' => 'TST-A',
            'is_active' => true,
        ]);
        $locationB = Location::create([
            'name' => 'Location B',
            'type' => 'yard',
            'code' => 'TST-B',
            'is_active' => true,
        ]);
        $now = now();

        DB::table('assets')->insert([
            'erp_asset_code' => 'AST-TJB-1',
            'name' => 'AST-TJB-1',
            'maintenance_category_id' => $maintenanceCategory->id,
            'is_active' => true,
            'operational_status' => 'ready_for_field',
            'current_location_id' => $locationA->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('assets')->insert([
            'erp_asset_code' => 'AST-TJB-2',
            'name' => 'AST-TJB-2',
            'maintenance_category_id' => $maintenanceCategory->id,
            'is_active' => true,
            'operational_status' => 'ready_for_field',
            'current_location_id' => $locationB->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [
            DB::table('assets')->where('erp_asset_code', 'AST-TJB-1')->value('id'),
            DB::table('assets')->where('erp_asset_code', 'AST-TJB-2')->value('id'),
            $locationA->id,
            $locationB->id,
        ];
    }

    private function migration(): Migration
    {
        $path = collect(glob(database_path('migrations/*_create_tajoura_base_and_relocate_assets.php')))
            ->sole();

        return require $path;
    }
}
