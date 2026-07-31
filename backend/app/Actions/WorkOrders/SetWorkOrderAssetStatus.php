<?php

namespace App\Actions\WorkOrders;

use App\Enums\OperationalStatus;
use App\Models\WorkOrder;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

class SetWorkOrderAssetStatus
{
    public function execute(WorkOrder $workOrder, OperationalStatus $status): WorkOrder
    {
        return DB::transaction(function () use ($workOrder, $status) {
            $asset = $workOrder->asset;
            $before = $asset->toArray();

            $asset->update(['operational_status' => $status]);

            app(AuditLogger::class)->log('asset.status_updated', $asset, $before, $asset->fresh()->toArray(), [
                'work_order_id' => $workOrder->id,
            ]);

            return $workOrder;
        });
    }
}
