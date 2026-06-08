<?php

namespace App\Actions\MaintenanceRequests;

use App\Actions\Pm\CreatePmSuppression;
use App\Enums\MaintenanceRequestStatus;
use App\Models\MaintenanceRequest;
use App\Services\Audit\AuditLogger;
use DomainException;
use Illuminate\Support\Facades\DB;

class RejectMaintenanceRequest
{
    public function execute(
        MaintenanceRequest $maintenanceRequest,
        int $rejectedByUserId,
        string $reason,
        ?string $suppressedUntilDate = null,
        ?string $suppressedUntilReading = null
    ): MaintenanceRequest {
        return DB::transaction(function () use ($maintenanceRequest, $rejectedByUserId, $reason, $suppressedUntilDate, $suppressedUntilReading) {
            $logger = app(AuditLogger::class);
            $locked = MaintenanceRequest::where('id', $maintenanceRequest->id)->lockForUpdate()->first();
            $before = $locked->toArray();

            if ($locked->status !== MaintenanceRequestStatus::PENDING_REVIEW) {
                throw new DomainException('Only pending review requests can be rejected.');
            }

            $locked->update([
                'status' => MaintenanceRequestStatus::REJECTED,
                'reviewed_by' => $rejectedByUserId,
                'reviewed_at' => now(),
                'rejection_reason' => $reason,
            ]);

            if ($locked->is_preventive && $locked->pm_rule_id) {
                app(CreatePmSuppression::class)->execute(
                    $locked,
                    $locked->pmRule,
                    $rejectedByUserId,
                    'rejected',
                    $suppressedUntilDate,
                    $suppressedUntilReading,
                    $reason
                );
            }

            $after = $locked->fresh()->toArray();
            $logger->log('maintenance_request.rejected', $locked, $before, $after);

            return $locked->fresh();
        });
    }
}
