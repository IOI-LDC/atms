<?php

namespace App\Actions\MaintenanceRequests;

use App\Actions\Pm\CreatePmSuppression;
use App\Enums\MaintenanceRequestStatus;
use App\Models\MaintenanceRequest;
use App\Services\Audit\AuditLogger;
use DomainException;
use Illuminate\Support\Facades\DB;

class CancelMaintenanceRequest
{
    /**
     * @param  string  $decisionType  How the suppression should read in reporting.
     *                                Defaults to 'cancelled' — a decision to skip
     *                                this occurrence. `CloseWorkOrder` passes
     *                                'performed_under_repair' when the service was
     *                                actually done under another work order, so PM
     *                                compliance does not count it as a skip.
     */
    public function execute(
        MaintenanceRequest $maintenanceRequest,
        int $cancelledByUserId,
        string $reason,
        ?string $suppressedUntilDate = null,
        ?string $suppressedUntilReading = null,
        string $decisionType = 'cancelled'
    ): MaintenanceRequest {
        return DB::transaction(function () use ($maintenanceRequest, $cancelledByUserId, $reason, $suppressedUntilDate, $suppressedUntilReading, $decisionType) {
            $logger = app(AuditLogger::class);
            $locked = MaintenanceRequest::where('id', $maintenanceRequest->id)->lockForUpdate()->first();
            $before = $locked->toArray();

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
                    $decisionType,
                    $suppressedUntilDate,
                    $suppressedUntilReading,
                    $reason
                );
            }

            $after = $locked->fresh()->toArray();
            $logger->log('maintenance_request.cancelled', $locked, $before, $after);

            return $locked->fresh();
        });
    }
}
