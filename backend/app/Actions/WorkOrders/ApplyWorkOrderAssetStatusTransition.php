<?php

namespace App\Actions\WorkOrders;

use App\Enums\OperationalStatus;
use App\Models\WorkOrder;
use App\Services\Audit\AuditLogger;

/**
 * Applies an automatic, lifecycle-driven asset operational-status transition
 * for a Work Order (approve -> start -> close -> cancel).
 *
 * Distinct from SetWorkOrderAssetStatus (the manual, user-driven setter behind
 * the "Update status..." button): this never throws and silently no-ops when
 * the guard says to skip or the asset is already at the target status.
 *
 * Guard contract:
 *  - corrective approval -> DOWN,     skip if already UNDER_MAINTENANCE
 *  - start               -> UNDER_MAINTENANCE (forced)
 *  - close               -> ACTIVE,   skip if currently ACTIVE or INACTIVE
 *  - cancel              -> caller-chosen (DOWN|ACTIVE), no skip
 */
class ApplyWorkOrderAssetStatusTransition
{
    /**
     * @param  OperationalStatus  $target
     * @param  OperationalStatus[]  $skipIfCurrent  when the asset's current status is one of these, do nothing
     * @return bool  true if the status was changed
     */
    public function execute(WorkOrder $workOrder, OperationalStatus $target, array $skipIfCurrent = []): bool
    {
        $asset = $workOrder->asset()->lockForUpdate()->first();

        if (! $asset) {
            return false;
        }

        $current = $asset->operational_status;

        if ($current !== null && in_array($current, $skipIfCurrent, true)) {
            return false;
        }

        if ($current === $target) {
            return false;
        }

        $before = $asset->toArray();
        $asset->update(['operational_status' => $target]);

        app(AuditLogger::class)->log('asset.status_updated', $asset, $before, $asset->fresh()->toArray(), [
            'work_order_id' => $workOrder->id,
            'source' => 'work_order_lifecycle',
        ]);

        return true;
    }
}
