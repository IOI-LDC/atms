<?php

namespace App\Actions\Assets;

use App\Enums\BookingStatus;
use App\Models\Asset;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Releases all active bookings on an asset. Called automatically when an asset
 * is deactivated or withdrawn from the maintenance program.
 */
class ReleaseAssetBookings
{
    public function execute(Asset $asset, string $reason = 'asset_deactivated'): int
    {
        return DB::transaction(function () use ($asset, $reason) {
            $activeBookings = $asset->bookings()->active()->get();

            if ($activeBookings->isEmpty()) {
                return 0;
            }

            $asset->bookings()->active()->update([
                'status' => BookingStatus::RELEASED,
                'cancelled_at' => now(),
            ]);

            app(AuditLogger::class)->log(
                'asset.bookings_released',
                $asset,
                ['reason' => $reason],
                ['released_count' => $activeBookings->count()]
            );

            return $activeBookings->count();
        });
    }
}
