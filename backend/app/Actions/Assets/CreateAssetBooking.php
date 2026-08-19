<?php

namespace App\Actions\Assets;

use App\Enums\BookingStatus;
use App\Models\Asset;
use App\Models\Booking;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\Assets\AssetWorkEligibility;
use Illuminate\Support\Facades\DB;

class CreateAssetBooking
{
    /**
     * @param  array{booked_from: string, booked_until: string, booking_reference?: ?string, notes?: ?string, force?: bool}  $data
     */
    public function execute(Asset $asset, User $user, array $data): Booking
    {
        $from = $data['booked_from'];
        $until = $data['booked_until'];
        $force = $data['force'] ?? false;

        return DB::transaction(function () use ($asset, $user, $data, $from, $until, $force) {
            // Both axes, not just `is_active`. `Asset::booted` auto-releases
            // active bookings when an asset is withdrawn *or* deactivated —
            // checking only one here let a withdrawn asset be re-booked the
            // moment after its own bookings were released, so the two halves of
            // one rule disagreed.
            //
            // Locked, and inside the transaction, because this is the action
            // where the unlocked version was outright wrong: the guard ran
            // before the transaction opened, so a deactivation could release the
            // existing bookings and this request would insert a new active one
            // straight afterwards.
            $asset = AssetWorkEligibility::lockAndGuard($asset, 'create a booking');

            $overlapping = Booking::where('asset_id', $asset->id)
                ->overlapping($from, $until)
                ->with('bookedBy')
                ->get();

            if ($overlapping->isNotEmpty() && ! $force) {
                throw new BookingOverlapException($overlapping);
            }

            $booking = Booking::create([
                'asset_id' => $asset->id,
                'booked_by' => $user->id,
                'booked_from' => $from,
                'booked_until' => $until,
                'booking_reference' => $data['booking_reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'status' => BookingStatus::ACTIVE,
            ]);

            app(AuditLogger::class)->log(
                'asset.booked',
                $asset,
                [],
                $booking->toArray()
            );

            return $booking->load(['asset', 'bookedBy']);
        });
    }
}
