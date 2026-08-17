<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Release 4c — drop `assets.erp_status`.
 *
 * The column mirrored the ERP's own status vocabulary alongside ATMS's
 * `maintenance_status`, and nothing read it: release 4b removed it from
 * `AssetResource`, and no import or sync has written it since asset sync was
 * shelved. Every one of the 400 live rows held the same value (`active`), so it
 * distinguished nothing even while it was populated.
 *
 * ⚠️ **`assets.maintenance_sub_status` is deliberately NOT dropped here**, though
 * the original 4c plan paired them. Its readers went in 4b — nothing writes or
 * serves it — but the column is retained until Phase 2 Assembly is specified.
 * The recorded design (🟠 P2-001) derives `installed`/`ready` from
 * `parent_asset_id` and should not need it, but the column holds only NULLs and
 * dropping it early would have to be undone if that spec lands differently.
 *
 * ⚠️ **`parts.erp_status` is a different column and stays.** Parts genuinely
 * sync from ERP — `SyncParts`, `ImportPartsCommand` and `PartResource` all use
 * it. Only the assets one is removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropColumn('erp_status');
        });
    }

    /**
     * Restores the column, not its contents.
     *
     * The values are not recoverable and are not worth recovering: they were a
     * single repeated string. Anything genuinely needing an ERP status for an
     * asset should take it from `erp_raw_data`, which is still stored.
     */
    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->string('erp_status')->nullable();
        });
    }
};
