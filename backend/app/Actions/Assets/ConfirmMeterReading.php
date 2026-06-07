<?php

namespace App\Actions\Assets;

use App\Models\AssetMeterReading;
use Illuminate\Support\Facades\DB;
use DomainException;

class ConfirmMeterReading
{
    public function execute(AssetMeterReading $reading, int $confirmedByUserId): AssetMeterReading
    {
        return DB::transaction(function () use ($reading, $confirmedByUserId) {
            $lockedReading = AssetMeterReading::where('id', $reading->id)->lockForUpdate()->first();

            if ($lockedReading->confirmed_at !== null) {
                return $lockedReading; // Already confirmed
            }

            // Find the latest confirmed reading for this asset and type
            // using lockForUpdate or at least checking the max value
            $latestConfirmed = AssetMeterReading::where('asset_id', $lockedReading->asset_id)
                ->where('usage_reading_type_id', $lockedReading->usage_reading_type_id)
                ->whereNotNull('confirmed_at')
                ->lockForUpdate()
                ->orderByDesc('reading_at')
                ->first();

            if ($latestConfirmed && $lockedReading->reading_value < $latestConfirmed->reading_value) {
                throw new DomainException('Confirmed readings must not be lower than the latest confirmed reading.');
            }

            if ($latestConfirmed && $lockedReading->reading_at < $latestConfirmed->reading_at) {
                throw new DomainException('Reading date cannot be earlier than the latest confirmed reading date.');
            }

            $lockedReading->confirmed_by_user_id = $confirmedByUserId;
            $lockedReading->confirmed_at = now();
            $lockedReading->save();

            return $lockedReading;
        });
    }
}
