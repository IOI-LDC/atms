<?php

namespace App\Actions\WorkOrders;

use App\Enums\WorkOrderStatus;
use App\Models\WorkOrder;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

class UpdateWorkOrderExecution
{
    public function execute(WorkOrder $workOrder, array $attributes): WorkOrder
    {
        return DB::transaction(function () use ($workOrder, $attributes) {
            $logger = app(AuditLogger::class);
            $locked = WorkOrder::where('id', $workOrder->id)->lockForUpdate()->first();

            if (in_array($locked->status, [WorkOrderStatus::CLOSED, WorkOrderStatus::CANCELLED])) {
                throw new \DomainException('Cannot update a closed or cancelled work order.');
            }

            $before = $workOrder->toArray();
            $fillable = array_intersect_key($attributes, array_flip(['description']));
            $locked->update($fillable);
            $after = $workOrder->fresh()->toArray();
            $logger->log('work_order.updated', $locked, $before, $after);

            return $locked->fresh();
        });
    }
}
