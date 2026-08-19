<?php

namespace App\Actions\MaintenanceRequests;

use App\Actions\Assets\RecordMeterReading;
use App\Enums\MaintenanceRequestStatus;
use App\Enums\RoleCode;
use App\Models\Asset;
use App\Models\BusinessNumberSequence;
use App\Models\MaintenanceRequest;
use App\Models\UsageReadingType;
use App\Models\User;
use App\Notifications\MaintenanceRequests\MaintenanceRequestSubmittedNotification;
use App\Services\Audit\AuditLogger;
use App\Support\Assets\AssetWorkEligibility;
use App\Support\FrontendUrl;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

class CreateCorrectiveMaintenanceRequest
{
    public function execute(
        Asset $asset,
        int $createdByUserId,
        string $priority = 'medium',
        ?string $description = null,
        ?array $meterReading = null
    ): MaintenanceRequest {
        $mr = DB::transaction(function () use ($asset, $createdByUserId, $priority, $description, $meterReading) {
            // Locked before the check: the request that follows is new work, and
            // a withdrawal committing between an unlocked read and this insert
            // would leave a pending request against an asset nobody maintains.
            $asset = AssetWorkEligibility::lockAndGuard($asset, 'create a maintenance request');

            $logger = app(AuditLogger::class);
            $number = BusinessNumberSequence::next('MR', 'MR-');

            $before = [];

            $mr = MaintenanceRequest::create([
                'number' => $number,
                'asset_id' => $asset->id,
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

        $this->notifyManagers($mr);

        return $mr;
    }

    private function notifyManagers(MaintenanceRequest $mr): void
    {
        $managerEmails = User::where('is_active', true)
            ->whereHas('role', fn ($q) => $q->where('code', RoleCode::MAINTENANCE_MANAGER->value))
            ->pluck('email')
            ->filter()
            ->values()
            ->all();

        if (empty($managerEmails)) {
            return;
        }

        $mr->load('asset', 'createdBy');

        Notification::route('account_email', 'workflow@atms')
            ->notify(new MaintenanceRequestSubmittedNotification(
                managerEmails: $managerEmails,
                mrNumber: $mr->number,
                assetName: $mr->asset?->name ?? 'Unknown asset',
                priority: $mr->priority,
                requesterName: $mr->createdBy?->name ?? 'System',
                actionUrl: FrontendUrl::to('/maintenance/requests/'.$mr->id),
            ));
    }
}
