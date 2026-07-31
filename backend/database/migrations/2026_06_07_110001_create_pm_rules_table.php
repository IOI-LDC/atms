<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pm_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('trigger_type');
            $table->unsignedInteger('interval_days')->nullable();
            $table->decimal('interval_reading', 12, 2)->nullable();
            $table->foreignId('usage_reading_type_id')->nullable()->constrained('usage_reading_types')->cascadeOnDelete();
            $table->date('last_triggered_date')->nullable();
            $table->decimal('last_triggered_reading', 12, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('deactivated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('deactivated_at')->nullable();
            $table->foreignId('reactivated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reactivated_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pm_rules');
    }
};
