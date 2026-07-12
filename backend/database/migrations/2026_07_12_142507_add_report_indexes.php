<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Index-only migration backing the Reports Pass 1 query patterns. No schema
 * changes — additive composite indexes only. operational_status is low-
 * cardinality and intentionally left unindexed (seq-scan is efficient).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenance_requests', function (Blueprint $table) {
            // R-7, R-8: date-triggered PM due/overdue scans.
            $table->index(['is_preventive', 'triggered_by_date', 'trigger_date'], 'mr_pm_due_index');
        });

        Schema::table('work_orders', function (Blueprint $table) {
            // R-14: backlog status + age ordering.
            $table->index(['status', 'created_at'], 'wo_status_created_index');
        });

        Schema::table('assets', function (Blueprint $table) {
            // R-2: group-by location.
            $table->index('current_location_id', 'assets_location_index');
        });

        Schema::table('asset_pm_assignments', function (Blueprint $table) {
            // R-1: active-assignment scan by rule.
            $table->index(['is_active', 'pm_rule_id'], 'apa_active_rule_index');
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_requests', function (Blueprint $table) {
            $table->dropIndex('mr_pm_due_index');
        });

        Schema::table('work_orders', function (Blueprint $table) {
            $table->dropIndex('wo_status_created_index');
        });

        Schema::table('assets', function (Blueprint $table) {
            $table->dropIndex('assets_location_index');
        });

        Schema::table('asset_pm_assignments', function (Blueprint $table) {
            $table->dropIndex('apa_active_rule_index');
        });
    }
};
