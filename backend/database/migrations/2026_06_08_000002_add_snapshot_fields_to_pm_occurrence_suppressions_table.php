<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pm_occurrence_suppressions', function (Blueprint $table) {
            $table->string('decision_type')->after('trigger_type');
            $table->boolean('triggered_by_date')->default(false)->after('decision_type');
            $table->boolean('triggered_by_reading')->default(false)->after('triggered_by_date');
            $table->date('trigger_date')->nullable()->after('triggered_by_reading');
            $table->decimal('trigger_reading_value', 12, 2)->nullable()->after('trigger_date');
            $table->foreignId('trigger_reading_type_id')->nullable()->after('trigger_reading_value')->constrained('usage_reading_types')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pm_occurrence_suppressions', function (Blueprint $table) {
            $table->dropForeign(['trigger_reading_type_id']);
            $table->dropColumn(['decision_type', 'triggered_by_date', 'triggered_by_reading', 'trigger_date', 'trigger_reading_value', 'trigger_reading_type_id']);
        });
    }
};
