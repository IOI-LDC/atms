<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pm_occurrence_suppressions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pm_rule_id')->constrained('pm_rules')->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
            $table->foreignId('maintenance_request_id')->constrained('maintenance_requests')->cascadeOnDelete();
            $table->string('trigger_type');
            $table->date('suppressed_until_date')->nullable();
            $table->decimal('suppressed_until_reading', 12, 2)->nullable();
            $table->foreignId('decided_by')->constrained('users')->cascadeOnDelete();
            $table->timestamp('decided_at');
            $table->string('reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pm_occurrence_suppressions');
    }
};
