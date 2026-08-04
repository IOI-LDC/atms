<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('assets')->where('operational_status', 'active')->update(['operational_status' => 'ready_for_field']);
        DB::table('assets')->where('operational_status', 'inactive')->update(['operational_status' => 'scraped']);

        // The column default must follow the rename or raw inserts would write a dead value.
        Schema::table('assets', function (Blueprint $table) {
            $table->string('operational_status')->default('ready_for_field')->change();
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->string('operational_status')->default('active')->change();
        });

        DB::table('assets')->where('operational_status', 'ready_for_field')->update(['operational_status' => 'active']);
        DB::table('assets')->where('operational_status', 'scraped')->update(['operational_status' => 'inactive']);
    }
};
