<?php

namespace App\Actions\WorkOrders;

use App\Enums\WorkOrderStatus;
use App\Models\WorkOrder;
use App\Models\WorkOrderPmMark;
use App\Services\Audit\AuditLogger;
use DomainException;
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
 *
 * ⚠️ Locks the **work order** first, then the mark — the same order
 * {@see SetWorkOrderPmMark} and `CancelWorkOrder` take. It previously locked
 * only the mark, which left the status check running against a row nothing
 * held: a clear could pass authorisation on a completed work order, race a
 * concurrent close, and delete the mark either just after the order went
 * terminal or just before close consumed it. Either way the asset's PM
 * baseline silently failed to advance for work that was actually performed.
 */
class ClearWorkOrderPmMark
{
    public function execute(WorkOrder $workOrder): void
    {
        DB::transaction(function () use ($workOrder) {
            $locked = WorkOrder::where('id', $workOrder->id)->lockForUpdate()->first();

            if (! in_array($locked->status, [
                WorkOrderStatus::OPEN,
                WorkOrderStatus::IN_PROGRESS,
                WorkOrderStatus::COMPLETED,
            ], true)) {
                throw new DomainException('A PM level can only be cleared on an open, in-progress or completed work order.');
            }

            $mark = WorkOrderPmMark::where('work_order_id', $locked->id)->lockForUpdate()->first();

            if ($mark === null) {
                return;
            }

            $before = $mark->toArray();
            $assignmentId = $mark->asset_pm_assignment_id;
            $mark->delete();

            app(AuditLogger::class)->log('work_order_pm_mark.cleared', $mark, $before, [], [
                'work_order_id' => $locked->id,
                'asset_pm_assignment_id' => $assignmentId,
            ]);
        });
    }
}
