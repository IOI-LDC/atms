<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the operator actually typed.
 *
 * The Work Order reading form takes a *delta* — hours operated since the last
 * reading — and the browser adds it to the base to post an absolute
 * `reading_value`. The delta itself was discarded: never sent, never stored,
 * never audited. So when a total looks wrong there is no way to tell whether the
 * operator mistyped the delta or inherited a bad base.
 *
 * Nullable, and informational only. Nothing in PM evaluation, reporting, or the
 * monotonicity guards reads it — `reading_value` remains the single source of
 * truth for what the meter says. Readings created without a delta (imports, the
 * edit dialog, API clients posting absolutes) leave it null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asset_meter_readings', function (Blueprint $table) {
            $table->decimal('entered_delta', 12, 2)->nullable()->after('reading_value');
        });
    }

    public function down(): void
    {
        Schema::table('asset_meter_readings', function (Blueprint $table) {
            $table->dropColumn('entered_delta');
        });
    }
};
