<?php

namespace App\Actions\MaintenanceRequests;

use App\Enums\MaintenanceRequestStatus;
use App\Enums\WorkOrderStatus;
use App\Models\BusinessNumberSequence;
use App\Models\MaintenanceRequest;
use App\Models\WorkOrder;
use DomainException;
use Illuminate\Support\Facades\DB;

class ApproveMaintenanceRequestAndCreateWorkOrder
{
    public function execute(MaintenanceRequest $maintenanceRequest, int $approvedByUserId): MaintenanceRequest
    {
        return DB::transaction(function () use ($maintenanceRequest, $approvedByUserId) {
            $locked = MaintenanceRequest::where('id', $maintenanceRequest->id)->lockForUpdate()->first();

            if ($locked->status !== MaintenanceRequestStatus::PENDING_REVIEW) {
                throw new DomainException('Only pending review requests can be approved.');
            }

            $locked->update([
                'status' => MaintenanceRequestStatus::CONVERTED,
                'reviewed_by' => $approvedByUserId,
                'reviewed_at' => now(),
            ]);

            $woNumber = BusinessNumberSequence::next('WO', 'WO-');

            WorkOrder::create([
                'number' => $woNumber,
                'maintenance_request_id' => $locked->id,
                'asset_id' => $locked->asset_id,
                'status' => WorkOrderStatus::OPEN,
                'priority' => $locked->priority,
                'description' => $locked->description,
            ]);

            return $locked->fresh();
        });
    }
}
