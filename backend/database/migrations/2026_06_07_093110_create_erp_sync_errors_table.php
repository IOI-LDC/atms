<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('erp_sync_errors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('erp_sync_job_id')->constrained('erp_sync_jobs')->cascadeOnDelete();
            $table->string('external_id')->nullable();
            $table->string('error_type');
            $table->text('error_message');
            $table->jsonb('payload')->nullable();
            $table->timestamps(); // includes created_at
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('erp_sync_errors');
    }
};
