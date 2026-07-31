<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds soft-delete and update tracking to asset meter readings so that
     * edits are timestamped and deletions are recoverable/auditable.
     *
     * Decision: soft-delete (deleted_at) + updated_at. Confirmed readings stay
     * immutable at the application layer; only unconfirmed readings may be
     * edited or soft-deleted, so PM-trigger logic (which only consumes
     * confirmed readings) is unaffected.
     */
    public function up(): void
    {
        Schema::table('asset_meter_readings', function (Blueprint $table) {
            $table->timestamp('updated_at')->nullable()->after('created_at');
            $table->softDeletes()->after('updated_at');
        });
    }

    public function down(): void
    {
        Schema::table('asset_meter_readings', function (Blueprint $table) {
            $table->dropSoftDeletes();
            $table->dropColumn('updated_at');
        });
    }
};
