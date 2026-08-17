<?php

namespace App\Actions\WorkOrders;

use App\Enums\WorkOrderStatus;
use App\Models\AssetPmAssignment;
use App\Models\WorkOrder;
use App\Models\WorkOrderPmMark;
use App\Services\Audit\AuditLogger;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Record the highest PM level performed during this work order (RQ1).
 *
 * Idempotent by construction: `work_order_pm_marks.work_order_id` is unique, so
 * this replaces any existing mark rather than adding one. Submitting the same
 * body twice is a no-op beyond a touched timestamp.
 *
 * Nothing is applied here. The mark is staged and `CloseWorkOrder` turns it into
 * a baseline reset; cancelling discards it. Applying immediately would advance
 * the asset's schedule for work that may never be signed off.
 */
class SetWorkOrderPmMark
{
    public function execute(WorkOrder $workOrder, int $assignmentId, int $markedByUserId): WorkOrderPmMark
    {
        return DB::transaction(function () use ($workOrder, $assignmentId, $markedByUserId) {
            $logger = app(AuditLogger::class);
            $locked = WorkOrder::where('id', $workOrder->id)->lockForUpdate()->first();

            // The policy already excludes closed and cancelled. This covers the
            // remaining case it cannot: an Admin or Manager acting on a COMPLETED
            // work order is legitimate — the paperwork window is still open —
            // but an OPEN one that was never started is not what "performed
            // during the work" means.
            if (! in_array($locked->status, [
                WorkOrderStatus::OPEN,
                WorkOrderStatus::IN_PROGRESS,
                WorkOrderStatus::COMPLETED,
            ], true)) {
                throw new DomainException('A PM level can only be marked on an open, in-progress or completed work order.');
            }

            $assignment = AssetPmAssignment::where('id', $assignmentId)->lockForUpdate()->first();

            // Same two guards `CloseWorkOrder::recordDeclaredService` applies, so
            // a mark that passes here cannot fail at close for a reason the
            // marker could have been told about immediately.
            if (! $assignment || $assignment->asset_id !== $locked->asset_id) {
                throw new DomainException('That service schedule does not belong to this work order\'s asset.');
            }

            if (! $assignment->is_active) {
                throw new DomainException('That service schedule is not active.');
            }

            $existing = WorkOrderPmMark::where('work_order_id', $locked->id)->first();
            $before = $existing?->toArray() ?? [];

            $mark = WorkOrderPmMark::updateOrCreate(
                ['work_order_id' => $locked->id],
                [
                    'asset_pm_assignment_id' => $assignment->id,
                    'marked_by_user_id' => $markedByUserId,
                    'marked_at' => now(),
                ],
            );

            $logger->log('work_order_pm_mark.set', $mark, $before, $mark->fresh()->toArray(), [
                'work_order_id' => $locked->id,
                'asset_pm_assignment_id' => $assignment->id,
                'maintenance_level' => $assignment->pmRule?->maintenance_level,
                'replaced_assignment_id' => $existing?->asset_pm_assignment_id,
            ]);

            return $mark->fresh();
        });
    }
}
