<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pm_rules', function (Blueprint $table) {
            // Drop the asset_id foreign key (by column — supported on both PG and
            // SQLite's table rebuild) before removing the columns it references.
            $table->dropForeign(['asset_id']);
            $table->dropColumn(['asset_id', 'last_triggered_date', 'last_triggered_reading']);
        });
    }

    public function down(): void
    {
        Schema::table('pm_rules', function (Blueprint $table) {
            $table->foreignId('asset_id')->after('id')->constrained('assets')->cascadeOnDelete();
            $table->date('last_triggered_date')->nullable();
            $table->decimal('last_triggered_reading', 12, 2)->nullable();
        });
    }
};
