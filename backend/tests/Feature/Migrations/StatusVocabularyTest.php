<?php

namespace Tests\Feature\Migrations;

use App\Models\MaintenanceCategory;
use App\Models\MasterDataItem;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Release 4a of the status-vocabulary change.
 *
 * Follows `RenameOperationalStatusValuesTest`: load the migration by glob, run
 * `up()` twice to prove idempotency, then `down()` to prove reversibility. 4a is
 * the additive half of the rollout and must be safe to run — and to roll back —
 * against a live database with the old code still deployed.
 */
class StatusVocabularyTest extends TestCase
{
    use RefreshDatabase;

    private function seedMigration(): Migration
    {
        return require collect(glob(database_path('migrations/*_seed_asset_conditions_vocabulary.php')))->sole();
    }

    private function asset(array $overrides = []): int
    {
        return DB::table('assets')->insertGetId(array_merge([
            'erp_asset_code' => 'AST-MIG-'.uniqid(),
            'name' => 'Migration Fixture',
            'maintenance_category_id' => MaintenanceCategory::factory()->create()->id,
            'is_active' => true,
            'operational_status' => 'ready_for_field',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    public function test_the_vocabulary_is_seeded_with_normal_as_the_protected_default(): void
    {
        $items = MasterDataItem::activeIn(MasterDataItem::ASSET_CONDITIONS)->get();

        $this->assertSame(
            ['normal', 'need_assembly', 'missing_parts', 'need_inspection'],
            $items->pluck('value')->all(),
            'Order is the picker order — Normal leads because it is the default.',
        );
        $this->assertSame(
            ['Normal', 'Need Assembly', 'Missing Parts', 'Need Inspection'],
            $items->pluck('label')->all(),
        );

        $default = MasterDataItem::defaultFor(MasterDataItem::ASSET_CONDITIONS);
        $this->assertNotNull($default);
        $this->assertSame('normal', $default->value);
    }

    /**
     * "Need Maintenance" was one of LDC's requested values and is deliberately
     * absent: a pending Maintenance Request already records that need, and a
     * hand-set duplicate could disagree with it.
     */
    public function test_need_maintenance_is_not_a_condition(): void
    {
        $this->assertDatabaseMissing('master_data_items', [
            'group_key' => MasterDataItem::ASSET_CONDITIONS,
            'value' => 'need_maintenance',
        ]);
    }

    public function test_existing_assets_are_backfilled_to_the_default(): void
    {
        // RefreshDatabase already ran the migration, so a row inserted now is
        // the "created after the backfill" case; re-running up() must still
        // leave it at the default rather than skipping it.
        $id = $this->asset(['condition_status' => null]);

        $this->seedMigration()->up();

        $this->assertSame('normal', DB::table('assets')->where('id', $id)->value('condition_status'));
    }

    public function test_up_is_idempotent_and_does_not_flatten_a_chosen_condition(): void
    {
        $chosen = $this->asset(['condition_status' => 'missing_parts']);
        $blank = $this->asset(['condition_status' => null]);

        $migration = $this->seedMigration();
        $migration->up();
        $migration->up();

        // A second run must not duplicate the vocabulary…
        $this->assertSame(4, MasterDataItem::where('group_key', MasterDataItem::ASSET_CONDITIONS)->count());
        // …nor overwrite a condition somebody deliberately set.
        $this->assertSame('missing_parts', DB::table('assets')->where('id', $chosen)->value('condition_status'));
        $this->assertSame('normal', DB::table('assets')->where('id', $blank)->value('condition_status'));
    }

    public function test_down_clears_the_backfill_but_leaves_chosen_conditions(): void
    {
        $chosen = $this->asset(['condition_status' => 'need_inspection']);
        $backfilled = $this->asset(['condition_status' => 'normal']);

        $this->seedMigration()->down();

        $this->assertSame(0, MasterDataItem::where('group_key', MasterDataItem::ASSET_CONDITIONS)->count());
        $this->assertNull(DB::table('assets')->where('id', $backfilled)->value('condition_status'));
        $this->assertSame('need_inspection', DB::table('assets')->where('id', $chosen)->value('condition_status'));
    }

    /**
     * The partial unique index is what makes `defaultFor()` deterministic. Without
     * it two rows could claim the flag and the resolved default would be
     * whichever the planner returned first.
     */
    public function test_a_second_default_in_one_group_is_rejected_by_the_database(): void
    {
        $this->expectException(QueryException::class);

        MasterDataItem::create([
            'group_key' => MasterDataItem::ASSET_CONDITIONS,
            'value' => 'rival_default',
            'label' => 'Rival Default',
            'sort_order' => 9,
            'is_active' => true,
            'is_default' => true,
        ]);
    }

    /** The index is per group, so an unrelated vocabulary may hold its own default. */
    public function test_a_different_group_may_have_its_own_default(): void
    {
        MasterDataItem::create([
            'group_key' => MasterDataItem::MAINTENANCE_PRIORITIES,
            'value' => 'routine',
            'label' => 'Routine',
            'sort_order' => 9,
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->assertSame('routine', MasterDataItem::defaultFor(MasterDataItem::MAINTENANCE_PRIORITIES)?->value);
        $this->assertSame('normal', MasterDataItem::defaultFor(MasterDataItem::ASSET_CONDITIONS)?->value);
    }
}
