<?php

namespace App\Actions\WorkOrders;

use App\Enums\RoleCode;
use App\Enums\WorkOrderStatus;
use App\Models\User;
use App\Models\WorkOrder;
use DomainException;
use Illuminate\Support\Facades\DB;

class StartWorkOrder
{
    public function execute(WorkOrder $workOrder): WorkOrder
    {
        return DB::transaction(function () use ($workOrder) {
            $locked = WorkOrder::where('id', $workOrder->id)->lockForUpdate()->first();

            if ($locked->status !== WorkOrderStatus::OPEN) {
                throw new DomainException('Only open work orders can be started.');
            }

            if ($locked->assigned_to_user_id === null) {
                throw new DomainException('Work order must be assigned before starting.');
            }

            $assignee = User::find($locked->assigned_to_user_id);
            if (! $assignee || ! $assignee->is_active || ! $assignee->hasRole(RoleCode::TECHNICIAN)) {
                throw new DomainException('Assigned user is no longer an active Technician. Reassign before starting.');
            }

            $locked->update([
                'status' => WorkOrderStatus::IN_PROGRESS,
                'started_at' => now(),
            ]);

            return $locked->fresh();
        });
    }
}
