<?php

namespace Tests\Feature\ListOptions;

use App\Enums\RoleCode;
use App\Models\Asset;
use App\Models\FaSubclassTypeCode;
use App\Models\MaintenanceCategory;
use App\Models\MasterDataItem;
use App\Models\Part;
use App\Models\Role;
use App\Models\UsageReadingType;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListOptionControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function createUser(RoleCode $role): User
    {
        return User::factory()->create([
            'role_id' => Role::where('code', $role)->first()->id,
            'is_active' => true,
        ]);
    }

    // ── maintenance_priorities ──────────────────────────────────────────────

    public function test_maintenance_priorities_returns_active_only_sorted(): void
    {
        MasterDataItem::create([
            'group_key' => 'maintenance_priorities', 'value' => 'low', 'label' => 'Low',
            'sort_order' => 0, 'is_active' => true,
        ]);
        MasterDataItem::create([
            'group_key' => 'maintenance_priorities', 'value' => 'high', 'label' => 'High',
            'sort_order' => 2, 'is_active' => true,
        ]);
        // inactive — must be excluded
        MasterDataItem::create([
            'group_key' => 'maintenance_priorities', 'value' => 'legacy', 'label' => 'Legacy',
            'sort_order' => 9, 'is_active' => false,
        ]);
        // different group — must be excluded
        MasterDataItem::create([
            'group_key' => 'other_group', 'value' => 'x', 'label' => 'X',
            'sort_order' => 0, 'is_active' => true,
        ]);

        $requester = $this->createUser(RoleCode::REQUESTER);

        $response = $this->actingAs($requester)->getJson('/api/list-options/maintenance_priorities');

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.value', 'low')
            ->assertJsonPath('data.1.value', 'high')
            ->assertJsonPath('data.0.sort_order', 0);
    }

    // ── usage_reading_types ─────────────────────────────────────────────────

    public function test_usage_reading_types_returns_active_only(): void
    {
        UsageReadingType::create(['name' => 'Hours', 'unit' => 'h', 'is_active' => true]);
        UsageReadingType::create(['name' => 'Legacy', 'unit' => 'x', 'is_active' => false]);

        $tech = $this->createUser(RoleCode::TECHNICIAN);

        $response = $this->actingAs($tech)->getJson('/api/list-options/usage_reading_types');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Hours')
            ->assertJsonPath('data.0.unit', 'h');
    }

    // ── fa_subclass_type_codes ──────────────────────────────────────────────

    public function test_fa_subclass_type_codes_returns_all_fields(): void
    {
        FaSubclassTypeCode::create([
            'fa_subclass_code' => 'MWD', 'type_code' => 'DRL',
            'description' => 'MWD tools', 'has_no_physical_size' => false,
        ]);

        $requester = $this->createUser(RoleCode::REQUESTER);

        $response = $this->actingAs($requester)->getJson('/api/list-options/fa_subclass_type_codes');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.fa_subclass_code', 'MWD')
            ->assertJsonPath('data.0.type_code', 'DRL')
            ->assertJsonPath('data.0.has_no_physical_size', false);
    }

    // ── access control ──────────────────────────────────────────────────────

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/list-options/maintenance_priorities')->assertStatus(401);
    }

    public function test_unknown_group_returns_404(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);

        $this->actingAs($admin)->getJson('/api/list-options/nonexistent')->assertNotFound();
    }

    // ── Admin CRUD regression ───────────────────────────────────────────────

    public function test_admin_can_still_create_priority_via_master_data_crud(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);

        $response = $this->actingAs($admin)->postJson('/api/admin/master-data/maintenance_priorities', [
            'value' => 'urgent',
            'label' => 'Urgent',
            'sort_order' => 5,
            'is_active' => true,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.value', 'urgent');

        $this->assertDatabaseHas('master_data_items', [
            'group_key' => 'maintenance_priorities',
            'value' => 'urgent',
        ]);
    }

    public function test_non_admin_cannot_access_master_data_crud(): void
    {
        $requester = $this->createUser(RoleCode::REQUESTER);

        $this->actingAs($requester)
            ->postJson('/api/admin/master-data/maintenance_priorities', [
                'value' => 'x', 'label' => 'X',
            ])
            ->assertForbidden();
    }

    // ── maintenance_categories ──────────────────────────────────────────────

    public function test_maintenance_categories_returns_active_only_sorted_by_name(): void
    {
        MaintenanceCategory::factory()->create(['code' => 'MOTOR', 'name' => 'Motor']);
        MaintenanceCategory::factory()->create(['code' => 'JAR', 'name' => 'Jar']);
        MaintenanceCategory::factory()->inactive()->create(['code' => 'OLD', 'name' => 'Aardvark']);

        $response = $this->actingAs($this->createUser(RoleCode::TECHNICIAN))
            ->getJson('/api/list-options/maintenance_categories')
            ->assertOk();

        $this->assertSame(['Jar', 'Motor', 'Unclassified'], collect($response->json('data'))->pluck('name')->all());
    }

    /**
     * The category vocabulary is managed through its own admin CRUD
     * (`/api/admin/maintenance-categories`, covered in MaintenanceCategoryTest),
     * NOT through the generic master-data endpoint.
     *
     * `/admin/master-data/{groupKey}` used to accept any group key, so it
     * answered for this name too and merely wrote to the wrong table. Since 4a a
     * managed-group allowlist rejects it outright. Both halves are asserted: the
     * 404, and — regardless of the reason — that no category was created.
     */
    public function test_master_data_endpoint_does_not_write_to_maintenance_categories(): void
    {
        $this->actingAs($this->createUser(RoleCode::ADMINISTRATOR))
            ->postJson('/api/admin/master-data/maintenance_categories', [
                'value' => 'SMUGGLED', 'label' => 'Smuggled',
            ])
            ->assertNotFound();

        $this->assertFalse(MaintenanceCategory::where('code', 'SMUGGLED')->exists());
        $this->assertDatabaseMissing('master_data_items', ['group_key' => 'maintenance_categories']);

        $this->actingAs($this->createUser(RoleCode::ADMINISTRATOR))
            ->postJson('/api/list-options/maintenance_categories', ['code' => 'X', 'name' => 'X'])
            ->assertStatus(405);
    }

    // ── asset_sizes ─────────────────────────────────────────────────────────

    public function test_asset_sizes_returns_distinct_sizes_in_numeric_order(): void
    {
        Asset::create(['erp_asset_code' => 'A1', 'name' => 'A', 'size_inches' => '9 5/8']);
        Asset::create(['erp_asset_code' => 'A2', 'name' => 'B', 'size_inches' => '6 3/4']);
        // Duplicate spelled differently — must collapse to one option.
        Asset::create(['erp_asset_code' => 'A3', 'name' => 'C', 'size_inches' => '6.75']);
        Asset::create(['erp_asset_code' => 'A4', 'name' => 'D', 'size_inches' => null]);
        Part::create(['erp_part_code' => 'P1', 'name' => 'P', 'size_inches' => '12 1/8']);

        $response = $this->actingAs($this->createUser(RoleCode::TECHNICIAN))
            ->getJson('/api/list-options/asset_sizes')
            ->assertOk();

        $this->assertSame(
            ['6 3/4"', '9 5/8"', '12 1/8"'],
            collect($response->json('data'))->pluck('label')->all(),
        );
        $this->assertSame(
            ['6.75000', '9.62500', '12.12500'],
            collect($response->json('data'))->pluck('value')->all(),
        );
    }

    public function test_asset_sizes_is_empty_when_nothing_is_sized(): void
    {
        $this->actingAs($this->createUser(RoleCode::TECHNICIAN))
            ->getJson('/api/list-options/asset_sizes')
            ->assertOk()
            ->assertJsonPath('data', []);
    }
}
