<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Controlled Maintenance Category vocabulary shared by Assets and Parts.
 *
 * Local ATMS data with no relationship to any ERP classification —
 * `fa_subclass_code` stays separate and is treated as Asset Class. Values are
 * introduced and changed only through the controlled workbook import; there is
 * deliberately no administration UI, so this table ships empty.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_categories', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_categories');
    }
};
