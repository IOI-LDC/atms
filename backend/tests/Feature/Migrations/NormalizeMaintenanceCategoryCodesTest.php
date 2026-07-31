<?php

namespace Tests\Feature\Migrations;

use App\Models\Asset;
use App\Models\MaintenanceCategory;
use App\Models\Part;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class NormalizeMaintenanceCategoryCodesTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_merges_duplicates_and_renames_legacy_categories_idempotently(): void
    {
        $canonicalAps = MaintenanceCategory::factory()->create([
            'code' => 'MWD_APS',
            'name' => 'MWD / APS',
        ]);
        $legacyAps = MaintenanceCategory::factory()->create([
            'code' => 'MWD__APS',
            'name' => 'MWD / APS',
        ]);
        $legacyVertex = MaintenanceCategory::factory()->create([
            'code' => 'MWD__VERTEX',
            'name' => 'MWD / VERTEX',
        ]);
        $legacySubFlow = MaintenanceCategory::factory()->create([
            'code' => 'SUB_FLOW__MWD',
            'name' => 'SUB FLOW / MWD',
        ]);

        $apsAsset = $this->asset('AST-APS', $legacyAps);
        $vertexAsset = $this->asset('AST-VERTEX', $legacyVertex);
        $subFlowAsset = $this->asset('AST-SUB-FLOW', $legacySubFlow);
        $canonicalPart = $this->part('PRT-APS-CANONICAL', $canonicalAps);
        $legacyPart = $this->part('PRT-APS-LEGACY', $legacyAps);

        $migration = $this->migration();
        $migration->up();

        $this->assertDatabaseMissing('maintenance_categories', ['code' => 'MWD__APS']);
        $this->assertDatabaseMissing('maintenance_categories', ['code' => 'MWD__VERTEX']);
        $this->assertDatabaseMissing('maintenance_categories', ['code' => 'SUB_FLOW__MWD']);
        $this->assertDatabaseHas('maintenance_categories', [
            'code' => 'MWD_APS',
            'name' => 'MWD / APS',
        ]);
        $this->assertDatabaseHas('maintenance_categories', [
            'code' => 'MWD_VERTEX',
            'name' => 'MWD / VERTEX',
        ]);
        $this->assertDatabaseHas('maintenance_categories', [
            'code' => 'MWD_SUB_FLOW',
            'name' => 'MWD / SUB FLOW',
        ]);

        $this->assertSame($canonicalAps->id, $apsAsset->refresh()->maintenance_category_id);
        $this->assertSame($canonicalAps->id, $canonicalPart->refresh()->maintenance_category_id);
        $this->assertSame($canonicalAps->id, $legacyPart->refresh()->maintenance_category_id);
        $this->assertSame($legacyVertex->id, $vertexAsset->refresh()->maintenance_category_id);
        $this->assertSame($legacySubFlow->id, $subFlowAsset->refresh()->maintenance_category_id);
        $this->assertDatabaseCount('maintenance_categories', 3);

        $migration->up();

        $this->assertDatabaseCount('maintenance_categories', 3);
        $this->assertSame($canonicalAps->id, $apsAsset->refresh()->maintenance_category_id);
        $this->assertSame($legacyVertex->id, $vertexAsset->refresh()->maintenance_category_id);
        $this->assertSame($legacySubFlow->id, $subFlowAsset->refresh()->maintenance_category_id);
    }

    private function asset(string $code, MaintenanceCategory $category): Asset
    {
        return Asset::create([
            'erp_asset_code' => $code,
            'name' => $code,
            'maintenance_category_id' => $category->id,
            'is_active' => true,
        ]);
    }

    private function part(string $code, MaintenanceCategory $category): Part
    {
        return Part::create([
            'erp_part_id' => (string) Str::uuid(),
            'erp_part_code' => $code,
            'name' => $code,
            'unit_of_measure' => 'PCS',
            'available_quantity' => 0,
            'erp_status' => 'active',
            'is_active' => true,
            'maintenance_category_id' => $category->id,
        ]);
    }

    private function migration(): Migration
    {
        $path = collect(glob(database_path('migrations/*_normalize_maintenance_category_codes.php')))
            ->sole();

        return require $path;
    }
}
