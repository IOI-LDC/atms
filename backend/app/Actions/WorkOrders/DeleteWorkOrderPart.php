<?php

namespace App\Actions\WorkOrders;

use App\Enums\WorkOrderStatus;
use App\Models\WorkOrder;
use App\Models\WorkOrderPart;
use DomainException;
use Illuminate\Support\Facades\DB;

class DeleteWorkOrderPart
{
    public function execute(int $workOrderPartId, int $workOrderId): void
    {
        DB::transaction(function () use ($workOrderPartId, $workOrderId) {
            $workOrder = WorkOrder::where('id', $workOrderId)->lockForUpdate()->first();

            if (! in_array($workOrder->status, [WorkOrderStatus::OPEN, WorkOrderStatus::IN_PROGRESS])) {
                throw new DomainException('Parts can only be removed from open or in-progress work orders.');
            }

            $partLine = WorkOrderPart::where('id', $workOrderPartId)
                ->where('work_order_id', $workOrderId)
                ->firstOrFail();

            $partLine->delete();
        });
    }
}
