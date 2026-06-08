<?php

namespace App\Actions\MaintenanceRequests;

use App\Actions\Assets\RecordMeterReading;
use App\Models\Asset;
use App\Models\BusinessNumberSequence;
use App\Models\MaintenanceRequest;
use App\Models\UsageReadingType;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class CreateCorrectiveMaintenanceRequest
{
    public function execute(
        Asset $asset,
        int $createdByUserId,
        string $priority = 'medium',
        ?string $description = null,
        ?array $meterReading = null
    ): MaintenanceRequest {
        return DB::transaction(function () use ($asset, $createdByUserId, $priority, $description, $meterReading) {
            $number = BusinessNumberSequence::next('MR', 'MR-');

            $mr = MaintenanceRequest::create([
                'number' => $number,
                'asset_id' => $asset->id,
                'type' => 'corrective',
                'status' => 'pending_review',
                'priority' => $priority,
                'description' => $description,
                'created_by' => $createdByUserId,
                'is_preventive' => false,
            ]);

            if ($meterReading) {
                $readingType = UsageReadingType::findOrFail($meterReading['usage_reading_type_id']);

                app(RecordMeterReading::class)->execute(
                    $asset,
                    $readingType,
                    (float) $meterReading['reading_value'],
                    Carbon::parse($meterReading['reading_at']),
                    'user',
                    $createdByUserId,
                    $mr->id,
                    null
                );
            }

            return $mr;
        });
    }
}
