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
        });
    }

    public function down(): void
    {
        Schema::table('pm_occurrence_suppressions', function (Blueprint $table) {
            $table->dropColumn(['decision_type', 'triggered_by_date', 'triggered_by_reading']);
        });
    }
};
