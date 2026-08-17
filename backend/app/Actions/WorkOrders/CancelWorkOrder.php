<?php

namespace App\Actions\WorkOrders;

use App\Enums\OperationalStatus;
use App\Enums\WorkOrderStatus;
use App\Models\WorkOrder;
use App\Models\WorkOrderPmMark;
use App\Notifications\WorkOrders\WorkOrderCancelledNotification;
use App\Services\Audit\AuditLogger;
use App\Support\FrontendUrl;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

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

            // Caller-chosen asset status: FAILURE = still faulty, READY_FOR_FIELD = false alarm.
            if ($assetStatus !== null) {
                app(ApplyWorkOrderAssetStatusTransition::class)->execute($locked, $assetStatus);
            }

            // A PM level marked during the work is discarded, never applied.
            // This is the case the staged model exists for: applying marks
            // immediately would have advanced this asset's schedule for work
            // that is now abandoned, pushing its next service out by a full
            // interval with nothing on the record to explain it.
            $mark = WorkOrderPmMark::where('work_order_id', $locked->id)->lockForUpdate()->first();

            if ($mark !== null) {
                $markBefore = $mark->toArray();
                $markedAssignmentId = $mark->asset_pm_assignment_id;
                $mark->delete();

                $logger->log('work_order_pm_mark.discarded', $locked, $markBefore, [], [
                    'work_order_id' => $locked->id,
                    'asset_pm_assignment_id' => $markedAssignmentId,
                    'reason' => 'work_order_cancelled',
                ]);
            }

            $this->notifyAssignee($locked->fresh(), $reason);

            return $locked->fresh();
        });
    }

    private function notifyAssignee(WorkOrder $workOrder, string $reason): void
    {
        $workOrder->load('asset', 'assignedTo');

        $technicianEmail = $workOrder->assignedTo?->email;

        if (! $technicianEmail) {
            return;
        }

        Notification::route('account_email', 'workflow@atms')
            ->notify(new WorkOrderCancelledNotification(
                technicianEmail: $technicianEmail,
                woNumber: $workOrder->number,
                assetName: $workOrder->asset?->name ?? 'Unknown asset',
                reason: $reason,
                actionUrl: FrontendUrl::to('/work-orders/'.$workOrder->id),
            ));
    }
}
