<?php

namespace App\Actions\WorkOrders;

use App\Actions\WorkOrders\ApplyWorkOrderAssetStatusTransition;
use App\Enums\OperationalStatus;
use App\Enums\WorkOrderStatus;
use App\Models\WorkOrder;
use App\Services\Audit\AuditLogger;
use DomainException;
use Illuminate\Support\Facades\DB;

class CancelWorkOrder
{
    public function execute(WorkOrder $workOrder, int $cancelledByUserId, string $reason, ?OperationalStatus $assetStatus = null): WorkOrder
    {
        return DB::transaction(function () use ($workOrder, $cancelledByUserId, $reason, $assetStatus) {
            $logger = app(AuditLogger::class);
            $locked = WorkOrder::where('id', $workOrder->id)->lockForUpdate()->first();

            if (! in_array($locked->status, [WorkOrderStatus::OPEN, WorkOrderStatus::IN_PROGRESS, WorkOrderStatus::COMPLETED])) {
                throw new DomainException('Only open, in-progress, or completed work orders can be cancelled.');
            }

            $before = $workOrder->toArray();
            $locked->update([
                'status' => WorkOrderStatus::CANCELLED,
                'cancelled_by_user_id' => $cancelledByUserId,
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
            ]);

            $after = $workOrder->fresh()->toArray();
            $logger->log('work_order.cancelled', $locked, $before, $after);

            // Caller-chosen asset status: DOWN = still faulty, ACTIVE = false alarm.
            if ($assetStatus !== null) {
                app(ApplyWorkOrderAssetStatusTransition::class)->execute($locked, $assetStatus);
            }

            return $locked->fresh();
        });
    }
}
