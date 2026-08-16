<?php

namespace Tests\Feature\Admin;

use App\Enums\RoleCode;
use App\Models\MasterDataItem;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Admin CRUD for the `master_data_items` vocabularies.
 *
 * The route takes the group key as a free string, which is what makes the three
 * guards here load-bearing rather than decorative:
 *
 *  - an unmanaged group 404s, so a typo cannot quietly create a vocabulary
 *    nothing reads;
 *  - `value` is immutable, because other tables store it — editing it orphans
 *    every row pointing at the old string;
 *  - the default row cannot be deactivated, because that is what automatic
 *    resets resolve to.
 *
 * None of the three is visible from the response shape, so without these tests
 * a refactor could drop any of them and the suite would stay green.
 */
class MasterDataItemTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function user(RoleCode $roleCode): User
    {
        return User::factory()->create([
            'role_id' => Role::where('code', $roleCode)->first()->id,
            'is_active' => true,
        ]);
    }

    private function admin(): User
    {
        return $this->user(RoleCode::ADMINISTRATOR);
    }

    // ── Managed-group allowlist ─────────────────────────────────────────────────

    /**
     * Both live groups must be served. `maintenance_priorities` is the older of
     * the two and the one the MR priority picker reads — an allowlist that
     * named only `asset_conditions` would break that screen silently.
     *
     * @return array<string, array{string}>
     */
    public static function managedGroupProvider(): array
    {
        return [
            'maintenance priorities' => [MasterDataItem::MAINTENANCE_PRIORITIES],
            'asset conditions' => [MasterDataItem::ASSET_CONDITIONS],
        ];
    }

    /**
     * Seeded here rather than relied on: `seed_maintenance_priorities` skips the
     * testing connection by design ("tests own their own fixtures"), whereas the
     * 4a `asset_conditions` seed runs everywhere because condition behaviour
     * depends on the vocabulary existing. Creating the row makes this test
     * indifferent to which convention a group follows.
     */
    #[DataProvider('managedGroupProvider')]
    public function test_managed_groups_are_served(string $groupKey): void
    {
        MasterDataItem::create([
            'group_key' => $groupKey,
            'value' => 'fixture_value',
            'label' => 'Fixture',
            'sort_order' => 99,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->admin())
            ->getJson("/api/admin/master-data/{$groupKey}")
            ->assertOk();

        $groups = collect($response->json('data'))->pluck('group_key')->unique()->all();
        $this->assertSame([$groupKey], $groups);
        $this->assertContains('fixture_value', collect($response->json('data'))->pluck('value')->all());
    }

    public function test_unknown_group_is_not_readable(): void
    {
        $this->actingAs($this->admin())
            ->getJson('/api/admin/master-data/not_a_real_group')
            ->assertNotFound();
    }

    public function test_unknown_group_cannot_be_created_into(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/admin/master-data/not_a_real_group', [
                'value' => 'smuggled',
                'label' => 'Smuggled',
            ])
            ->assertNotFound();

        $this->assertDatabaseMissing('master_data_items', ['group_key' => 'not_a_real_group']);
    }

    public function test_an_item_in_an_unmanaged_group_cannot_be_edited(): void
    {
        // A row predating the allowlist, or one left by a retired feature. The
        // guard reads the *item's* group, not the URL, so it is unreachable.
        $orphan = MasterDataItem::create([
            'group_key' => 'legacy_group',
            'value' => 'stale',
            'label' => 'Stale',
            'sort_order' => 0,
            'is_active' => true,
        ]);

        $this->actingAs($this->admin())
            ->patchJson("/api/admin/master-data/items/{$orphan->id}", ['label' => 'Renamed'])
            ->assertNotFound();

        $this->assertSame('Stale', $orphan->refresh()->label);
    }

    // ── `value` is immutable ────────────────────────────────────────────────────

    /**
     * `assets.condition_status` stores the value, not the id. Letting an Admin
     * edit it would leave every asset pointing at a string the vocabulary no
     * longer contains — with no foreign key to catch it.
     */
    public function test_value_cannot_be_changed_and_is_ignored_when_sent(): void
    {
        $item = MasterDataItem::where('group_key', MasterDataItem::ASSET_CONDITIONS)
            ->where('value', 'need_assembly')
            ->sole();

        $this->actingAs($this->admin())
            ->patchJson("/api/admin/master-data/items/{$item->id}", [
                'value' => 'renamed_value',
                'label' => 'Needs Assembly',
            ])
            ->assertOk();

        $item->refresh();
        $this->assertSame('need_assembly', $item->value);
        $this->assertSame('Needs Assembly', $item->label);
    }

    public function test_label_sort_order_and_active_flag_are_editable(): void
    {
        $item = MasterDataItem::where('group_key', MasterDataItem::ASSET_CONDITIONS)
            ->where('value', 'missing_parts')
            ->sole();

        $this->actingAs($this->admin())
            ->patchJson("/api/admin/master-data/items/{$item->id}", [
                'label' => 'Parts Missing',
                'sort_order' => 9,
                'is_active' => false,
            ])
            ->assertOk();

        $item->refresh();
        $this->assertSame('Parts Missing', $item->label);
        $this->assertSame(9, $item->sort_order);
        $this->assertFalse($item->is_active);
    }

    // ── The default row is protected ────────────────────────────────────────────

    public function test_the_default_row_cannot_be_deactivated(): void
    {
        $default = MasterDataItem::defaultFor(MasterDataItem::ASSET_CONDITIONS);

        $this->actingAs($this->admin())
            ->patchJson("/api/admin/master-data/items/{$default->id}", ['is_active' => false])
            ->assertStatus(422)
            ->assertJsonPath('errors.is_active.0', '"Normal" is the default for this list and cannot be deactivated. Rename it if the wording is wrong.');

        $this->assertTrue($default->refresh()->is_active);
    }

    /**
     * The guard is about deactivation alone — renaming the default is the one
     * repair the message offers, so it had better work.
     */
    public function test_the_default_row_can_still_be_renamed(): void
    {
        $default = MasterDataItem::defaultFor(MasterDataItem::ASSET_CONDITIONS);

        $this->actingAs($this->admin())
            ->patchJson("/api/admin/master-data/items/{$default->id}", ['label' => 'Serviceable'])
            ->assertOk();

        $this->assertSame('Serviceable', $default->refresh()->label);
        $this->assertTrue($default->refresh()->is_default);
    }

    public function test_a_non_default_row_can_be_deactivated(): void
    {
        $item = MasterDataItem::where('group_key', MasterDataItem::ASSET_CONDITIONS)
            ->where('value', 'need_inspection')
            ->sole();

        $this->actingAs($this->admin())
            ->patchJson("/api/admin/master-data/items/{$item->id}", ['is_active' => false])
            ->assertOk();

        $this->assertFalse($item->refresh()->is_active);
    }

    // ── Authorization ───────────────────────────────────────────────────────────

    /**
     * @return array<string, array{RoleCode}>
     */
    public static function nonAdminProvider(): array
    {
        return [
            'maintenance manager' => [RoleCode::MAINTENANCE_MANAGER],
            'technician' => [RoleCode::TECHNICIAN],
            'requester' => [RoleCode::REQUESTER],
        ];
    }

    #[DataProvider('nonAdminProvider')]
    public function test_only_administrators_may_manage_master_data(RoleCode $roleCode): void
    {
        $groupKey = MasterDataItem::ASSET_CONDITIONS;

        $this->actingAs($this->user($roleCode))
            ->getJson("/api/admin/master-data/{$groupKey}")
            ->assertForbidden();

        $this->actingAs($this->user($roleCode))
            ->postJson("/api/admin/master-data/{$groupKey}", ['value' => 'sneaky', 'label' => 'Sneaky'])
            ->assertForbidden();
    }
}
