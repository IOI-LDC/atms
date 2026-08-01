<?php

use App\Models\MaintenanceCategory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Every asset must carry a Maintenance Category.
 *
 * The category is the only ATMS-owned handle on an asset — `fa_subclass_code`
 * is written by the ERP sync, so ATMS cannot govern it. An asset with no
 * category is therefore an asset ATMS cannot route a form or a PM rule to, and
 * has no remedy for.
 *
 * ## Why a sentinel row rather than a bare NOT NULL
 *
 * New assets arrive from the ERP (`atms:import-erp-assets`), which knows
 * nothing about ATMS categories and has no subclass -> category mapping to
 * infer one from. A bare NOT NULL would make the ERP sync fail on every new
 * asset. Instead the column defaults to a seeded `UNCLASSIFIED` category:
 * an unclassified asset still exists, but it is now a **visible, countable,
 * filterable** state that an Admin can clear, rather than a null that no
 * screen or report could show.
 */
return new class extends Migration
{
    public function up(): void
    {
        $sentinelId = DB::table('maintenance_categories')
            ->where('code', MaintenanceCategory::UNCLASSIFIED_CODE)
            ->value('id');

        if ($sentinelId === null) {
            $sentinelId = DB::table('maintenance_categories')->insertGetId([
                'code' => MaintenanceCategory::UNCLASSIFIED_CODE,
                'name' => MaintenanceCategory::UNCLASSIFIED_NAME,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('assets')
            ->whereNull('maintenance_category_id')
            ->update(['maintenance_category_id' => $sentinelId]);

        // The default carries the sentinel's real id in this database, so a
        // freshly migrated test database and production agree by construction
        // rather than by a hardcoded number.
        DB::statement("ALTER TABLE assets ALTER COLUMN maintenance_category_id SET DEFAULT {$sentinelId}");
        DB::statement('ALTER TABLE assets ALTER COLUMN maintenance_category_id SET NOT NULL');
    }

    /**
     * Reversal drops the constraint, not the classification: assets moved out
     * of null stay in Unclassified, which is a truthful statement about them.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE assets ALTER COLUMN maintenance_category_id DROP NOT NULL');
        DB::statement('ALTER TABLE assets ALTER COLUMN maintenance_category_id DROP DEFAULT');
    }
};
