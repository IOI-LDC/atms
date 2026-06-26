<?php

namespace App\Actions\MaintenanceRequests;

use App\Actions\Assets\RecordMeterReading;
use App\Enums\MaintenanceRequestStatus;
use App\Enums\MaintenanceStatus;
use App\Models\Asset;
use App\Models\BusinessNumberSequence;
use App\Models\MaintenanceRequest;
use App\Models\UsageReadingType;
use App\Services\Audit\AuditLogger;
use Carbon\Carbon;
use DomainException;
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
        if ($asset->maintenance_status === MaintenanceStatus::INACTIVE) {
            throw new DomainException('Cannot create a maintenance request for an inactive asset.');
        }

        return DB::transaction(function () use ($asset, $createdByUserId, $priority, $description, $meterReading) {
            $logger = app(AuditLogger::class);
            $number = BusinessNumberSequence::next('MR', 'MR-');

            $before = [];

            $mr = MaintenanceRequest::create([
                'number' => $number,
                'asset_id' => $asset->id,
                'type' => 'corrective',
                'status' => MaintenanceRequestStatus::PENDING_REVIEW,
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

            $after = $mr->fresh()->toArray();
            $logger->log('maintenance_request.created', $mr, $before, $after);

            return $mr;
        });
    }
}
