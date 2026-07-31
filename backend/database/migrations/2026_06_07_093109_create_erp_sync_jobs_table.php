<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('erp_sync_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('sync_type'); // assets, parts
            $table->string('status'); // running, success, failed, partial
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->integer('total_records')->default(0);
            $table->integer('created_count')->default(0);
            $table->integer('updated_count')->default(0);
            $table->integer('skipped_count')->default(0);
            $table->integer('failed_count')->default(0);
            $table->text('error_message')->nullable();
            $table->foreignId('triggered_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps(); // includes created_at
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('erp_sync_jobs');
    }
};
