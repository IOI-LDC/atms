<?php

namespace Tests\Feature\ListOptions;

use App\Enums\RoleCode;
use App\Models\FaSubclassTypeCode;
use App\Models\MasterDataItem;
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
}
