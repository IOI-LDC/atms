<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parts', function (Blueprint $table) {
            $table->id();
            $table->string('erp_part_id')->nullable()->unique();
            $table->string('erp_part_code')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('unit_of_measure')->default('EA');
            $table->string('category')->nullable();
            $table->string('erp_status')->default('active');
            $table->jsonb('erp_raw_data')->nullable();
            $table->timestamp('erp_last_synced_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parts');
    }
};
