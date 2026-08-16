<?php

namespace Tests\Feature\Assets;

use App\Enums\RoleCode;
use App\Models\Asset;
use App\Models\MaintenanceCategory;
use App\Models\Part;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Search covers exactly what the identity package displays, and nothing more.
 *
 * Assets: Asset Tag, name, serial number, size, Maintenance Category.
 * Parts:  name, supplier Part Number, size, Maintenance Category.
 * Neither searches an ERP code.
 */
class IdentitySearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        $motor = MaintenanceCategory::factory()->create(['code' => 'MOTOR', 'name' => 'Motor']);

        Asset::create([
            'erp_asset_code' => 'FA000777',
            'asset_tag' => 'L-MTR-634-0026',
            'name' => 'Mud Lube Assembly',
            'serial_number' => 'M7-675-0026',
            'size_inches' => '6 3/4',
            'maintenance_category_id' => $motor->id,
            'is_active' => true,
        ]);

        Part::create([
            'erp_part_code' => 'PC-888',
            'part_number' => 'A77-M6-22-SK',
            'name' => 'Serv Kit',
            'size_inches' => '6 3/4',
            'maintenance_category_id' => $motor->id,
            'available_quantity' => 3,
            'is_active' => true,
        ]);
    }

    private function admin(): User
    {
        return User::factory()->create([
            'role_id' => Role::where('code', RoleCode::ADMINISTRATOR)->first()->id,
            'is_active' => true,
        ]);
    }

    private function assetNames(string $term): array
    {
        return collect(
            $this->actingAs($this->admin())->getJson('/api/assets?search='.urlencode($term))->assertOk()->json('data')
        )->pluck('name')->all();
    }

    private function partNames(string $term): array
    {
        return collect(
            $this->actingAs($this->admin())->getJson('/api/parts?search='.urlencode($term))->assertOk()->json('data')
        )->pluck('name')->all();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function assetMatchProvider(): array
    {
        return [
            'name' => ['Lube'],
            'asset tag' => ['L-MTR-634'],
            'serial number' => ['675-0026'],
            'maintenance category' => ['Motor'],
            'size as fraction' => ['6 3/4'],
            'size as decimal' => ['6.75'],
            'size with inch mark' => ['6 3/4"'],
        ];
    }

    #[DataProvider('assetMatchProvider')]
    public function test_asset_search_matches(string $term): void
    {
        $this->assertContains('Mud Lube Assembly', $this->assetNames($term));
    }

    public function test_asset_search_ignores_the_erp_code(): void
    {
        $this->assertNotContains('Mud Lube Assembly', $this->assetNames('FA000777'));
    }

    /**
     * A partial size is not a size. Matching loosely would surface 6 3/4" for a
     * search of "6 3", which is not what an exact numeric match means.
     */
    public function test_a_partial_size_does_not_match_on_size(): void
    {
        $this->assertNotContains('Mud Lube Assembly', $this->assetNames('6 3'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function partMatchProvider(): array
    {
        return [
            'name' => ['Serv'],
            'supplier part number' => ['A77-M6'],
            'maintenance category' => ['Motor'],
            'size as fraction' => ['6 3/4'],
            'size as decimal' => ['6.75'],
        ];
    }

    #[DataProvider('partMatchProvider')]
    public function test_part_search_matches(string $term): void
    {
        $this->assertContains('Serv Kit', $this->partNames($term));
    }

    /**
     * RQ4 reversed this: the ERP part code is the "No." LDC types when looking
     * a part up, so search that ignored it missed the most obvious query.
     */
    public function test_part_search_matches_the_erp_code(): void
    {
        $this->assertContains('Serv Kit', $this->partNames('PC-888'));
    }

    public function test_search_is_case_insensitive_across_the_new_fields(): void
    {
        $this->assertContains('Mud Lube Assembly', $this->assetNames('motor'));
        $this->assertContains('Serv Kit', $this->partNames('a77-m6'));
    }
}
