<?php

namespace App\Actions\Assets;

use App\Models\Asset;
use App\Models\AssetMeterReading;
use App\Models\UsageReadingType;
use App\Services\Audit\AuditLogger;
use DomainException;
use Illuminate\Support\Facades\DB;

class UpdateMeterReading
{
    /**
     * @param array{reading_value:float, reading_at:\DateTimeInterface, notes:?string} $attributes
     */
    public function execute(AssetMeterReading $reading, Asset $asset, UsageReadingType $readingType, float $readingValue, \DateTimeInterface $readingAt, ?string $notes): AssetMeterReading
    {
        return DB::transaction(function () use ($reading, $asset, $readingType, $readingValue, $readingAt, $notes) {
            $logger = app(AuditLogger::class);

            $lockedReading = AssetMeterReading::where('id', $reading->id)->lockForUpdate()->first();
            $before = $lockedReading->toArray();

            // Confirmed readings underpin PM-trigger calculations and must remain immutable.
            if ($lockedReading->confirmed_at !== null) {
                throw new DomainException('Confirmed meter readings cannot be edited.');
            }

            $lockedReading->reading_value = $readingValue;
            $lockedReading->reading_at = $readingAt;
            $lockedReading->notes = $notes;
            $lockedReading->save();

            $after = $lockedReading->fresh()->toArray();
            $logger->log('meter_reading.updated', $lockedReading, $before, $after);

            return $lockedReading;
        });
    }
}
