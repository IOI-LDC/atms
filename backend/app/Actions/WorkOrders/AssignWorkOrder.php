<?php

namespace App\Actions\WorkOrders;

use App\Enums\MaintenanceStatus;
use App\Enums\RoleCode;
use App\Enums\WorkOrderStatus;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\Audit\AuditLogger;
use DomainException;
use Illuminate\Support\Facades\DB;

class AssignWorkOrder
{
    public function execute(WorkOrder $workOrder, int $assignToUserId, int $assignedByUserId): WorkOrder
    {
        $asset = $workOrder->asset;

        if ($asset && $asset->maintenance_status === MaintenanceStatus::WITHDRAWN) {
            throw new DomainException('Cannot assign a work order for an inactive asset.');
        }

        return DB::transaction(function () use ($workOrder, $assignToUserId, $assignedByUserId) {
            $logger = app(AuditLogger::class);
            $locked = WorkOrder::where('id', $workOrder->id)->lockForUpdate()->first();

            if ($locked->status === WorkOrderStatus::CLOSED || $locked->status === WorkOrderStatus::CANCELLED) {
                throw new DomainException('Cannot assign a closed or cancelled work order.');
            }

            $assignee = User::find($assignToUserId);

            if (! $assignee || ! $assignee->isWorkOrderAssignee()) {
                throw new DomainException('Assignee must be an active Technician or Maintenance Manager.');
            }

            $before = $workOrder->toArray();
            $locked->update([
                'assigned_to_user_id' => $assignToUserId,
                'assigned_by_user_id' => $assignedByUserId,
                'assigned_at' => now(),
            ]);
            $after = $workOrder->fresh()->toArray();
            $logger->log('work_order.assigned', $locked, $before, $after);

            return $locked->fresh();
        });
    }
}
