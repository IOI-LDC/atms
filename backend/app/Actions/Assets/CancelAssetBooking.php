<?php

namespace App\Actions\Assets;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Services\Audit\AuditLogger;
use DomainException;
use Illuminate\Support\Facades\DB;

class CancelAssetBooking
{
    public function execute(Booking $booking): Booking
    {
        if ($booking->status !== BookingStatus::ACTIVE) {
            throw new DomainException('Only active bookings can be cancelled.');
        }

        return DB::transaction(function () use ($booking) {
            $before = $booking->toArray();

            $booking->update([
                'status' => BookingStatus::CANCELLED,
                'cancelled_at' => now(),
            ]);

            app(AuditLogger::class)->log(
                'asset.booking_cancelled',
                $booking->asset,
                $before,
                $booking->fresh()->toArray()
            );

            return $booking->fresh()->load(['asset', 'bookedBy']);
        });
    }
}
