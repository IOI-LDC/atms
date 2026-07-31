<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenance_requests', function (Blueprint $table) {
            $table->foreignId('pm_rule_id')->nullable()->after('is_preventive')->constrained('pm_rules')->nullOnDelete();
            $table->boolean('triggered_by_date')->nullable()->after('pm_rule_id');
            $table->boolean('triggered_by_reading')->nullable()->after('triggered_by_date');
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_requests', function (Blueprint $table) {
            $table->dropForeign(['pm_rule_id']);
            $table->dropColumn(['pm_rule_id', 'triggered_by_date', 'triggered_by_reading']);
        });
    }
};
