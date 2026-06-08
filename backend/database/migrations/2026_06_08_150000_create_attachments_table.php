<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->string('attachable_type');
            $table->unsignedBigInteger('attachable_id');
            $table->string('original_name');
            $table->string('stored_path');
            $table->string('mime_type');
            $table->unsignedBigInteger('size_bytes');
            $table->string('file_hash', 64)->nullable();
            $table->text('description')->nullable();
            $table->unsignedBigInteger('uploaded_by_user_id')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->unsignedBigInteger('deleted_by_user_id')->nullable();
            $table->timestamps();

            $table->index(['attachable_type', 'attachable_id']);
            $table->foreign('uploaded_by_user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('deleted_by_user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
    }
};
