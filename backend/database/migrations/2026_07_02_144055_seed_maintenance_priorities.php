<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the four maintenance priorities into master_data_items. Values MUST match
 * the existing MaintenanceRequest.priority string values so current records stay
 * consistent.
 *
 * Mirrors the seed_baseline_real_data pattern: raw SQL guarded out of the testing
 * environment (tests own their own MasterDataItem fixtures). ON CONFLICT keeps it
 * idempotent across re-runs (unique on group_key + value).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (App::environment('testing') || DB::connection()->getName() === 'testing') {
            return;
        }

        $now = now()->format('Y-m-d H:i:s');

        DB::statement(
            "INSERT INTO master_data_items (group_key, value, label, sort_order, is_active, created_at, updated_at) VALUES "
            ."('maintenance_priorities', 'low', 'Low', 0, true, '{$now}', '{$now}'), "
            ."('maintenance_priorities', 'medium', 'Medium', 1, true, '{$now}', '{$now}'), "
            ."('maintenance_priorities', 'high', 'High', 2, true, '{$now}', '{$now}'), "
            ."('maintenance_priorities', 'critical', 'Critical', 3, true, '{$now}', '{$now}') "
            .'ON CONFLICT (group_key, value) DO NOTHING'
        );
    }

    public function down(): void
    {
        if (App::environment('testing') || DB::connection()->getName() === 'testing') {
            return;
        }

        DB::table('master_data_items')
            ->where('group_key', 'maintenance_priorities')
            ->delete();
    }
};
