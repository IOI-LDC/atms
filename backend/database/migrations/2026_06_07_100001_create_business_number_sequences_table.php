<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_number_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('type')->unique();
            $table->unsignedBigInteger('current_value')->default(0);
            $table->timestamps();
        });

        DB::table('business_number_sequences')->insert([
            ['type' => 'MR', 'current_value' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['type' => 'WO', 'current_value' => 0, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('business_number_sequences');
    }
};
