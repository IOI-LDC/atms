<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_meter_readings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
            $table->foreignId('usage_reading_type_id')->constrained('usage_reading_types')->cascadeOnDelete();
            $table->decimal('reading_value', 12, 2);
            $table->timestamp('reading_at');
            $table->string('source'); // e.g., user, system, erp
            $table->foreignId('entered_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            // maintenance_request_id will be constrained later or handled carefully since we haven't created the table yet.
            // Actually, we can add the column now without foreign key constraint to avoid circular deps or we can skip FK.
            $table->unsignedBigInteger('maintenance_request_id')->nullable();

            $table->foreignId('confirmed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_meter_readings');
    }
};
