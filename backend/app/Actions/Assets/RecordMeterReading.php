<?php

namespace App\Actions\Assets;

use App\Models\Asset;
use App\Models\AssetMeterReading;
use App\Models\UsageReadingType;
use App\Services\Audit\AuditLogger;

class RecordMeterReading
{
    public function execute(
        Asset $asset,
        UsageReadingType $readingType,
        float $readingValue,
        \DateTimeInterface $readingAt,
        string $source,
        ?int $enteredByUserId = null,
        ?int $maintenanceRequestId = null,
        ?string $notes = null
    ): AssetMeterReading {
        $logger = app(AuditLogger::class);
        $before = [];

        // Just records it, does not confirm it. Confirmation is separate.
        $reading = AssetMeterReading::create([
            'asset_id' => $asset->id,
            'usage_reading_type_id' => $readingType->id,
            'reading_value' => $readingValue,
            'reading_at' => $readingAt,
            'source' => $source,
            'entered_by_user_id' => $enteredByUserId,
            'maintenance_request_id' => $maintenanceRequestId,
            'notes' => $notes,
        ]);

        $after = $reading->toArray();
        $logger->log('meter_reading.recorded', $reading, $before, $after);

        return $reading;
    }
}
