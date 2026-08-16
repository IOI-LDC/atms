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

    private function admin(): User
    {
        return User::factory()->create([
            'role_id' => Role::where('code', RoleCode::ADMINISTRATOR)->first()->id,
            'is_active' => true,
        ]);
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
}
