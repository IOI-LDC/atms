<?php

namespace App\Actions\Assets;

use App\Models\Asset;
use App\Models\AssetMeterReading;
use App\Models\UsageReadingType;

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
        // Just records it, does not confirm it. Confirmation is separate.
        return AssetMeterReading::create([
            'asset_id' => $asset->id,
            'usage_reading_type_id' => $readingType->id,
            'reading_value' => $readingValue,
            'reading_at' => $readingAt,
            'source' => $source,
            'entered_by_user_id' => $enteredByUserId,
            'maintenance_request_id' => $maintenanceRequestId,
            'notes' => $notes,
        ]);
    }
}
