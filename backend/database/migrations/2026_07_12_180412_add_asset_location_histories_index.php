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
        Schema::table('asset_location_histories', function (Blueprint $table) {
            $table->index(['effective_at', 'id'], 'asset_location_histories_effective_at_id_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('asset_location_histories', function (Blueprint $table) {
            $table->dropIndex('asset_location_histories_effective_at_id_index');
        });
    }
};
