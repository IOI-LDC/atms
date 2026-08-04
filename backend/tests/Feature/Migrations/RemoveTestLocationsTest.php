<?php

namespace Tests\Feature\Migrations;

use App\Models\Location;
use App\Models\MaintenanceCategory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RemoveTestLocationsTest extends TestCase
{
    use RefreshDatabase;

    private const MARKER = 'migration:2026-08-04 remove-test-locations';

    public function test_up_keeps_only_tajoura_base_and_cascades_history(): void
    {
        $maintenanceCategory = MaintenanceCategory::factory()->create();
        $tjb = Location::where('code', 'TJB')->sole();
        $locationAA = Location::create([
            'name' => 'Test A',
            'type' => 'yard',
            'code' => 'AA',
            'is_active' => true,
        ]);
        $locationBB = Location::create([
            'name' => 'Test B',
            'type' => 'yard',
            'code' => 'BB',
            'is_active' => true,
        ]);
        $now = now();

        DB::table('assets')->insert([
            'erp_asset_code' => 'AST-REM-1',
            'name' => 'AST-REM-1',
            'maintenance_category_id' => $maintenanceCategory->id,
            'is_active' => true,
            'operational_status' => 'ready_for_field',
            'current_location_id' => $tjb->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $assetId = DB::table('assets')->where('erp_asset_code', 'AST-REM-1')->value('id');

        DB::table('asset_location_histories')->insert([
            'asset_id' => $assetId,
            'from_location_id' => $tjb->id,
            'to_location_id' => $locationAA->id,
            'effective_at' => $now,
            'reason' => 'test',
            'notes' => self::MARKER.' to-aa',
            'changed_by_user_id' => null,
            'created_at' => $now,
        ]);
        DB::table('asset_location_histories')->insert([
            'asset_id' => $assetId,
            'from_location_id' => $locationBB->id,
            'to_location_id' => $tjb->id,
            'effective_at' => $now,
            'reason' => 'test',
            'notes' => self::MARKER.' to-tjb',
            'changed_by_user_id' => null,
            'created_at' => $now,
        ]);
        $historyToTjbId = DB::table('asset_location_histories')
            ->where('notes', self::MARKER.' to-tjb')
            ->sole()
            ->id;

        $this->migration()->up();

        $this->assertDatabaseCount('locations', 1);
        $this->assertDatabaseHas('locations', ['code' => 'TJB']);
        $this->assertDatabaseMissing('asset_location_histories', ['notes' => self::MARKER.' to-aa']);

        $this->assertDatabaseHas('asset_location_histories', [
            'id' => $historyToTjbId,
            'to_location_id' => $tjb->id,
        ]);
        $this->assertNull(DB::table('asset_location_histories')->where('id', $historyToTjbId)->value('from_location_id'));
        $this->assertDatabaseHas('assets', [
            'id' => $assetId,
            'current_location_id' => $tjb->id,
        ]);
    }

    public function test_down_restores_removed_locations(): void
    {
        $this->migration()->up();

        $this->migration()->down();

        $this->assertDatabaseCount('locations', 12);
        foreach (['WS', 'MY', 'WSY', 'WX', 'WY', 'RA', 'RB', 'RC', 'MB', 'MBA', 'MBB'] as $code) {
            $this->assertDatabaseHas('locations', ['code' => $code]);
        }
        $this->assertDatabaseHas('locations', ['code' => 'TJB']);
    }

    private function migration(): Migration
    {
        $path = collect(glob(database_path('migrations/*_remove_test_locations_keep_tajoura_base.php')))
            ->sole();

        return require $path;
    }
}
