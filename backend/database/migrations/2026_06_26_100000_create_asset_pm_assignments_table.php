<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_pm_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
            $table->foreignId('pm_rule_id')->constrained('pm_rules')->restrictOnDelete();
            $table->date('last_triggered_date')->nullable();
            $table->decimal('last_triggered_reading', 12, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('assigned_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('deactivated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('deactivated_at')->nullable();
            $table->foreignId('reactivated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reactivated_at')->nullable();
            $table->timestamps();

            $table->unique(['asset_id', 'pm_rule_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_pm_assignments');
    }
};
