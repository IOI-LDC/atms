<?php

namespace App\Actions\WorkOrders;

use App\Enums\WorkOrderStatus;
use App\Models\WorkOrder;
use DomainException;
use Illuminate\Support\Facades\DB;

class CancelWorkOrder
{
    public function execute(WorkOrder $workOrder, int $cancelledByUserId, string $reason): WorkOrder
    {
        return DB::transaction(function () use ($workOrder, $cancelledByUserId, $reason) {
            $locked = WorkOrder::where('id', $workOrder->id)->lockForUpdate()->first();

            if (! in_array($locked->status, [WorkOrderStatus::OPEN, WorkOrderStatus::IN_PROGRESS, WorkOrderStatus::COMPLETED])) {
                throw new DomainException('Only open, in-progress, or completed work orders can be cancelled.');
            }

            $locked->update([
                'status' => WorkOrderStatus::CANCELLED,
                'cancelled_by_user_id' => $cancelledByUserId,
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
            ]);

            return $locked->fresh();
        });
    }
}
