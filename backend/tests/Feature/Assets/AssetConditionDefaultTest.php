<?php

namespace Tests\Feature\Assets;

use App\Actions\Assets\CreateAsset;
use App\Enums\RoleCode;
use App\Models\Asset;
use App\Models\MasterDataItem;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Every creation path stamps a condition, and it comes from the vocabulary
 * rather than a constant in code.
 *
 * This matters beyond tidiness: release 4b's work-order close resets an asset's
 * condition through the same `MasterDataItem::defaultFor()` call. If the
 * creation paths quietly stopped resolving it — or resolved it to a hardcoded
 * 'normal' that no longer matches the table — close would write a value the
 * picker cannot offer, and nothing would fail loudly.
 *
 * The 4a backfill covers assets that already existed; these cover the ones
 * created afterwards.
 */
class AssetConditionDefaultTest extends TestCase
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

    public function test_the_seeded_default_is_normal(): void
    {
        $default = MasterDataItem::defaultFor(MasterDataItem::ASSET_CONDITIONS);

        $this->assertNotNull($default, 'The 4a seed must leave exactly one default.');
        $this->assertSame('normal', $default->value);
    }

    public function test_an_asset_created_through_the_api_gets_the_default_condition(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/assets', [
                'name' => 'New Motor',
                'erp_asset_code' => 'AST-COND-001',
            ])
            ->assertCreated();

        $this->assertSame('normal', Asset::where('erp_asset_code', 'AST-COND-001')->sole()->condition_status);
    }

    /**
     * The action honours an explicit value. No endpoint passes one yet — the
     * condition API contract lands in 4b — so this pins the pass-through the
     * moment that contract exists, rather than after it silently stopped
     * working.
     */
    public function test_an_explicit_condition_is_not_overwritten_by_the_default(): void
    {
        $asset = app(CreateAsset::class)->execute([
            'erp_asset_code' => 'AST-COND-002',
            'name' => 'Explicit Condition',
            'condition_status' => 'need_inspection',
        ]);

        $this->assertSame('need_inspection', $asset->condition_status);
    }

    public function test_the_erp_import_stamps_the_default_condition(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'erp').'.csv';
        file_put_contents($path, implode("\n", [
            'no,description,serialNo,faSubclassCode,vendorNo,underMaintenance,inactive',
            'ERP-001,Mud Motor,SN-1,DHT,VEND-1,false,false',
            'ERP-002,Jar Assembly,SN-2,JAR,VEND-2,true,false',
        ])."\n");

        $this->artisan('atms:import-erp-assets', ['file' => $path, '--force' => true])
            ->assertSuccessful();

        unlink($path);

        $this->assertSame(2, Asset::count());
        $this->assertSame(
            ['normal', 'normal'],
            Asset::orderBy('erp_asset_code')->pluck('condition_status')->all(),
        );
    }

    /**
     * With no resolvable default the creation paths write null, never a guessed
     * string. `defaultFor()`'s contract is "leave the value alone", and a
     * fabricated 'normal' would be a value the vocabulary no longer offers.
     */
    public function test_creation_writes_null_when_the_group_has_no_active_default(): void
    {
        // Straight to the table: the API deliberately refuses this, which is the
        // guard tested in MasterDataItemTest. This reaches the state a deleted
        // or hand-edited row could still produce.
        DB::table('master_data_items')
            ->where('group_key', MasterDataItem::ASSET_CONDITIONS)
            ->where('is_default', true)
            ->update(['is_active' => false]);

        $this->actingAs($this->admin())
            ->postJson('/api/assets', [
                'name' => 'Orphan Condition',
                'erp_asset_code' => 'AST-COND-003',
            ])
            ->assertCreated();

        $this->assertNull(Asset::where('erp_asset_code', 'AST-COND-003')->sole()->condition_status);
    }

    // ── The condition API contract (4b) ─────────────────────────────────────────

    public function test_a_condition_can_be_set_on_an_existing_asset(): void
    {
        $asset = Asset::create([
            'erp_asset_code' => 'AST-COND-004',
            'name' => 'Editable',
            'is_active' => true,
        ]);

        $this->actingAs($this->admin())
            ->patchJson("/api/assets/{$asset->id}", ['condition_status' => 'missing_parts'])
            ->assertOk()
            ->assertJsonPath('data.condition_status', 'missing_parts')
            ->assertJsonPath('data.condition_label', 'Missing Parts');
    }

    /**
     * Validation is resolved from the vocabulary, not a constant — so retiring a
     * condition takes it out of circulation immediately, without a deploy.
     * Assets already carrying it keep it; only new writes are refused.
     */
    public function test_a_retired_condition_cannot_be_assigned(): void
    {
        MasterDataItem::where('group_key', MasterDataItem::ASSET_CONDITIONS)
            ->where('value', 'need_assembly')
            ->update(['is_active' => false]);

        $asset = Asset::create([
            'erp_asset_code' => 'AST-COND-005',
            'name' => 'Editable',
            'is_active' => true,
        ]);

        $this->actingAs($this->admin())
            ->patchJson("/api/assets/{$asset->id}", ['condition_status' => 'need_assembly'])
            ->assertStatus(422);
    }

    public function test_an_unknown_condition_is_rejected(): void
    {
        $asset = Asset::create([
            'erp_asset_code' => 'AST-COND-006',
            'name' => 'Editable',
            'is_active' => true,
        ]);

        $this->actingAs($this->admin())
            ->patchJson("/api/assets/{$asset->id}", ['condition_status' => 'invented'])
            ->assertStatus(422);
    }

    /**
     * The picker every non-Admin screen reads. Serves active rows in sort order
     * and carries `is_default` so the asset form can pre-select the same value a
     * work-order close resets to, without hardcoding the string.
     */
    public function test_the_condition_picker_serves_the_active_vocabulary(): void
    {
        MasterDataItem::where('group_key', MasterDataItem::ASSET_CONDITIONS)
            ->where('value', 'missing_parts')
            ->update(['is_active' => false]);

        $data = $this->actingAs($this->admin())
            ->getJson('/api/list-options/asset_conditions')
            ->assertOk()
            ->json('data');

        $this->assertSame(['normal', 'need_assembly', 'need_inspection'], array_column($data, 'value'));
        $this->assertSame(['Normal', 'Need Assembly', 'Need Inspection'], array_column($data, 'label'));
        $this->assertTrue($data[0]['is_default']);
    }

    /**
     * The picker is a read path for every role — a technician setting a
     * condition needs the labels, and the Admin-gated master-data CRUD is a
     * different endpoint with a different purpose.
     */
    public function test_the_condition_picker_is_readable_by_a_technician(): void
    {
        $this->actingAs($this->user(RoleCode::TECHNICIAN))
            ->getJson('/api/list-options/asset_conditions')
            ->assertOk();
    }

    /**
     * `at_the_field` is derived from location. Accepting it here would let an
     * asset claim to be on a rig while its location says the yard.
     */
    public function test_at_the_field_cannot_be_set_through_the_asset_api(): void
    {
        $asset = Asset::create([
            'erp_asset_code' => 'AST-COND-007',
            'name' => 'Editable',
            'is_active' => true,
        ]);

        $this->actingAs($this->admin())
            ->patchJson("/api/assets/{$asset->id}", ['operational_status' => 'at_the_field'])
            ->assertStatus(422);

        $this->actingAs($this->admin())
            ->postJson('/api/assets', [
                'name' => 'Claimed Deployed',
                'erp_asset_code' => 'AST-COND-008',
                'operational_status' => 'at_the_field',
            ])
            ->assertStatus(422);
    }
}
