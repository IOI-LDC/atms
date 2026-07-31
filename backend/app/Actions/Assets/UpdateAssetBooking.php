<?php

namespace App\Actions\Assets;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Services\Audit\AuditLogger;
use DomainException;

class UpdateAssetBooking
{
    /**
     * @param  array{booked_from?: string, booked_until?: string, booking_reference?: ?string, notes?: ?string}  $data
     */
    public function execute(Booking $booking, array $data): Booking
    {
        if ($booking->status !== BookingStatus::ACTIVE) {
            throw new DomainException('Only active bookings can be edited.');
        }

        $before = $booking->only(['booked_from', 'booked_until', 'booking_reference', 'notes']);

        $booking->update($data);

        app(AuditLogger::class)->log(
            'asset.booking_updated',
            $booking->asset,
            $before,
            $booking->fresh()->only(['booked_from', 'booked_until', 'booking_reference', 'notes'])
        );

        return $booking->fresh()->load(['asset', 'bookedBy']);
    }
}
