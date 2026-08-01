<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PM rules can be pointed at Maintenance Categories instead of asset by asset.
 *
 * ## The pivot records intent; assignments remain the state
 *
 * A category link is expanded into one `asset_pm_assignments` row per member
 * asset rather than resolved on the fly, because PM state is inherently
 * per-asset: every assignment owns its `last_triggered_date` /
 * `last_triggered_reading`. The deciding case is the baseline —
 * `CreateAssetPmAssignment` stamps `last_triggered_date = now()` so a newly
 * covered asset gets one full interval of grace before its first PM. Dynamic
 * resolution has no moment at which to stamp that, so every asset would land
 * either immediately overdue or never due.
 *
 * Materializing also leaves the evaluation job, the L1–L4 cascade in
 * `CloseWorkOrder`, and all four PM reports reading exactly what they read now.
 *
 * ## Why assignments record where they came from
 *
 * `origin` separates rows a human created from rows a category link created, so
 * reconciliation can withdraw its own rows when an asset leaves a category
 * without ever touching a deliberate per-asset assignment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pm_rule_maintenance_category', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pm_rule_id')->constrained('pm_rules')->cascadeOnDelete();
            $table->foreignId('maintenance_category_id')->constrained('maintenance_categories')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['pm_rule_id', 'maintenance_category_id'], 'pm_rule_category_unique');
        });

        Schema::table('asset_pm_assignments', function (Blueprint $table) {
            $table->string('origin', 20)->default('manual')->after('pm_rule_id');
            $table->foreignId('source_maintenance_category_id')
                ->nullable()
                ->after('origin')
                ->constrained('maintenance_categories')
                ->nullOnDelete();

            // Reconciliation asks "which rows did this rule's category links
            // create?" on every run.
            $table->index(['pm_rule_id', 'origin'], 'asset_pm_assignments_rule_origin_index');
        });

        // Nobody assigns a category-expanded row, and nobody withdraws one — a
        // rule's coverage does. A null actor on either side is what separates
        // reconciliation's own work from a person's, which is the whole basis
        // of the precedence rule: it may undo itself, never a human decision.
        DB::statement('ALTER TABLE asset_pm_assignments ALTER COLUMN assigned_by DROP NOT NULL');
    }

    public function down(): void
    {
        DB::table('asset_pm_assignments')->whereNull('assigned_by')->delete();
        DB::statement('ALTER TABLE asset_pm_assignments ALTER COLUMN assigned_by SET NOT NULL');

        Schema::table('asset_pm_assignments', function (Blueprint $table) {
            $table->dropIndex('asset_pm_assignments_rule_origin_index');
            $table->dropConstrainedForeignId('source_maintenance_category_id');
            $table->dropColumn('origin');
        });

        Schema::dropIfExists('pm_rule_maintenance_category');
    }
};
