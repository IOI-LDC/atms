<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->string('sharepoint_item_id')->unique();
            $table->string('emp_id')->unique();
            $table->string('name');
            $table->string('email');
            $table->string('department')->nullable();
            $table->string('job_title')->nullable();
            $table->boolean('source_is_active')->default(true);
            $table->timestamp('source_updated_at')->nullable();
            $table->jsonb('source_raw_data')->nullable();
            $table->timestamp('last_synced_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
