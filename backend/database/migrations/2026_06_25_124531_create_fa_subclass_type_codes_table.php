<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fa_subclass_type_codes', function (Blueprint $table) {
            $table->id();
            $table->string('fa_subclass_code', 20)->unique();
            $table->string('type_code', 3);
            $table->string('description')->nullable();
            $table->boolean('has_no_physical_size')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fa_subclass_type_codes');
    }
};
