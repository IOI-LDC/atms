<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Structured Size and controlled Maintenance Category for Assets and Parts,
 * plus the supplier Part Number.
 *
 * Size is `numeric(9,5)` because 1/32" = 0.03125 needs exactly five decimal
 * places — four would round it away and break the exact equality the
 * compatibility rule depends on. Storage is numeric, never float.
 *
 * The legacy free-text category columns are removed by the following forward
 * migration after these controlled relationships are in place.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->decimal('size_inches', total: 9, places: 5)->nullable()->after('serial_number');
            $table->foreignId('maintenance_category_id')->nullable()->after('category')
                ->constrained('maintenance_categories')->restrictOnDelete();
        });

        Schema::table('parts', function (Blueprint $table) {
            $table->string('part_number')->nullable()->after('erp_part_code');
            $table->decimal('size_inches', total: 9, places: 5)->nullable()->after('description');
            $table->foreignId('maintenance_category_id')->nullable()->after('category')
                ->constrained('maintenance_categories')->restrictOnDelete();
        });

        // Compatibility filtering matches on both dimensions at once, and part
        // search now covers the supplier Part Number.
        Schema::table('assets', function (Blueprint $table) {
            $table->index(['maintenance_category_id', 'size_inches'], 'assets_category_size_index');
        });

        Schema::table('parts', function (Blueprint $table) {
            $table->index(['maintenance_category_id', 'size_inches'], 'parts_category_size_index');
            $table->index('part_number', 'parts_part_number_index');
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropIndex('assets_category_size_index');
            $table->dropConstrainedForeignId('maintenance_category_id');
            $table->dropColumn('size_inches');
        });

        Schema::table('parts', function (Blueprint $table) {
            $table->dropIndex('parts_category_size_index');
            $table->dropIndex('parts_part_number_index');
            $table->dropConstrainedForeignId('maintenance_category_id');
            $table->dropColumn(['size_inches', 'part_number']);
        });
    }
};
