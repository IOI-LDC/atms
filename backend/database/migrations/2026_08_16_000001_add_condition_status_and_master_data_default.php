<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Release 4a of the status-vocabulary change — additive schema only.
 *
 * Deliberately safe to run against the *old* application code: it adds columns
 * and an index, and changes no existing value. The operational-status value
 * migration and the enum narrowing belong to 4b, behind the downtime cutover.
 *
 * `assets.condition_status` stays **nullable**. Every creation path resolves the
 * default explicitly (CreateAsset and both import commands), so NOT NULL would
 * buy a table lock for no behavioural gain.
 *
 * `master_data_items.is_default` marks the value an automatic reset returns to —
 * `CloseWorkOrder` resolves it from the table rather than hardcoding a string,
 * so renaming or replacing the default is an Admin action, not a deploy. The
 * partial unique index is what makes "resolve the default" deterministic: without
 * it two rows could claim the flag and the winner would be query-order luck.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->string('condition_status')->nullable()->after('maintenance_sub_status');
            $table->index('condition_status');
        });

        Schema::table('master_data_items', function (Blueprint $table) {
            $table->boolean('is_default')->default(false)->after('is_active');
        });

        // At most one default per vocabulary. Partial, so the thousands of
        // non-default rows are not forced to be distinct from one another.
        DB::statement(
            'CREATE UNIQUE INDEX master_data_items_one_default_per_group
             ON master_data_items (group_key) WHERE is_default'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS master_data_items_one_default_per_group');

        Schema::table('master_data_items', function (Blueprint $table) {
            $table->dropColumn('is_default');
        });

        Schema::table('assets', function (Blueprint $table) {
            $table->dropIndex(['condition_status']);
            $table->dropColumn('condition_status');
        });
    }
};
