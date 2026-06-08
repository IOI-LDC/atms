<?php

namespace App\Actions\Assets;

use App\Models\AssetMeterReading;
use App\Services\Audit\AuditLogger;
use DomainException;
use Illuminate\Support\Facades\DB;

class ConfirmMeterReading
{
    public function execute(AssetMeterReading $reading, int $confirmedByUserId): AssetMeterReading
    {
        return DB::transaction(function () use ($reading, $confirmedByUserId) {
            $logger = app(AuditLogger::class);

            AssetMeterReading::where('asset_id', $reading->asset_id)
                ->where('usage_reading_type_id', $reading->usage_reading_type_id)
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            $latestConfirmed = AssetMeterReading::where('asset_id', $reading->asset_id)
                ->where('usage_reading_type_id', $reading->usage_reading_type_id)
                ->whereNotNull('confirmed_at')
                ->orderByDesc('reading_at')
                ->first();

            $lockedReading = AssetMeterReading::where('id', $reading->id)->lockForUpdate()->first();
            $before = $lockedReading->toArray();

            if ($lockedReading->confirmed_at !== null) {
                return $lockedReading;
            }

            if ($latestConfirmed && $lockedReading->reading_value < $latestConfirmed->reading_value) {
                throw new DomainException('Confirmed readings must not be lower than the latest confirmed reading.');
            }

            if ($latestConfirmed && $lockedReading->reading_at < $latestConfirmed->reading_at) {
                throw new DomainException('Reading date cannot be earlier than the latest confirmed reading date.');
            }

            $lockedReading->confirmed_by_user_id = $confirmedByUserId;
            $lockedReading->confirmed_at = now();
            $lockedReading->save();

            $after = $lockedReading->fresh()->toArray();
            $logger->log('meter_reading.confirmed', $lockedReading, $before, $after);

            return $lockedReading;
        });
    }
}
