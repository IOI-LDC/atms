<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assets', function (Blueprint $table) {
            $table->id();
            $table->string('erp_asset_id')->nullable()->unique();
            $table->string('erp_asset_code')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('category')->nullable();
            $table->string('serial_number')->nullable();
            $table->string('model')->nullable();
            $table->string('manufacturer')->nullable();
            // $table->foreignId('current_location_id')->nullable()->constrained('locations'); // Locations are Task 8
            $table->string('operational_status')->default('active');
            $table->string('erp_status')->default('active');
            $table->jsonb('erp_raw_data')->nullable();
            $table->timestamp('erp_last_synced_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assets');
    }
};
