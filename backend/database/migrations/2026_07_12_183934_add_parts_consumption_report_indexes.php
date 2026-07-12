<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->index(
                ['status', 'completed_at', 'id'],
                'wo_status_completed_at_id_index'
            );
        });

        Schema::table('work_order_parts', function (Blueprint $table) {
            $table->index(
                ['work_order_id', 'part_id'],
                'wop_work_order_part_index'
            );
        });
    }

    public function down(): void
    {
        Schema::table('work_order_parts', function (Blueprint $table) {
            $table->dropIndex('wop_work_order_part_index');
        });

        Schema::table('work_orders', function (Blueprint $table) {
            $table->dropIndex('wo_status_completed_at_id_index');
        });
    }
};
