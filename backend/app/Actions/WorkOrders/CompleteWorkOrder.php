<?php

namespace App\Actions\WorkOrders;

use App\Enums\RoleCode;
use App\Enums\WorkOrderStatus;
use App\Exceptions\WorkOrderFormIncompleteException;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\Audit\AuditLogger;
use DomainException;
use Illuminate\Support\Facades\DB;

class CompleteWorkOrder
{
    public function execute(WorkOrder $workOrder, int $completedByUserId, ?string $completionNotes = null): WorkOrder
    {
        return DB::transaction(function () use ($workOrder, $completedByUserId, $completionNotes) {
            $logger = app(AuditLogger::class);
            $locked = WorkOrder::where('id', $workOrder->id)->lockForUpdate()->first();

            if ($locked->status !== WorkOrderStatus::IN_PROGRESS) {
                throw new DomainException('Only in-progress work orders can be completed.');
            }

            if ($locked->assigned_to_user_id !== null && $locked->assigned_to_user_id !== $completedByUserId) {
                $completer = User::find($completedByUserId);
                if (! $completer || (! $completer->hasRole(RoleCode::MAINTENANCE_MANAGER) && ! $completer->hasRole(RoleCode::ADMINISTRATOR))) {
                    throw new DomainException('Only the assigned technician or a manager can complete this work order.');
                }
            }

            // WO Forms completion gate: if the WO has an attached form, every
            // required field must be filled before it can transition to
            // completed. Throws a typed exception (caught before the generic
            // DomainException handler) carrying the missing-field list.
            $locked->load('workOrderForm.fields');
            if ($locked->workOrderForm && ! $locked->isFormComplete()) {
                throw new WorkOrderFormIncompleteException($locked->missingRequiredFields());
            }

            // Audit from the locked row (authoritative pre-update state), not
            // the route-bound parameter, which may be stale relative to the
            // row we hold under FOR UPDATE. Aligns with the Form Template
            // actions' pattern.
            $before = $locked->toArray();
            $locked->update([
                'status' => WorkOrderStatus::COMPLETED,
                'completed_by_user_id' => $completedByUserId,
                'completed_at' => now(),
                'completion_notes' => $completionNotes,
            ]);
            $after = $locked->fresh()->toArray();
            $logger->log('work_order.completed', $locked, $before, $after);

            return $locked->fresh();
        });
    }
}
