<?php

namespace Tests\Feature\Assets;

use App\Enums\RoleCode;
use App\Models\Asset;
use App\Models\MaintenanceCategory;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Every asset carries a Maintenance Category.
 *
 * It is the only classification ATMS owns — `fa_subclass_code` belongs to the
 * ERP sync — so an asset without one cannot be routed a form or a PM rule and
 * has no remedy for it. The constraint is enforced in the database, with an
 * `Unclassified` default so the ERP sync can keep creating assets it knows no
 * category for. Unclassified is a state you can see and clear; null was not.
 */
class AssetCategoryRequiredTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function admin(): User
    {
        return User::factory()->create([
            'role_id' => Role::where('code', RoleCode::ADMINISTRATOR)->first()->id,
            'is_active' => true,
        ]);
    }

    private function unclassified(): MaintenanceCategory
    {
        return MaintenanceCategory::where('code', MaintenanceCategory::UNCLASSIFIED_CODE)->firstOrFail();
    }

    public function test_the_unclassified_category_exists_and_is_active(): void
    {
        $this->assertTrue($this->unclassified()->is_active);
    }

    /**
     * The ERP sync creates assets from a payload with no category in it, so
     * this is the path that would otherwise break under a bare NOT NULL.
     */
    public function test_an_asset_created_without_a_category_lands_in_unclassified(): void
    {
        $asset = Asset::create([
            'erp_asset_code' => 'AST-'.uniqid(),
            'name' => 'Imported from ERP',
            'is_active' => true,
        ]);

        $this->assertSame($this->unclassified()->id, $asset->fresh()->maintenance_category_id);
    }

    public function test_the_column_refuses_an_explicit_null(): void
    {
        $asset = Asset::create([
            'erp_asset_code' => 'AST-'.uniqid(),
            'name' => 'Mud Motor',
            'is_active' => true,
        ]);

        $this->expectException(QueryException::class);

        DB::table('assets')->where('id', $asset->id)->update(['maintenance_category_id' => null]);
    }

    public function test_the_api_refuses_to_clear_an_assets_category(): void
    {
        $asset = Asset::create([
            'erp_asset_code' => 'AST-'.uniqid(),
            'name' => 'Mud Motor',
            'is_active' => true,
        ]);

        $this->actingAs($this->admin())
            ->patchJson("/api/assets/{$asset->id}", ['maintenance_category_id' => null])
            ->assertStatus(422)
            ->assertJsonValidationErrors('maintenance_category_id');
    }

    public function test_the_api_still_reassigns_a_category(): void
    {
        $asset = Asset::create([
            'erp_asset_code' => 'AST-'.uniqid(),
            'name' => 'Mud Motor',
            'is_active' => true,
        ]);
        $category = MaintenanceCategory::factory()->create(['code' => 'MUD_MOTOR', 'name' => 'Mud Motor']);

        $this->actingAs($this->admin())
            ->patchJson("/api/assets/{$asset->id}", ['maintenance_category_id' => $category->id])
            ->assertOk();

        $this->assertSame($category->id, $asset->fresh()->maintenance_category_id);
    }

    /**
     * Deactivating the default bucket would hide exactly the assets an operator
     * needs to find and classify.
     */
    public function test_the_unclassified_category_cannot_be_deactivated(): void
    {
        $this->actingAs($this->admin())
            ->patchJson('/api/admin/maintenance-categories/'.MaintenanceCategory::UNCLASSIFIED_CODE, [
                'is_active' => false,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('is_active');

        $this->assertTrue($this->unclassified()->fresh()->is_active);
    }

    public function test_an_ordinary_category_can_still_be_deactivated(): void
    {
        $category = MaintenanceCategory::factory()->create(['code' => 'MUD_MOTOR', 'name' => 'Mud Motor']);

        $this->actingAs($this->admin())
            ->patchJson("/api/admin/maintenance-categories/{$category->code}", ['is_active' => false])
            ->assertOk();

        $this->assertFalse($category->fresh()->is_active);
    }
}
