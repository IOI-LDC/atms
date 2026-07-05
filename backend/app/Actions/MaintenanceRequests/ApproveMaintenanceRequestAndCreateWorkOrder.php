<?php

namespace App\Actions\MaintenanceRequests;

use App\Enums\MaintenanceRequestStatus;
use App\Enums\MaintenanceStatus;
use App\Enums\OperationalStatus;
use App\Enums\WorkOrderStatus;
use App\Actions\WorkOrders\ApplyWorkOrderAssetStatusTransition;
use App\Actions\WorkOrders\AssignWorkOrder;
use App\Actions\WorkOrders\SnapshotFormTemplateIntoWorkOrder;
use App\Models\BusinessNumberSequence;
use App\Models\MaintenanceRequest;
use App\Models\WorkOrder;
use App\Services\Audit\AuditLogger;
use DomainException;
use Illuminate\Support\Facades\DB;

class ApproveMaintenanceRequestAndCreateWorkOrder
{
    public function execute(MaintenanceRequest $maintenanceRequest, int $approvedByUserId, ?int $assignToUserId = null, ?bool $isFailure = null): MaintenanceRequest
    {
        $asset = $maintenanceRequest->asset;

        if ($asset && $asset->maintenance_status === MaintenanceStatus::WITHDRAWN) {
            throw new DomainException('Cannot approve a maintenance request for an inactive asset.');
        }

        return DB::transaction(function () use ($maintenanceRequest, $approvedByUserId, $assignToUserId, $isFailure) {
            $logger = app(AuditLogger::class);
            $locked = MaintenanceRequest::where('id', $maintenanceRequest->id)->lockForUpdate()->first();

            $before = $locked->toArray();

            if ($locked->status !== MaintenanceRequestStatus::PENDING_REVIEW) {
                throw new DomainException('Only pending review requests can be approved.');
            }

            $locked->update([
                'status' => MaintenanceRequestStatus::CONVERTED,
                'reviewed_by' => $approvedByUserId,
                'reviewed_at' => now(),
                'is_failure' => $isFailure,
            ]);

            $woNumber = BusinessNumberSequence::next('WO', 'WO-');

            $workOrder = WorkOrder::create([
                'number' => $woNumber,
                'maintenance_request_id' => $locked->id,
                'asset_id' => $locked->asset_id,
                'status' => WorkOrderStatus::OPEN,
                'priority' => $locked->priority,
                'description' => $locked->description,
            ]);

            // WO Forms: snapshot the asset's active FormTemplate (if any) into
            // the new Work Order. Self-contained copy; no-op when no template.
            app(SnapshotFormTemplateIntoWorkOrder::class)->execute($workOrder);

            // Asset operational status: a corrective order reports a fault -
            // mark the asset DOWN unless it is already UNDER_MAINTENANCE (e.g.
            // a concurrent PM). Preventive orders change nothing here; the asset
            // goes UNDER_MAINTENANCE when the WO is started.
            if (! $locked->is_preventive) {
                app(ApplyWorkOrderAssetStatusTransition::class)
                    ->execute($workOrder, OperationalStatus::DOWN, [OperationalStatus::UNDER_MAINTENANCE]);
            }

            // WO-02: optionally assign in the same transaction so approval and
            // assignment are atomic. Reuses AssignWorkOrder for the role rule
            // (active Technician or Maintenance Manager) and the audit event.
            // A failed assignment rolls back the whole conversion.
            if ($assignToUserId !== null) {
                app(AssignWorkOrder::class)->execute($workOrder, $assignToUserId, $approvedByUserId);
            }

            $after = $locked->fresh()->toArray();
            $logger->log('maintenance_request.approved', $locked, $before, $after);

            return $locked->fresh();
        });
    }
}
