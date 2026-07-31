<?php

namespace App\Actions\WorkOrders;

use App\Enums\WorkOrderStatus;
use App\Models\Part;
use App\Models\WorkOrder;
use App\Models\WorkOrderPart;
use App\Services\Audit\AuditLogger;
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
            $logger = app(AuditLogger::class);
            $workOrder = WorkOrder::where('id', $workOrderId)->lockForUpdate()->first();

            if (! in_array($workOrder->status, [WorkOrderStatus::OPEN, WorkOrderStatus::IN_PROGRESS])) {
                throw new DomainException('Parts can only be added to open or in-progress work orders.');
            }

            $this->guardPartIsRequestable(Part::findOrFail($partId), $workOrder);

            $before = [];
            $partLine = WorkOrderPart::create([
                'work_order_id' => $workOrderId,
                'part_id' => $partId,
                'quantity' => $quantity,
                'notes' => $notes,
                'added_by_user_id' => $addedByUserId,
            ]);

            $after = $partLine->toArray();
            $logger->log('record_work_order_part', $partLine, $before, $after);

            return $partLine;
        });
    }

    /**
     * Re-apply every rule the filtered part picker already enforces.
     *
     * The picker narrows its list server-side, so these checks exist to reject a
     * direct API call that skips it. Availability is the ERP snapshot, not a
     * live ATMS balance — recording consumption never decrements it, so this
     * rejects parts ERP reports as out of stock, not parts this work order has
     * exhausted.
     */
    private function guardPartIsRequestable(Part $part, WorkOrder $workOrder): void
    {
        if (! $part->is_active) {
            throw new DomainException('That part is inactive and cannot be requested.');
        }

        if ((float) $part->available_quantity <= 0) {
            throw new DomainException('That part is out of stock and cannot be requested.');
        }

        $asset = $workOrder->asset;

        if ($asset !== null && ! $part->isCompatibleWith($asset)) {
            throw new DomainException('That part is not compatible with this work order\'s asset.');
        }
    }
}
