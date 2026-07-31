<?php

use App\Enums\MaintenanceRequestStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('maintenance_requests', function (Blueprint $table) {
            // Nullable with no default so NULL (unclassified) is distinct
            // from a deliberate false (no-failure-found).
            $table->boolean('is_failure')->nullable()->after('is_preventive');
        });

        // Backfill: historical CONVERTED corrective MRs were approved before
        // this feature existed. Treat them as failures so MTBF continuity is
        // preserved. Pending-review CMRs stay NULL (unvalidated; may be
        // rejected) and are therefore excluded from MTBF.
        DB::table('maintenance_requests')
            ->where('is_preventive', false)
            ->where('status', MaintenanceRequestStatus::CONVERTED->value)
            ->update(['is_failure' => true]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('maintenance_requests', function (Blueprint $table) {
            $table->dropColumn('is_failure');
        });
    }
};
