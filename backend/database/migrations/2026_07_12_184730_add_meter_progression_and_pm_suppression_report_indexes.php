<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asset_meter_readings', function (Blueprint $table) {
            $table->index(['reading_at', 'id'], 'amr_reading_at_id_index');
            $table->index(
                ['asset_id', 'usage_reading_type_id', 'reading_at', 'id'],
                'amr_asset_type_reading_id_index'
            );
        });

        Schema::table('pm_occurrence_suppressions', function (Blueprint $table) {
            $table->index(['decided_at', 'id'], 'pos_decided_at_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('asset_meter_readings', function (Blueprint $table) {
            $table->dropIndex('amr_reading_at_id_index');
            $table->dropIndex('amr_asset_type_reading_id_index');
        });

        Schema::table('pm_occurrence_suppressions', function (Blueprint $table) {
            $table->dropIndex('pos_decided_at_id_index');
        });
    }
};
