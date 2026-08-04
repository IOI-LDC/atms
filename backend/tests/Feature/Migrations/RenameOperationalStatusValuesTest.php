<?php

namespace Tests\Feature\Migrations;

use App\Models\MaintenanceCategory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RenameOperationalStatusValuesTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_renames_legacy_operational_status_values_idempotently(): void
    {
        $maintenanceCategory = MaintenanceCategory::factory()->create();
        $now = now();

        // Legacy values are inserted through the query builder because the
        // OperationalStatus enum cast rejects them before the migration runs.
        DB::table('assets')->insert([
            'erp_asset_code' => 'AST-ACTIVE',
            'name' => 'AST-ACTIVE',
            'maintenance_category_id' => $maintenanceCategory->id,
            'is_active' => true,
            'operational_status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('assets')->insert([
            'erp_asset_code' => 'AST-INACTIVE',
            'name' => 'AST-INACTIVE',
            'maintenance_category_id' => $maintenanceCategory->id,
            'is_active' => true,
            'operational_status' => 'inactive',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $migration = $this->migration();
        $migration->up();

        $this->assertDatabaseHas('assets', [
            'erp_asset_code' => 'AST-ACTIVE',
            'operational_status' => 'ready_for_field',
        ]);
        $this->assertDatabaseHas('assets', [
            'erp_asset_code' => 'AST-INACTIVE',
            'operational_status' => 'scraped',
        ]);

        $migration->up();

        $this->assertDatabaseHas('assets', [
            'erp_asset_code' => 'AST-ACTIVE',
            'operational_status' => 'ready_for_field',
        ]);
        $this->assertDatabaseHas('assets', [
            'erp_asset_code' => 'AST-INACTIVE',
            'operational_status' => 'scraped',
        ]);
    }

    private function migration(): Migration
    {
        $path = collect(glob(database_path('migrations/*_rename_operational_status_values.php')))
            ->sole();

        return require $path;
    }
}
