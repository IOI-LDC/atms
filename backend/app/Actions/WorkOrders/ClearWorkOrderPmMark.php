<?php

namespace App\Actions\WorkOrders;

use App\Models\WorkOrder;
use App\Models\WorkOrderPmMark;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Remove a staged PM mark — "actually, we didn't do that level".
 *
 * Same permission as setting one, deliberately: the technician who mis-marked
 * is the person who notices, and making them find a manager to undo a typo
 * would just leave wrong marks in place.
 *
 * Clearing when there is no mark succeeds silently. The caller's intent — "this
 * work order has no PM mark" — is satisfied either way, and a 404 would make a
 * double-click look like a failure.
 */
class ClearWorkOrderPmMark
{
    public function execute(WorkOrder $workOrder): void
    {
        DB::transaction(function () use ($workOrder) {
            $mark = WorkOrderPmMark::where('work_order_id', $workOrder->id)->lockForUpdate()->first();

            if ($mark === null) {
                return;
            }

            $before = $mark->toArray();
            $assignmentId = $mark->asset_pm_assignment_id;
            $mark->delete();

            app(AuditLogger::class)->log('work_order_pm_mark.cleared', $mark, $before, [], [
                'work_order_id' => $workOrder->id,
                'asset_pm_assignment_id' => $assignmentId,
            ]);
        });
    }
}
