<?php

namespace App\Actions\WorkOrders;

use App\Enums\RoleCode;
use App\Enums\WorkOrderStatus;
use App\Models\User;
use App\Models\WorkOrder;
use DomainException;
use Illuminate\Support\Facades\DB;

class CompleteWorkOrder
{
    public function execute(WorkOrder $workOrder, int $completedByUserId, ?string $completionNotes = null): WorkOrder
    {
        return DB::transaction(function () use ($workOrder, $completedByUserId, $completionNotes) {
            $locked = WorkOrder::where('id', $workOrder->id)->lockForUpdate()->first();

            if ($locked->status !== WorkOrderStatus::IN_PROGRESS) {
                throw new DomainException('Only in-progress work orders can be completed.');
            }

            if ($locked->assigned_to_user_id !== null && $locked->assigned_to_user_id !== $completedByUserId) {
                $completer = User::find($completedByUserId);
                if (! $completer || (! $completer->hasRole(RoleCode::MAINTENANCE_MANAGER) && ! $completer->hasRole(RoleCode::ADMINISTRATOR))) {
                    throw new DomainException('Only the assigned technician or a manager can complete this work order.');
                }
            }

            $locked->update([
                'status' => WorkOrderStatus::COMPLETED,
                'completed_by_user_id' => $completedByUserId,
                'completed_at' => now(),
                'completion_notes' => $completionNotes,
            ]);

            return $locked->fresh();
        });
    }
}
