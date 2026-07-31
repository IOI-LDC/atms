<?php

namespace App\Actions\MaintenanceRequests;

use App\Enums\MaintenanceRequestStatus;
use App\Models\MaintenanceRequest;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

class UpdateMaintenanceRequest
{
    public function execute(MaintenanceRequest $maintenanceRequest, array $data): MaintenanceRequest
    {
        return DB::transaction(function () use ($maintenanceRequest, $data) {
            $logger = app(AuditLogger::class);

            $mr = MaintenanceRequest::where('id', $maintenanceRequest->id)->lockForUpdate()->first();

            if ($mr->status !== MaintenanceRequestStatus::PENDING_REVIEW) {
                throw new \DomainException('Only pending review maintenance requests can be updated.');
            }

            $before = $mr->toArray();

            $allowed = array_intersect_key($data, array_flip(['description', 'priority', 'asset_id']));
            $mr->update($allowed);

            $after = $mr->fresh()->toArray();
            $logger->log('maintenance_request.updated', $mr, $before, $after);

            return $mr;
        });
    }
}
