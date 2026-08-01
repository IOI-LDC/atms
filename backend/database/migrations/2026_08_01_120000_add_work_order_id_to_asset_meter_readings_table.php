<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Attribute a meter reading to the work order it was taken on.
 *
 * The work order detail page listed every reading the asset had ever had, with
 * nothing to say which ones belonged to the job on screen. Existing rows stay
 * null and read as asset history, which is accurate — they were never recorded
 * against a work order.
 *
 * nullOnDelete rather than cascade: a reading is a measurement of the asset and
 * survives the work order that prompted it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asset_meter_readings', function (Blueprint $table) {
            $table->foreignId('work_order_id')->nullable()->after('maintenance_request_id')
                ->constrained('work_orders')->nullOnDelete();

            // The work order page partitions one asset's readings by work order.
            $table->index(['asset_id', 'work_order_id'], 'amr_asset_work_order_index');
        });
    }

    public function down(): void
    {
        Schema::table('asset_meter_readings', function (Blueprint $table) {
            $table->dropIndex('amr_asset_work_order_index');
            $table->dropConstrainedForeignId('work_order_id');
        });
    }
};
