<?php

namespace App\Actions\WorkOrders;

use App\Enums\WorkOrderStatus;
use App\Models\WorkOrder;
use App\Models\WorkOrderPart;
use DomainException;
use Illuminate\Support\Facades\DB;

class RecordWorkOrderPart
{
    public function execute(
        int $workOrderId,
        int $partId,
        float $quantity,
        int $addedByUserId,
        ?string $notes = null
    ): WorkOrderPart {
        return DB::transaction(function () use ($workOrderId, $partId, $quantity, $addedByUserId, $notes) {
            $workOrder = WorkOrder::where('id', $workOrderId)->lockForUpdate()->first();

            if (! in_array($workOrder->status, [WorkOrderStatus::OPEN, WorkOrderStatus::IN_PROGRESS])) {
                throw new DomainException('Parts can only be added to open or in-progress work orders.');
            }

            return WorkOrderPart::create([
                'work_order_id' => $workOrderId,
                'part_id' => $partId,
                'quantity' => $quantity,
                'notes' => $notes,
                'added_by_user_id' => $addedByUserId,
            ]);
        });
    }
}
