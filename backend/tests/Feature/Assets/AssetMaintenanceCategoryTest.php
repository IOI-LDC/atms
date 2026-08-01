<?php

namespace Tests\Feature\Assets;

use App\Enums\RoleCode;
use App\Models\Asset;
use App\Models\MaintenanceCategory;
use App\Models\Role;
use App\Models\User;
use App\Support\Size;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Asset Size and Maintenance Category are owned by the controlled workbook
 * import — no administration UI, no API write path. `fa_subclass_code` stays
 * ERP-owned and separate; it is Asset Class, not Maintenance Category.
 */
class AssetMaintenanceCategoryTest extends TestCase
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

    private function asset(array $attributes = []): Asset
    {
        return Asset::create(array_merge([
            'erp_asset_code' => 'AST-'.uniqid(),
            'name' => 'Mud Motor',
            'is_active' => true,
        ], $attributes));
    }

    public function test_asset_update_does_not_expose_a_legacy_category(): void
    {
        $asset = $this->asset();

        $response = $this->actingAs($this->admin())
            ->patchJson("/api/assets/{$asset->id}", [
                'name' => 'Renamed Motor',
                'category' => 'Attacker Supplied',
            ])
            ->assertOk();

        $asset->refresh();

        $this->assertSame('Renamed Motor', $asset->name);
        $this->assertArrayNotHasKey('category', $response->json('data'));
    }

    /**
     * Assigning an existing category or size to one asset is allowed — that is
     * what the Edit Asset dropdowns do. What stays closed is editing the
     * category *vocabulary*, which has no CRUD endpoint at all.
     */
    public function test_asset_update_can_assign_maintenance_category_and_size(): void
    {
        $category = MaintenanceCategory::factory()->create();
        $asset = $this->asset();

        $this->actingAs($this->admin())
            ->patchJson("/api/assets/{$asset->id}", [
                'maintenance_category_id' => $category->id,
                'size_inches' => '6 3/4',
            ])
            ->assertOk();

        $asset->refresh();

        $this->assertSame($category->id, $asset->maintenance_category_id);
        $this->assertSame('6.75000', $asset->size_inches->canonical());
    }

    /**
     * Size and category are not symmetrical. A size ATMS does not know is
     * genuinely unknown, so it can be cleared; a category is the one handle
     * ATMS has on the asset, so it can only be reassigned — including to
     * Unclassified, which is what "not classified" now means.
     */
    public function test_asset_update_can_clear_size_but_not_maintenance_category(): void
    {
        $category = MaintenanceCategory::factory()->create();
        $asset = $this->asset(['maintenance_category_id' => $category->id, 'size_inches' => '8']);

        $this->actingAs($this->admin())
            ->patchJson("/api/assets/{$asset->id}", ['size_inches' => null])
            ->assertOk();

        $this->actingAs($this->admin())
            ->patchJson("/api/assets/{$asset->id}", ['maintenance_category_id' => null])
            ->assertStatus(422)
            ->assertJsonValidationErrors('maintenance_category_id');

        $asset->refresh();

        $this->assertNull($asset->size_inches);
        $this->assertSame($category->id, $asset->maintenance_category_id);
    }

    public function test_asset_update_rejects_an_unknown_maintenance_category(): void
    {
        $asset = $this->asset();

        $this->actingAs($this->admin())
            ->patchJson("/api/assets/{$asset->id}", ['maintenance_category_id' => 999999])
            ->assertStatus(422);
    }

    public function test_asset_update_rejects_an_unparseable_size(): void
    {
        $asset = $this->asset();

        $this->actingAs($this->admin())
            ->patchJson("/api/assets/{$asset->id}", ['size_inches' => 'LARGE'])
            ->assertStatus(422);
    }

    public function test_fa_subclass_code_remains_writable_and_separate(): void
    {
        $category = MaintenanceCategory::factory()->create(['code' => 'MUD_MOTOR', 'name' => 'Mud Motor']);
        $asset = $this->asset(['maintenance_category_id' => $category->id]);

        $this->actingAs($this->admin())
            ->patchJson("/api/assets/{$asset->id}", ['fa_subclass_code' => 'MUD MOTOR'])
            ->assertOk();

        $asset->refresh();

        $this->assertSame('MUD MOTOR', $asset->fa_subclass_code);
        $this->assertSame($category->id, $asset->maintenance_category_id);
    }

    public function test_asset_resource_exposes_size_and_maintenance_category(): void
    {
        $category = MaintenanceCategory::factory()->create(['code' => 'MUD_MOTOR', 'name' => 'Mud Motor']);
        $asset = $this->asset([
            'serial_number' => 'M40-778812',
            'size_inches' => '9 5/8',
            'maintenance_category_id' => $category->id,
        ]);

        $this->actingAs($this->admin())
            ->getJson("/api/assets/{$asset->id}")
            ->assertOk()
            ->assertJsonPath('data.serial_number', 'M40-778812')
            ->assertJsonPath('data.size', '9 5/8"')
            ->assertJsonPath('data.size_inches', '9.62500')
            ->assertJsonPath('data.maintenance_category.code', 'MUD_MOTOR')
            ->assertJsonPath('data.maintenance_category.name', 'Mud Motor');
    }

    public function test_missing_size_is_null_and_category_falls_back_to_unclassified(): void
    {
        $asset = $this->asset();

        $this->actingAs($this->admin())
            ->getJson("/api/assets/{$asset->id}")
            ->assertOk()
            ->assertJsonPath('data.size', null)
            ->assertJsonPath('data.size_inches', null)
            ->assertJsonPath('data.maintenance_category.code', MaintenanceCategory::UNCLASSIFIED_CODE);
    }

    public function test_asset_index_embeds_the_maintenance_category(): void
    {
        $category = MaintenanceCategory::factory()->create(['code' => 'JAR', 'name' => 'Jar']);
        $this->asset(['maintenance_category_id' => $category->id, 'size_inches' => '8']);

        $this->actingAs($this->admin())
            ->getJson('/api/assets')
            ->assertOk()
            ->assertJsonPath('data.0.maintenance_category.name', 'Jar')
            ->assertJsonPath('data.0.size', '8"');
    }

    public function test_size_stores_exactly_at_a_thirty_second(): void
    {
        $asset = $this->asset(['size_inches' => '12 1/32']);

        $stored = $asset->refresh()->size_inches;

        $this->assertInstanceOf(Size::class, $stored);
        $this->assertSame('12.03125', $stored->canonical());
        $this->assertSame('12 1/32"', $stored->format());
    }
}
