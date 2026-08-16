<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Release 4b — rewrite every remaining legacy `operational_status` value.
 *
 * This runs BEFORE `OperationalStatus` narrows to four cases. Order matters: the
 * enum is a cast on `Asset`, so a single surviving legacy row would make every
 * read of that asset throw once the cases are gone. The final assertion in
 * `up()` exists to make that impossible rather than merely unlikely.
 *
 * ⚠️ **Raw SQL bypasses Eloquent.** `Asset::booted` releases an asset's active
 * bookings when it is deactivated, and that hook does NOT fire here — so this
 * migration performs the release itself for the rows it deactivates. The
 * cutover preflight also checks for active bookings, but preflight is advisory
 * and this is not: the migration must be correct on any database it meets,
 * including one whose bookings were created after the preflight was run.
 *
 * ⚠️ **Filter by value, never by id.** Ids were captured on 2026-08-16 (155
 * `scraped`; 353 and 410 `down`) and are recorded in STATE as a snapshot only. A
 * work-order close or a manual status set between then and the cutover changes
 * which rows carry these values, so anything keyed to those ids would migrate
 * the wrong rows — or miss rows entirely.
 */
return new class extends Migration
{
    /**
     * Legacy value → replacement. Two of the four also leave the fleet.
     *
     * `down → failure` is the rename LDC asked for: they read "down" as
     * "waiting for parts", which is a *condition*, not an operational state.
     *
     * `under_inspection → under_maintenance` follows the design's cancellation
     * of Phase 1.5 — an inspection **is** preventive maintenance, so it is not a
     * separate operational state.
     *
     * `scraped` and `lih` (lost in hole) both mean the asset is gone. Since the
     * 2026-08-16 design, `is_active = false` is the only "out of ATMS" control,
     * so they become an ordinary status plus a deactivation. Neither had a
     * distinguishing label home in the agreed vocabulary — recovering "was it
     * scrapped or lost?" needs a withdrawal-reason field (🟠 D-021), which is
     * why this migration does not pretend to preserve the distinction.
     *
     * @var array<string, array{status: string, deactivate: bool}>
     */
    private const MAPPING = [
        'down' => ['status' => 'failure', 'deactivate' => false],
        'under_inspection' => ['status' => 'under_maintenance', 'deactivate' => false],
        'scraped' => ['status' => 'ready_for_field', 'deactivate' => true],
        'lih' => ['status' => 'ready_for_field', 'deactivate' => true],
    ];

    public function up(): void
    {
        foreach (self::MAPPING as $legacy => $target) {
            if ($target['deactivate']) {
                // Release bookings BEFORE the update, while the rows are still
                // identifiable by their legacy status. Mirrors what
                // `Asset::booted` would have done had this gone through Eloquent.
                $ids = DB::table('assets')->where('operational_status', $legacy)->pluck('id');

                if ($ids->isNotEmpty()) {
                    DB::table('bookings')
                        ->whereIn('asset_id', $ids)
                        ->where('status', 'active')
                        ->update(['status' => 'released', 'cancelled_at' => now()]);
                }
            }

            $update = ['operational_status' => $target['status']];

            if ($target['deactivate']) {
                $update['is_active'] = false;
            }

            DB::table('assets')->where('operational_status', $legacy)->update($update);
        }

        // The enum narrows in this same release. A survivor here is a broken
        // application, not a data-quality note, so fail the migration rather
        // than the next read.
        $remaining = DB::table('assets')
            ->whereIn('operational_status', array_keys(self::MAPPING))
            ->count();

        if ($remaining > 0) {
            throw new RuntimeException(
                "Refusing to complete: {$remaining} asset(s) still carry a legacy operational_status."
            );
        }
    }

    /**
     * Reverses the renames only — deliberately **not** the deactivations.
     *
     * `scraped` and `lih` both became `ready_for_field` + `is_active = false`,
     * which is indistinguishable from an asset that was legitimately deactivated
     * while ready. Reactivating on that guess would put retired equipment back
     * into the pool, so this leaves those rows alone and says so.
     *
     * The same applies in the other direction: `failure` rows created after this
     * ran are turned back into `down`. Immediately after `up()` — which is when
     * a rollback actually happens — that set is exactly the rows `up()` renamed.
     *
     * The cutover's abort path is the backup snapshot, not this method. That is
     * recorded in the release notes and is why the loss above is acceptable.
     */
    public function down(): void
    {
        DB::table('assets')->where('operational_status', 'failure')->update(['operational_status' => 'down']);
    }
};
