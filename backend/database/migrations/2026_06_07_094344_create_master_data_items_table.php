<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('master_data_items', function (Blueprint $table) {
            $table->id();
            $table->string('group_key');
            $table->string('value');
            $table->string('label');
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['group_key', 'value']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('master_data_items');
    }
};
