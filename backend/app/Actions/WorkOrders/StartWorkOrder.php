<?php

namespace App\Actions\WorkOrders;

use App\Actions\WorkOrders\ApplyWorkOrderAssetStatusTransition;
use App\Enums\OperationalStatus;
use App\Enums\RoleCode;
use App\Enums\WorkOrderStatus;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\Audit\AuditLogger;
use DomainException;
use Illuminate\Support\Facades\DB;

class StartWorkOrder
{
    public function execute(WorkOrder $workOrder): WorkOrder
    {
        return DB::transaction(function () use ($workOrder) {
            $logger = app(AuditLogger::class);
            $locked = WorkOrder::where('id', $workOrder->id)->lockForUpdate()->first();

            if ($locked->status !== WorkOrderStatus::OPEN) {
                throw new DomainException('Only open work orders can be started.');
            }

            if ($locked->assigned_to_user_id === null) {
                throw new DomainException('Work order must be assigned before starting.');
            }

            $assignee = User::find($locked->assigned_to_user_id);
            if (! $assignee || ! $assignee->isWorkOrderAssignee()) {
                throw new DomainException('Assigned user is no longer an active Technician or Maintenance Manager. Reassign before starting.');
            }

            $before = $workOrder->toArray();
            $locked->update([
                'status' => WorkOrderStatus::IN_PROGRESS,
                'started_at' => now(),
            ]);
            $after = $workOrder->fresh()->toArray();
            $logger->log('work_order.started', $locked, $before, $after);

            // Force the asset UNDER_MAINTENANCE once work begins (all work orders).
            app(ApplyWorkOrderAssetStatusTransition::class)
                ->execute($locked, OperationalStatus::UNDER_MAINTENANCE);

            return $locked->fresh();
        });
    }
}
