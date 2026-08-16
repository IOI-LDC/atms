<?php

namespace App\Actions\WorkOrders;

use App\Enums\WorkOrderStatus;
use App\Models\Part;
use App\Models\WorkOrder;
use App\Models\WorkOrderPart;
use App\Services\Audit\AuditLogger;
use DomainException;
use Illuminate\Support\Facades\DB;

class DeleteWorkOrderPart
{
    public function execute(int $workOrderPartId, int $workOrderId): void
    {
        DB::transaction(function () use ($workOrderPartId, $workOrderId) {
            $logger = app(AuditLogger::class);
            $workOrder = WorkOrder::where('id', $workOrderId)->lockForUpdate()->first();

            if (! in_array($workOrder->status, [WorkOrderStatus::OPEN, WorkOrderStatus::IN_PROGRESS])) {
                throw new DomainException('Parts can only be removed from open or in-progress work orders.');
            }

            $partLine = WorkOrderPart::where('id', $workOrderPartId)
                ->where('work_order_id', $workOrderId)
                ->lockForUpdate()
                ->firstOrFail();

            // Read the stored quantity before the delete — it is the only record
            // of how much this line took, and restoring the *stored* value (not
            // whatever the caller once sent) is what makes the round-trip exact.
            $quantity = $partLine->quantity;
            $before = $partLine->toArray();

            $part = Part::where('id', $partLine->part_id)->lockForUpdate()->firstOrFail();
            $stockBefore = $part->available_quantity;

            $partLine->delete();

            DB::update(
                'UPDATE parts SET available_quantity = available_quantity + ?, updated_at = ? WHERE id = ?',
                [$quantity, now(), $part->id],
            );

            $logger->log('delete_work_order_part', $partLine, $before, [], [
                'part_id' => $part->id,
                'available_quantity_before' => $stockBefore,
                'available_quantity_after' => $part->fresh()->available_quantity,
            ]);
        });
    }
}
