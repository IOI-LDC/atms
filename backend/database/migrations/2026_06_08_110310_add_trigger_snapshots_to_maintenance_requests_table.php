<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('maintenance_requests', function (Blueprint $table) {
            $table->date('trigger_date')->nullable()->after('triggered_by_reading');
            $table->decimal('trigger_reading_value', 12, 2)->nullable()->after('trigger_date');
            $table->foreignId('trigger_reading_type_id')->nullable()->after('trigger_reading_value')->constrained('usage_reading_types')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_requests', function (Blueprint $table) {
            $table->dropForeign(['trigger_reading_type_id']);
            $table->dropColumn(['trigger_date', 'trigger_reading_value', 'trigger_reading_type_id']);
        });
    }
};
