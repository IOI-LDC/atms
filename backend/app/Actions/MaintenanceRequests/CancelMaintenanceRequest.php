<?php

namespace App\Actions\MaintenanceRequests;

use App\Actions\Pm\CreatePmSuppression;
use App\Enums\MaintenanceRequestStatus;
use App\Models\MaintenanceRequest;
use DomainException;
use Illuminate\Support\Facades\DB;

class CancelMaintenanceRequest
{
    public function execute(
        MaintenanceRequest $maintenanceRequest,
        int $cancelledByUserId,
        string $reason,
        ?string $suppressedUntilDate = null,
        ?string $suppressedUntilReading = null
    ): MaintenanceRequest {
        return DB::transaction(function () use ($maintenanceRequest, $cancelledByUserId, $reason, $suppressedUntilDate, $suppressedUntilReading) {
            $locked = MaintenanceRequest::where('id', $maintenanceRequest->id)->lockForUpdate()->first();

            if ($locked->status !== MaintenanceRequestStatus::PENDING_REVIEW) {
                throw new DomainException('Only pending review requests can be cancelled.');
            }

            $locked->update([
                'status' => MaintenanceRequestStatus::CANCELLED,
                'cancelled_by' => $cancelledByUserId,
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
            ]);

            if ($locked->is_preventive && $locked->pm_rule_id) {
                app(CreatePmSuppression::class)->execute(
                    $locked,
                    $locked->pmRule,
                    $cancelledByUserId,
                    'cancelled',
                    $suppressedUntilDate,
                    $suppressedUntilReading,
                    $reason
                );
            }

            return $locked->fresh();
        });
    }
}
