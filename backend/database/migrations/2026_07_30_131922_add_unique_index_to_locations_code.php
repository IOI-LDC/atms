<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Location codes are short (2-3 char) identifiers that must be unique.
     * The column stays nullable so internal flows that create locations
     * without a code keep working; multiple NULLs are allowed by the index.
     */
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->unique('code');
        });
    }

    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->dropUnique(['code']);
        });
    }
};
